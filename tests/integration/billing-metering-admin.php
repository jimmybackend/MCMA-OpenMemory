<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Billing\AdminService;
use MCMA\Core\Billing\ApiKeyService;
use MCMA\Core\Billing\BillableAskService;
use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingException;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Billing\RecordedPaymentProvider;
use MCMA\Core\Billing\UsageAwareEmbeddingProvider;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class BillingFakeEmbedding implements EmbeddingProvider, UsageAwareEmbeddingProvider
{
    public int $calls=0;
    public function id(): string { return 'test:embed:v1'; }
    public function embed(string $text): array { $this->calls++; return [1.0,0.0,0.0]; }
    public function lastUsage(): array { return ['inputTokens'=>7,'totalTokens'=>7,'method'=>'provider']; }
}
final class BillingFakeGeneration implements GenerationProvider
{
    public int $calls=0;
    public function id(): string { return 'test:gen:v1'; }
    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        return ['text'=>'Fresh generated answer','usage'=>['inputTokens'=>10,'outputTokens'=>5,'totalTokens'=>15],'stop_reason'=>'stop'];
    }
}

function assert_billing(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}
function rr_billing(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_billing($path); else @unlink($path);
    }
    @rmdir($dir);
}

$base=sys_get_temp_dir().'/mcma-billing-'.bin2hex(random_bytes(5));
try{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR='.$base.'/keys');
    $pepper='0123456789abcdef0123456789abcdef0123456789abcdef';
    $root=new LocalFilesystemAdapter($base.'/storage');
    $users=new MultiUserService($root,$pepper);
    $alice=$users->register('https://id.example.test','alice-subject');
    $bob=$users->register('https://id.example.test','bob-subject');
    $aliceLib=$users->resolveUserIdForService($alice['user_id']);
    $bobLib=$users->resolveUserIdForService($bob['user_id']);

    $catalog=new BillingCatalog($root);
    $billing=new BillingService($catalog);
    $billing->ensureAccount($aliceLib);
    $billing->setPlan($aliceLib,'starter');

    $catalog->setPricing('test:embed:v1',[
        'currency'=>'USD','version'=>'test-v1',
        'embedding_credit_units_per_1m'=>100000,
        'embedding_cost_micros_per_1m'=>200000,
    ]);
    $catalog->setPricing('test:gen:v1',[
        'currency'=>'USD','version'=>'test-v1',
        'input_credit_units_per_1m'=>100000,
        'output_credit_units_per_1m'=>200000,
        'input_cost_micros_per_1m'=>300000,
        'output_cost_micros_per_1m'=>600000,
    ]);

    $billing->credit($aliceLib,500,'initial test credits','test');
    assert_billing(($billing->summary($aliceLib)['balance_units']??0)===500,'Initial balance mismatch');

    $knowledge=new KnowledgeService($aliceLib);
    $knowledge->capture(
        'librarian',
        'Known exact question',
        'Known exact answer',
        'text',
        0.99,
        'verified',
        [['source_type'=>'working-test','reference'=>'billing-test']],
        'stable',
        3600,
        'reuse-unless-stale'
    );

    $embed=new BillingFakeEmbedding();
    $gen=new BillingFakeGeneration();
    $billable=new BillableAskService($aliceLib,$billing,$embed,$gen,64);

    $before=$billing->summary($aliceLib)['balance_units'];
    $exact=$billable->ask('req_exact','web','Known exact question');
    $after=$billing->summary($aliceLib)['balance_units'];
    assert_billing(($exact['route']??null)==='memory-exact','Exact memory route failed');
    assert_billing(($exact['billing']['ai_billed']??true)===false,'Exact memory should not be billed');
    assert_billing($before===$after,'Exact memory changed balance');
    assert_billing($embed->calls===0&&$gen->calls===0,'Exact memory called AI provider');

    $generated=$billable->ask('req_generated','api','A new unknown question');
    assert_billing(($generated['route']??null)==='provider','Generated route failed');
    assert_billing(($generated['billing']['ai_billed']??false)===true,'Generated request was not billed');
    assert_billing($embed->calls>=1,'Embedding provider was not metered');
    assert_billing($gen->calls===1,'Generation provider call count mismatch');

    $daily=$billing->dailyUsage($aliceLib,gmdate('Y-m-d'));
    $summary=$daily['summary']??[];
    assert_billing(($summary['requests']??0)===1,'Daily billed request count mismatch');
    assert_billing(($summary['embedding_tokens']??0)>=7,'Embedding tokens not recorded');
    assert_billing(($summary['input_tokens']??0)===10,'Generation input tokens mismatch');
    assert_billing(($summary['output_tokens']??0)===5,'Generation output tokens mismatch');
    assert_billing(($summary['credit_units_charged']??0)>0,'Credits were not charged');
    assert_billing(isset($summary['cost_micros_by_currency']['USD']),'USD cost summary missing');

    $events=$daily['events']??[];
    $usageEvent=null;
    foreach($events as $event) if(is_array($event)&&($event['type']??null)==='usage') $usageEvent=$event;
    assert_billing(is_array($usageEvent),'Usage event missing');
    assert_billing(count($usageEvent['pricing_snapshots']??[])>=2,'Pricing snapshots missing');
    assert_billing(($usageEvent['origin']??null)==='api','Usage origin missing');

    $paymentProvider=new RecordedPaymentProvider('stripe');
    $payment=$billing->recordPayment($aliceLib,$paymentProvider,[
        'provider_payment_id'=>'pi_test_001',
        'amount_micros'=>5000000,
        'currency'=>'USD',
        'credit_units'=>1000,
    ]);
    assert_billing(($payment['payment']['provider']??null)==='stripe','Payment provider mismatch');

    $duplicate=false;
    try{
        $billing->recordPayment($aliceLib,$paymentProvider,[
            'provider_payment_id'=>'pi_test_001','amount_micros'=>5000000,'currency'=>'USD','credit_units'=>1000,
        ]);
    }catch(BillingException $e){$duplicate=$e->reason()==='duplicate_payment';}
    assert_billing($duplicate,'Duplicate payment was accepted');

    $apiKeys=new ApiKeyService($root,$users,'api-pepper-0123456789abcdef0123456789abcdef');
    $createdKey=$apiKeys->create($alice['user_id'],'CI key');
    assert_billing(str_starts_with($createdKey['token'],'mcma_api_'),'API token format mismatch');
    $auth=$apiKeys->authenticate($createdKey['token']);
    assert_billing(($auth['user_id']??null)===$alice['user_id'],'API token user mismatch');
    $keys=$apiKeys->list($alice['user_id']);
    assert_billing(count($keys)===1&&!isset($keys[0]['token_hash']),'API key metadata leaked token hash');
    $apiKeys->revoke($alice['user_id'],$createdKey['key_id']);
    $revoked=false;
    try{$apiKeys->authenticate($createdKey['token']);}catch(BillingException $e){$revoked=$e->reason()==='invalid_api_token';}
    assert_billing($revoked,'Revoked API key still authenticates');

    $admin=new AdminService($root,$users,$billing,$catalog,$pepper);
    $admin->bootstrapRoot('https://id.example.test','root-admin-subject');
    $unauthorized=false;
    try{$admin->listUsers('https://id.example.test','not-admin');}catch(BillingException $e){$unauthorized=$e->reason()==='admin_required';}
    assert_billing($unauthorized,'Non-admin accessed superadmin service');

    $admin->adjustCredits('https://id.example.test','root-admin-subject',$alice['user_id'],50,'support credit');
    $admin->adjustCredits('https://id.example.test','root-admin-subject',$alice['user_id'],-25,'correction');
    $admin->setPlan('https://id.example.test','root-admin-subject',$alice['user_id'],'pro');
    $admin->setServiceStatus('https://id.example.test','root-admin-subject',$alice['user_id'],'suspended');

    $blocked=false;
    try{$billable->ask('req_suspended','web','Known exact question');}catch(BillingException $e){$blocked=$e->reason()==='service_inactive';}
    assert_billing($blocked,'Suspended billing account still served requests');

    $admin->setServiceStatus('https://id.example.test','root-admin-subject',$alice['user_id'],'active');
    $adminUsers=$admin->listUsers('https://id.example.test','root-admin-subject');
    assert_billing(count($adminUsers)===2,'Admin user list mismatch');
    assert_billing(isset($adminUsers[0]['billing'],$adminUsers[0]['totals']),'Admin billing overview incomplete');

    $bobSummary=$billing->summary($bobLib);
    assert_billing(($bobSummary['balance_units']??-1)===100000,'Bob Free allowance mismatch');
    assert_billing(($bobSummary['quota']['monthly_tokens_used']??-1)===0,'Alice token usage leaked into Bob quota');
    $bobTotals=$billing->totals($bobLib);
    assert_billing(($bobTotals['payments']??-1)===0&&($bobTotals['total_tokens']??-1)===0,'Alice billing activity leaked into Bob account');

    $allBytes='';
    foreach($root->list('') as $locator) $allBytes.=$root->get($locator)['bytes'];
    assert_billing(!str_contains($allBytes,'alice-subject'),'Alice subject leaked in storage');
    assert_billing(!str_contains($allBytes,'root-admin-subject'),'Admin subject leaked in storage');
    assert_billing(!str_contains($allBytes,$createdKey['token']),'Plaintext API token leaked in storage');

    echo "MCMA billing, metering, API keys and superadmin integration passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rr_billing($base);
}
