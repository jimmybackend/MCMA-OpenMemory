<?php
declare(strict_types=1);
require_once __DIR__ . '/../../packages/core/src/Storage/StorageAdapter.php';
require_once __DIR__ . '/../../packages/core/src/Storage/AwsSigV4.php';
require_once __DIR__ . '/../../packages/core/src/Storage/S3StorageAdapter.php';

use MCMA\Core\Storage\S3StorageAdapter;

$objects=[];
$requester=function(string $method,string $url,array $headers,string $body) use (&$objects): array {
    if(!isset($headers['authorization'])||!str_starts_with($headers['authorization'],'AWS4-HMAC-SHA256 ')) throw new RuntimeException('Missing SigV4 authorization');
    $parts=parse_url($url); if($parts===false) return [500,'',[]];
    $path=(string)($parts['path']??''); parse_str((string)($parts['query']??''),$query);
    $prefix='/bucket/';
    if($path==='/bucket' || $path==='/bucket/'){
        if($method!=='GET') return [405,'',[]];
        $wanted=(string)($query['prefix']??'');
        $xml='<ListBucketResult><IsTruncated>false</IsTruncated>';
        foreach($objects as $key=>$obj){ if($wanted===''||str_starts_with($key,$wanted)) $xml.='<Contents><Key>'.htmlspecialchars(rawurlencode($key),ENT_XML1).'</Key><ETag>&quot;'.$obj['etag'].'&quot;</ETag></Contents>'; }
        $xml.='</ListBucketResult>'; return [200,$xml,['content-type'=>'application/xml']];
    }
    if(!str_starts_with($path,$prefix)) return [404,'',[]];
    $key=rawurldecode(substr($path,strlen($prefix)));
    if($method==='HEAD'){
        if(!isset($objects[$key])) return [404,'',[]];
        return [200,'',['etag'=>'"'.$objects[$key]['etag'].'"']];
    }
    if($method==='GET'){
        if(!isset($objects[$key])) return [404,'',[]];
        return [200,$objects[$key]['bytes'],['etag'=>'"'.$objects[$key]['etag'].'"']];
    }
    if($method==='PUT'){
        if(($headers['if-none-match']??null)==='*' && isset($objects[$key])) return [412,'',[]];
        if(isset($headers['if-match'])){
            $want=trim((string)$headers['if-match'],'"');
            if(!isset($objects[$key])||!hash_equals($want,$objects[$key]['etag'])) return [412,'',[]];
        }
        $etag=md5($body); $objects[$key]=['bytes'=>$body,'etag'=>$etag]; return [200,'',['etag'=>'"'.$etag.'"']];
    }
    if($method==='DELETE') { unset($objects[$key]); return [204,'',[]]; }
    return [405,'',[]];
};

$s3=new S3StorageAdapter('bucket','us-east-1','memory','https://s3.test.local',true,'AKID','SECRET',null,$requester);
$v1=$s3->put('manifest.mcma',"one\n",null,true);
if($s3->get('manifest.mcma')['bytes']!=="one\n") throw new RuntimeException('S3 round trip failed');
try{$s3->put('manifest.mcma',"duplicate\n",null,true);throw new RuntimeException('S3 createOnly overwrite accepted');}catch(Throwable $e){if(str_contains($e->getMessage(),'createOnly overwrite accepted'))throw $e;}
$v2=$s3->put('manifest.mcma',"two\n",$v1,false);
if($v2===$v1) throw new RuntimeException('S3 ETag did not change');
try{$s3->put('manifest.mcma',"stale\n",$v1,false);throw new RuntimeException('S3 stale CAS accepted');}catch(Throwable $e){if(str_contains($e->getMessage(),'S3 stale CAS accepted'))throw $e;}
$s3->put('objects/aa/item.mcma',"bytes\n",null,true);
$list=$s3->list('objects/');
if($list!==['objects/aa/item.mcma']) throw new RuntimeException('S3 list mismatch: '.json_encode($list));
if(($s3->capabilities()['compare_and_swap']??false)!==true) throw new RuntimeException('S3 CAS capability missing');
if(!str_starts_with($s3->id(),'s3:bucket@us-east-1/')) throw new RuntimeException('S3 id mismatch');
echo "MCMA S3 storage adapter simulation passed.\n";
