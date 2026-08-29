<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/src/Storage/StorageAdapter.php';
require_once __DIR__ . '/../../packages/core/src/Storage/LocalFilesystemAdapter.php';
require_once __DIR__ . '/../../packages/core/src/Storage/StorageMigrator.php';

use MCMA\Core\Storage\LocalFilesystemAdapter;
use MCMA\Core\Storage\StorageMigrator;

$base=sys_get_temp_dir().'/mcma-storage-'.bin2hex(random_bytes(4));
$sourceRoot=$base.'/source'; $destRoot=$base.'/dest';
function rrmdir_storage(string $dir): void { if(!is_dir($dir))return; foreach(scandir($dir)?:[] as $i){if($i==='.'||$i==='..')continue;$p=$dir.DIRECTORY_SEPARATOR.$i;if(is_dir($p))rrmdir_storage($p);else@unlink($p);}@rmdir($dir); }
try{
    $source=new LocalFilesystemAdapter($sourceRoot); $dest=new LocalFilesystemAdapter($destRoot);
    $objectBytes="{\"synthetic\":true}\n";
    $manifestBytes="{\"manifest\":true}\n";
    $source->put('objects/aa/bb/aabb.mcma',$objectBytes,null,true);
    $source->put('manifest.mcma',$manifestBytes,null,true);
    $result=StorageMigrator::copy($source,$dest);
    if(($result['objects_copied']??null)!==1)throw new RuntimeException('Expected one copied object');
    if($dest->get('objects/aa/bb/aabb.mcma')['bytes']!==$objectBytes)throw new RuntimeException('Object bytes changed during provider migration');
    if($dest->get('manifest.mcma')['bytes']!==$manifestBytes)throw new RuntimeException('Manifest bytes changed during provider migration');

    $m=$dest->get('manifest.mcma');
    $dest->put('manifest.mcma',"new\n",$m['version']);
    try{$dest->put('manifest.mcma',"stale\n",$m['version']);throw new RuntimeException('Stale CAS write accepted');}
    catch(Throwable $e){if(str_contains($e->getMessage(),'Stale CAS write accepted'))throw $e;}
    echo "MCMA storage adapter/local migration passed.\n";
}finally{rrmdir_storage($base);}
