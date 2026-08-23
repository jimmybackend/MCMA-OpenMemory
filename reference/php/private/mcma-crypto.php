<?php
declare(strict_types=1);
function abortWith(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
if ($argc !== 5) abortWith("Usage:\n  php mcma-crypto.php encrypt INPUT OUTPUT.mcma LOGICAL_PATH\n  php mcma-crypto.php decrypt INPUT.mcma OUTPUT LOGICAL_PATH");
[, $mode, $input, $output, $logicalPath] = $argv;
$logicalPath=trim(str_replace('\\','/',$logicalPath),'/');
if($logicalPath==='' || str_contains($logicalPath,'..') || !preg_match('#^[A-Za-z0-9/_.-]+$#',$logicalPath)) abortWith('Invalid logical path');
$master=base64_decode(getenv('MCMA_MASTER_KEY_B64') ?: '',true);
if($master===false || strlen($master)!==32) abortWith('MCMA_MASTER_KEY_B64 must decode to exactly 32 bytes');
$fileName=basename($mode==='encrypt' ? $output : $input);
if(!preg_match('/^[A-Za-z0-9._-]+\.mcma$/',$fileName)) abortWith('MCMA filename must end in .mcma');
$version='mcma-key-v2';
$identity=$version."\n".$logicalPath.'/'.$fileName;
$key=hash_hkdf('sha256',$master,32,$identity,'MCMA');
$keyId=substr(hash('sha256',$identity),0,16);
$aad='MCMA2|'.$keyId.'|'.$logicalPath.'|'.$fileName;
if($mode==='encrypt'){
 $plain=file_get_contents($input); if($plain===false) abortWith('Unable to read input file');
 $iv=random_bytes(12); $tag='';
 $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16); if($cipher===false) abortWith('Encryption failed');
 $env=['format'=>'mcma-v2','cipher'=>'AES-256-GCM','kdf'=>'HKDF-SHA256','key_version'=>$version,'key_id'=>$keyId,'logical_path'=>$logicalPath,'file'=>$fileName,'temperature'=>'hot','created_at'=>gmdate('c'),'iv_b64'=>base64_encode($iv),'tag_b64'=>base64_encode($tag),'ciphertext_b64'=>base64_encode($cipher)];
 if(file_put_contents($output,json_encode($env,JSON_UNESCAPED_SLASHES))===false) abortWith('Unable to write output');
 echo "Encrypted: $output\nKey ID: $keyId\n"; exit(0);
}
if($mode==='decrypt'){
 $raw=file_get_contents($input); if($raw===false) abortWith('Unable to read MCMA file'); $env=json_decode($raw,true);
 if(!is_array($env) || ($env['format']??'')!=='mcma-v2' || ($env['key_version']??'')!==$version) abortWith('Invalid MCMA v2 envelope');
 if(($env['key_id']??'')!==$keyId || ($env['logical_path']??'')!==$logicalPath || ($env['file']??'')!==$fileName) abortWith('MCMA identity/path mismatch');
 $iv=base64_decode((string)($env['iv_b64']??''),true); $tag=base64_decode((string)($env['tag_b64']??''),true); $cipher=base64_decode((string)($env['ciphertext_b64']??''),true);
 if($iv===false || $tag===false || $cipher===false || strlen($iv)!==12 || strlen($tag)!==16) abortWith('Invalid GCM/base64 data');
 $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad); if($plain===false) abortWith('Authentication/decryption failed');
 if(file_put_contents($output,$plain)===false) abortWith('Unable to write output'); echo "Decrypted: $output\n"; exit(0);
}
abortWith('Mode must be encrypt or decrypt');
