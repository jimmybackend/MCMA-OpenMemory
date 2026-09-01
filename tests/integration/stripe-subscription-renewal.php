<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Billing\StripeCheckoutService;
use MCMA\Core\Billing\StripeClient;
use MCMA\Core\Billing\StripePackageCatalog;
use MCMA\Core\Billing\StripeWebhookVerifier;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

function assert_sub(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}
function rr_sub(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_sub($path); else @unlink($path);
    }
    @rmdir($dir);
}
function stripe_sub_signature(int $timestamp,string $body,string $secret): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$body,$secret);
}

$base=sys_get_temp_dir().'/mcma-stripe-sub-'.bin2hex(random_bytes(5));
try{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR='.$base.'/keys');

    $root=new LocalFilesystemAdapter($base.'/storage');
    $users=new MultiUserService($root,'0123456789abcdef0123456789abcdef0123456789abcdef');
    $user=$users->register('https://id.example.test','subscriber-user');
    $library=$users->resolveUserIdForService($user['user_id']);

    $billing=new BillingService(new BillingCatalog($root));
    $billing->ensureAccount($library);

    $packages=new StripePackageCatalog([
        'pro-monthly-2026'=>[
            'label'=>'Pro Monthly',
            'billing_mode'=>'subscription',
            'price_id'=>'price_recurringPro123',
            'plan_id'=>'pro',
            'credit_units'=>1000,
            'currency'=>'usd',
            'amount_minor'=>2500,
            'minor_unit_exponent'=>2,
        ],
    ]);
    $package=$packages->get('pro-monthly-2026');
    assert_sub(($package['billing_mode']??null)==='subscription','Subscription package mode mismatch');

    $subscriptionStatus='active';
    $subscriptionId='sub_test_recurring001';
    $periodEnd=1779999999;

    $requester=function(string $method,string $url,array $headers,string $body)use(
        &$subscriptionStatus,$subscriptionId,$periodEnd,$user,$package
    ):array{
        if($method==='POST'&&$url==='https://api.stripe.com/v1/checkout/sessions'){
            parse_str($body,$form);
            assert_sub(($form['mode']??null)==='subscription','Stripe Checkout did not use subscription mode');
            assert_sub(($form['client_reference_id']??null)===$user['user_id'],'Subscription client reference mismatch');
            assert_sub(($form['line_items'][0]['price']??null)===$package['price_id'],'Subscription price mismatch');
            assert_sub(($form['subscription_data']['metadata']['mcma_user_id']??null)===$user['user_id'],'Subscription metadata user missing');
            assert_sub(($form['subscription_data']['metadata']['mcma_package_id']??null)==='pro-monthly-2026','Subscription metadata package missing');
            assert_sub(($form['subscription_data']['metadata']['mcma_package_fingerprint']??null)===$package['fingerprint'],'Subscription fingerprint missing');
            assert_sub(!isset($form['payment_intent_data']),'Subscription Checkout must not send payment_intent_data');
            return [200,json_encode([
                'id'=>'cs_test_subscription001',
                'object'=>'checkout.session',
                'url'=>'https://checkout.stripe.com/c/pay/subscription-test',
            ],JSON_THROW_ON_ERROR),[]];
        }

        if($method==='GET'&&$url==='https://api.stripe.com/v1/subscriptions/'.$subscriptionId){
            return [200,json_encode([
                'id'=>$subscriptionId,
                'object'=>'subscription',
                'livemode'=>false,
                'status'=>$subscriptionStatus,
                'cancel_at_period_end'=>false,
                'metadata'=>[
                    'mcma_user_id'=>$user['user_id'],
                    'mcma_package_id'=>'pro-monthly-2026',
                    'mcma_package_fingerprint'=>$package['fingerprint'],
                ],
                'items'=>[
                    'object'=>'list',
                    'data'=>[[
                        'id'=>'si_test_001',
                        'object'=>'subscription_item',
                        'current_period_end'=>$periodEnd,
                        'quantity'=>1,
                        'price'=>[
                            'id'=>$package['price_id'],
                            'object'=>'price',
                            'currency'=>'usd',
                            'type'=>'recurring',
                            'recurring'=>['interval'=>'month','interval_count'=>1],
                        ],
                    ]],
                ],
            ],JSON_THROW_ON_ERROR),[]];
        }

        throw new RuntimeException('Unexpected Stripe request '.$method.' '.$url);
    };

    $client=new StripeClient('sk_test_subscription123',$requester);
    $secret='whsec_subscription_test_123456789';
    $now=1777500000;
    $stripe=new StripeCheckoutService(
        $client,
        new StripeWebhookVerifier($secret,300,static fn():int=>$now),
        $packages,
        $users,
        $billing,
        'https://memory.example.test'
    );

    $checkout=$stripe->createCheckout($user['user_id'],'pro-monthly-2026');
    assert_sub(($checkout['package']['billing_mode']??null)==='subscription','Checkout response billing mode missing');

    $checkoutEvent=[
        'id'=>'evt_sub_checkout',
        'type'=>'checkout.session.completed',
        'livemode'=>false,
        'data'=>['object'=>[
            'id'=>'cs_test_subscription001',
            'object'=>'checkout.session',
            'mode'=>'subscription',
            'payment_status'=>'paid',
            'subscription'=>$subscriptionId,
            'client_reference_id'=>$user['user_id'],
            'metadata'=>[
                'mcma_user_id'=>$user['user_id'],
                'mcma_package_id'=>'pro-monthly-2026',
                'mcma_package_fingerprint'=>$package['fingerprint'],
            ],
        ]],
    ];
    $checkoutBody=json_encode($checkoutEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $checkoutHandled=$stripe->handleWebhook($checkoutBody,stripe_sub_signature($now,$checkoutBody,$secret));
    assert_sub(($checkoutHandled['credits_granted']??-1)===0,'Subscription checkout incorrectly granted credits');
    assert_sub(($billing->summary($library)['balance_units']??-1)===100000,'Free allowance missing while subscription awaits invoice');
    assert_sub(($billing->account($library)['plan_id']??null)==='free','Subscription checkout activated plan before invoice.paid');
    assert_sub(($billing->account($library)['stripe_subscription']['subscription_id']??null)===$subscriptionId,'Subscription state was not linked');

    $invoice1=[
        'id'=>'in_test_subscription001',
        'object'=>'invoice',
        'status'=>'paid',
        'paid'=>true,
        'amount_paid'=>2500,
        'currency'=>'usd',
        'billing_reason'=>'subscription_create',
        'parent'=>[
            'type'=>'subscription_details',
            'subscription_details'=>['subscription'=>$subscriptionId],
        ],
    ];
    $invoiceEvent1=[
        'id'=>'evt_invoice_paid_001',
        'type'=>'invoice.paid',
        'livemode'=>false,
        'data'=>['object'=>$invoice1],
    ];
    $invoiceBody1=json_encode($invoiceEvent1,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $paid1=$stripe->handleWebhook($invoiceBody1,stripe_sub_signature($now,$invoiceBody1,$secret));
    assert_sub(($paid1['processed']??false)===true,'Initial subscription invoice was not processed');
    assert_sub(($billing->summary($library)['balance_units']??0)===101000,'Initial subscription credits missing');
    assert_sub(($billing->account($library)['plan_id']??null)==='pro','Initial subscription plan not activated');
    assert_sub(($billing->account($library)['stripe_subscription']['status']??null)==='active','Subscription state not active');

    $invoice2=$invoice1;
    $invoice2['id']='in_test_subscription002';
    $invoice2['billing_reason']='subscription_cycle';
    $invoiceEvent2=[
        'id'=>'evt_invoice_paid_002',
        'type'=>'invoice.paid',
        'livemode'=>false,
        'data'=>['object'=>$invoice2],
    ];
    $invoiceBody2=json_encode($invoiceEvent2,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $paid2=$stripe->handleWebhook($invoiceBody2,stripe_sub_signature($now,$invoiceBody2,$secret));
    assert_sub(($paid2['processed']??false)===true,'Recurring invoice was not processed');
    assert_sub(($billing->summary($library)['balance_units']??0)===102000,'Recurring credits were not added');

    $retryEvent=$invoiceEvent2;
    $retryEvent['id']='evt_invoice_paid_002_retry';
    $retryBody=json_encode($retryEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $retry=$stripe->handleWebhook($retryBody,stripe_sub_signature($now,$retryBody,$secret));
    assert_sub(($retry['already_recorded']??false)===true,'Recurring invoice retry was not idempotent');
    assert_sub(($billing->summary($library)['balance_units']??0)===102000,'Recurring invoice retry duplicated credits');

    $subscriptionStatus='past_due';
    $failedInvoice=[
        'id'=>'in_test_subscription_failed',
        'object'=>'invoice',
        'status'=>'open',
        'paid'=>false,
        'amount_paid'=>0,
        'currency'=>'usd',
        'billing_reason'=>'subscription_cycle',
        'parent'=>[
            'type'=>'subscription_details',
            'subscription_details'=>['subscription'=>$subscriptionId],
        ],
    ];
    $failedEvent=[
        'id'=>'evt_invoice_failed',
        'type'=>'invoice.payment_failed',
        'livemode'=>false,
        'data'=>['object'=>$failedInvoice],
    ];
    $failedBody=json_encode($failedEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $failed=$stripe->handleWebhook($failedBody,stripe_sub_signature($now,$failedBody,$secret));
    assert_sub(($failed['service_changed']??true)===false,'Past-due payment failure changed service immediately');
    assert_sub(($billing->account($library)['plan_id']??null)==='pro','Past-due payment failure downgraded plan too early');
    assert_sub(($billing->account($library)['stripe_subscription']['status']??null)==='past_due','Past-due status not recorded');

    $subscriptionStatus='canceled';
    $deletedEvent=[
        'id'=>'evt_subscription_deleted',
        'type'=>'customer.subscription.deleted',
        'livemode'=>false,
        'data'=>['object'=>json_decode($requester('GET','https://api.stripe.com/v1/subscriptions/'.$subscriptionId,[],'')[1],true,64,JSON_THROW_ON_ERROR)],
    ];
    $deletedBody=json_encode($deletedEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $deleted=$stripe->handleWebhook($deletedBody,stripe_sub_signature($now,$deletedBody,$secret));
    assert_sub(($deleted['processed']??false)===true,'Subscription deletion was not processed');
    assert_sub(($billing->account($library)['plan_id']??null)==='free','Canceled subscription did not downgrade to free');
    assert_sub(($billing->account($library)['service_status']??null)==='active','Canceled subscription disabled free service');
    assert_sub(($billing->summary($library)['balance_units']??0)===102000,'Cancellation removed purchased credits');

    echo "MCMA Stripe recurring subscription renewals passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rr_sub($base);
}
