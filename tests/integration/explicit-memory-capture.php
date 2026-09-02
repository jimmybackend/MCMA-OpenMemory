<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Memory\ExplicitMemoryService;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class ExplicitMemoryGenerationProvider implements GenerationProvider
{
    public int $calls=0;

    public function id(): string { return 'test:explicit-memory-organizer:v1'; }

    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        if(!is_string($context['system_instructions']??null)||!str_contains($context['system_instructions'],'memory librarian')){
            throw new RuntimeException('Explicit memory organizer did not receive system instructions');
        }
        if(!str_contains($question,'MCMA')||!str_contains($question,'floating')){
            throw new RuntimeException('Explicit memory organizer did not receive user memory as data');
        }

        return [
            'text'=>json_encode([
                'title'=>'MCMA semantic precision decision',
                'normalized_content'=>'MCMA must use floating-point values for semantic similarity so a single topic can retain greater precision, and semantic retrieval must preserve all configured filters.',
                'retrieval_question'=>'What semantic precision and filtering decision was made for MCMA?',
                'cognitive_layer'=>'90-projects',
                'scope'=>'project',
                'temperature'=>'hot',
                'freshness_class'=>'stable',
                'classification_reason'=>'This is a durable architecture decision for the MCMA project.',
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            'usage'=>['inputTokens'=>40,'outputTokens'=>80,'totalTokens'=>120],
            'stop_reason'=>'end_turn',
        ];
    }
}

final class ExplicitMemoryEmbeddingProvider implements EmbeddingProvider
{
    public int $calls=0;

    public function id(): string { return 'test:explicit-memory-embedding:v1:dimensions=3'; }

    public function embed(string $text): array
    {
        $this->calls++;
        $normalized=mb_strtolower(trim(preg_replace('/\s+/u',' ',$text)??$text),'UTF-8');
        if(str_contains($normalized,'semantic precision')||str_contains($normalized,'precisión semántica')){
            return [1.0,0.0,0.0];
        }
        return [0.0,0.0,1.0];
    }
}

final class InvalidExplicitMemoryGenerationProvider implements GenerationProvider
{
    public function id(): string { return 'test:invalid-organizer:v1'; }
    public function generate(string $question,array $context=[]): array
    {
        return ['text'=>'not-json','usage'=>['inputTokens'=>2,'outputTokens'=>1,'totalTokens'=>3]];
    }
}

function explicit_memory_ok(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function explicit_memory_rrmdir(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) explicit_memory_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

$base=sys_get_temp_dir().'/mcma-explicit-memory-'.bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR='.$base.'/keys');
putenv('MCMA_MASTER_KEY_B64');

try{
    explicit_memory_ok(
        ExplicitMemoryService::isExplicitSaveRequest('Guarda esto: MCMA usa números flotantes.'),
        'Spanish explicit save intent was not detected'
    );
    explicit_memory_ok(
        ExplicitMemoryService::isExplicitSaveRequest('Quiero que recuerdes esto: dato importante.'),
        'Spanish natural explicit save intent was not detected'
    );
    explicit_memory_ok(
        ExplicitMemoryService::isExplicitSaveRequest('Remember this: use deterministic indexes.'),
        'English explicit save intent was not detected'
    );
    explicit_memory_ok(
        !ExplicitMemoryService::isExplicitSaveRequest('¿Cómo guardo esto en PHP?'),
        'Ordinary question was misclassified as explicit memory'
    );

    $lib=Library::init(new LocalFilesystemAdapter($base.'/library'),'private');
    $lib->initializeAccessControl();

    $generator=new ExplicitMemoryGenerationProvider();
    $embedding=new ExplicitMemoryEmbeddingProvider();
    $service=new ExplicitMemoryService($lib,$generator,$embedding);

    $request='Guarda esto: MCMA debe usar floating point para la similitud semantica porque da mas precision en un solo tema y la recuperacion semantica no debe saltarse los filtros.';
    $result=$service->capture('owner',$request);

    explicit_memory_ok(($result['route']??null)==='memory-capture','Explicit memory route mismatch');
    explicit_memory_ok(($result['stored']??false)===true,'Explicit memory was not stored');
    explicit_memory_ok(($result['provider_called']??false)===true,'Organizer provider was not called');
    explicit_memory_ok(($result['organizer']['model_output_valid']??false)===true,'Organizer output was not accepted');
    explicit_memory_ok(($result['storage']['classification']['cognitive_layer']??null)==='90-projects','Cognitive layer mismatch');
    explicit_memory_ok(($result['storage']['classification']['scope']??null)==='project','Scope mismatch');
    explicit_memory_ok(($result['storage']['classification']['temperature']??null)==='hot','Temperature mismatch');
    explicit_memory_ok(str_starts_with((string)$result['logical_ref'],'memory://user/projects/mcma-semantic-precision-decision-'),'Canonical classified route mismatch');
    explicit_memory_ok(str_contains((string)$result['answer']['value'],'Ruta: memory://user/projects/'),'User confirmation did not include canonical route');

    $canonical=$lib->readAs('owner',(string)$result['logical_ref']);
    explicit_memory_ok(($canonical['payload']['metadata']['cognitive_layer']??null)==='90-projects','Canonical outer cognitive layer mismatch');
    explicit_memory_ok(($canonical['payload']['metadata']['scope']??null)==='project','Canonical outer scope mismatch');
    explicit_memory_ok(($canonical['payload']['metadata']['maturity']??null)==='confirmed','Canonical maturity mismatch');
    $content=$canonical['payload']['content']??null;
    explicit_memory_ok(is_array($content),'Canonical explicit memory content is malformed');
    explicit_memory_ok(
        ($content['source']['original']??null)==='MCMA debe usar floating point para la similitud semantica porque da mas precision en un solo tema y la recuperacion semantica no debe saltarse los filtros.',
        'Canonical source text was not preserved'
    );
    explicit_memory_ok(
        str_contains((string)($content['content']??''),'floating-point'),
        'Normalized memory content was not stored'
    );

    $retrievalQuestion=(string)($content['retrieval']['question']??'');
    $knowledge=(new KnowledgeService($lib))->inspect('owner',$retrievalQuestion);
    explicit_memory_ok(($knowledge['record']['epistemic']['validation_state']??null)==='verified','Recovery mirror was not verified');
    explicit_memory_ok(abs((float)($knowledge['record']['epistemic']['confidence']??0)-0.95)<1e-12,'Recovery mirror confidence mismatch');
    explicit_memory_ok(in_array((string)$result['logical_ref'],$knowledge['record']['relations']??[],true),'Recovery mirror does not point to canonical memory');
    explicit_memory_ok(is_array($result['storage']['retrieval']['semantic_index']??null),'Explicit memory was not incrementally indexed');

    $semantic=(new SemanticIndexService($lib))->answer(
        'ai',
        'What semantic precision decision should MCMA remember?',
        $embedding,
        false,
        0.75,
        0.75,
        5
    );
    explicit_memory_ok(($semantic['reusable']??false)===true,'Explicit memory recovery mirror was not semantically reusable');
    explicit_memory_ok(
        str_contains((string)($semantic['answer']['value']??''),'floating-point'),
        'Semantic recovery did not return normalized explicit memory'
    );

    $firstObject=(string)$result['storage']['object_id'];
    $firstHash=(string)$result['storage']['storage_hash'];
    $again=$service->capture('owner',$request);
    explicit_memory_ok(($again['storage']['created']??true)===false,'Repeated explicit memory created a duplicate canonical object');
    explicit_memory_ok(hash_equals($firstObject,(string)$again['storage']['object_id']),'Repeated explicit memory changed stable object id');
    explicit_memory_ok(!hash_equals($firstHash,(string)$again['storage']['storage_hash']),'Repeated explicit memory did not create a revision');

    $fallbackLib=Library::init(new LocalFilesystemAdapter($base.'/fallback-library'),'private');
    $fallbackLib->initializeAccessControl();
    $fallback=(new ExplicitMemoryService($fallbackLib,new InvalidExplicitMemoryGenerationProvider(),null))->capture(
        'owner',
        'Guarda esto: Mi editor preferido es Vim.'
    );
    explicit_memory_ok(($fallback['stored']??false)===true,'Invalid organizer output caused memory loss');
    explicit_memory_ok(($fallback['organizer']['model_output_valid']??true)===false,'Invalid organizer output did not trigger fallback');
    explicit_memory_ok(($fallback['storage']['classification']['cognitive_layer']??null)==='40-semantic','Fallback cognitive layer mismatch');
    explicit_memory_ok(
        ($fallback['memory']['content']??null)==='Mi editor preferido es Vim.',
        'Fallback did not preserve source text'
    );

    $emptyRejected=false;
    try{
        (new ExplicitMemoryService($fallbackLib,null,null))->capture('owner','Guarda esto');
    }catch(RuntimeException){
        $emptyRejected=true;
    }
    explicit_memory_ok($emptyRejected,'Empty explicit save command was stored');

    echo "MCMA explicit classified memory capture passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    explicit_memory_rrmdir($base);
}
