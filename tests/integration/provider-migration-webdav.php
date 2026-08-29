<?php
declare(strict_types=1);

require_once __DIR__.'/../../packages/core/src/Storage/StorageAdapter.php';
require_once __DIR__.'/../../packages/core/src/Storage/LocalFilesystemAdapter.php';
require_once __DIR__.'/../../packages/core/src/Storage/WebDavStorageAdapter.php';
require_once __DIR__.'/../../packages/core/src/Storage/StorageMigrator.php';

use MCMA\Core\Storage\LocalFilesystemAdapter;
use MCMA\Core\Storage\WebDavStorageAdapter;
use MCMA\Core\Storage\StorageMigrator;

$files=[]; $dirs=['/dav/mcma/' => true];
$requester=function(string $method,string $url,array $headers,string $body) use (&$files,&$dirs): array {
    $path=rawurldecode((string)parse_url($url,PHP_URL_PATH));
    if($method==='MKCOL'){ $dirs[rtrim($path,'/').'/']=true; return [201,'',[]]; }
    if($method==='HEAD'){
        if(isset($files[$path])) return [200,'',['etag'=>'"'.$files[$path]['etag'].'"']];
        if(isset($dirs[rtrim($path,'/').'/'])) return [200,'',[]];
        return [404,'',[]];
    }
    if($method==='GET') return isset($files[$path]) ? [200,$files[$path]['bytes'],['etag'=>'"'.$files[$path]['etag'].'"']] : [404,'',[]];
    if($method==='PUT'){
        if(($headers['if-none-match']??null)==='*' && isset($files[$path])) return [412,'',[]];
        $etag=md5($body); $files[$path]=['bytes'=>$body,'etag'=>$etag]; return [201,'',['etag'=>'"'.$etag.'"']];
    }
    if($method==='PROPFIND'){
        $collection=rtrim($path,'/').'/'; if(!isset($dirs[$collection])) return [404,'',[]];
        $xml='<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
        $xml.='<d:response><d:href>'.$collection.'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
        foreach($dirs as $dir=>$_){ if($dir===$collection) continue; if(rtrim(dirname(rtrim($dir,'/')),'/').'/'===$collection) $xml.='<d:response><d:href>'.$dir.'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>'; }
        foreach($files as $file=>$v){ if(rtrim(dirname($file),'/').'/'===$collection) $xml.='<d:response><d:href>'.$file.'</d:href><d:propstat><d:prop><d:resourcetype/></d:prop></d:propstat></d:response>'; }
        $xml.='</d:multistatus>'; return [207,$xml,[]];
    }
    return [405,'',[]];
};

$base=sys_get_temp_dir().'/mcma-webdav-migration-'.bin2hex(random_bytes(4)); $src=$base.'/src'; $dst=$base.'/dst';
function rr_webdav(string $d):void{if(!is_dir($d))return;foreach(scandir($d)?:[] as $i){if($i==='.'||$i==='..')continue;$p=$d.DIRECTORY_SEPARATOR.$i;if(is_dir($p))rr_webdav($p);else@unlink($p);}@rmdir($d);}
try{
    $local=new LocalFilesystemAdapter($src);
    $local->put('objects/aa/bb/one.mcma',"object-one\0bytes\n",null,true);
    $local->put('objects/cc/dd/two.mcma',"object-two\n",null,true);
    $local->put('manifest.mcma',"manifest-exact\n",null,true);
    $webdav=new WebDavStorageAdapter('https://example.test/dav/mcma','none',null,null,null,$requester);
    if(StorageMigrator::copy($local,$webdav)['objects_copied']!==2) throw new RuntimeException('Local to WebDAV count mismatch');
    $local2=new LocalFilesystemAdapter($dst);
    if(StorageMigrator::copy($webdav,$local2)['objects_copied']!==2) throw new RuntimeException('WebDAV to Local count mismatch');
    foreach(['objects/aa/bb/one.mcma','objects/cc/dd/two.mcma','manifest.mcma'] as $loc) if(!hash_equals($local->get($loc)['bytes'],$local2->get($loc)['bytes'])) throw new RuntimeException('Byte mismatch after WebDAV round trip: '.$loc);
    echo "MCMA Local-WebDAV-Local byte-preserving migration passed.\n";
}finally{rr_webdav($base);}
