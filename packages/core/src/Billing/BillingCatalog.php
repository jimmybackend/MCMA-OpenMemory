<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use MCMA\Core\Library;
use MCMA\Core\Storage\PrefixStorageAdapter;
use MCMA\Core\Storage\StorageAdapter;
use RuntimeException;

final class BillingCatalog
{
    private const PREFIX = 'system/billing';
    private const PLANS_REF = 'memory://billing/plans';
    private const PRICING_REF = 'memory://billing/pricing';

    public function __construct(private readonly StorageAdapter $rootStorage)
    {
    }

    public function bootstrap(): array
    {
        $library = $this->library();
        return [
            'library_id'=>$library->libraryId(),
            'plans'=>$this->plans(),
            'pricing'=>$this->pricing(),
        ];
    }

    public function plans(): array
    {
        $content = $this->read(self::PLANS_REF);
        $plans = $content['plans'] ?? null;
        if (!is_array($plans) || ($plans !== [] && array_is_list($plans))) throw new RuntimeException('Malformed billing plans');
        return $plans;
    }

    public function plan(string $planId): array
    {
        $plans = $this->plans();
        $plan = $plans[$planId] ?? null;
        if (!is_array($plan)) throw new BillingException('Unknown billing plan: '.$planId, 'unknown_plan', 403);
        return $plan;
    }

    public function setPlan(string $planId, array $plan): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $planId)) throw new RuntimeException('Invalid plan id');
        $normalized = self::validatePlan($planId, $plan);

        $this->mutate(self::PLANS_REF, function(array $content) use ($planId, $normalized): array {
            $plans = $content['plans'] ?? [];
            if (!is_array($plans) || ($plans !== [] && array_is_list($plans))) throw new RuntimeException('Malformed billing plans');
            $plans[$planId] = $normalized;
            ksort($plans,SORT_STRING);
            $content['plans'] = $plans;
            $content['updated_at'] = self::now();
            return $content;
        });

        return $normalized;
    }

    public function pricing(): array
    {
        return $this->read(self::PRICING_REF);
    }

    public function setPricing(string $providerId, array $rates): array
    {
        $providerId = trim($providerId);
        if ($providerId === '' || strlen($providerId) > 512) throw new RuntimeException('Invalid provider id');

        $entry = [
            'provider_id'=>$providerId,
            'currency'=>strtoupper((string)($rates['currency'] ?? 'USD')),
            'input_cost_micros_per_1m'=>self::nonNegativeInt($rates,'input_cost_micros_per_1m'),
            'output_cost_micros_per_1m'=>self::nonNegativeInt($rates,'output_cost_micros_per_1m'),
            'cached_cost_micros_per_1m'=>self::nonNegativeInt($rates,'cached_cost_micros_per_1m'),
            'embedding_cost_micros_per_1m'=>self::nonNegativeInt($rates,'embedding_cost_micros_per_1m'),
            'input_credit_units_per_1m'=>self::nonNegativeInt($rates,'input_credit_units_per_1m'),
            'output_credit_units_per_1m'=>self::nonNegativeInt($rates,'output_credit_units_per_1m'),
            'cached_credit_units_per_1m'=>self::nonNegativeInt($rates,'cached_credit_units_per_1m'),
            'embedding_credit_units_per_1m'=>self::nonNegativeInt($rates,'embedding_credit_units_per_1m'),
            'version'=>(string)($rates['version'] ?? gmdate('YmdHis')),
            'updated_at'=>self::now(),
        ];
        if (!preg_match('/^[A-Z]{3}$/',$entry['currency'])) throw new RuntimeException('Pricing currency must be ISO-style 3-letter code');
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/',$entry['version'])) throw new RuntimeException('Invalid pricing version');

        $key = hash('sha256',$providerId);
        $this->mutate(self::PRICING_REF, function(array $content) use ($key,$entry): array {
            $prices = $content['prices'] ?? [];
            if (!is_array($prices) || ($prices !== [] && array_is_list($prices))) throw new RuntimeException('Malformed pricing catalog');
            $prices[$key] = $entry;
            ksort($prices,SORT_STRING);
            $content['prices'] = $prices;
            $content['updated_at'] = self::now();
            return $content;
        });
        return $entry;
    }

    public function price(string $providerId): array
    {
        $catalog = $this->pricing();
        $entry = $catalog['prices'][hash('sha256',$providerId)] ?? null;
        if (!is_array($entry) || ($entry['provider_id'] ?? null) !== $providerId) {
            throw new BillingException('Pricing is not configured for provider: '.$providerId, 'pricing_missing', 503);
        }
        return $entry;
    }

    public function calculate(array $components): array
    {
        $totalCredits = 0;
        $totalCostMicros = 0;
        $snapshots = [];
        $currency = null;

        foreach ($components as $component) {
            if (!is_array($component)) continue;
            $providerId = (string)($component['provider_id'] ?? '');
            $tokens = (int)($component['input_tokens'] ?? 0)
                + (int)($component['output_tokens'] ?? 0)
                + (int)($component['embedding_tokens'] ?? 0);
            if ($tokens <= 0) continue;

            $price = $this->price($providerId);
            if ($currency === null) $currency = $price['currency'];
            if ($currency !== $price['currency']) throw new BillingException('Mixed billing currencies are not supported in one request', 'mixed_currency', 500);

            $cached = max(0,(int)($component['cached_tokens'] ?? 0));
            $input = max(0,(int)($component['input_tokens'] ?? 0) - $cached);
            $output = max(0,(int)($component['output_tokens'] ?? 0));
            $embedding = max(0,(int)($component['embedding_tokens'] ?? 0));

            $credits =
                self::charge($input,(int)$price['input_credit_units_per_1m'])
                + self::charge($output,(int)$price['output_credit_units_per_1m'])
                + self::charge($cached,(int)$price['cached_credit_units_per_1m'])
                + self::charge($embedding,(int)$price['embedding_credit_units_per_1m']);

            $cost =
                self::charge($input,(int)$price['input_cost_micros_per_1m'])
                + self::charge($output,(int)$price['output_cost_micros_per_1m'])
                + self::charge($cached,(int)$price['cached_cost_micros_per_1m'])
                + self::charge($embedding,(int)$price['embedding_cost_micros_per_1m']);

            $totalCredits += $credits;
            $totalCostMicros += $cost;
            $snapshots[] = [
                'provider_id'=>$providerId,
                'pricing_version'=>$price['version'],
                'currency'=>$price['currency'],
                'rates'=>[
                    'input_cost_micros_per_1m'=>$price['input_cost_micros_per_1m'],
                    'output_cost_micros_per_1m'=>$price['output_cost_micros_per_1m'],
                    'cached_cost_micros_per_1m'=>$price['cached_cost_micros_per_1m'],
                    'embedding_cost_micros_per_1m'=>$price['embedding_cost_micros_per_1m'],
                    'input_credit_units_per_1m'=>$price['input_credit_units_per_1m'],
                    'output_credit_units_per_1m'=>$price['output_credit_units_per_1m'],
                    'cached_credit_units_per_1m'=>$price['cached_credit_units_per_1m'],
                    'embedding_credit_units_per_1m'=>$price['embedding_credit_units_per_1m'],
                ],
                'credits'=>$credits,
                'cost_micros'=>$cost,
            ];
        }

        return [
            'credit_units'=>$totalCredits,
            'cost_micros'=>$totalCostMicros,
            'currency'=>$currency ?? 'USD',
            'pricing_snapshots'=>$snapshots,
        ];
    }

    public function estimate(string $providerId, string $kind, int $inputTokens, int $outputTokens = 0): array
    {
        $component = [
            'provider_id'=>$providerId,
            'input_tokens'=>max(0,$inputTokens),
            'output_tokens'=>max(0,$outputTokens),
            'cached_tokens'=>0,
            'embedding_tokens'=>$kind==='embedding'?max(0,$inputTokens):0,
        ];
        if ($kind === 'embedding') $component['input_tokens'] = 0;
        return $this->calculate([$component]);
    }

    private function library(): Library
    {
        $storage = new PrefixStorageAdapter($this->rootStorage,self::PREFIX);
        if (!$storage->exists('manifest.mcma')) {
            try {
                $library = Library::init($storage,'private');
                $library->initializeAccessControl(null,'owner');
                $library->writeAs('owner',self::PLANS_REF,[
                    'version'=>'1.0',
                    'plans'=>self::defaultPlans(),
                    'updated_at'=>self::now(),
                ],'json','warm','00-system','system','confirmed');
                $library->writeAs('owner',self::PRICING_REF,[
                    'version'=>'1.0',
                    'prices'=>[],
                    'updated_at'=>self::now(),
                ],'json','warm','00-system','system','confirmed');
                return $library;
            } catch (RuntimeException $e) {
                if (!str_contains(strtolower($e->getMessage()),'already') && !str_contains(strtolower($e->getMessage()),'non-empty')) throw $e;
            }
        }

        $library = Library::open($storage);
        if (!$this->hasRef($library,self::PLANS_REF)) {
            $library->writeAs('owner',self::PLANS_REF,['version'=>'1.0','plans'=>self::defaultPlans(),'updated_at'=>self::now()],'json','warm','00-system','system','confirmed');
        }
        if (!$this->hasRef($library,self::PRICING_REF)) {
            $library->writeAs('owner',self::PRICING_REF,['version'=>'1.0','prices'=>[],'updated_at'=>self::now()],'json','warm','00-system','system','confirmed');
        }
        return $library;
    }

    private function read(string $ref): array
    {
        $payload = $this->library()->read($ref)['payload']['content'] ?? null;
        if (!is_array($payload) || array_is_list($payload)) throw new RuntimeException('Malformed billing system object: '.$ref);
        return $payload;
    }

    private function mutate(string $ref, callable $callback): void
    {
        $this->library()->mutateJson($ref,function(mixed $current) use ($callback): array {
            if (!is_array($current) || array_is_list($current)) throw new RuntimeException('Malformed billing object');
            return $callback($current);
        },'owner');
    }

    private function hasRef(Library $library,string $ref): bool
    {
        foreach($library->list() as $entry) if(in_array($ref,$entry['logical_refs']??[],true)) return true;
        return false;
    }

    private static function defaultPlans(): array
    {
        return [
            'free'=>self::validatePlan('free',[
                'api_enabled'=>false,'embedding_enabled'=>true,'requests_per_minute'=>10,
                'daily_request_limit'=>100,'concurrent_requests'=>1,'max_request_credit_units'=>100000,
                'allowed_providers'=>['*'],
            ]),
            'starter'=>self::validatePlan('starter',[
                'api_enabled'=>true,'embedding_enabled'=>true,'requests_per_minute'=>30,
                'daily_request_limit'=>2000,'concurrent_requests'=>2,'max_request_credit_units'=>1000000,
                'allowed_providers'=>['*'],
            ]),
            'pro'=>self::validatePlan('pro',[
                'api_enabled'=>true,'embedding_enabled'=>true,'requests_per_minute'=>120,
                'daily_request_limit'=>20000,'concurrent_requests'=>8,'max_request_credit_units'=>10000000,
                'allowed_providers'=>['*'],
            ]),
            'business'=>self::validatePlan('business',[
                'api_enabled'=>true,'embedding_enabled'=>true,'requests_per_minute'=>600,
                'daily_request_limit'=>100000,'concurrent_requests'=>32,'max_request_credit_units'=>100000000,
                'allowed_providers'=>['*'],
            ]),
        ];
    }

    private static function validatePlan(string $id,array $plan): array
    {
        $providers = $plan['allowed_providers'] ?? ['*'];
        if (!is_array($providers) || $providers===[]) throw new RuntimeException('Plan allowed_providers must be a non-empty array');
        foreach($providers as $provider) if(!is_string($provider)||$provider===''||strlen($provider)>512) throw new RuntimeException('Invalid allowed provider');
        return [
            'id'=>$id,
            'api_enabled'=>(bool)($plan['api_enabled']??false),
            'embedding_enabled'=>(bool)($plan['embedding_enabled']??true),
            'requests_per_minute'=>self::boundedInt($plan,'requests_per_minute',1,100000),
            'daily_request_limit'=>self::boundedInt($plan,'daily_request_limit',1,100000000),
            'concurrent_requests'=>self::boundedInt($plan,'concurrent_requests',1,10000),
            'max_request_credit_units'=>self::boundedInt($plan,'max_request_credit_units',0,PHP_INT_MAX),
            'allowed_providers'=>array_values(array_unique($providers)),
            'updated_at'=>self::now(),
        ];
    }

    private static function nonNegativeInt(array $value,string $field): int
    {
        $n=$value[$field]??0;
        if(!is_int($n)&&!(is_string($n)&&preg_match('/^\d+$/',$n))) throw new RuntimeException($field.' must be a non-negative integer');
        $n=(int)$n;
        if($n<0) throw new RuntimeException($field.' must be a non-negative integer');
        return $n;
    }

    private static function boundedInt(array $value,string $field,int $min,int $max): int
    {
        $n=self::nonNegativeInt($value,$field);
        if($n<$min||$n>$max) throw new RuntimeException($field.' is out of range');
        return $n;
    }

    private static function charge(int $tokens,int $rate): int
    {
        if($tokens<=0||$rate<=0) return 0;
        if($tokens>1_000_000_000||$rate>1_000_000_000_000) throw new RuntimeException('Billing token/rate value is too large');
        return intdiv($tokens*$rate+999_999,1_000_000);
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
}
