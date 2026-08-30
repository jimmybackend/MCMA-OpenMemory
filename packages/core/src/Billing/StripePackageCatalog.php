<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use JsonException;
use RuntimeException;

final class StripePackageCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $packages;

    public function __construct(array $packages)
    {
        $normalized=[];
        foreach($packages as $id=>$package){
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/',$id)){
                throw new RuntimeException('Invalid Stripe package id');
            }
            if(!is_array($package)||array_is_list($package)){
                throw new RuntimeException('Stripe package must be an object: '.$id);
            }
            $normalized[$id]=$this->normalize($id,$package);
        }
        if($normalized===[]) throw new RuntimeException('At least one Stripe package is required');
        ksort($normalized,SORT_STRING);
        $this->packages=$normalized;
    }

    public static function fromJson(string $json): self
    {
        try{$value=json_decode($json,true,64,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new RuntimeException('MCMA_STRIPE_PACKAGES_JSON is invalid JSON',0,$e);}
        if(!is_array($value)||array_is_list($value)) throw new RuntimeException('MCMA_STRIPE_PACKAGES_JSON must be a JSON object');
        return new self($value);
    }

    public function get(string $id): array
    {
        $package=$this->packages[$id]??null;
        if(!is_array($package)) throw new BillingException('Unknown Stripe package','stripe_package_unknown',404);
        return $package;
    }

    public function publicPackages(): array
    {
        $out=[];
        foreach($this->packages as $id=>$package){
            $out[]=[
                'id'=>$id,
                'label'=>$package['label'],
                'billing_mode'=>$package['billing_mode'],
                'plan_id'=>$package['plan_id'],
                'credit_units'=>$package['credit_units'],
                'currency'=>strtoupper($package['currency']),
                'amount_minor'=>$package['amount_minor'],
                'minor_unit_exponent'=>$package['minor_unit_exponent'],
            ];
        }
        return $out;
    }

    private function normalize(string $id,array $package): array
    {
        $priceId=trim((string)($package['price_id']??''));
        if(!preg_match('/^price_[A-Za-z0-9]+$/',$priceId)) throw new RuntimeException('Invalid Stripe price_id for package '.$id);

        $label=trim((string)($package['label']??$id));
        if($label===''||strlen($label)>128) throw new RuntimeException('Invalid Stripe package label');

        $planId=trim((string)($package['plan_id']??''));
        if($planId!==''&&!preg_match('/^[a-z][a-z0-9-]{1,63}$/',$planId)) throw new RuntimeException('Invalid Stripe package plan_id');

        $billingMode=strtolower(trim((string)($package['billing_mode']??'payment')));
        if(!in_array($billingMode,['payment','subscription'],true)) throw new RuntimeException('Stripe package billing_mode must be payment or subscription');

        $credits=self::integer($package,'credit_units',0,PHP_INT_MAX);
        if($credits<1&&$planId==='') throw new RuntimeException('Stripe package must grant credits and/or a plan');

        $currency=strtolower(trim((string)($package['currency']??'')));
        if(!preg_match('/^[a-z]{3}$/',$currency)) throw new RuntimeException('Invalid Stripe package currency');

        $amountMinor=self::integer($package,'amount_minor',0,PHP_INT_MAX);
        $minorExponent=self::integer($package,'minor_unit_exponent',0,3);

        $factor=10 ** (6-$minorExponent);
        if($amountMinor>intdiv(PHP_INT_MAX,$factor)) throw new RuntimeException('Stripe package amount is too large');

        $fingerprint=hash('sha256',implode('|',[
            $id,$billingMode,$priceId,$planId,(string)$credits,$currency,(string)$amountMinor,(string)$minorExponent
        ]));

        return [
            'id'=>$id,
            'label'=>$label,
            'billing_mode'=>$billingMode,
            'price_id'=>$priceId,
            'plan_id'=>$planId,
            'credit_units'=>$credits,
            'currency'=>$currency,
            'amount_minor'=>$amountMinor,
            'minor_unit_exponent'=>$minorExponent,
            'amount_micros'=>$amountMinor*$factor,
            'fingerprint'=>$fingerprint,
        ];
    }

    private static function integer(array $value,string $field,int $min,int $max): int
    {
        $raw=$value[$field]??null;
        if(!is_int($raw)&&!(is_string($raw)&&preg_match('/^\d+$/',$raw))) throw new RuntimeException($field.' must be an integer');
        $n=(int)$raw;
        if($n<$min||$n>$max) throw new RuntimeException($field.' is out of range');
        return $n;
    }
}
