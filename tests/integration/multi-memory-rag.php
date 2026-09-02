<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Context\MultiMemoryContextBuilder;
use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class MultiRagEmbeddingProvider implements EmbeddingProvider
{
    public int $calls=0;

    public function id(): string { return 'test:multi-memory-rag:dimensions=3'; }

    public function embed(string $text): array
    {
        $this->calls++;
        return match(KnowledgeRecord::normalizeIntent($text)){
            'user architecture memory' => [0.98,0.20,0.0],
            'documentation architecture memory' => [0.97,0.24,0.0],
            'model architecture memory' => [0.99,0.14,0.0],
            'unverified architecture draft' => [0.995,0.10,0.0],
            'denied architecture secret' => [0.997,0.08,0.0],
            'synthesize architecture evidence',
            'synthesize architecture evidence and save it' => [1.0,0.0,0.0],
            default => [0.0,0.0,1.0],
        };
    }
}

final class MultiRagGenerationProvider implements GenerationProvider
{
    public int $calls=0;
    public array $lastContext=[];

    public function id(): string { return 'test:multi-rag-generation:v1'; }

    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        $this->lastContext=$context;
        if(!is_array($context['multi_memory_context']??null)){
            throw new RuntimeException('Generation did not receive multi-memory RAG context');
        }
        return [
            'text'=>'Synthesized answer from selected MCMA memories.',
            'usage'=>['inputTokens'=>80,'outputTokens'=>20,'totalTokens'=>100],
        ];
    }
}

function multi_rag_ok(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function multi_rag_rrmdir(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) multi_rag_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

$base=sys_get_temp_dir().'/mcma-multi-rag-'.bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR='.$base.'/keys');
putenv('MCMA_MASTER_KEY_B64');

try{
    $lib=Library::init(new LocalFilesystemAdapter($base.'/library'),'private');
    $lib->initializeAccessControl();

    $knowledge=new KnowledgeService($lib);
    $embedding=new MultiRagEmbeddingProvider();
    $semantic=new SemanticIndexService($lib);
    $librarian=new Librarian($knowledge,$semantic,$embedding);
    $generator=new MultiRagGenerationProvider();

    $user=$librarian->remember('User architecture memory','User-confirmed storage architecture detail.',[
        'confidence'=>0.95,
        'validation_state'=>'verified',
        'provenance'=>[['source_type'=>'user','reference'=>'user-confirmed-architecture']],
        'freshness_class'=>'stable',
        'max_age_seconds'=>86400,
    ]);
    $docs=$librarian->remember('Documentation architecture memory','Documented encryption architecture detail.',[
        'confidence'=>0.92,
        'validation_state'=>'supported',
        'provenance'=>[['source_type'=>'documentation','reference'=>'docs/architecture.md']],
        'freshness_class'=>'stable',
        'max_age_seconds'=>86400,
    ]);
    $model=$librarian->remember('Model architecture memory','Earlier model interpretation of the architecture.',[
        'confidence'=>0.95,
        'validation_state'=>'verified',
        'provenance'=>[['source_type'=>'model','reference'=>'test:model:v1']],
        'freshness_class'=>'immutable',
        'max_age_seconds'=>null,
    ]);
    $unverified=$librarian->remember('Unverified architecture draft','Draft that must not enter trusted multi-RAG.',[
        'confidence'=>0.99,
        'validation_state'=>'unverified',
        'provenance'=>[['source_type'=>'user','reference'=>'unverified-draft']],
        'freshness_class'=>'immutable',
        'max_age_seconds'=>null,
    ]);
    $denied=$librarian->remember('Denied architecture secret','Permission denied candidate.',[
        'confidence'=>0.99,
        'validation_state'=>'verified',
        'provenance'=>[['source_type'=>'user','reference'=>'denied-secret']],
        'freshness_class'=>'immutable',
        'max_age_seconds'=>null,
    ]);

    $deniedRef=KnowledgeRecord::logicalRef('Denied architecture secret');
    $policy=$lib->permissions('owner');
    $policy['resources'][]=[
        'resource'=>$deniedRef,
        'subject'=>'ai',
        'deny'=>['read'],
    ];
    $lib->setPermissions($policy,'owner');

    $builder=new MultiMemoryContextBuilder(
        $lib,
        2600,
        3,
        6,
        0.55,
        0.50,
        1200,
        3
    );
    $ask=new AskService($knowledge,$semantic,$embedding,$generator,$librarian,null,$builder);

    $callsBefore=$embedding->calls;
    $result=$ask->ask(
        'ai',
        'Synthesize architecture evidence',
        false,
        0.75,
        0.9999,
        5,
        false
    );

    multi_rag_ok(($result['route']??null)==='provider','Wide RAG discovery incorrectly became a direct semantic answer');
    multi_rag_ok(($result['context_used']['multi_memory']??false)===true,'Multi-memory RAG context was not reported');
    multi_rag_ok(($result['context_used']['memory']??true)===false,'Legacy single-memory context duplicated multi-memory RAG');
    multi_rag_ok($embedding->calls===$callsBefore+1,'Ask generated more than one query embedding for semantic + multi-RAG selection');

    $rag=$generator->lastContext['multi_memory_context']??null;
    multi_rag_ok(is_array($rag),'Generator did not receive multi-memory context');
    $memories=$rag['memories']??[];
    multi_rag_ok(is_array($memories)&&count($memories)>=2,'Multi-memory RAG did not combine multiple memories');
    multi_rag_ok(count($memories)<=3,'Multi-memory RAG exceeded max memories');
    multi_rag_ok(($rag['selection']['estimated_tokens_upper_bound']??PHP_INT_MAX)<=2600,'Multi-memory RAG exceeded context budget');
    multi_rag_ok(($rag['selection']['strategy']??null)==='multi-memory-rag-v1','Multi-memory RAG strategy metadata missing');

    $refs=array_map(static fn(array $memory): string => (string)($memory['logical_ref']??''),$memories);
    multi_rag_ok(!in_array(KnowledgeRecord::logicalRef('Unverified architecture draft'),$refs,true),'Unverified memory entered multi-RAG context');
    multi_rag_ok(!in_array($deniedRef,$refs,true),'Permission-denied memory entered multi-RAG context');
    multi_rag_ok(in_array(KnowledgeRecord::logicalRef('User architecture memory'),$refs,true),'Verified user memory was not selected');
    multi_rag_ok(in_array(KnowledgeRecord::logicalRef('Documentation architecture memory'),$refs,true),'Supported documentation memory was not selected');

    $userPos=array_search(KnowledgeRecord::logicalRef('User architecture memory'),$refs,true);
    $modelPos=array_search(KnowledgeRecord::logicalRef('Model architecture memory'),$refs,true);
    if($modelPos!==false){
        multi_rag_ok($userPos!==false&&$userPos<$modelPos,'Provenance-aware ranking did not prioritize user evidence over similar model evidence');
    }

    foreach($memories as $memory){
        multi_rag_ok(isset($memory['similarity'],$memory['confidence'],$memory['freshness_class'],$memory['provenance_score'],$memory['rag_score']),'RAG ranking metadata incomplete');
        multi_rag_ok(is_array($memory['provenance']??null)&&$memory['provenance']!==[],'Selected memory lost provenance');
    }

    $saved=$ask->ask(
        'ai',
        'Synthesize architecture evidence and save it',
        false,
        0.75,
        0.9999,
        5,
        true
    );
    multi_rag_ok(($saved['stored']??false)===true,'Generated multi-RAG synthesis was not stored');
    $stored=$knowledge->inspect('owner','Synthesize architecture evidence and save it');
    $memorySources=array_values(array_filter(
        $stored['record']['provenance']??[],
        static fn(array $source): bool => ($source['source_type']??null)==='memory'
    ));
    multi_rag_ok(count($memorySources)>=2,'Stored synthesis did not preserve selected-memory provenance');
    multi_rag_ok(count($stored['record']['relations']??[])>=2,'Stored synthesis did not preserve memory relations');

    multi_rag_ok(($lib->verify()['ok']??false)===true,'Library verify failed after multi-memory RAG operations');

    echo "MCMA multi-memory RAG integration passed.\n";
} finally {
    multi_rag_rrmdir($base);
}
