<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Agent\SecurityAgent;
use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Storage\LocalFilesystemAdapter;

$base=sys_get_temp_dir().'/mcma-knowledge-'.bin2hex(random_bytes(4));
$libraryPath=$base.'/library';
$keyDir=$base.'/keys';
putenv('MCMA_KEY_DIR='.$keyDir);
putenv('MCMA_MASTER_KEY_B64');

function rr_knowledge(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_knowledge($path); else @unlink($path);
    }
    @rmdir($dir);
}
function assert_true_knowledge(bool $condition,string $message): void
{
    if(!$condition) throw new RuntimeException($message);
}

try{
    $lib=Library::init(new LocalFilesystemAdapter($libraryPath),'private');
    $lib->initializeAccessControl();

    $service=new KnowledgeService($lib);
    $question="¿Qué es MCMA?";
    $provenance=[[
        'source_type'=>'working-test',
        'reference'=>'tests/integration/knowledge-core.php',
        'note'=>'Executable reference implementation test',
    ]];

    $first=$service->capture(
        'librarian',
        $question,
        'MCMA es un archivo modular de memoria cognitiva.',
        'text',
        0.92,
        'supported',
        $provenance,
        'stable',
        3600,
        'reuse-unless-stale'
    );
    assert_true_knowledge(($first['created']??false)===true,'First knowledge capture must create object');

    $reuse=$service->directAnswer('ai',"  ¿qué   es MCMA?  ");
    assert_true_knowledge(($reuse['decision']??null)==='reuse','Supported fresh knowledge should be reused');
    assert_true_knowledge(($reuse['answer']['value']??null)==='MCMA es un archivo modular de memoria cognitiva.','Direct answer content mismatch');
    assert_true_knowledge(count($reuse['provenance']??[])===1,'Direct answer should expose allowed provenance');

    $current=$service->directAnswer('ai',$question,true);
    assert_true_knowledge(($current['decision']??null)==='revalidate','Current-data request must revalidate stable knowledge');
    assert_true_knowledge(!isset($current['answer']),'Revalidation path must not return remembered answer');

    $stale=$service->directAnswer('ai',$question,false,0.75,time()+7200);
    assert_true_knowledge(($stale['decision']??null)==='revalidate','Stale knowledge must revalidate');
    assert_true_knowledge(in_array('stale',$stale['reasons']??[],true),'Stale reason missing');

    $second=$service->capture(
        'librarian',
        $question,
        'MCMA es memoria portable, cifrada y controlada por el usuario.',
        'text',
        0.96,
        'verified',
        $provenance,
        'stable',
        86400,
        'reuse-unless-stale'
    );
    assert_true_knowledge(($second['created']??true)===false,'Second capture should revise existing knowledge');
    assert_true_knowledge(hash_equals($first['object_id'],$second['object_id']),'Knowledge correction must preserve object_id');
    assert_true_knowledge(!hash_equals($first['storage_hash'],$second['storage_hash']),'Knowledge correction must create new encrypted revision');

    $validated=$service->validateKnowledge(
        'librarian',
        $question,
        'verified',
        0.99,
        'Integration test confirms the current implementation.',
        [[
            'source_type'=>'working-test',
            'reference'=>'github-actions:knowledge-core',
        ]]
    );
    assert_true_knowledge(($validated['validation_state']??null)==='verified','Knowledge validation state update failed');

    $inspection=$service->inspect('owner',$question);
    $history=$inspection['record']['epistemic']['history']??[];
    assert_true_knowledge(count($history)>=2,'Validation history transition was not preserved');
    assert_true_knowledge(($inspection['record']['epistemic']['evidence_count']??0)>=2,'Additional provenance evidence was not counted');

    $edited=$service->replaceAnswerId(
        'owner',
        substr((string)$second['logical_ref'],strlen('memory://knowledge/q-')),
        'MCMA es memoria corregida directamente por el usuario desde Biblioteca.',
        [[
            'source_type'=>'user',
            'reference'=>'web-biblioteca-inline-edit',
            'note'=>'Owner corrected this answer directly in Biblioteca',
        ]]
    );
    assert_true_knowledge(($edited['validation_state']??null)==='verified','Direct Biblioteca edit did not verify Knowledge');
    assert_true_knowledge(abs((float)($edited['confidence']??0)-0.95)<1e-12,'Direct Biblioteca edit confidence mismatch');
    assert_true_knowledge(($edited['temperature']??null)==='warm','Direct Biblioteca edit temperature mismatch');
    assert_true_knowledge(($edited['freshness_class']??null)==='stable','Direct Biblioteca edit freshness mismatch');
    $editedInspection=$service->inspect('owner',$question);
    assert_true_knowledge(
        ($editedInspection['record']['answer']['value']??null)==='MCMA es memoria corregida directamente por el usuario desde Biblioteca.',
        'Direct Biblioteca edit did not replace the Knowledge answer'
    );
    assert_true_knowledge(
        ($editedInspection['record']['epistemic']['validation_state']??null)==='verified'
        &&abs((float)($editedInspection['record']['epistemic']['confidence']??0)-0.95)<1e-12,
        'Direct Biblioteca edit did not persist owner trust metadata'
    );
    assert_true_knowledge(
        ($editedInspection['record']['freshness']['class']??null)==='stable'
        &&($editedInspection['record']['freshness']['reuse_policy']??null)==='reuse-unless-stale',
        'Direct Biblioteca edit did not persist stable reusable freshness'
    );

    $service->validateKnowledge('librarian',$question,'disputed',0.40,'Contradictory evidence requires review.');
    $disputed=$service->directAnswer('ai',$question);
    assert_true_knowledge(($disputed['decision']??null)==='reject','Disputed knowledge must be rejected');
    assert_true_knowledge(!isset($disputed['answer']),'Rejected knowledge must not expose direct answer');

    $miss=$service->directAnswer('ai','Una pregunta nunca guardada');
    assert_true_knowledge(($miss['decision']??null)==='miss','Unknown intent must return miss');

    $old=KnowledgeRecord::create(
        'Dato antiguo',
        'valor',
        'text',
        0.95,
        'verified',
        [['source_type'=>'documentation','reference'=>'example-doc','captured_at'=>'2026-01-01T00:00:00Z']],
        'dynamic',
        60,
        'revalidate-if-stale',
        [],
        '2026-01-01T00:00:00Z'
    );
    $oldAssessment=KnowledgeRecord::assess($old,false,0.75,strtotime('2026-01-01T00:02:00Z'));
    assert_true_knowledge(($oldAssessment['decision']??null)==='revalidate','Deterministic stale assessment failed');

    $immutable=KnowledgeRecord::create(
        'Dos más dos',
        '4',
        'text',
        1.0,
        'verified',
        [['source_type'=>'working-test','reference'=>'arithmetic']],
        'immutable',
        null,
        'reuse-unless-stale'
    );
    $immutableAssessment=KnowledgeRecord::assess($immutable,true,0.95,time()+1000000);
    assert_true_knowledge(($immutableAssessment['decision']??null)==='reuse','Immutable verified knowledge should survive current-data requirement');

    $librarian=new Librarian($service);
    $agentCapture=$librarian->remember('¿Qué capa prueba el agente bibliotecario?','Knowledge Core',[
        'confidence'=>0.90,
        'validation_state'=>'supported',
        'provenance'=>[['source_type'=>'working-test','reference'=>'librarian-agent-test']],
        'freshness_class'=>'stable',
        'max_age_seconds'=>3600,
    ]);
    assert_true_knowledge(isset($agentCapture['object_id']),'Librarian agent failed to remember knowledge');

    $lib->vaultPut('service-token','agent-secret','api-token','owner');
    $security=new SecurityAgent($lib);
    $metadata=$security->vaultMetadata();
    assert_true_knowledge(count($metadata)===1,'SecurityAgent vault metadata failed');
    $secretHash=$security->useSecret('service-token',fn(string $secret)=>hash('sha256',$secret));
    assert_true_knowledge(hash_equals(hash('sha256','agent-secret'),$secretHash),'SecurityAgent trusted secret use failed');

    $verify=$lib->verify();
    assert_true_knowledge(($verify['ok']??false)===true,'Library verify failed after knowledge/agent operations');

    echo "MCMA knowledge reuse and deterministic agents integration passed.\n";
} finally {
    rr_knowledge($base);
}
