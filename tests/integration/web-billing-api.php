<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Billing\AdminService;
use MCMA\Core\Billing\ApiKeyService;
use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\LocalFilesystemAdapter;
use MCMA\Core\Web\EncryptedCookie;
use MCMA\Core\Web\HttpRequest;
use MCMA\Core\Web\OidcClient;
use MCMA\Core\Web\WebApplication;

function assert_web_billing(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}
function rr_web_billing(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_web_billing($path); else @unlink($path);
    }
    @rmdir($dir);
}
function json_response_body($response): array
{
    return json_decode($response->body(),true,64,JSON_THROW_ON_ERROR);
}

$base=sys_get_temp_dir().'/mcma-web-billing-'.bin2hex(random_bytes(5));
try{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR='.$base.'/keys');

    $pepper='0123456789abcdef0123456789abcdef0123456789abcdef';
    $root=new LocalFilesystemAdapter($base.'/storage');
    $users=new MultiUserService($root,$pepper);
    $catalog=new BillingCatalog($root);
    $billing=new BillingService($catalog);
    $apiKeys=new ApiKeyService($root,$users,'api-key-pepper-0123456789abcdef0123456789abcdef');
    $admin=new AdminService($root,$users,$billing,$catalog,$pepper);
    $admin->bootstrapRoot('https://id.example.test','root-admin');

    $cipher=new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef','session');
    $stateCipher=new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef','oidc-state');
    $oidc=new OidcClient(
        'https://id.example.test','client',null,'https://memory.example.test/callback','openid',
        static fn(string $method,string $url,array $headers,string $body):array=>[500,'',[]]
    );

    $app=new WebApplication(
        $users,$oidc,$stateCipher,$cipher,new ProviderFactory(),
        'https://memory.example.test',true,true,
        ['embedding-provider'=>'none','generation-provider'=>'none'],
        3600,$billing,$apiKeys,$admin,true,128
    );

    $now=time();
    $aliceCookie=$cipher->seal(['v'=>1,'iss'=>'https://id.example.test','sub'=>'alice-web','iat'=>$now,'exp'=>$now+3600]);
    $adminCookie=$cipher->seal(['v'=>1,'iss'=>'https://id.example.test','sub'=>'root-admin','iat'=>$now,'exp'=>$now+3600]);

    $aliceMe=$app->handle(new HttpRequest('GET','/mcma/v1/me',[],[],['mcma_session'=>$aliceCookie]));
    assert_web_billing($aliceMe->status()===200,'Alice /me failed');
    $aliceData=json_response_body($aliceMe);
    $userId=(string)$aliceData['user']['user_id'];
    assert_web_billing(($aliceData['billing']['account']['plan_id']??null)==='free','Default plan is not free');

    $plan=$app->handle(new HttpRequest(
        'POST','/mcma/v1/admin/users/'.$userId.'/plan',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],[],
        ['mcma_session'=>$adminCookie],
        json_encode(['plan_id'=>'starter'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($plan->status()===200,'Admin plan update failed');

    $credits=$app->handle(new HttpRequest(
        'POST','/mcma/v1/admin/users/'.$userId.'/credits',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],[],
        ['mcma_session'=>$adminCookie],
        json_encode(['units'=>5000,'reason'=>'test purchase'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($credits->status()===200,'Admin credits failed');

    $keyResponse=$app->handle(new HttpRequest(
        'POST','/mcma/v1/api-keys',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],[],
        ['mcma_session'=>$aliceCookie],
        json_encode(['label'=>'external client'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($keyResponse->status()===201,'API key creation failed');
    $token=(string)json_response_body($keyResponse)['key']['token'];
    assert_web_billing(str_starts_with($token,'mcma_api_'),'Created API token missing');

    $apiMe=$app->handle(new HttpRequest('GET','/mcma/v1/me',[
        'authorization'=>'Bearer '.$token,
    ]));
    assert_web_billing($apiMe->status()===200,'Bearer /me failed');
    assert_web_billing((json_response_body($apiMe)['user']['user_id']??null)===$userId,'Bearer resolved wrong user');

    $apiAsk=$app->handle(new HttpRequest(
        'POST','/mcma/v1/ask',
        ['authorization'=>'Bearer '.$token,'content-type'=>'application/json'],[],[],
        json_encode(['question'=>'No provider configured'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($apiAsk->status()===200,'Bearer ask failed');
    $askData=json_response_body($apiAsk);
    assert_web_billing(($askData['result']['decision']??null)==='provider-required','Unexpected no-provider decision');
    assert_web_billing(($askData['result']['billing']['ai_billed']??true)===false,'No-provider request was billed');

    $billingResponse=$app->handle(new HttpRequest('GET','/mcma/v1/billing',[
        'authorization'=>'Bearer '.$token,
    ]));
    assert_web_billing($billingResponse->status()===200,'Bearer billing endpoint failed');
    assert_web_billing((json_response_body($billingResponse)['billing']['balance_units']??0)===105000,'Billing balance mismatch after Free allowance plus admin credits');

    $adminUsers=$app->handle(new HttpRequest('GET','/mcma/v1/admin/users',[],[],['mcma_session'=>$adminCookie]));
    assert_web_billing($adminUsers->status()===200,'Admin users endpoint failed');
    assert_web_billing(count(json_response_body($adminUsers)['users']??[])===1,'Admin users count mismatch');

    $suspend=$app->handle(new HttpRequest(
        'POST','/mcma/v1/admin/users/'.$userId.'/service',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],[],
        ['mcma_session'=>$adminCookie],
        json_encode(['status'=>'suspended'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($suspend->status()===200,'Admin suspend failed');

    $blocked=$app->handle(new HttpRequest(
        'POST','/mcma/v1/ask',
        ['authorization'=>'Bearer '.$token,'content-type'=>'application/json'],[],[],
        json_encode(['question'=>'Should be blocked'],JSON_THROW_ON_ERROR)
    ));
    assert_web_billing($blocked->status()===403,'Suspended API request was not blocked');
    assert_web_billing((json_response_body($blocked)['error']??null)==='service_inactive','Suspension error mismatch');

    echo "MCMA web billing, API-key and superadmin routing passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rr_web_billing($base);
}
