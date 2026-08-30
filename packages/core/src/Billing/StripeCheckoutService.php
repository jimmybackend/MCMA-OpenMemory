<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

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

        $params=[
            'mode'=>'payment',
            'success_url'=>$this->publicOrigin.'/?stripe=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'=>$this->publicOrigin.'/?stripe=cancel',
            'client_reference_id'=>$userId,
            'line_items'=>[[
                'price'=>$package['price_id'],
                'quantity'=>1,
            ]],
            'metadata'=>[
                'mcma_user_id'=>$userId,
                'mcma_package_id'=>$packageId,
                'mcma_package_fingerprint'=>$package['fingerprint'],
            ],
            'payment_intent_data'=>[
                'metadata'=>[
                    'mcma_user_id'=>$userId,
                    'mcma_package_id'=>$packageId,
                    'mcma_package_fingerprint'=>$package['fingerprint'],
                ],
            ],
        ];

        $session=$this->client->createCheckoutSession($params);
        return [
            'checkout_session_id'=>$session['id'],
            'url'=>$session['url'],
            'package'=>[
                'id'=>$packageId,
                'label'=>$package['label'],
                'plan_id'=>$package['plan_id'],
                'credit_units'=>$package['credit_units'],
                'currency'=>strtoupper($package['currency']),
                'amount_minor'=>$package['amount_minor'],
            ],
        ];
    }

    public function handleWebhook(string $rawBody,string $signatureHeader): array
    {
        $event=$this->verifier->verify($rawBody,$signatureHeader);
        $type=(string)($event['type']??'');

        if(!in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)){
            return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'event_type'=>$type,'reason'=>'event-not-used'];
        }

        if(isset($event['livemode'])&&is_bool($event['livemode'])&&$event['livemode']!==$this->client->expectedLiveMode()){
            throw new BillingException('Stripe webhook livemode does not match configured API key','stripe_livemode_mismatch',400);
        }

        $session=$event['data']['object']??null;
        if(!is_array($session)||($session['object']??null)!=='checkout.session'){
            throw new BillingException('Stripe webhook does not contain a Checkout Session','stripe_webhook_invalid',400);
        }

        $sessionId=(string)($session['id']??'');
        if(!str_starts_with($sessionId,'cs_')) throw new BillingException('Stripe Checkout Session id is invalid','stripe_webhook_invalid',400);
        if(($session['mode']??null)!=='payment') return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'unsupported-checkout-mode'];
        if(($session['payment_status']??null)!=='paid') return ['accepted'=>true,'processed'=>false,'event_id'=>$event['id'],'reason'=>'payment-not-paid'];

        $metadata=$session['metadata']??[];
        if(!is_array($metadata)) throw new BillingException('Stripe Checkout metadata is invalid','stripe_checkout_metadata_invalid',400);

        $userId=(string)($session['client_reference_id']??'');
        $metadataUser=(string)($metadata['mcma_user_id']??'');
        $packageId=(string)($metadata['mcma_package_id']??'');
        $packageFingerprint=(string)($metadata['mcma_package_fingerprint']??'');
        if(!preg_match('/^usr_[0-9a-f]{64}$/',$userId)||!hash_equals($userId,$metadataUser)){
            throw new BillingException('Stripe Checkout user binding is invalid','stripe_checkout_user_mismatch',400);
        }

        $package=$this->packages->get($packageId);
        if($packageFingerprint===''||!hash_equals((string)$package['fingerprint'],$packageFingerprint)){
            throw new BillingException('Stripe Checkout package configuration changed','stripe_package_fingerprint_mismatch',409);
        }
        $amount=(int)($session['amount_total']??-1);
        $currency=strtolower((string)($session['currency']??''));
        if($amount!==$package['amount_minor']||$currency!==$package['currency']){
            throw new BillingException('Stripe Checkout amount/currency does not match package','stripe_checkout_amount_mismatch',400);
        }

        $library=$this->users->resolveUserIdForService($userId,false);
        $recorded=false;
        try{
            $provider=new RecordedPaymentProvider('stripe');
            $this->billing->recordPayment($library,$provider,[
                'provider_payment_id'=>$sessionId,
                'amount_micros'=>$package['amount_micros'],
                'currency'=>strtoupper($package['currency']),
                'credit_units'=>$package['credit_units'],
            ]);
            $recorded=true;
        }catch(BillingException $e){
            if($e->reason()!=='duplicate_payment') throw $e;
        }

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
            'credit_units'=>$package['credit_units'],
            'plan_id'=>$package['plan_id'],
        ];
    }
}
