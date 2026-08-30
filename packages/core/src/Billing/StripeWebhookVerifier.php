<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

use JsonException;
use RuntimeException;

final class StripeWebhookVerifier
{
    /** @var callable */
    private $clock;

    public function __construct(
        private readonly string $endpointSecret,
        private readonly int $toleranceSeconds=300,
        ?callable $clock=null
    ){
        if(!str_starts_with($this->endpointSecret,'whsec_')||strlen($this->endpointSecret)<16){
            throw new RuntimeException('Invalid Stripe webhook endpoint secret');
        }
        if($this->toleranceSeconds<30||$this->toleranceSeconds>3600) throw new RuntimeException('Invalid Stripe webhook tolerance');
        $this->clock=$clock??static fn():int=>time();
    }

    public function verify(string $rawBody,string $signatureHeader): array
    {
        if($rawBody===''||strlen($rawBody)>1_048_576) throw new BillingException('Invalid Stripe webhook body','stripe_webhook_invalid',400);

        $timestamp=null;$signatures=[];
        foreach(explode(',',$signatureHeader) as $part){
            $pair=explode('=',trim($part),2);
            if(count($pair)!==2) continue;
            if($pair[0]==='t'&&preg_match('/^\d+$/',$pair[1])) $timestamp=(int)$pair[1];
            if($pair[0]==='v1'&&preg_match('/^[0-9a-f]{64}$/i',$pair[1])) $signatures[]=strtolower($pair[1]);
        }
        if($timestamp===null||$signatures===[]) throw new BillingException('Stripe webhook signature is malformed','stripe_webhook_signature_invalid',400);

        $now=(int)($this->clock)();
        if(abs($now-$timestamp)>$this->toleranceSeconds){
            throw new BillingException('Stripe webhook timestamp is outside tolerance','stripe_webhook_timestamp_invalid',400);
        }

        $expected=hash_hmac('sha256',$timestamp.'.'.$rawBody,$this->endpointSecret);
        $valid=false;
        foreach($signatures as $signature){
            if(hash_equals($expected,$signature)){$valid=true;break;}
        }
        if(!$valid) throw new BillingException('Stripe webhook signature verification failed','stripe_webhook_signature_invalid',400);

        try{$event=json_decode($rawBody,true,64,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new BillingException('Stripe webhook JSON is invalid','stripe_webhook_invalid',400);}
        if(!is_array($event)||array_is_list($event)||!is_string($event['id']??null)||!str_starts_with($event['id'],'evt_')){
            throw new BillingException('Stripe webhook event is invalid','stripe_webhook_invalid',400);
        }
        return $event;
    }
}
