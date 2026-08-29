<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/src/Storage/StorageAdapter.php';
require_once __DIR__ . '/../../packages/core/src/Storage/GitHubStorageAdapter.php';

use MCMA\Core\Storage\GitHubStorageAdapter;

$files=[]; $counter=0;
$requester=function(string $method,string $path,?array $json=null) use (&$files,&$counter): array {
    if(preg_match('#/contents/(.+?)(?:\?ref=.*)?$#',$path,$m)){
        $file=rawurldecode(str_replace('%2F','/',$m[1]));
        $file=preg_replace('/\?ref=.*$/','',$file);
        if($method==='GET'){
            if(!isset($files[$file]))return[404,['message'=>'Not Found']];
            return[200,['type'=>'file','content'=>base64_encode($files[$file]['bytes']),'sha'=>$files[$file]['sha']]];
        }
        if($method==='PUT'){
            $bytes=base64_decode((string)($json['content']??''),true); if($bytes===false)return[422,[]];
            if(isset($files[$file])&&isset($json['sha'])&&!hash_equals($files[$file]['sha'],(string)$json['sha']))return[409,['message'=>'Conflict']];
            if(isset($files[$file])&&!isset($json['sha']))return[422,['message'=>'sha required']];
            $sha='blob'.(++$counter);$files[$file]=['bytes'=>$bytes,'sha'=>$sha];return[isset($json['sha'])?200:201,['content'=>['sha'=>$sha]]];
        }
    }
    if(str_contains($path,'/branches/main'))return[200,['commit'=>['commit'=>['tree'=>['sha'=>'tree1']]]]];
    if(str_contains($path,'/git/trees/tree1')){
        $tree=[];foreach($files as $file=>$v)$tree[]=['path'=>$file,'type'=>'blob','sha'=>$v['sha']];return[200,['truncated'=>false,'tree'=>$tree]];
    }
    return[500,['message'=>'unexpected '.$method.' '.$path]];
};

$g=new GitHubStorageAdapter('owner','repo','main','memory',null,$requester);
$v1=$g->put('manifest.mcma',"one\n",null,true);
if($g->get('manifest.mcma')['bytes']!=="one\n")throw new RuntimeException('GitHub round trip failed');
$v2=$g->put('manifest.mcma',"two\n",$v1,false);
if($v2===$v1)throw new RuntimeException('GitHub version did not change');
try{$g->put('manifest.mcma',"stale\n",$v1,false);throw new RuntimeException('GitHub stale CAS accepted');}
catch(Throwable $e){if(str_contains($e->getMessage(),'GitHub stale CAS accepted'))throw $e;}
$g->put('objects/aa/item.mcma',"bytes\n",null,true);
$list=$g->list('objects/');
if($list!==['objects/aa/item.mcma'])throw new RuntimeException('GitHub list mismatch: '.json_encode($list));
if(($g->capabilities()['compare_and_swap']??false)!==true)throw new RuntimeException('GitHub CAS capability missing');
echo "MCMA GitHub storage adapter simulation passed.\n";
