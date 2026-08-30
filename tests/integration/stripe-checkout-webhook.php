<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingException;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Billing\StripeCheckoutService;
use MCMA\Core\Billing\StripeClient;
use MCMA\Core\Billing\StripePackageCatalog;
use MCMA\Core\Billing\StripeWebhookVerifier;
use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\LocalFilesystemAdapter;
use MCMA\Core\Web\EncryptedCookie;
use MCMA\Core\Web\HttpRequest;
use MCMA\Core\Web\OidcClient;
use MCMA\Core\Web\WebApplication;

function assert_stripe(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}
function rr_stripe(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_stripe($path); else @unlink($path);
    }
    @rmdir($dir);
}
function stripe_signature(int $timestamp,string $body,string $secret): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$body,$secret);
}

$base=sys_get_temp_dir().'/mcma-stripe-'.bin2hex(random_bytes(5));
try{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR='.$base.'/keys');

    $root=new LocalFilesystemAdapter($base.'/storage');
    $users=new MultiUserService($root,'0123456789abcdef0123456789abcdef0123456789abcdef');
    $user=$users->register('https://id.example.test','stripe-user');
    $library=$users->resolveUserIdForService($user['user_id']);

    $billing=new BillingService(new BillingCatalog($root));
    $billing->ensureAccount($library);

    $packages=new StripePackageCatalog([
        'starter-pack'=>[
            'label'=>'Starter Pack',
            'price_id'=>'price_testStarter123',
            'plan_id'=>'starter',
            'credit_units'=>250,
            'currency'=>'usd',
            'amount_minor'=>1000,
            'minor_unit_exponent'=>2,
        ],
    ]);

    $packageFingerprint=$packages->get('starter-pack')['fingerprint'];

    $checkoutSeen=false;
    $stripeClient=new StripeClient('sk_test_test123456',function(string $method,string $url,array $headers,string $body)use(&$checkoutSeen,$user,$packageFingerprint):array{
        assert_stripe($method==='POST','Stripe checkout method mismatch');
        assert_stripe($url==='https://api.stripe.com/v1/checkout/sessions','Stripe checkout URL mismatch');
        assert_stripe(($headers['authorization']??null)==='Basic '.base64_encode('sk_test_test123456:'),'Stripe Basic Auth mismatch');
        parse_str($body,$form);
        assert_stripe(($form['mode']??null)==='payment','Stripe checkout mode mismatch');
        assert_stripe(($form['client_reference_id']??null)===$user['user_id'],'Stripe client_reference_id mismatch');
        assert_stripe(($form['line_items'][0]['price']??null)==='price_testStarter123','Stripe price mismatch');
        assert_stripe(($form['metadata']['mcma_user_id']??null)===$user['user_id'],'Stripe user metadata mismatch');
        assert_stripe(($form['metadata']['mcma_package_id']??null)==='starter-pack','Stripe package metadata mismatch');
        assert_stripe(($form['metadata']['mcma_package_fingerprint']??null)===$packageFingerprint,'Stripe package fingerprint missing');
        $checkoutSeen=true;
        return [200,json_encode([
            'id'=>'cs_test_checkout001',
            'object'=>'checkout.session',
            'url'=>'https://checkout.stripe.com/c/pay/test-session',
        ],JSON_THROW_ON_ERROR),[]];
    });

    $secret='whsec_test_webhook_123456789';
    $now=1777500000;
    $stripe=new StripeCheckoutService(
        $stripeClient,
        new StripeWebhookVerifier($secret,300,static fn():int=>$now),
        $packages,
        $users,
        $billing,
        'https://memory.example.test'
    );

    $created=$stripe->createCheckout($user['user_id'],'starter-pack');
    assert_stripe($checkoutSeen,'Stripe checkout API was not called');
    assert_stripe(($created['checkout_session_id']??null)==='cs_test_checkout001','Checkout session id mismatch');
    assert_stripe(($created['package']['credit_units']??null)===250,'Checkout credits mismatch');

    $session=[
        'id'=>'cs_test_checkout001',
        'object'=>'checkout.session',
        'mode'=>'payment',
        'payment_status'=>'paid',
        'client_reference_id'=>$user['user_id'],
        'amount_total'=>1000,
        'currency'=>'usd',
        'metadata'=>[
            'mcma_user_id'=>$user['user_id'],
            'mcma_package_id'=>'starter-pack',
            'mcma_package_fingerprint'=>$packageFingerprint,
        ],
    ];
    $event=[
        'id'=>'evt_test_checkout001',
        'object'=>'event',
        'type'=>'checkout.session.completed',
        'livemode'=>false,
        'data'=>['object'=>$session],
    ];
    $body=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $fulfilled=$stripe->handleWebhook($body,stripe_signature($now,$body,$secret));
    assert_stripe(($fulfilled['processed']??false)===true,'Stripe webhook was not processed');
    assert_stripe(($fulfilled['already_recorded']??true)===false,'First Stripe webhook marked duplicate');

    $summary=$billing->summary($library);
    assert_stripe(($summary['balance_units']??0)===250,'Stripe credits were not applied');
    assert_stripe(($summary['account']['plan_id']??null)==='starter','Stripe plan was not applied');

    $retryEvent=$event;
    $retryEvent['id']='evt_test_checkout002';
    $retryBody=json_encode($retryEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $retry=$stripe->handleWebhook($retryBody,stripe_signature($now,$retryBody,$secret));
    assert_stripe(($retry['already_recorded']??false)===true,'Stripe retry was not idempotent');
    assert_stripe(($billing->summary($library)['balance_units']??0)===250,'Stripe retry duplicated credits');

    $badSignature=false;
    try{$stripe->handleWebhook($body,'t='.$now.',v1='.str_repeat('0',64));}
    catch(BillingException $e){$badSignature=$e->reason()==='stripe_webhook_signature_invalid';}
    assert_stripe($badSignature,'Invalid Stripe webhook signature was accepted');

    $bad=$event;
    $bad['id']='evt_test_bad_amount';
    $bad['data']['object']['id']='cs_test_checkout_bad_amount';
    $bad['data']['object']['amount_total']=999;
    $badBody=json_encode($bad,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $badAmount=false;
    try{$stripe->handleWebhook($badBody,stripe_signature($now,$badBody,$secret));}
    catch(BillingException $e){$badAmount=$e->reason()==='stripe_checkout_amount_mismatch';}
    assert_stripe($badAmount,'Stripe amount mismatch was accepted');
    assert_stripe(($billing->summary($library)['balance_units']??0)===250,'Bad Stripe event changed credits');

    $unpaid=$event;
    $unpaid['id']='evt_test_unpaid';
    $unpaid['data']['object']['id']='cs_test_unpaid';
    $unpaid['data']['object']['payment_status']='unpaid';
    $unpaidBody=json_encode($unpaid,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $ignored=$stripe->handleWebhook($unpaidBody,stripe_signature($now,$unpaidBody,$secret));
    assert_stripe(($ignored['processed']??true)===false,'Unpaid Stripe session was processed');

    // Web routing: authenticated package list + checkout; webhook remains unauthenticated but signed.
    $cipher=new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef','session');
    $stateCipher=new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef','oidc-state');
    $oidc=new OidcClient(
        'https://id.example.test','client',null,'https://memory.example.test/callback','openid',
        static fn(string $method,string $url,array $headers,string $requestBody):array=>[500,'',[]]
    );
    $app=new WebApplication(
        $users,$oidc,$stateCipher,$cipher,new ProviderFactory(),
        'https://memory.example.test',true,true,
        ['embedding-provider'=>'none','generation-provider'=>'none'],
        3600,$billing,null,null,true,128,$stripe
    );
    $realNow=time();
    $cookie=$cipher->seal([
        'v'=>1,'iss'=>'https://id.example.test','sub'=>'stripe-user','iat'=>$realNow,'exp'=>$realNow+3600,
    ]);

    $packageResponse=$app->handle(new HttpRequest(
        'GET','/mcma/v1/billing/stripe/packages',[],[],['mcma_session'=>$cookie]
    ));
    assert_stripe($packageResponse->status()===200,'Stripe packages web route failed');

    $checkoutResponse=$app->handle(new HttpRequest(
        'POST','/mcma/v1/billing/stripe/checkout',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],[],
        ['mcma_session'=>$cookie],
        json_encode(['package_id'=>'starter-pack'],JSON_THROW_ON_ERROR)
    ));
    assert_stripe($checkoutResponse->status()===201,'Stripe checkout web route failed');

    $webhookEvent=$event;
    $webhookEvent['id']='evt_test_web_route';
    $webhookEvent['data']['object']['id']='cs_test_web_route';
    $webhookBody=json_encode($webhookEvent,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $webhookResponse=$app->handle(new HttpRequest(
        'POST','/mcma/v1/billing/stripe/webhook',
        ['stripe-signature'=>stripe_signature($now,$webhookBody,$secret),'content-type'=>'application/json'],
        [],[],$webhookBody
    ));
    assert_stripe($webhookResponse->status()===200,'Stripe webhook web route failed');
    assert_stripe(($billing->summary($library)['balance_units']??0)===500,'Stripe web route did not add credits');

    echo "MCMA Stripe Checkout and verified webhook integration passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rr_stripe($base);
}
