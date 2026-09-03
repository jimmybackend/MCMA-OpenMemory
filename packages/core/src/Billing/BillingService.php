<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;
use RuntimeException;

final class BillingService
{
    private const ACCOUNT_REF = 'memory://billing/account';

    public function __construct(private readonly BillingCatalog $catalog)
    {
    }

    public function ensureAccount(Library $library,string $planId='free'): array
    {
        if(!$this->hasRef($library,self::ACCOUNT_REF)){
            $this->catalog->plan($planId);
            try{
                $library->writeAs('owner',self::ACCOUNT_REF,[
                    'version'=>'1.0',
                    'plan_id'=>$planId,
                    'service_status'=>'active',
                    'created_at'=>self::now(),
                    'updated_at'=>self::now(),
                ],'json','warm','00-system','system','confirmed');
            }catch(RuntimeException $e){
                if(!str_contains(strtolower($e->getMessage()),'already exists')) throw $e;
            }
        }
        return $this->account($library);
    }

    public function account(Library $library): array
    {
        $library->refresh();
        $content=$library->read(self::ACCOUNT_REF)['payload']['content']??null;
        if(!is_array($content)||array_is_list($content)||($content['version']??null)!=='1.0') throw new RuntimeException('Malformed billing account');
        return $content;
    }

    public function setPlan(Library $library,string $planId): array
    {
        $this->catalog->plan($planId);
        $this->ensureAccount($library);
        $library->mutateJson(self::ACCOUNT_REF,function(mixed $current)use($planId):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed billing account');
            $current['plan_id']=$planId;$current['updated_at']=self::now();return $current;
        },'owner');
        return $this->account($library);
    }

    public function setServiceStatus(Library $library,string $status): array
    {
        if(!in_array($status,['active','suspended','cancelled'],true)) throw new RuntimeException('Invalid service status');
        $this->ensureAccount($library);
        $library->mutateJson(self::ACCOUNT_REF,function(mixed $current)use($status):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed billing account');
            $current['service_status']=$status;$current['updated_at']=self::now();return $current;
        },'owner');
        return $this->account($library);
    }

    public function setStripeSubscriptionState(
        Library $library,
        string $subscriptionId,
        string $packageId,
        string $status,
        ?int $currentPeriodEnd,
        bool $cancelAtPeriodEnd
    ): array {
        if(!preg_match('/^sub_[A-Za-z0-9_]+$/',$subscriptionId)) throw new RuntimeException('Invalid Stripe subscription id');
        if(!preg_match('/^[a-z][a-z0-9_-]{1,63}$/',$packageId)) throw new RuntimeException('Invalid Stripe package id');
        if(!in_array($status,['trialing','active','past_due','incomplete','incomplete_expired','unpaid','canceled','paused'],true)){
            throw new RuntimeException('Invalid Stripe subscription status');
        }
        if($currentPeriodEnd!==null&&$currentPeriodEnd<0) throw new RuntimeException('Invalid Stripe subscription period end');

        $this->ensureAccount($library);
        $library->mutateJson(self::ACCOUNT_REF,function(mixed $current)use(
            $subscriptionId,$packageId,$status,$currentPeriodEnd,$cancelAtPeriodEnd
        ):array{
            if(!is_array($current)||array_is_list($current)) throw new RuntimeException('Malformed billing account');
            $current['stripe_subscription']=[
                'subscription_id'=>$subscriptionId,
                'package_id'=>$packageId,
                'status'=>$status,
                'current_period_end'=>$currentPeriodEnd,
                'cancel_at_period_end'=>$cancelAtPeriodEnd,
                'updated_at'=>self::now(),
            ];
            $current['updated_at']=self::now();
            return $current;
        },'owner');
        return $this->account($library);
    }

    public function state(Library $library): array
    {
        $library->refresh();
        $refs=$this->ledgerRefs($library);
        if($refs===[]) return ['balance_units'=>0,'reserved_units'=>0,'active_reservations'=>[],'last_ledger_ref'=>null];
        $ref=end($refs);
        $content=$library->read($ref)['payload']['content']??null;
        if(!is_array($content)||array_is_list($content)) throw new RuntimeException('Malformed billing ledger');
        $state=$content['closing_state']??null;
        if(!is_array($state)) throw new RuntimeException('Billing ledger has no closing state');
        return $state+['last_ledger_ref'=>$ref];
    }

    public function summary(Library $library): array
    {
        $account=$this->ensureAccount($library);
        $plan=$this->catalog->plan((string)$account['plan_id']);
        $allowance=$this->ensureMonthlyAllowanceForPlan($library,$account,$plan);
        $state=$this->state($library);
        return [
            'account'=>$account,
            'plan'=>$plan,
            'balance_units'=>(int)$state['balance_units'],
            'reserved_units'=>(int)$state['reserved_units'],
            'available_units'=>(int)$state['balance_units']-(int)$state['reserved_units'],
            'active_requests'=>count($state['active_reservations']??[]),
            'last_ledger_ref'=>$state['last_ledger_ref']??null,
            'quota'=>$this->quotaSnapshot($library,$plan,$allowance),
        ];
    }

    public function adjustCredits(Library $library,int $units,string $reason,string $source='admin-adjustment',array $metadata=[]): array
    {
        if($units===0) throw new RuntimeException('Credit adjustment cannot be zero');
        $state=$this->state($library);
        if($units<0){
            $available=(int)$state['balance_units']-(int)$state['reserved_units'];
            if(abs($units)>$available) throw new BillingException('Credit adjustment exceeds available balance','adjustment_exceeds_balance',409);
        }

        return $this->appendEvent($library,[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'adjustment',
            'occurred_at'=>self::now(),
            'source'=>$source,
            'reason'=>substr(trim($reason),0,512),
            'balance_delta_units'=>$units,
            'reserved_delta_units'=>0,
            'metadata'=>$metadata,
        ]);
    }

    public function credit(Library $library,int $units,string $reason,string $source='admin',array $metadata=[]): array
    {
        if($units<1) throw new RuntimeException('Credit units must be positive');
        return $this->appendEvent($library,[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'credit',
            'occurred_at'=>self::now(),
            'source'=>$source,
            'reason'=>substr(trim($reason),0,512),
            'balance_delta_units'=>$units,
            'reserved_delta_units'=>0,
            'metadata'=>$metadata,
        ]);
    }

    public function authorizeChannel(Library $library,string $origin): array
    {
        $account=$this->ensureAccount($library);
        if(($account['service_status']??null)!=='active'){
            throw new BillingException('Service is not active','service_inactive',403);
        }
        $plan=$this->catalog->plan((string)$account['plan_id']);
        if($origin==='api'&&!($plan['api_enabled']??false)){
            throw new BillingException('API access is not enabled for this plan','api_not_allowed',403);
        }
        return ['account'=>$account,'plan'=>$plan];
    }

    public function estimateReservation(
        string $question,
        ?string $embeddingProviderId,
        ?string $generationProviderId,
        int $maxOutputTokens,
        int $generationContextBytes = 0
    ): array {
        $components = [];
        $bytes = max(1, strlen($question));
        $generationContextBytes=max(0,min(1_000_000,$generationContextBytes));

        if ($embeddingProviderId !== null) {
            // Reserve conservatively for semantic query plus a possible
            // Librarian re-index of a newly generated record.
            $components[] = [
                'provider_id'=>$embeddingProviderId,
                'input_tokens'=>0,
                'output_tokens'=>0,
                'cached_tokens'=>0,
                // A tokenizer cannot emit more tokens than input bytes for
                // byte-representable text, so bytes are already a conservative
                // upper bound. Multiplying by two caused false credit exhaustion.
                'embedding_tokens'=>min(1_000_000_000,$bytes),
            ];
        }

        if ($generationProviderId !== null) {
            $components[] = [
                'provider_id'=>$generationProviderId,
                // Question/context budgets are byte upper bounds. Treating
                // each byte as two tokens double-counted reserved context and
                // could block users whose real credit balance was sufficient.
                'input_tokens'=>min(1_000_000_000,$bytes + $generationContextBytes),
                'output_tokens'=>max(0,$maxOutputTokens),
                'cached_tokens'=>0,
                'embedding_tokens'=>0,
            ];
        }

        $estimatedTokens=0;
        foreach($components as $component){
            if(!is_array($component)) continue;
            $estimatedTokens+=max(0,(int)($component['input_tokens']??0))
                +max(0,(int)($component['output_tokens']??0))
                +max(0,(int)($component['embedding_tokens']??0));
        }

        return $this->catalog->calculate($components)+['estimated_tokens'=>$estimatedTokens];
    }

    public function reserve(
        Library $library,
        string $requestId,
        string $origin,
        array $providerIds,
        int $estimatedCreditUnits,
        int $estimatedTokens=0
    ): array {
        $authorization=$this->authorizeChannel($library,$origin);
        $account=$authorization['account'];
        $plan=$authorization['plan'];
        $this->ensureMonthlyAllowanceForPlan($library,$account,$plan);

        foreach($providerIds as $providerId){
            if(!$this->providerAllowed((string)$providerId,$plan['allowed_providers']??[])) throw new BillingException('Provider is not allowed by plan','provider_not_allowed',403);
            $this->catalog->price((string)$providerId);
        }

        if($estimatedCreditUnits<0) throw new RuntimeException('Estimated credits cannot be negative');
        if($estimatedTokens<0) throw new RuntimeException('Estimated tokens cannot be negative');
        if($estimatedCreditUnits>(int)$plan['max_request_credit_units']) throw new BillingException('Request exceeds plan credit limit','request_credit_limit',402);

        $monthlyTokenLimit=(int)($plan['monthly_token_limit']??0);
        if($monthlyTokenLimit>0){
            $month=$this->monthlyUsage($library,gmdate('Y-m'));
            $used=(int)($month['summary']['total_tokens']??0);
            // Monthly token quota is authoritative on settled provider usage.
            // Reservation estimates are intentionally conservative (context,
            // output and embedding headroom), so treating them as consumed
            // tokens can falsely block a user who still has quota remaining.
            if($used>=$monthlyTokenLimit){
                throw new BillingException('Monthly AI token allowance exhausted','monthly_token_limit',402);
            }
        }

        $state=$this->state($library);
        $active=$state['active_reservations']??[];
        if(count($active)>=(int)$plan['concurrent_requests']) throw new BillingException('Concurrent request limit reached','concurrency_limit',429);

        $events=$this->todayEvents($library);
        $now=time();$lastMinute=0;$todayReservations=0;
        foreach($events as $event){
            if(($event['type']??null)!=='reservation') continue;
            $todayReservations++;
            $ts=strtotime((string)($event['occurred_at']??''))?:0;
            if($ts>=$now-60) $lastMinute++;
        }
        if($lastMinute>=(int)$plan['requests_per_minute']) throw new BillingException('Request rate limit reached','rate_limit',429);
        if($todayReservations>=(int)$plan['daily_request_limit']) throw new BillingException('Daily request limit reached','daily_limit',429);

        $available=(int)$state['balance_units']-(int)$state['reserved_units'];
        if($estimatedCreditUnits>$available) throw new BillingException('Insufficient credits','insufficient_credits',402);

        $reservationId='res_'.bin2hex(random_bytes(16));
        $event=[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'reservation',
            'occurred_at'=>self::now(),
            'request_id'=>$requestId,
            'reservation_id'=>$reservationId,
            'origin'=>$origin,
            'provider_ids'=>array_values($providerIds),
            'reserved_credit_units'=>$estimatedCreditUnits,
            'balance_delta_units'=>0,
            'reserved_delta_units'=>$estimatedCreditUnits,
        ];
        $ledger=$this->appendEvent($library,$event);
        return ['reservation_id'=>$reservationId,'reserved_credit_units'=>$estimatedCreditUnits,'ledger'=>$ledger];
    }

    public function settle(
        Library $library,
        string $requestId,
        string $reservationId,
        string $origin,
        UsageCollector $collector,
        string $status='success',
        array $metadata=[]
    ): array {
        $state=$this->state($library);
        $reservation=$state['active_reservations'][$reservationId]??null;
        if(!is_array($reservation)) throw new BillingException('Billing reservation not found','reservation_missing',409);
        if(($reservation['request_id']??null)!==$requestId) throw new BillingException('Billing reservation/request mismatch','reservation_mismatch',409);

        $calculated=$this->catalog->calculate($collector->components());
        $credits=(int)$calculated['credit_units'];
        $reserved=(int)($reservation['units']??0);

        $availableBeyondReserved=(int)$state['balance_units']-(int)$state['reserved_units'];
        if($credits>$reserved+$availableBeyondReserved){
            // The external model has already executed, so usage must remain auditable.
            // Allow the balance to go negative and block future requests until credits are restored.
            $metadata['overdraft']=true;
        }

        $event=[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'usage',
            'occurred_at'=>self::now(),
            'request_id'=>$requestId,
            'reservation_id'=>$reservationId,
            'origin'=>$origin,
            'status'=>$status,
            'provider_usage'=>$collector->components(),
            'usage_summary'=>$collector->summary(),
            'pricing_snapshots'=>$calculated['pricing_snapshots'],
            'cost_micros'=>$calculated['cost_micros'],
            'currency'=>$calculated['currency'],
            'credit_units_charged'=>$credits,
            'balance_delta_units'=>-$credits,
            'reserved_delta_units'=>-$reserved,
            'metadata'=>$metadata,
        ];
        $ledger=$this->appendEvent($library,$event);
        return ['usage'=>$event,'ledger'=>$ledger,'balance'=>$this->state($library)];
    }

    public function release(Library $library,string $requestId,string $reservationId,string $reason): array
    {
        $state=$this->state($library);
        $reservation=$state['active_reservations'][$reservationId]??null;
        if(!is_array($reservation)) return ['released'=>false,'reason'=>'already-settled-or-missing'];
        $reserved=(int)($reservation['units']??0);
        $ledger=$this->appendEvent($library,[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'release',
            'occurred_at'=>self::now(),
            'request_id'=>$requestId,
            'reservation_id'=>$reservationId,
            'reason'=>substr($reason,0,512),
            'balance_delta_units'=>0,
            'reserved_delta_units'=>-$reserved,
        ]);
        return ['released'=>true,'units'=>$reserved,'ledger'=>$ledger];
    }

    public function recordPayment(Library $library,PaymentProvider $provider,array $verifiedPayment): array
    {
        $payment=$provider->normalizeVerifiedPayment($verifiedPayment);
        $fingerprint=hash('sha256',(string)$payment['provider'].'|'.(string)$payment['provider_payment_id']);
        foreach($this->ledgerRefs($library) as $ref){
            $content=$library->read($ref)['payload']['content']??null;
            if(!is_array($content)) continue;
            foreach(($content['events']??[]) as $existing){
                if(is_array($existing)&&($existing['type']??null)==='payment'&&($existing['payment_fingerprint']??null)===$fingerprint){
                    throw new BillingException('Payment is already recorded','duplicate_payment',409);
                }
            }
        }
        $event=[
            'event_id'=>'evt_'.bin2hex(random_bytes(16)),
            'type'=>'payment',
            'occurred_at'=>self::now(),
            'payment_fingerprint'=>$fingerprint,
            'payment'=>$payment,
            'balance_delta_units'=>(int)$payment['credit_units'],
            'reserved_delta_units'=>0,
        ];
        $ledger=$this->appendEvent($library,$event);
        return ['payment'=>$payment,'ledger'=>$ledger];
    }

    public function totals(Library $library): array
    {
        $library->refresh();
        $totals = [
            'requests'=>0,'input_tokens'=>0,'output_tokens'=>0,'cached_tokens'=>0,
            'embedding_tokens'=>0,'total_tokens'=>0,'model_calls'=>0,'duration_ms'=>0,
            'credit_units_charged'=>0,'cost_micros'=>0,'cost_micros_by_currency'=>[],'payments'=>0,
            'payment_amount_micros_by_currency'=>[],'payment_credit_units'=>0,
        ];

        foreach ($this->ledgerRefs($library) as $ref) {
            $content = $library->read($ref)['payload']['content'] ?? null;
            if (!is_array($content)) continue;
            $summary = is_array($content['summary'] ?? null) ? $content['summary'] : [];
            foreach (['requests','input_tokens','output_tokens','cached_tokens','embedding_tokens','total_tokens','model_calls','duration_ms','credit_units_charged','cost_micros'] as $field) {
                $totals[$field] += (int)($summary[$field] ?? 0);
            }
            foreach (($content['events'] ?? []) as $event) {
                if (!is_array($event)) continue;
                if (($event['type'] ?? null) === 'usage') {
                    $usageCurrency=(string)($event['currency']??'USD');
                    $totals['cost_micros_by_currency'][$usageCurrency]=
                        (int)($totals['cost_micros_by_currency'][$usageCurrency]??0)
                        +(int)($event['cost_micros']??0);
                    continue;
                }
                if (($event['type'] ?? null) !== 'payment') continue;
                $payment = $event['payment'] ?? null;
                if (!is_array($payment) || ($payment['status'] ?? null) !== 'confirmed') continue;
                $currency = (string)($payment['currency'] ?? 'USD');
                $totals['payments']++;
                $totals['payment_credit_units'] += (int)($payment['credit_units'] ?? 0);
                $totals['payment_amount_micros_by_currency'][$currency] =
                    (int)($totals['payment_amount_micros_by_currency'][$currency] ?? 0)
                    + (int)($payment['amount_micros'] ?? 0);
            }
        }

        ksort($totals['cost_micros_by_currency'], SORT_STRING);
        ksort($totals['payment_amount_micros_by_currency'], SORT_STRING);
        return $totals;
    }

    public function dailyUsage(Library $library,string $date): array
    {
        $library->refresh();
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Date must be YYYY-MM-DD');
        $ref=self::ledgerRef($date);
        if(!$this->hasRef($library,$ref)) return ['date'=>$date,'events'=>[],'summary'=>self::emptyUsageSummary()];
        $content=$library->read($ref)['payload']['content']??null;
        if(!is_array($content)) throw new RuntimeException('Malformed billing ledger');
        return $content;
    }

    public function monthlyUsage(Library $library,string $period): array
    {
        if(!preg_match('/^\d{4}-\d{2}$/',$period)) throw new RuntimeException('Period must be YYYY-MM');
        $library->refresh();
        $summary=self::emptyUsageSummary();
        $reservations=0;
        $prefix='memory://billing/ledger/'.str_replace('-','/',$period).'/';

        foreach($this->ledgerRefs($library) as $ref){
            if(!str_starts_with($ref,$prefix)) continue;
            $content=$library->read($ref)['payload']['content']??null;
            if(!is_array($content)) throw new RuntimeException('Malformed billing ledger');
            $daily=is_array($content['summary']??null)?$content['summary']:[];
            foreach(['requests','input_tokens','output_tokens','cached_tokens','embedding_tokens','total_tokens','model_calls','duration_ms','credit_units_charged','cost_micros'] as $field){
                $summary[$field]=(int)($summary[$field]??0)+(int)($daily[$field]??0);
            }
            foreach(($content['events']??[]) as $event){
                if(is_array($event)&&($event['type']??null)==='reservation') $reservations++;
            }
        }

        return ['period'=>$period,'reservations'=>$reservations,'summary'=>$summary];
    }


    private function ensureMonthlyAllowanceForPlan(Library $library,array $account,array $plan): array
    {
        $period=gmdate('Y-m');
        $target=(int)($plan['monthly_credit_allowance']??0);
        $planId=(string)($account['plan_id']??$plan['id']??'');

        if($target<=0){
            return [
                'period'=>$period,'plan_id'=>$planId,'target_credit_units'=>0,
                'granted_credit_units'=>0,'applied'=>false,
            ];
        }

        $existing=$this->findMonthlyAllowanceEvent($library,$period,$planId);
        if($existing!==null){
            return [
                'period'=>$period,'plan_id'=>$planId,
                'target_credit_units'=>(int)($existing['target_credit_units']??$target),
                'granted_credit_units'=>(int)($existing['granted_credit_units']??0),
                'applied'=>true,
            ];
        }

        $state=$this->state($library);
        $available=(int)($state['balance_units']??0)-(int)($state['reserved_units']??0);
        $grant=max(0,$target-$available);
        $event=[
            'event_id'=>'allowance_'.substr(hash('sha256',$library->libraryId().'|'.$period.'|'.$planId),0,32),
            'type'=>'allowance',
            'occurred_at'=>self::now(),
            'source'=>'plan-monthly-allowance',
            'plan_id'=>$planId,
            'allowance_period'=>$period,
            'target_credit_units'=>$target,
            'granted_credit_units'=>$grant,
            'balance_delta_units'=>$grant,
            'reserved_delta_units'=>0,
        ];
        $this->appendEvent($library,$event);

        return [
            'period'=>$period,'plan_id'=>$planId,'target_credit_units'=>$target,
            'granted_credit_units'=>$grant,'applied'=>true,
        ];
    }

    private function findMonthlyAllowanceEvent(Library $library,string $period,string $planId): ?array
    {
        $prefix='memory://billing/ledger/'.str_replace('-','/',$period).'/';
        foreach($this->ledgerRefs($library) as $ref){
            if(!str_starts_with($ref,$prefix)) continue;
            $content=$library->read($ref)['payload']['content']??null;
            if(!is_array($content)) continue;
            foreach(($content['events']??[]) as $event){
                if(!is_array($event)||($event['type']??null)!=='allowance') continue;
                if(($event['allowance_period']??null)===$period&&($event['plan_id']??null)===$planId) return $event;
            }
        }
        return null;
    }

    private function quotaSnapshot(Library $library,array $plan,array $allowance): array
    {
        $period=gmdate('Y-m');
        $month=$this->monthlyUsage($library,$period);
        $today=$this->dailyUsage($library,gmdate('Y-m-d'));
        $dailyReservations=0;
        foreach(($today['events']??[]) as $event){
            if(is_array($event)&&($event['type']??null)==='reservation') $dailyReservations++;
        }

        $next=strtotime($period.'-01 00:00:00 UTC +1 month');
        return [
            'period'=>$period,
            'daily_requests_used'=>$dailyReservations,
            'daily_requests_limit'=>(int)($plan['daily_request_limit']??0),
            'monthly_tokens_used'=>(int)($month['summary']['total_tokens']??0),
            'monthly_tokens_limit'=>(int)($plan['monthly_token_limit']??0),
            'monthly_credit_allowance'=>(int)($plan['monthly_credit_allowance']??0),
            'monthly_allowance_granted'=>(int)($allowance['granted_credit_units']??0),
            'next_reset_at'=>$next===false?null:gmdate('Y-m-d\TH:i:s\Z',$next),
        ];
    }

    private function appendEvent(Library $library,array $event): array
    {
        $date=substr((string)$event['occurred_at'],0,10);
        $ref=self::ledgerRef($date);

        for($attempt=0;$attempt<5;$attempt++){
            try{
                if(!$this->hasRef($library,$ref)){
                    $prior=$this->state($library);
                    $opening=[
                        'balance_units'=>(int)($prior['balance_units']??0),
                        'reserved_units'=>(int)($prior['reserved_units']??0),
                        'active_reservations'=>is_array($prior['active_reservations']??null)?$prior['active_reservations']:[],
                    ];
                    try{
                        $library->writeAs('owner',$ref,[
                            'ledger_version'=>'1.0',
                            'date'=>$date,
                            'opening_state'=>$opening,
                            'events'=>[],
                            'closing_state'=>$opening,
                            'summary'=>self::emptyUsageSummary(),
                        ],'json','warm','00-system','system','confirmed');
                    }catch(RuntimeException $e){
                        if(!str_contains(strtolower($e->getMessage()),'already exists')) throw $e;
                    }
                }

                $library->mutateJson($ref,function(mixed $current)use($event):array{
                    if(!is_array($current)||array_is_list($current)||($current['ledger_version']??null)!=='1.0') throw new RuntimeException('Malformed billing ledger');
                    $events=$current['events']??[];
                    if(!is_array($events)) throw new RuntimeException('Malformed billing events');
                    foreach($events as $existing){
                        if(!is_array($existing)) continue;
                        if(($existing['event_id']??null)===$event['event_id']) return $current;
                        if(($event['type']??null)==='payment'
                            && ($existing['type']??null)==='payment'
                            && isset($event['payment_fingerprint'])
                            && ($existing['payment_fingerprint']??null)===$event['payment_fingerprint']){
                            throw new BillingException('Payment is already recorded','duplicate_payment',409);
                        }
                    }

                    $state=$current['closing_state']??[];
                    if(!is_array($state)) throw new RuntimeException('Malformed billing closing state');
                    $state['balance_units']=(int)($state['balance_units']??0)+(int)($event['balance_delta_units']??0);
                    $state['reserved_units']=(int)($state['reserved_units']??0)+(int)($event['reserved_delta_units']??0);
                    if($state['reserved_units']<0) throw new RuntimeException('Billing reserved balance cannot be negative');
                    $active=is_array($state['active_reservations']??null)?$state['active_reservations']:[];
                    if(($event['type']??null)==='reservation'){
                        $active[(string)$event['reservation_id']]=[
                            'request_id'=>(string)$event['request_id'],
                            'units'=>(int)$event['reserved_credit_units'],
                            'occurred_at'=>(string)$event['occurred_at'],
                        ];
                    }elseif(in_array($event['type']??null,['usage','release'],true)){
                        unset($active[(string)($event['reservation_id']??'')]);
                    }
                    $state['active_reservations']=$active;
                    $events[]=$event;
                    $current['events']=$events;
                    $current['closing_state']=$state;
                    $summary=is_array($current['summary']??null)?$current['summary']:self::emptyUsageSummary();
                    if(($event['type']??null)==='usage'){
                        $u=$event['usage_summary']??[];
                        foreach(['input_tokens','output_tokens','cached_tokens','embedding_tokens','total_tokens','model_calls','duration_ms'] as $field){
                            $summary[$field]=(int)($summary[$field]??0)+(int)($u[$field]??0);
                        }
                        $summary['requests']=(int)($summary['requests']??0)+1;
                        $summary['credit_units_charged']=(int)($summary['credit_units_charged']??0)+(int)($event['credit_units_charged']??0);
                        $summary['cost_micros']=(int)($summary['cost_micros']??0)+(int)($event['cost_micros']??0);
                        $currency=(string)($event['currency']??'USD');
                        $byCurrency=is_array($summary['cost_micros_by_currency']??null)?$summary['cost_micros_by_currency']:[];
                        $byCurrency[$currency]=(int)($byCurrency[$currency]??0)+(int)($event['cost_micros']??0);
                        ksort($byCurrency,SORT_STRING);
                        $summary['cost_micros_by_currency']=$byCurrency;
                    }
                    $current['summary']=$summary;
                    return $current;
                },'owner');
                return $this->dailyUsage($library,$date);
            }catch(RuntimeException $e){
                $message=strtolower($e->getMessage());
                if($attempt===4||(!str_contains($message,'version conflict')&&!str_contains($message,'already exists'))) throw $e;
            }
        }
        throw new RuntimeException('Unable to append billing event');
    }

    private function todayEvents(Library $library): array
    {
        return $this->dailyUsage($library,gmdate('Y-m-d'))['events']??[];
    }

    private function ledgerRefs(Library $library): array
    {
        $refs=[];
        foreach($library->list() as $entry) foreach($entry['logical_refs']??[] as $ref){
            if(is_string($ref)&&preg_match('#^memory://billing/ledger/\d{4}/\d{2}/\d{2}$#',$ref)) $refs[]=$ref;
        }
        sort($refs,SORT_STRING);
        return array_values(array_unique($refs));
    }

    private function hasRef(Library $library,string $ref): bool
    {
        foreach($library->list() as $entry) if(in_array($ref,$entry['logical_refs']??[],true)) return true;
        return false;
    }

    private function providerAllowed(string $providerId,array $allowed): bool
    {
        if(in_array('*',$allowed,true)||in_array($providerId,$allowed,true)) return true;
        foreach($allowed as $pattern){
            if(is_string($pattern)&&str_ends_with($pattern,'*')&&str_starts_with($providerId,substr($pattern,0,-1))) return true;
        }
        return false;
    }

    private static function ledgerRef(string $date): string
    {
        [$y,$m,$d]=explode('-',$date);
        return 'memory://billing/ledger/'.$y.'/'.$m.'/'.$d;
    }

    private static function emptyUsageSummary(): array
    {
        return [
            'requests'=>0,'input_tokens'=>0,'output_tokens'=>0,'cached_tokens'=>0,
            'embedding_tokens'=>0,'total_tokens'=>0,'model_calls'=>0,'duration_ms'=>0,
            'credit_units_charged'=>0,'cost_micros'=>0,'cost_micros_by_currency'=>[],
        ];
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
