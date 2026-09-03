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
    public int $mcmaCalls=0;

    public function id(): string { return 'test:explicit-memory-organizer:v2'; }

    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        if(!is_string($context['system_instructions']??null)||!str_contains($context['system_instructions'],'memory librarian')){
            throw new RuntimeException('Explicit memory organizer did not receive system instructions');
        }
        if(!str_contains($context['system_instructions'],'category_path')){
            throw new RuntimeException('Explicit memory organizer did not receive dynamic taxonomy instructions');
        }

        if(str_contains($question,'pollo') && str_contains($question,'Coca')){
            $payload=[
                'title'=>'Receta de la abuela: pollo a la Coca-Cola',
                'normalized_content'=>"Receta de la abuela para pollo a la Coca-Cola. Ingredientes: pollo, Coca-Cola, cebolla y sal. Preparación: dorar el pollo, agregar cebolla y Coca-Cola, sazonar y cocinar hasta que la salsa reduzca.",
                'retrieval_question'=>'¿Tienes guardada la receta de la abuela para pollo a la Coca-Cola?',
                'category_path'=>['recetas','cocina'],
                'cognitive_layer'=>'50-procedural',
                'scope'=>'user',
                'temperature'=>'warm',
                'freshness_class'=>'stable',
                'classification_reason'=>'Es una receta familiar reutilizable y corresponde a conocimiento procedural de cocina.',
            ];
        }elseif(str_contains($question,'server_name mailit.click') && str_contains($question,'nginx')){
            $payload=[
                'title'=>'Configuración Nginx de mailit.click',
                'normalized_content'=>"Configuración Nginx guardada para el EC2 de mailit.click:\nserver {\n    listen 80;\n    server_name mailit.click www.mailit.click;\n    root /var/www/html;\n}",
                'retrieval_question'=>'¿Qué configuración de Nginx está guardada para el servidor EC2 mailit.click?',
                'category_path'=>['configuraciones','servidores','mailit.click'],
                'cognitive_layer'=>'50-procedural',
                'scope'=>'project',
                'temperature'=>'hot',
                'freshness_class'=>'dynamic',
                'classification_reason'=>'Es configuración operativa de Nginx asociada a un servidor concreto.',
            ];
        }else{
            if(!str_contains($question,'MCMA')||!str_contains($question,'floating')){
                throw new RuntimeException('Explicit memory organizer did not receive expected user memory as data');
            }
            $this->mcmaCalls++;
            $payload=[
                'title'=>$this->mcmaCalls===1?'MCMA semantic precision decision':'Semantic precision decision for MCMA',
                'normalized_content'=>'MCMA must use floating-point values for semantic similarity so a single topic can retain greater precision, and semantic retrieval must preserve all configured filters.',
                'retrieval_question'=>'What semantic precision and filtering decision was made for MCMA?',
                'category_path'=>['proyectos','mcma','arquitectura'],
                'cognitive_layer'=>'90-projects',
                'scope'=>'project',
                'temperature'=>'hot',
                'freshness_class'=>'stable',
                'classification_reason'=>'This is a durable architecture decision for the MCMA project.',
            ];
        }

        return [
            'text'=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
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
        $normalized=strtolower(trim(preg_replace('/\s+/u',' ',$text)??$text));
        if(str_contains($normalized,'semantic precision')||str_contains($normalized,'precisión semántica')){
            return [1.0,0.0,0.0];
        }
        if(str_contains($normalized,'pollo')||str_contains($normalized,'coca-cola')||str_contains($normalized,'receta')){
            return [0.0,1.0,0.0];
        }
        if(str_contains($normalized,'nginx')||str_contains($normalized,'mailit.click')){
            return [0.0,0.0,1.0];
        }
        return [0.5773502692,0.5773502692,0.5773502692];
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
    explicit_memory_ok(str_starts_with((string)$result['logical_ref'],'memory://user/proyectos/mcma/arquitectura/mcma-semantic-precision-decision-'),'Canonical dynamic taxonomy route mismatch');
    explicit_memory_ok(($result['storage']['classification']['category_path']??null)===['proyectos','mcma','arquitectura'],'Dynamic category path mismatch');
    explicit_memory_ok(($result['storage']['classification']['category_slugs']??null)===['proyectos','mcma','arquitectura'],'Dynamic category slugs mismatch');
    explicit_memory_ok(str_contains((string)$result['answer']['value'],'Carpetas: proyectos / mcma / arquitectura'),'User confirmation did not include thematic folders');
    explicit_memory_ok(str_contains((string)$result['answer']['value'],'Ruta: memory://user/proyectos/mcma/arquitectura/'),'User confirmation did not include dynamic canonical route');

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

    $recipeRequest="Guarda la receta de mi abuela: pollo a la Coca. Ingredientes: pollo, Coca-Cola, cebolla y sal. Preparación: dorar el pollo, agregar cebolla y Coca-Cola, sazonar y reducir.";
    $recipe=$service->capture('owner',$recipeRequest);
    explicit_memory_ok(
        str_starts_with((string)$recipe['logical_ref'],'memory://user/recetas/cocina/receta-de-la-abuela-pollo-a-la-coca-cola-'),
        'Recipe was not stored in dynamic recipes/cooking taxonomy'
    );
    explicit_memory_ok(($recipe['storage']['classification']['category_path']??null)===['recetas','cocina'],'Recipe category path mismatch');
    explicit_memory_ok(($recipe['storage']['classification']['cognitive_layer']??null)==='50-procedural','Recipe cognitive layer mismatch');
    explicit_memory_ok(
        str_contains((string)($recipe['memory']['source']??''),'receta de mi abuela'),
        'Save-command parsing lost the recipe relationship before the colon'
    );

    $recipeAnswer=(new SemanticIndexService($lib))->answer(
        'ai',
        '¿Tienes guardada la receta de pollo a la coca de mi abuela?',
        $embedding,
        false,
        0.75,
        0.75,
        5
    );
    explicit_memory_ok(($recipeAnswer['reusable']??false)===true,'Recipe was not semantically recoverable');
    explicit_memory_ok(str_contains((string)($recipeAnswer['answer']['value']??''),'Receta de la abuela'),'Recipe semantic recovery returned wrong memory');
    explicit_memory_ok(($recipeAnswer['canonical_memory_ref']??null)===$recipe['logical_ref'],'Recipe recovery did not expose canonical thematic route');

    $nginxRequest="Guarda esta configuración de nginx del EC2 mailit.click:\nserver {\n    listen 80;\n    server_name mailit.click www.mailit.click;\n    root /var/www/html;\n}";
    $nginx=$service->capture('owner',$nginxRequest);
    explicit_memory_ok(
        str_starts_with((string)$nginx['logical_ref'],'memory://user/configuraciones/servidores/mailit-click/configuracion-nginx-de-mailit-click-'),
        'Nginx configuration was not stored in configurations/servers/mailit.click taxonomy'
    );
    explicit_memory_ok(
        ($nginx['storage']['classification']['category_path']??null)===['configuraciones','servidores','mailit.click'],
        'Nginx category path mismatch'
    );
    explicit_memory_ok(($nginx['storage']['classification']['freshness_class']??null)==='dynamic','Nginx freshness should be dynamic');
    explicit_memory_ok(
        str_contains((string)($nginx['memory']['source']??''),'configuración de nginx del EC2 mailit.click'),
        'Save-command parsing lost Nginx server context before the colon'
    );

    $nginxAnswer=(new SemanticIndexService($lib))->answer(
        'ai',
        '¿Tienes guardada configuración de nginx para mailit.click?',
        $embedding,
        false,
        0.75,
        0.75,
        5
    );
    explicit_memory_ok(($nginxAnswer['reusable']??false)===true,'Nginx configuration was not semantically recoverable');
    explicit_memory_ok(str_contains((string)($nginxAnswer['answer']['value']??''),'server_name mailit.click'),'Nginx semantic recovery returned wrong memory');
    explicit_memory_ok(($nginxAnswer['canonical_memory_ref']??null)===$nginx['logical_ref'],'Nginx recovery did not expose canonical thematic route');

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

    // Versioned owner mutation of a canonical memory.
    $mutation = new \MCMA\Core\Memory\MemoryMutationService($lib);
    $originalRef = (string)$result['logical_ref'];
    $originalStored = $lib->readAs('owner', $originalRef);
    $originalRevision = (int)($originalStored['payload']['metadata']['revision'] ?? 1);

    $updatedMutation = $mutation->execute(
        'owner',
        'modifica memoria ' . $originalRef . ' con mailit.click usa Nginx, CloudFront y PHP-FPM con configuración actualizada.'
    );
    explicit_memory_ok(($updatedMutation['mutation']['status'] ?? null) === 'completed', 'Versioned mutation did not complete');
    explicit_memory_ok(($updatedMutation['mutation']['versioned'] ?? false) === true, 'Versioned mutation flag missing');
    $updatedStored = $lib->readAs('owner', $originalRef);
    explicit_memory_ok(
        (int)($updatedStored['payload']['metadata']['revision'] ?? 0) === $originalRevision + 1,
        'Canonical mutation did not increment revision'
    );
    explicit_memory_ok(
        ($updatedStored['payload']['metadata']['previous_storage_hash'] ?? null) === $originalStored['storage_hash'],
        'Canonical mutation did not preserve previous storage hash'
    );
    explicit_memory_ok(
        str_contains((string)($updatedStored['payload']['content']['content'] ?? ''), 'mailit.click'),
        'Canonical mutation did not persist replacement content'
    );

    // Legacy canonical memories must preserve their rich structure when a
    // conversational anchor such as "ese conocimiento" is updated.
    $legacyRef='memory://user/sistemas/correo/mantenimiento/mailit-click-legacy-versionado';
    $lib->writeAs(
        'owner',
        $legacyRef,
        [
            'title'=>'Mantenimiento de mailit.click',
            'content'=>'mailit.click tiene un pendiente en index.php y pvisit.',
            'classification'=>[
                'category_path'=>['sistemas','correo','mantenimiento'],
                'cognitive_layer'=>'90-projects',
                'scope'=>'project',
                'temperature'=>'hot',
            ],
        ],
        'json','hot','90-projects','project','confirmed'
    );
    $legacyBefore=$lib->readAs('owner',$legacyRef);
    explicit_memory_ok(
        \MCMA\Core\Memory\MemoryMutationService::isMutationRequest(
            'actualiza ese conocimiento con mailit.click ya corrigió pvisit y conserva analytics.'
        ),
        'Contextual knowledge update wording was not recognized'
    );
    $legacyUpdate=$mutation->execute(
        'owner',
        'actualiza ese conocimiento con mailit.click ya corrigió pvisit y conserva analytics.',
        $legacyRef
    );
    $legacyAfter=$lib->readAs('owner',$legacyRef);
    explicit_memory_ok(($legacyUpdate['canonical_memory_ref']??null)===$legacyRef,'Contextual mutation lost canonical memory ref');
    explicit_memory_ok(
        (int)($legacyAfter['payload']['metadata']['revision']??0)===(int)($legacyBefore['payload']['metadata']['revision']??1)+1,
        'Contextual legacy update did not create a new revision'
    );
    explicit_memory_ok(
        ($legacyAfter['payload']['content']['title']??null)==='Mantenimiento de mailit.click',
        'Legacy update destroyed memory title'
    );
    explicit_memory_ok(
        ($legacyAfter['payload']['content']['classification']['category_path']??null)===['sistemas','correo','mantenimiento'],
        'Legacy update destroyed memory classification'
    );
    explicit_memory_ok(
        str_contains((string)($legacyAfter['payload']['content']['content']??''),'corrigió pvisit'),
        'Contextual legacy update did not replace knowledge content'
    );

    $legacyAppend=$mutation->execute(
        'owner',
        'actualiza ese conocimiento y agrega: también se verificaron las pruebas de agentes.',
        $legacyRef
    );
    $legacyAppended=$lib->readAs('owner',$legacyRef);
    explicit_memory_ok(
        str_contains((string)($legacyAppended['payload']['content']['content']??''),'corrigió pvisit')
        && str_contains((string)($legacyAppended['payload']['content']['content']??''),'pruebas de agentes'),
        'Append mutation did not preserve prior knowledge and add the new concept'
    );
    explicit_memory_ok(
        (int)($legacyAppended['payload']['metadata']['revision']??0)===(int)($legacyAfter['payload']['metadata']['revision']??0)+1,
        'Append mutation did not create another revision'
    );

    $deletedMutation = $mutation->execute('owner', 'elimina memoria ' . $originalRef);
    explicit_memory_ok(($deletedMutation['mutation']['action'] ?? null) === 'delete', 'Delete mutation action mismatch');
    $deletedStored = $lib->readAs('owner', $originalRef);
    explicit_memory_ok(
        ($deletedStored['payload']['content']['lifecycle']['status'] ?? null) === 'deleted',
        'Delete mutation did not create a versioned tombstone'
    );
    explicit_memory_ok(
        (int)($deletedStored['payload']['metadata']['revision'] ?? 0) === $originalRevision + 2,
        'Delete mutation did not increment revision'
    );

    echo "MCMA explicit classified memory capture passed.\n";
}finally{
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    explicit_memory_rrmdir($base);
}
