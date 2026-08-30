<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;
use MCMA\Core\MultiUser\MultiUserService;
use RuntimeException;

final class StripeCheckoutService
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly StripeWebhookVerifier $verifier,
        private readonly StripePackageCatalog $packages,
        private readonly MultiUserService $users,
        private readonly BillingService $billing,
        private readonly string $publicOrigin
    ){
        if(!preg_match('#^https://[^/]+$#',$this->publicOrigin)) throw new RuntimeException('Stripe public origin must be HTTPS without a path');
    }

    public function packages(): array
    {
        return $this->packages->publicPackages();
    }

    public function createCheckout(string $userId,string $packageId): array
    {
        $library=$this->users->resolveUserIdForService($userId,true);
        $this->billing->ensureAccount($library);
        $package=$this->packages->get($packageId);

        $metadata=[
            'mcma_user_id'=>$userId,
            'mcma_package_id'=>$packageId,
            'mcma_package_fingerprint'=>$package['fingerprint'],
        ];
        $params=[
            'mode'=>$package['billing_mode'],
            'success_url'=>$this->publicOrigin.'/?stripe=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'=>$this->publicOrigin.'/?stripe=cancel',
            'client_reference_id'=>$userId,
            'line_items'=>[[
                'price'=>$package['price_id'],
                'quantity'=>1,
            ]],
            'metadata'=>$metadata,
        ];

        if($package['billing_mode']==='subscription'){
            $params['subscription_data']=['metadata'=>$metadata];
        }else{
            $params['payment_intent_data']=['metadata'=>$metadata];
        }

        $session=$this->client->createCheckoutSession($params);
        return [
            'checkout_session_id'=>$session['id'],
            'url'=>$session['url'],
            'package'=>[
                'id'=>$packageId,
                'label'=>$package['label'],
                'billing_mode'=>$package['billing_mode'],
                'plan_id'=>$package['plan_id'],
                'credit_units'=>$package['credit_units'],
                'currency'=>strtoupper($package['currency']),
                'amount_minor'=>$package['amount_minor'],
                'minor_unit_exponent'=>$package['minor_unit_exponent'],
            ],
        ];
    }

    public function handleWebhook(string $rawBody,string $signatureHeader): array
    {
        $event=$this->verifier->verify($rawBody,$signatureHeader);
        $this->assertEventLiveMode($event);
        $type=(string)($event['type']??'');

        return match($type){
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded'=>$this->handleCheckoutEvent($event),

            'invoice.paid'=>$this->handleInvoicePaid($event),
            'invoice.payment_failed'=>$this->handleInvoicePaymentFailed($event),

            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.paused',
            'customer.subscription.resumed'=>$this->handleSubscriptionEvent($event),

            default=>[
                'accepted'=>true,
                'processed'=>false,
                'event_id'=>$event['id'],
                'event_type'=>$type,
                'reason'=>'event-not-used',
            ],
        };
    }

    private function handleCheckoutEvent(array $event): array
    {
        $session=$event['data']['object']??null;
        if(!is_array($session)||($session['object']??null)!=='checkout.session'){
            throw new BillingException('Stripe webhook does not contain a Checkout Session','stripe_webhook_invalid',400);
        }

        $sessionId=(string)($session['id']??'');
        if(!str_starts_with($sessionId,'cs_')) throw new BillingException('Stripe Checkout Session id is invalid','stripe_webhook_invalid',400);

        [$userId,$packageId,$package]=$this->validateBinding(
            (string)($session['client_reference_id']??''),
            is_array($session['metadata']??null)?$session['metadata']:[],
            null
        );

        $mode=(string)($session['mode']??'');
        if($mode!==$package['billing_mode']){
            throw new BillingException('Stripe Checkout mode does not match package','stripe_checkout_mode_mismatch',400);
        }

        if($mode==='subscription'){
            $subscriptionId=$this->subscriptionIdFromValue($session['subscription']??null);
            if($subscriptionId!==null){
                $subscription=$this->client->retrieveSubscription($subscriptionId);
                [$subUserId,$subPackageId]=$this->validateSubscription($subscription);
                if($subUserId!==$userId||$subPackageId!==$packageId){
                    throw new BillingException('Stripe Checkout and Subscription binding mismatch','stripe_subscription_binding_mismatch',400);
                }
                $library=$this->users->resolveUserIdForService($userId,false);
                $this->storeSubscriptionState($library,$subscription,$packageId);
            }
            return [
                'accepted'=>true,
                'processed'=>true,
                'event_id'=>$event['id'],
                'checkout_session_id'=>$sessionId,
                'user_id'=>$userId,
                'package_id'=>$packageId,
                'billing_mode'=>'subscription',
                'credits_granted'=>0,
                'reason'=>'subscription-credits-are-granted-by-invoice-paid',
            ];
        }

        if(($session['payment_status']??null)!=='paid'){
            return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'payment-not-paid'];
        }

        $amount=(int)($session['amount_total']??-1);
        $currency=strtolower((string)($session['currency']??''));
        if($amount!==$package['amount_minor']||$currency!==$package['currency']){
            throw new BillingException('Stripe Checkout amount/currency does not match package','stripe_checkout_amount_mismatch',400);
        }

        $library=$this->users->resolveUserIdForService($userId,false);
        $recorded=$this->recordStripePayment(
            $library,
            $sessionId,
            $package,
            ['checkout_session_id'=>$sessionId,'billing_mode'=>'payment']
        );

        if($package['plan_id']!==''){
            $this->billing->setPlan($library,$package['plan_id']);
            $this->billing->setServiceStatus($library,'active');
        }

        return [
            'accepted'=>true,
            'processed'=>true,
            'already_recorded'=>!$recorded,
            'event_id'=>$event['id'],
            'checkout_session_id'=>$sessionId,
            'user_id'=>$userId,
            'package_id'=>$packageId,
            'billing_mode'=>'payment',
            'credit_units'=>$package['credit_units'],
            'plan_id'=>$package['plan_id'],
        ];
    }

    private function handleInvoicePaid(array $event): array
    {
        $invoice=$this->invoiceObject($event);
        $invoiceId=(string)($invoice['id']??'');
        if(!str_starts_with($invoiceId,'in_')) throw new BillingException('Stripe invoice id is invalid','stripe_invoice_invalid',400);
        if(($invoice['status']??null)!=='paid'&&($invoice['paid']??null)!==true){
            return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'invoice-not-paid'];
        }

        $subscriptionId=$this->subscriptionIdFromInvoice($invoice);
        if($subscriptionId===null){
            return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'invoice-not-for-subscription'];
        }

        $subscription=$this->client->retrieveSubscription($subscriptionId);
        [$userId,$packageId,$package]=$this->validateSubscription($subscription);
        if($package['billing_mode']!=='subscription'){
            throw new BillingException('Stripe subscription is bound to a non-subscription package','stripe_subscription_package_mismatch',400);
        }

        $status=(string)($subscription['status']??'');
        if($status!=='active'){
            $this->syncSubscriptionLifecycle($subscription,$package,$userId,$packageId,false);
            return [
                'accepted'=>true,'processed'=>false,'event_id'=>$event['id'],
                'reason'=>'subscription-not-active','subscription_status'=>$status,
            ];
        }

        $amount=(int)($invoice['amount_paid']??-1);
        $currency=strtolower((string)($invoice['currency']??''));
        if($amount!==$package['amount_minor']||$currency!==$package['currency']){
            throw new BillingException('Stripe recurring invoice amount/currency does not match package','stripe_invoice_amount_mismatch',409);
        }

        $library=$this->users->resolveUserIdForService($userId,false);
        $currentSubscription=$this->currentSubscriptionId($library);
        if($currentSubscription!==null&&$currentSubscription!==$subscriptionId){
            return [
                'accepted'=>true,'processed'=>false,'event_id'=>$event['id'],
                'reason'=>'stale-subscription-invoice','subscription_id'=>$subscriptionId,
            ];
        }

        $recorded=$this->recordStripePayment(
            $library,
            $invoiceId,
            $package,
            [
                'subscription_id'=>$subscriptionId,
                'invoice_id'=>$invoiceId,
                'billing_reason'=>(string)($invoice['billing_reason']??''),
                'billing_mode'=>'subscription',
            ]
        );

        if($package['plan_id']!=='') $this->billing->setPlan($library,$package['plan_id']);
        $this->billing->setServiceStatus($library,'active');
        $this->storeSubscriptionState($library,$subscription,$packageId);

        return [
            'accepted'=>true,
            'processed'=>true,
            'already_recorded'=>!$recorded,
            'event_id'=>$event['id'],
            'invoice_id'=>$invoiceId,
            'subscription_id'=>$subscriptionId,
            'user_id'=>$userId,
            'package_id'=>$packageId,
            'credit_units'=>$package['credit_units'],
            'plan_id'=>$package['plan_id'],
            'billing_reason'=>(string)($invoice['billing_reason']??''),
        ];
    }

    private function handleInvoicePaymentFailed(array $event): array
    {
        $invoice=$this->invoiceObject($event);
        $subscriptionId=$this->subscriptionIdFromInvoice($invoice);
        if($subscriptionId===null){
            return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'invoice-not-for-subscription'];
        }

        $subscription=$this->client->retrieveSubscription($subscriptionId);
        [$userId,$packageId,$package]=$this->validateSubscription($subscription);
        $library=$this->users->resolveUserIdForService($userId,false);

        $currentSubscription=$this->currentSubscriptionId($library);
        if($currentSubscription===null||$currentSubscription===$subscriptionId){
            $this->storeSubscriptionState($library,$subscription,$packageId);
        }

        return [
            'accepted'=>true,
            'processed'=>true,
            'event_id'=>$event['id'],
            'subscription_id'=>$subscriptionId,
            'user_id'=>$userId,
            'package_id'=>$packageId,
            'subscription_status'=>(string)($subscription['status']??''),
            'service_changed'=>false,
            'reason'=>'payment-failed-awaiting-stripe-retries',
        ];
    }

    private function handleSubscriptionEvent(array $event): array
    {
        $subscription=$event['data']['object']??null;
        if(!is_array($subscription)||($subscription['object']??null)!=='subscription'){
            throw new BillingException('Stripe webhook does not contain a Subscription','stripe_webhook_invalid',400);
        }

        [$userId,$packageId,$package]=$this->validateSubscription($subscription);
        $subscriptionId=(string)$subscription['id'];
        $library=$this->users->resolveUserIdForService($userId,false);
        $current=$this->currentSubscriptionId($library);

        if($current!==null&&$current!==$subscriptionId){
            return [
                'accepted'=>true,'processed'=>false,'event_id'=>$event['id'],
                'reason'=>'stale-subscription-event','subscription_id'=>$subscriptionId,
            ];
        }

        $this->syncSubscriptionLifecycle(
            $subscription,
            $package,
            $userId,
            $packageId,
            (string)($event['type']??'')==='customer.subscription.deleted'
        );

        return [
            'accepted'=>true,
            'processed'=>true,
            'event_id'=>$event['id'],
            'subscription_id'=>$subscriptionId,
            'user_id'=>$userId,
            'package_id'=>$packageId,
            'subscription_status'=>(string)($subscription['status']??''),
            'plan_id'=>$this->billing->account($library)['plan_id']??'',
        ];
    }

    private function syncSubscriptionLifecycle(
        array $subscription,
        array $package,
        string $userId,
        string $packageId,
        bool $forceEnded
    ): void {
        $library=$this->users->resolveUserIdForService($userId,false);
        $subscriptionId=(string)($subscription['id']??'');
        $status=(string)($subscription['status']??'');

        if($this->currentSubscriptionId($library)!==null&&!$this->isCurrentSubscription($library,$subscriptionId)){
            return;
        }

        $this->storeSubscriptionState($library,$subscription,$packageId);

        if(in_array($status,['active','trialing'],true)&&!$forceEnded){
            // Economic entitlement is granted by invoice.paid. Lifecycle events
            // only persist state so they cannot double-activate or double-credit.
            return;
        }

        if($forceEnded||in_array($status,['canceled','unpaid','paused','incomplete_expired'],true)){
            $account=$this->billing->account($library);
            if($package['plan_id']!==''&&($account['plan_id']??null)===$package['plan_id']){
                $this->billing->setPlan($library,'free');
            }
            $this->billing->setServiceStatus($library,'active');
        }
    }

    private function validateSubscription(array $subscription): array
    {
        if(($subscription['object']??null)!=='subscription'){
            throw new BillingException('Stripe subscription object is invalid','stripe_subscription_invalid',400);
        }
        if(isset($subscription['livemode'])&&is_bool($subscription['livemode'])&&$subscription['livemode']!==$this->client->expectedLiveMode()){
            throw new BillingException('Stripe subscription livemode mismatch','stripe_livemode_mismatch',400);
        }

        $metadata=$subscription['metadata']??[];
        if(!is_array($metadata)) throw new BillingException('Stripe subscription metadata is invalid','stripe_subscription_metadata_invalid',400);

        [$userId,$packageId,$package]=$this->validateBinding('', $metadata, $subscription);
        if($package['billing_mode']!=='subscription'){
            throw new BillingException('Stripe subscription package is not recurring','stripe_subscription_package_mismatch',400);
        }

        $items=$subscription['items']['data']??null;
        if(!is_array($items)||count($items)!==1||!is_array($items[0]??null)){
            throw new BillingException('Stripe subscription must contain exactly one configured item','stripe_subscription_items_invalid',409);
        }
        $price=$items[0]['price']??null;
        $priceId=is_array($price)?(string)($price['id']??''):(string)$price;
        $quantity=(int)($items[0]['quantity']??1);
        if($priceId!==$package['price_id']||$quantity!==1){
            throw new BillingException('Stripe subscription item does not match configured package','stripe_subscription_price_mismatch',409);
        }

        return [$userId,$packageId,$package];
    }

    private function validateBinding(string $clientReferenceId,array $metadata,?array $subscription): array
    {
        $metadataUser=(string)($metadata['mcma_user_id']??'');
        $userId=$clientReferenceId!==''?$clientReferenceId:$metadataUser;
        $packageId=(string)($metadata['mcma_package_id']??'');
        $packageFingerprint=(string)($metadata['mcma_package_fingerprint']??'');

        if(!preg_match('/^usr_[0-9a-f]{64}$/',$userId)||!hash_equals($userId,$metadataUser)){
            throw new BillingException('Stripe user binding is invalid','stripe_checkout_user_mismatch',400);
        }

        $package=$this->packages->get($packageId);
        if($packageFingerprint===''||!hash_equals((string)$package['fingerprint'],$packageFingerprint)){
            throw new BillingException('Stripe package configuration changed','stripe_package_fingerprint_mismatch',409);
        }

        return [$userId,$packageId,$package];
    }

    private function recordStripePayment(Library $library,string $providerPaymentId,array $package,array $metadata): bool
    {
        try{
            $provider=new RecordedPaymentProvider('stripe');
            $this->billing->recordPayment($library,$provider,[
                'provider_payment_id'=>$providerPaymentId,
                'amount_micros'=>$package['amount_micros'],
                'currency'=>strtoupper($package['currency']),
                'credit_units'=>$package['credit_units'],
            ] + ['metadata'=>$metadata]);
            return true;
        }catch(BillingException $e){
            if($e->reason()!=='duplicate_payment') throw $e;
            return false;
        }
    }

    private function storeSubscriptionState(Library $library,array $subscription,string $packageId): void
    {
        $this->billing->setStripeSubscriptionState(
            $library,
            (string)$subscription['id'],
            $packageId,
            (string)($subscription['status']??''),
            $this->subscriptionPeriodEnd($subscription),
            (bool)($subscription['cancel_at_period_end']??false)
        );
    }

    private function currentSubscriptionId(Library $library): ?string
    {
        $account=$this->billing->account($library);
        $id=$account['stripe_subscription']['subscription_id']??null;
        return is_string($id)&&$id!==''?$id:null;
    }

    private function isCurrentSubscription(Library $library,string $subscriptionId): bool
    {
        return $this->currentSubscriptionId($library)===$subscriptionId;
    }

    private function subscriptionPeriodEnd(array $subscription): ?int
    {
        $direct=$subscription['current_period_end']??null;
        if(is_int($direct)&&$direct>=0) return $direct;

        $max=null;
        foreach(($subscription['items']['data']??[]) as $item){
            if(!is_array($item)) continue;
            $value=$item['current_period_end']??null;
            if(is_int($value)&&$value>=0) $max=$max===null?$value:max($max,$value);
        }
        return $max;
    }

    private function invoiceObject(array $event): array
    {
        $invoice=$event['data']['object']??null;
        if(!is_array($invoice)||($invoice['object']??null)!=='invoice'){
            throw new BillingException('Stripe webhook does not contain an Invoice','stripe_webhook_invalid',400);
        }
        return $invoice;
    }

    private function subscriptionIdFromInvoice(array $invoice): ?string
    {
        $parent=$invoice['parent']??null;
        if(is_array($parent)&&($parent['type']??null)==='subscription_details'){
            $value=$parent['subscription_details']['subscription']??null;
            $id=$this->subscriptionIdFromValue($value);
            if($id!==null) return $id;
        }

        return $this->subscriptionIdFromValue($invoice['subscription']??null);
    }

    private function subscriptionIdFromValue(mixed $value): ?string
    {
        if(is_string($value)&&preg_match('/^sub_[A-Za-z0-9_]+$/',$value)) return $value;
        if(is_array($value)&&is_string($value['id']??null)&&preg_match('/^sub_[A-Za-z0-9_]+$/',$value['id'])) return $value['id'];
        return null;
    }

    private function assertEventLiveMode(array $event): void
    {
        if(isset($event['livemode'])&&is_bool($event['livemode'])&&$event['livemode']!==$this->client->expectedLiveMode()){
            throw new BillingException('Stripe webhook livemode does not match configured API key','stripe_livemode_mismatch',400);
        }
    }
}
