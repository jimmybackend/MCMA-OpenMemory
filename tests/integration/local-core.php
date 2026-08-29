<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\KeyStore;
use MCMA\Core\LocalLibrary;

$base=sys_get_temp_dir().'/mcma-local-core-'.bin2hex(random_bytes(4));
$libraryPath=$base.'/library'; $keyDir=$base.'/keys'; $recoveryFile=$base.'/recovery.mcma-key';
putenv('MCMA_KEY_DIR='.$keyDir); putenv('MCMA_MASTER_KEY_B64');

function rrmdir(string $dir): void {
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

try{
    $lib=LocalLibrary::init($libraryPath,'private'); $libraryId=$lib->libraryId();
    if($lib->verify()['objects_verified']!==0) throw new RuntimeException('Expected empty library');

    $write=$lib->write('memory://topics/integration-test','hello MCMA 1.0','text','hot','40-semantic','global','confirmed');
    $objectId=$write['object_id']; $hash1=$write['storage_hash'];

    $update=$lib->update('memory://topics/integration-test','hello MCMA 1.0 revision 2');
    if($update['object_id']!==$objectId) throw new RuntimeException('Update changed object_id');
    if($update['storage_hash']===$hash1) throw new RuntimeException('Update did not create a new storage revision');
    if($update['previous_storage_hash']!==$hash1) throw new RuntimeException('Update revision chain mismatch');

    $hash2=$update['storage_hash'];
    $temperature=$lib->setTemperature('memory://topics/integration-test','frozen');
    if($temperature['object_id']!==$objectId) throw new RuntimeException('Temperature transition changed object_id');
    if($temperature['storage_hash']===$hash2) throw new RuntimeException('Temperature transition did not create a new storage revision');

    $reopened=LocalLibrary::open($libraryPath);
    $read=$reopened->read('memory://topics/integration-test');
    if(($read['payload']['content']??null)!=='hello MCMA 1.0 revision 2') throw new RuntimeException('Read mismatch');
    if(($read['payload']['metadata']['temperature']??null)!=='frozen') throw new RuntimeException('Temperature mismatch');
    if(($read['payload']['metadata']['revision']??null)!==3) throw new RuntimeException('Revision number mismatch');
    if($reopened->verify()['objects_verified']!==1) throw new RuntimeException('Expected one verified current object');

    KeyStore::exportRecovery($libraryId,$recoveryFile,'correct-horse-battery-staple');
    $keyPath=KeyStore::keyPath($libraryId); if(!unlink($keyPath)) throw new RuntimeException('Unable to remove local key for recovery test');

    try{ LocalLibrary::open($libraryPath); throw new RuntimeException('Library opened without a key'); }
    catch(Throwable $e){ if(str_contains($e->getMessage(),'Library opened without a key')) throw $e; }

    $import=KeyStore::importRecovery($recoveryFile,'correct-horse-battery-staple');
    if($import['library_id']!==$libraryId) throw new RuntimeException('Recovered library_id mismatch');
    $recovered=LocalLibrary::open($libraryPath);
    if(($recovered->read('memory://topics/integration-test')['payload']['content']??null)!=='hello MCMA 1.0 revision 2') throw new RuntimeException('Recovered key could not decrypt library');

    echo "MCMA local-core hardening integration passed.\n";
}finally{ rrmdir($base); }
