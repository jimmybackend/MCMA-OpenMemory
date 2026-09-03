<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Ask\AskService;
use MCMA\Core\Ask\GenerationProvider;
use MCMA\Core\Context\BroadMemoryRecallBuilder;
use MCMA\Core\Context\ConversationContextBuilder;
use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Interaction\InteractionCatalogService;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class InteractionCatalogGenerationProvider implements GenerationProvider
{
    public int $calls=0;

    public function id(): string { return 'test:interaction-catalog:v1'; }

    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        if(!is_string($context['system_instructions']??null)||!str_contains($context['system_instructions'],'cognitive library cataloger')){
            throw new RuntimeException('Interaction cataloger system instructions missing');
        }

        return [
            'text'=>json_encode([
                'title'=>'Arquitectura de memoria MCMA',
                'topics'=>['IA','memoria artificial'],
                'projects'=>['MCMA'],
                'people'=>['Jimmy'],
                'characters'=>[],
                'entities'=>['MCMA-OpenMemory'],
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'usage'=>['inputTokens'=>20,'outputTokens'=>30,'totalTokens'=>50],
        ];
    }
}

final class ConversationContextGenerationProvider implements GenerationProvider
{
    public array $lastContext=[];
    public int $calls=0;

    public function id(): string { return 'test:conversation-context:v1'; }

    public function generate(string $question,array $context=[]): array
    {
        $this->calls++;
        $this->lastContext=$context;
        return ['text'=>'Respuesta usando contexto conversacional seleccionado.'];
    }
}

final class InteractionCatalogEmbeddingProvider implements EmbeddingProvider
{
    public int $calls=0;
    public function id(): string { return 'test:interaction-library-embedding:v1:dimensions=3'; }
    public function embed(string $text): array
    {
        $this->calls++;
        return [1.0,0.0,0.0];
    }
}

function interaction_ok(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function interaction_rrmdir(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) interaction_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function tree_has_ref(array $node,string $ref): bool
{
    if(($node['@ref']??null)===$ref) return true;
    foreach($node as $key=>$value){
        if(str_starts_with((string)$key,'@')||!is_array($value)) continue;
        if(tree_has_ref($value,$ref)) return true;
    }
    return false;
}

$base=sys_get_temp_dir().'/mcma-interaction-library-'.bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR='.$base.'/keys');
putenv('MCMA_MASTER_KEY_B64');

try{
    $lib=Library::init(new LocalFilesystemAdapter($base.'/library'),'private');
    $lib->initializeAccessControl();

    $lib->writeAs(
        'owner',
        'memory://user/proyectos/mcma/arquitectura-base',
        ['title'=>'Arquitectura base','content'=>'MCMA usa objetos cifrados.','classification'=>['category_path'=>['proyectos','mcma']]],
        'json','hot','90-projects','project','confirmed'
    );

    $archive=new InteractionArchiveService($lib);
    $conversation='conv_'.str_repeat('a',32);
    $request='req_'.str_repeat('b',32);
    $question='Jimmy pregunta cómo organizar la memoria artificial del proyecto MCMA.';

    $stored=$archive->archive('owner',$request,$conversation,$question,[
        'route'=>'provider',
        'provider_called'=>true,
        'provider_id'=>'test:nova',
        'answer'=>['format'=>'text','value'=>'MCMA puede organizar memoria cifrada como una biblioteca cognitiva.'],
        'stored'=>false,
        'billing'=>['credit_units_charged'=>12,'usage'=>['total_tokens'=>12]],
    ]);
    $ref=(string)$stored['logical_ref'];
    interaction_ok(str_starts_with($ref,'memory://interactions/'),'Interaction canonical ref missing');
    interaction_ok(($stored['conversation_id']??null)===$conversation,'Conversation id was not preserved');

    $read=$archive->read('owner',$ref);
    interaction_ok(($read['interaction']['question']??null)===$question,'Archived question mismatch');
    interaction_ok(($read['interaction']['answer']['value']??null)==='MCMA puede organizar memoria cifrada como una biblioteca cognitiva.','Archived answer mismatch');
    interaction_ok(($read['interaction']['validation']['state']??null)==='unverified','New interaction should be unverified');

    $byRequest=$archive->interactionByRequestId('owner',$conversation,$request);
    interaction_ok(is_array($byRequest),'Request-id lookup did not recover archived interaction');
    interaction_ok(($byRequest['logical_ref']??null)===$ref,'Request-id lookup returned wrong interaction');

    $archiveSearch=$archive->search('owner','MCMA',5);
    interaction_ok(($archiveSearch['total_matches']??0)>=1,'Archived interaction text search did not find MCMA');
    interaction_ok(($archiveSearch['ai_tokens_used']??-1)===0,'Archived interaction search used AI tokens');

    for($i=0;$i<52;$i++){
        $archive->archive(
            'owner',
            'req_'.str_pad(dechex($i+1),32,'0',STR_PAD_LEFT),
            $conversation,
            'Pregunta histórica '.($i+1),
            [
                'route'=>'memory-exact',
                'provider_called'=>false,
                'answer'=>['format'=>'text','value'=>'Respuesta histórica '.($i+1)],
                'stored'=>false,
            ]
        );
    }

    $beforeTree=$archive->libraryTree('owner');
    interaction_ok(($beforeTree['interaction_total']??0)===53,'Persistent interaction archive should not be capped at 50');
    interaction_ok(($beforeTree['ai_tokens_used']??-1)===0,'Library tree read used AI tokens');
    interaction_ok(tree_has_ref($beforeTree['tree'],$ref),'Library tree does not reference archived interaction');
    interaction_ok(isset($beforeTree['tree']['Memoria personal']['proyectos']),'Personal memory branch missing from cognitive library');
    interaction_ok(isset($beforeTree['tree']['Conversaciones']['Por sesión']),'Conversation session view missing');
    interaction_ok(isset($beforeTree['tree']['Conversaciones']['Por fecha']),'Conversation date view missing');
    interaction_ok(isset($beforeTree['tree']['Knowledge']),'Knowledge shelf missing');

    $conversationList=$archive->conversations('owner');
    interaction_ok(($conversationList['total']??0)===1,'Conversation index should group 53 turns into one conversation');
    interaction_ok(($conversationList['ai_tokens_used']??-1)===0,'Conversation list used AI tokens');
    interaction_ok(($conversationList['credit_units_charged']??-1)===0,'Conversation list charged credits');
    interaction_ok(($conversationList['conversations'][0]['conversation_id']??null)===$conversation,'Conversation list id mismatch');
    interaction_ok(($conversationList['conversations'][0]['interaction_count']??0)===53,'Conversation turn count mismatch');

    $conversationDetail=$archive->conversation('owner',$conversation);
    interaction_ok(count($conversationDetail['interactions']??[])===53,'Conversation detail did not return all canonical interactions');
    interaction_ok(($conversationDetail['ai_tokens_used']??-1)===0,'Conversation detail used AI tokens');
    interaction_ok(($conversationDetail['credit_units_charged']??-1)===0,'Conversation detail charged credits');
    $detailRefs=array_map(static fn(array $item): string => (string)($item['logical_ref']??''),$conversationDetail['interactions']);
    interaction_ok(in_array($ref,$detailRefs,true),'Conversation detail lost canonical interaction ref');

    $contextConversation='conv_'.str_repeat('e',32);
    $contextRefs=[];
    $contextTurns=[
        ['Configuramos nginx y el certificado TLS en mailit.click.','El certificado TLS quedó asociado al virtual host de nginx.'],
        ['También hablamos de una receta de cocina.','La receta no está relacionada con el servidor.'],
        ['El servidor web atiende HTTPS en el puerto 443.','El puerto 443 quedó documentado.'],
        ['Después revisamos el despliegue de MCMA.','El despliegue seguía estable.'],
        ['El último paso fue reiniciar php-fpm-mcma.','El servicio quedó listo para conexiones.'],
    ];
    foreach($contextTurns as $i=>$turn){
        $contextRefs[]=(string)$archive->archive(
            'owner',
            'req_'.str_pad(dechex(0x100+$i),32,'0',STR_PAD_LEFT),
            $contextConversation,
            $turn[0],
            [
                'route'=>'provider',
                'provider_called'=>true,
                'provider_id'=>'test:nova',
                'answer'=>['format'=>'text','value'=>$turn[1]],
                'stored'=>false,
            ]
        )['logical_ref'];
    }

    $policy=$lib->permissions('owner');
    $policy['resources'][]=[
        'resource'=>$contextRefs[4],
        'subject'=>'ai',
        'deny'=>['read'],
    ];
    $lib->setPermissions($policy,'owner');

    $contextBuilder=new ConversationContextBuilder($lib,2200,3,5,0.20,2);
    $selectedContext=$contextBuilder->build(
        'ai',$contextConversation,'¿Qué hicimos con nginx y el certificado TLS?'
    );
    interaction_ok(is_array($selectedContext),'Conversation context builder returned no context');
    interaction_ok(($selectedContext['selection']['selected_turns']??0)<=3,'Conversation context exceeded max turns');
    interaction_ok(($selectedContext['selection']['estimated_tokens_upper_bound']??999999)<=2200,'Conversation context exceeded token budget');
    $selectedRefs=array_map(static fn(array $turn): string => (string)($turn['logical_ref']??''),$selectedContext['turns']??[]);
    interaction_ok(!in_array($contextRefs[4],$selectedRefs,true),'Conversation context bypassed ai read permission');
    interaction_ok(in_array($contextRefs[0],$selectedRefs,true),'Relevant older nginx/TLS turn was not selected');
    interaction_ok(count($selectedRefs)<count($contextTurns),'Conversation context injected the full history');

    $conversationGenerator=new ConversationContextGenerationProvider();
    $conversationAsk=new AskService(
        new KnowledgeService($lib),
        null,
        null,
        $conversationGenerator,
        null,
        $contextBuilder
    );
    $conversationResult=$conversationAsk->ask(
        'ai',
        '¿Qué hicimos con nginx y el certificado TLS?',
        false,
        0.75,
        0.78,
        5,
        false,
        [],
        null,
        null,
        $contextConversation
    );
    interaction_ok($conversationGenerator->calls===1,'Conversation-aware Ask did not call generation');
    interaction_ok(is_array($conversationGenerator->lastContext['conversation_context']??null),'AskService did not pass selected conversation context');
    interaction_ok(($conversationResult['context_used']['conversation']??false)===true,'Ask result did not report conversation context use');
    interaction_ok(count($conversationResult['context_used']['conversation_context']['turns']??[])===count($selectedRefs),'Context transparency did not preserve selected turns');

    $generator=new InteractionCatalogGenerationProvider();
    $embedding=new InteractionCatalogEmbeddingProvider();
    $approved=$archive->validate(
        'owner',$ref,'approve',new InteractionCatalogService($generator),$embedding
    );
    interaction_ok(($approved['validation_state']??null)==='verified','Approved interaction was not verified');
    interaction_ok(($approved['catalog']['topics'][0]??null)==='IA','Approval catalog topic missing');
    interaction_ok(($approved['catalog']['projects'][0]??null)==='MCMA','Approval catalog project missing');
    interaction_ok(($approved['catalog']['people'][0]??null)==='Jimmy','Approval catalog person missing');
    interaction_ok($generator->calls===1,'Approval should classify exactly once');

    $knowledge=new KnowledgeService($lib);
    $reuse=$knowledge->directAnswer('owner',$question);
    interaction_ok(($reuse['decision']??null)==='reuse','Approved interaction did not become reusable knowledge');
    interaction_ok(($reuse['answer']['value']??null)==='MCMA puede organizar memoria cifrada como una biblioteca cognitiva.','Approved knowledge answer mismatch');

    $knowledge->capture(
        'librarian',
        '¿Cuál es la configuración de mailit.click?',
        'mailit.click usa Nginx, CloudFront, PHP-FPM y memoria cifrada MCMA.',
        'text',
        0.95,
        'verified',
        [['source_type'=>'user','reference'=>'memory://user/configuraciones/mailit-click']],
        'dynamic',
        2592000,
        'reuse-unless-stale',
        []
    );
    $broadRecall=(new BroadMemoryRecallBuilder($lib,8,16000))->build('ai','¿Qué sabes de mailit.click?',0.75);
    interaction_ok(is_array($broadRecall),'Broad memory recall returned no context for mailit.click');
    interaction_ok(($broadRecall['subject']??null)==='mailit.click','Broad memory recall subject mismatch');
    interaction_ok(count($broadRecall['items']??[])>=1,'Broad memory recall selected no memories');
    interaction_ok(
        str_contains((string)($broadRecall['items'][0]['answer']??''),'mailit.click'),
        'Broad memory recall did not return the stored mailit.click memory'
    );

    $afterTree=$archive->libraryTree('owner');
    interaction_ok(isset($afterTree['tree']['Conversaciones']['Por temas']['IA']),'Topic view missing approved interaction');
    interaction_ok(isset($afterTree['tree']['Conversaciones']['Por proyectos']['MCMA']),'Project view missing approved interaction');
    interaction_ok(isset($afterTree['tree']['Conversaciones']['Por personas']['Jimmy']),'People view missing approved interaction');
    interaction_ok(isset($afterTree['tree']['Conversaciones']['Por estado']['verified']),'Verified interaction view missing');
    interaction_ok(($afterTree['knowledge_total']??0)>=1,'Knowledge shelf did not include approved interaction');

    $discardRef=(string)$archive->archive(
        'owner','req_'.str_repeat('c',32),'conv_'.str_repeat('d',32),
        'Respuesta que no debe convertirse en conocimiento.',
        [
            'route'=>'provider','provider_called'=>true,'provider_id'=>'test:nova',
            'answer'=>['format'=>'text','value'=>'Contenido rechazado.'],'stored'=>false,
        ]
    )['logical_ref'];
    $discarded=$archive->validate('owner',$discardRef,'discard');
    interaction_ok(($discarded['validation_state']??null)==='retracted','Discarded interaction was not retracted');

    $afterSecondConversation=$archive->conversations('owner');
    interaction_ok(($afterSecondConversation['total']??0)===3,'Expected original, context-selection and discarded conversations in encrypted index');
    interaction_ok(($afterSecondConversation['ai_tokens_used']??-1)===0,'Updated conversation index browse used AI tokens');

    $verify=$lib->verify();
    interaction_ok(($verify['ok']??false)===true,'Library verify failed after interaction archive operations');

    echo "MCMA persistent cognitive interaction library integration passed.\n";
} finally {
    interaction_rrmdir($base);
}
