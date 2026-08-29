<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\HistoricalCrypto;
use MCMA\Core\LocalLibrary;

$base=sys_get_temp_dir().'/mcma-migrate-'.bin2hex(random_bytes(4));
$libraryPath=$base.'/library'; $keyDir=$base.'/keys'; $historicalFile=$base.'/historical.mcma';
putenv('MCMA_KEY_DIR='.$keyDir); putenv('MCMA_MASTER_KEY_B64');

function rrmdir_migration(string $dir): void {
    if(!is_dir($dir)) return;
    foreach(scandir($dir)?:[] as $item){
        if($item==='.'||$item==='..') continue;
        $path=$dir.DIRECTORY_SEPARATOR.$item;
        if(is_dir($path)) rrmdir_migration($path); else @unlink($path);
    }
    @rmdir($dir);
}
function makeHistoricalV2(string $masterKey,string $plaintext): array {
    $path='memories/hot/manual'; $file='synthetic-history.mcma'; $version='mcma-key-v2';
    $identity=$version."\n".$path.'/'.$file; $key=hash_hkdf('sha256',$masterKey,32,$identity,'MCMA');
    $keyId=substr(hash('sha256',$identity),0,16); $aad='MCMA2|'.$keyId.'|'.$path.'|'.$file;
    $iv=hex2bin('000102030405060708090a0b'); $tag='';
    $ciphertext=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
    if($ciphertext===false) throw new RuntimeException('Unable to build synthetic V2 fixture');
    return ['format'=>'mcma-v2','cipher'=>'AES-256-GCM','kdf'=>'HKDF-SHA256','key_version'=>$version,'key_id'=>$keyId,'logical_path'=>$path,'file'=>$file,'temperature'=>'hot','created_at'=>'2026-08-29T00:00:00Z','iv_b64'=>base64_encode($iv),'tag_b64'=>base64_encode($tag),'ciphertext_b64'=>base64_encode($ciphertext)];
}

try{
    if(!mkdir($base,0700,true)&&!is_dir($base)) throw new RuntimeException('Unable to create temp directory');
    $legacyMaster=hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
    $historical=makeHistoricalV2($legacyMaster,'historical MCMA content');
    file_put_contents($historicalFile,json_encode($historical,JSON_UNESCAPED_SLASHES));

    $decoded=HistoricalCrypto::readAndDecrypt($historicalFile,$legacyMaster);
    if($decoded['plaintext']!=='historical MCMA content') throw new RuntimeException('Historical decrypt mismatch');

    $lib=LocalLibrary::init($libraryPath,'private');
    $result=$lib->importHistorical('memory://topics/migrated-history',$decoded['plaintext'],$decoded['envelope'],'text','cold','40-semantic','global','observed','synthetic-history.mcma');
    if(($result['source_format']??null)!=='mcma-v2') throw new RuntimeException('Migration source format mismatch');
    if(!file_exists($historicalFile)) throw new RuntimeException('Migration deleted historical source');

    $read=$lib->read('memory://topics/migrated-history');
    if(($read['payload']['content']??null)!=='historical MCMA content') throw new RuntimeException('Migrated content mismatch');
    if(($read['payload']['metadata']['provenance'][0]['source_format']??null)!=='mcma-v2') throw new RuntimeException('Migration provenance missing');

    try{
        $lib->importHistorical('memory://topics/migrated-history-copy',$decoded['plaintext'],$decoded['envelope']);
        throw new RuntimeException('Duplicate historical migration was accepted');
    }catch(Throwable $e){
        if(str_contains($e->getMessage(),'Duplicate historical migration was accepted')) throw $e;
    }

    $lib->verify();
    echo "MCMA historical migration integration passed.\n";
}finally{ rrmdir_migration($base); }
