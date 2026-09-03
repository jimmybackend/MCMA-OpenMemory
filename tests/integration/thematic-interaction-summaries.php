<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Interaction\InteractionArchiveService;
use MCMA\Core\Interaction\InteractionCatalogService;
use MCMA\Core\Interaction\ThematicSummaryService;
use MCMA\Core\Library;
use MCMA\Core\Storage\LocalFilesystemAdapter;

function thematic_ok(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

function thematic_rrmdir(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) thematic_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function thematic_refs(Library $lib,string $actor='owner'): array
{
    $refs=[];
    foreach($lib->listAs($actor) as $entry){
        foreach($entry['logical_refs']??[] as $ref){
            if(is_string($ref)&&str_starts_with($ref,'memory://user/temas/')&&str_contains($ref,'/resumenes/')){
                $refs[]=$ref;
            }
        }
    }
    sort($refs,SORT_STRING);
    return array_values(array_unique($refs));
}

$base=sys_get_temp_dir().'/mcma-thematic-summary-'.bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR='.$base.'/keys');
putenv('MCMA_MASTER_KEY_B64');

try{
    $libA=Library::init(new LocalFilesystemAdapter($base.'/user-a'),'private');
    $libA->initializeAccessControl();

    $libB=Library::init(new LocalFilesystemAdapter($base.'/user-b'),'private');
    $libB->initializeAccessControl();

    $archive=new InteractionArchiveService($libA);
    $conversation='conv_'.str_repeat('a',32);
    $request='req_'.str_repeat('b',32);

    $question='¿Qué configuración hemos hecho en mailit.click?';
    $stored=$archive->archive(
        'owner',
        $request,
        $conversation,
        $question,
        [
            'route'=>'provider',
            'provider_called'=>true,
            'provider_id'=>'test:nova',
            'answer'=>[
                'format'=>'text',
                'value'=>'En mailit.click se documentó la configuración de MCMA y su despliegue.',
            ],
            'stored'=>false,
            'billing'=>[
                'credit_units_charged'=>10,
                'usage'=>['total_tokens'=>10],
                'provider_usage'=>[],
            ],
        ]
    );

    $interactionRef=(string)$stored['logical_ref'];
    thematic_ok(str_starts_with($interactionRef,'memory://interactions/'),'Canonical interaction was not archived');

    $canonicalRefs=[];
    foreach($libA->listAs('owner') as $entry){
        foreach($entry['logical_refs']??[] as $ref){
            if(is_string($ref)&&str_starts_with($ref,'memory://interactions/')) $canonicalRefs[]=$ref;
        }
    }
    thematic_ok(count($canonicalRefs)===1,'Interaction transcript was archived more than once');

    $summaries=thematic_refs($libA);
    thematic_ok(count($summaries)>=1,'No thematic summary was created');
    $summaryRef=$summaries[0];
    thematic_ok(str_starts_with($summaryRef,'memory://user/temas/'),'Summary is not stored under personal thematic memory');
    thematic_ok(str_contains($summaryRef,'/resumenes/'.$request),'Summary path is not deterministic by interaction id');

    $first=$libA->readAs('owner',$summaryRef);
    $payload=$first['payload']['content']??null;
    thematic_ok(is_array($payload),'Thematic summary payload is malformed');
    thematic_ok(($payload['interaction_ref']??null)===$interactionRef,'Summary lost canonical interaction reference');
    thematic_ok(($payload['conversation_id']??null)===$conversation,'Summary conversation id mismatch');
    thematic_ok(($payload['interaction_id']??null)===$request,'Summary interaction id mismatch');
    thematic_ok(($payload['request_id']??null)===$request,'Summary request id mismatch');
    thematic_ok(!array_key_exists('question',$payload),'Summary duplicated full question field');
    thematic_ok(!array_key_exists('answer',$payload),'Summary duplicated full answer field');
    thematic_ok(is_string($payload['summary']??null)&&trim((string)$payload['summary'])!=='','Summary text missing');
    thematic_ok(!str_contains((string)$payload['summary'],$question),'Summary reconstructed the full canonical question');
    thematic_ok(($payload['title']??null)!==$question,'Provisional thematic title duplicated the full canonical question');
    thematic_ok(($payload['validation']['state']??null)==='unverified','Initial summary validation state mismatch');
    thematic_ok(($payload['validation']['trusted_for_conclusions']??true)===false,'Unverified summary became trusted for conclusions');

    $archiveRead=$archive->read('owner',$interactionRef);
    $sync=(new ThematicSummaryService($libA))->sync(
        'owner',$interactionRef,$archiveRead['interaction'],$archiveRead['storage_hash']
    );
    thematic_ok(($sync['ai_tokens_used']??-1)===0,'Deterministic thematic sync used AI tokens');
    thematic_ok(($sync['provider_usage']??null)===[],'Deterministic thematic sync reported hidden provider usage');
    thematic_ok(count(thematic_refs($libA))===count($summaries),'Reprocessing duplicated thematic summary');
    $actions=array_column($sync['summaries']??[],'action');
    thematic_ok(in_array('unchanged',$actions,true),'Idempotent reprocessing created an unnecessary revision');

    $approved=$archive->validate(
        'owner',$interactionRef,'approve',new InteractionCatalogService(null),null
    );
    thematic_ok(($approved['validation_state']??null)==='verified','Interaction approval failed');

    $afterApprove=$libA->readAs('owner',$summaryRef);
    $approvedPayload=$afterApprove['payload']['content']??[];
    thematic_ok(($approvedPayload['validation']['state']??null)==='verified','Summary was not updated to verified');
    thematic_ok(($approvedPayload['validation']['trusted_for_conclusions']??false)===true,'Verified summary was not enabled for future conclusions');
    thematic_ok(($afterApprove['payload']['metadata']['maturity']??null)==='confirmed','Verified summary metadata maturity is not confirmed');
    thematic_ok(
        (int)($afterApprove['payload']['metadata']['revision']??0)>(int)($first['payload']['metadata']['revision']??0),
        'Approval did not version the thematic summary'
    );
    thematic_ok(
        ($afterApprove['payload']['metadata']['previous_storage_hash']??null)===$first['storage_hash'],
        'Approval revision did not preserve previous_storage_hash'
    );

    $discarded=$archive->validate('owner',$interactionRef,'discard');
    thematic_ok(($discarded['validation_state']??null)==='retracted','Interaction discard failed');

    $afterDiscard=$libA->readAs('owner',$summaryRef);
    $discardedPayload=$afterDiscard['payload']['content']??[];
    thematic_ok(($discardedPayload['validation']['state']??null)==='retracted','Retracted interaction did not update thematic summary');
    thematic_ok(($discardedPayload['validation']['trusted_for_conclusions']??true)===false,'Retracted summary remained trusted for conclusions');

    $isolated=false;
    try{
        $libB->readAs('owner',$summaryRef);
    }catch(Throwable){
        $isolated=true;
    }
    thematic_ok($isolated,'User B could read User A thematic summary');

    $tree=$archive->libraryTree('owner');
    thematic_ok(isset($tree['tree']['Memoria personal']['temas']),'Library did not expose personal thematic folder');
    thematic_ok(isset($tree['tree']['Memoria personal']['temas']['mailit-click']),'mailit.click thematic folder missing from Library');
    thematic_ok(isset($tree['tree']['Memoria personal']['temas']['mailit-click']['resumenes']),'Thematic summaries folder missing from Library');

    thematic_ok(($libA->verify()['ok']??false)===true,'User A library verify failed');
    thematic_ok(($libB->verify()['ok']??false)===true,'User B library verify failed');

    echo "MCMA thematic interaction summaries integration passed.\n";
} finally {
    thematic_rrmdir($base);
}
