<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Library;
use MCMA\Core\Storage\LocalFilesystemAdapter;

$base=sys_get_temp_dir().'/mcma-permissions-vault-'.bin2hex(random_bytes(4));
$libraryPath=$base.'/library';
$keyDir=$base.'/keys';
putenv('MCMA_KEY_DIR='.$keyDir);
putenv('MCMA_MASTER_KEY_B64');

function rr_permissions(string $dir): void
{
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rr_permissions($path); else @unlink($path);
    }
    @rmdir($dir);
}
function mustFail(callable $fn,string $label): void
{
    try{$fn();throw new RuntimeException('Expected failure: '.$label);}
    catch(Throwable $e){if(str_starts_with($e->getMessage(),'Expected failure:')) throw $e;}
}

try{
    $storage=new LocalFilesystemAdapter($libraryPath);
    $lib=Library::init($storage,'private');
    $written=$lib->writeAs('owner','memory://topics/security-test','hello secure memory');

    $init=$lib->initializeAccessControl();
    if(($init['initialized']??false)!==true) throw new RuntimeException('Access control did not initialize');

    $aiRead=$lib->readAs('ai','memory://topics/security-test');
    if(($aiRead['payload']['content']??null)!=='hello secure memory') throw new RuntimeException('AI permitted read failed');
    mustFail(fn()=> $lib->updateAs('ai','memory://topics/security-test','forbidden'),'AI update');

    $secret="token-".bin2hex(random_bytes(8))."\0binary";
    $secretHash=hash('sha256',$secret);
    $vaultWrite=$lib->vaultPut('api-token',$secret,'api-token','owner');
    if(($vaultWrite['name']??null)!=='api-token') throw new RuntimeException('Vault put failed');

    $metadata=$lib->vaultList('security-agent');
    if(count($metadata)!==1||($metadata[0]['name']??null)!=='api-token') throw new RuntimeException('Vault metadata list failed');
    $metadataJson=json_encode($metadata,JSON_THROW_ON_ERROR);
    if(str_contains($metadataJson,$secret)||str_contains($metadataJson,'secret_b64u')) throw new RuntimeException('Vault metadata leaked secret material');

    mustFail(fn()=> $lib->read('memory://access/vault'),'raw vault read');
    mustFail(fn()=> $lib->readAs('ai','memory://access/vault'),'AI vault read');
    mustFail(fn()=> $lib->useVaultSecret('api-token','ai',fn(string $s)=>hash('sha256',$s)),'AI secret use');

    $usedHash=$lib->useVaultSecret('api-token','security-agent',fn(string $s)=>hash('sha256',$s));
    if(!hash_equals($secretHash,$usedHash)) throw new RuntimeException('Security agent secret-use boundary failed');

    $policy=$lib->permissions('owner');
    if(($policy['default']??null)!=='deny') throw new RuntimeException('Expected deny-by-default policy');
    mustFail(fn()=> $lib->permissions('ai'),'AI permission policy read');

    $policy['resources'][]=['resource'=>'memory://topics/security-test','subject'=>'ai','deny'=>['read']];
    $lib->setPermissions($policy,'owner');
    mustFail(fn()=> $lib->readAs('ai','memory://topics/security-test'),'AI resource deny');

    $vaultEntry=null;
    foreach($lib->list() as $entry){
        if(in_array('memory://access/vault',$entry['logical_refs']??[],true)){$vaultEntry=$entry;break;}
    }
    if($vaultEntry===null) throw new RuntimeException('Vault index entry missing');
    $stored=$storage->get(Library::objectLocator($vaultEntry['storage_hash']))['bytes'];
    $envelope=json_decode($stored,true,512,JSON_THROW_ON_ERROR);
    if(($envelope['protected']['container']??null)!=='vault') throw new RuntimeException('Vault does not use vault container');
    if(($envelope['protected']['crypto']['key_context']??null)!=='vault') throw new RuntimeException('Vault does not use vault key context');
    if(str_contains($stored,$secret)) throw new RuntimeException('Encrypted vault object contains plaintext secret');

    $verify=$lib->verify();
    if(($verify['ok']??false)!==true) throw new RuntimeException('Library verify failed after security operations');

    $lib->vaultDelete('api-token','owner');
    if($lib->vaultList('owner')!==[]) throw new RuntimeException('Vault delete failed');

    echo "MCMA permissions and vault integration passed.\n";
} finally {
    $secret = isset($secret) ? str_repeat("\0",strlen($secret)) : '';
    rr_permissions($base);
}
