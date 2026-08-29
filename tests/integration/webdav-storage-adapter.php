<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/src/Storage/StorageAdapter.php';
require_once __DIR__ . '/../../packages/core/src/Storage/WebDavStorageAdapter.php';

use MCMA\Core\Storage\WebDavStorageAdapter;

$files=[]; $dirs=['/dav/mcma/' => true];
$requester=function(string $method,string $url,array $headers,string $body) use (&$files,&$dirs): array {
    $path=rawurldecode((string)parse_url($url,PHP_URL_PATH));
    if (($headers['authorization'] ?? '') !== 'Basic ' . base64_encode('user:pass')) throw new RuntimeException('WebDAV auth header missing');
    if($method==='MKCOL'){ $dirs[rtrim($path,'/').'/']=true; return [201,'',[]]; }
    if($method==='HEAD'){
        if(isset($files[$path])) return [200,'',['etag'=>'"'.$files[$path]['etag'].'"']];
        if(isset($dirs[rtrim($path,'/').'/'])) return [200,'',[]];
        return [404,'',[]];
    }
    if($method==='GET') return isset($files[$path]) ? [200,$files[$path]['bytes'],['etag'=>'"'.$files[$path]['etag'].'"']] : [404,'',[]];
    if($method==='PUT'){
        if(($headers['if-none-match']??null)==='*' && isset($files[$path])) return [412,'',[]];
        if(isset($headers['if-match'])){
            $want=trim((string)$headers['if-match'],'"');
            if(!isset($files[$path]) || !hash_equals($want,$files[$path]['etag'])) return [412,'',[]];
        }
        $etag=md5($body); $files[$path]=['bytes'=>$body,'etag'=>$etag]; return [201,'',['etag'=>'"'.$etag.'"']];
    }
    if($method==='PROPFIND'){
        $collection=rtrim($path,'/').'/'; if(!isset($dirs[$collection])) return [404,'',[]];
        $xml='<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
        $xml.='<d:response><d:href>'.htmlspecialchars($collection,ENT_XML1).'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
        foreach($dirs as $dir=>$_){
            if($dir===$collection) continue;
            $parent=rtrim(dirname(rtrim($dir,'/')),'/').'/';
            if($parent===$collection) $xml.='<d:response><d:href>'.htmlspecialchars($dir,ENT_XML1).'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
        }
        foreach($files as $file=>$v){
            $parent=rtrim(dirname($file),'/').'/';
            if($parent===$collection) $xml.='<d:response><d:href>'.htmlspecialchars($file,ENT_XML1).'</d:href><d:propstat><d:prop><d:resourcetype/><d:getetag>&quot;'.$v['etag'].'&quot;</d:getetag></d:prop></d:propstat></d:response>';
        }
        $xml.='</d:multistatus>'; return [207,$xml,['content-type'=>'application/xml']];
    }
    if($method==='DELETE'){ unset($files[$path]); return [204,'',[]]; }
    return [405,'',[]];
};

$webdav=new WebDavStorageAdapter('https://example.test/dav/mcma','basic','user','pass',null,$requester);
$v1=$webdav->put('manifest.mcma',"one\n",null,true);
if($webdav->get('manifest.mcma')['bytes']!=="one\n") throw new RuntimeException('WebDAV round trip failed');
try{$webdav->put('manifest.mcma',"duplicate\n",null,true);throw new RuntimeException('WebDAV createOnly overwrite accepted');}catch(Throwable $e){if(str_contains($e->getMessage(),'createOnly overwrite accepted')) throw $e;}
$v2=$webdav->put('manifest.mcma',"two\n",$v1);
if($v1===$v2) throw new RuntimeException('WebDAV ETag did not change');
try{$webdav->put('manifest.mcma',"stale\n",$v1);throw new RuntimeException('WebDAV stale CAS accepted');}catch(Throwable $e){if(str_contains($e->getMessage(),'stale CAS accepted')) throw $e;}
$webdav->put('objects/aa/bb/item.mcma',"bytes\n",null,true);
if($webdav->list('objects/')!==['objects/aa/bb/item.mcma']) throw new RuntimeException('WebDAV list mismatch');
if(($webdav->capabilities()['compare_and_swap']??false)!==true) throw new RuntimeException('WebDAV CAS capability missing');
echo "MCMA WebDAV storage adapter simulation passed.\n";
