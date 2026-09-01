<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Billing\BillableAskService;
use MCMA\Core\Billing\BillingCatalog;
use MCMA\Core\Billing\BillingException;
use MCMA\Core\Billing\BillingService;
use MCMA\Core\Billing\UsageAwareEmbeddingProvider;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class QuotaEmbedding implements EmbeddingProvider, UsageAwareEmbeddingProvider
{
    public int $calls=0;
    public function id(): string { return 'quota:embed:v1'; }
    public function embed(string $text): array { $this->calls++; return [1.0,0.0,0.0]; }
    public function lastUsage(): array { return ['inputTokens'=>2,'totalTokens'=>2,'method'=>'provider']; }
}

final class QuotaGeneration implements GenerationProvider
{
    public int $calls=0;
    public function id(): string { return 'quota:gen:v1'; }
    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        return ['text'=>'quota answer '.$this->calls,'usage'=>['inputTokens'=>3,'outputTokens'=>2,'totalTokens'=>5]];
    }
}

function qassert(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function qrr(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) qrr($path); else @unlink($path);
    }
    @rmdir($dir);
}

$base=sys_get_temp_dir().'/mcma-free-quota-'.bin2hex(random_bytes(5));
try{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR='.$base.'/keys');

    $root=new LocalFilesystemAdapter($base.'/storage');
    $users=new MultiUserService($root,'quota-pepper-0123456789abcdef0123456789abcdef');
    $record=$users->register('https://id.example.test','free-user');
    $library=$users->resolveUserIdForService($record['user_id']);

    $catalog=new BillingCatalog($root);
    $catalog->setPlan('free',[
        'api_enabled'=>false,
        'embedding_enabled'=>true,
        'requests_per_minute'=>100,
        'daily_request_limit'=>10,
        'concurrent_requests'=>1,
        'max_request_credit_units'=>100,
        'monthly_credit_allowance'=>100,
        'monthly_token_limit'=>25,
        'allowed_providers'=>['quota:*'],
    ]);
    $catalog->setPricing('quota:embed:v1',[
        'currency'=>'USD','version'=>'quota-v1',
        'embedding_credit_units_per_1m'=>1000000,
        'embedding_cost_micros_per_1m'=>1,
    ]);
    $catalog->setPricing('quota:gen:v1',[
        'currency'=>'USD','version'=>'quota-v1',
        'input_credit_units_per_1m'=>1000000,
        'output_credit_units_per_1m'=>1000000,
        'input_cost_micros_per_1m'=>1,
        'output_cost_micros_per_1m'=>1,
    ]);

    $billing=new BillingService($catalog);
    $first=$billing->summary($library);
    qassert(($first['available_units']??0)===100,'Free monthly allowance was not granted');
    qassert(($first['quota']['monthly_credit_allowance']??0)===100,'Free allowance target missing');
    qassert(($first['quota']['monthly_tokens_limit']??0)===25,'Monthly token limit missing');

    $second=$billing->summary($library);
    qassert(($second['available_units']??0)===100,'Monthly allowance stacked on repeated summary');

    $embed=new QuotaEmbedding();
    $gen=new QuotaGeneration();
    $ask=new BillableAskService($library,$billing,$embed,$gen,5);

    foreach(['a','b','c'] as $i=>$question){
        $result=$ask->ask('quota_req_'.$i,'web',$question,false,0.75,0.99,5,false);
        qassert(($result['billing']['ai_billed']??false)===true,'Expected provider-backed request to be billed');
    }

    $quota=$billing->summary($library)['quota'];
    qassert(($quota['daily_requests_used']??0)===3,'Daily AI request counter mismatch');
    qassert(($quota['monthly_tokens_used']??0)===21,'Monthly AI token counter mismatch');

    $blocked=false;
    try{
        $ask->ask('quota_req_4','web','d',false,0.75,0.99,5,false);
    }catch(BillingException $e){
        $blocked=$e->reason()==='monthly_token_limit'&&$e->httpStatus()===402;
    }
    qassert($blocked,'Monthly token quota did not block the next provider call');
    qassert($gen->calls===3,'Generation provider was called after monthly quota exhaustion');

    echo "MCMA free monthly allowance and quota integration passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    qrr($base);
}
