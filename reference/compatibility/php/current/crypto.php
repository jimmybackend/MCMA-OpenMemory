<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

function fail(int $status, string $error): never { http_response_code($status); echo json_encode(['ok'=>false,'error'=>$error], JSON_UNESCAPED_SLASHES); exit; }
function validPath(string $path): string {
    $path = trim(str_replace('\\','/',$path), '/');
    if ($path === '' || str_contains($path,'..') || !preg_match('#^[A-Za-z0-9/_.-]+$#',$path)) fail(400,'invalid_path');
    return $path;
}
function validFile(string $file): string { if (!preg_match('/^[A-Za-z0-9._-]+\.mcma$/',$file)) fail(400,'invalid_mcma_filename'); return $file; }
function b64(string $v, string $error): string { $x=base64_decode($v,true); if($x===false) fail(400,$error); return $x; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405,'post_required');
$master = base64_decode(getenv('MCMA_MASTER_KEY_B64') ?: '', true);
$token = getenv('MCMA_API_TOKEN') ?: '';
if ($master === false || strlen($master)!==32) fail(500,'master_key_unavailable');
if (strlen($token)<32) fail(500,'api_token_unavailable');
$auth=$_SERVER['HTTP_AUTHORIZATION'] ?? '';
if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m) || !hash_equals($token,$m[1])) fail(401,'unauthorized');
$body=json_decode(file_get_contents('php://input') ?: '',true);
if(!is_array($body)) fail(400,'invalid_json');
$action=(string)($body['action'] ?? '');
$path=validPath((string)($body['path'] ?? ''));
$file=validFile((string)($body['file'] ?? ''));
$keyVersion='mcma-key-v2';
$identity=$keyVersion."\n".$path.'/'.$file;
$key=hash_hkdf('sha256',$master,32,$identity,'MCMA');
$keyId=substr(hash('sha256',$identity),0,16);
$aad='MCMA2|'.$keyId.'|'.$path.'|'.$file;

if($action==='encrypt'){
    $plain=b64((string)($body['plaintext_b64'] ?? ''),'invalid_plaintext_b64');
    $temperature=strtolower((string)($body['temperature'] ?? 'hot'));
    if(!in_array($temperature,['hot','warm','cold','frozen'],true)) fail(400,'invalid_temperature');
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
    if($cipher===false) fail(500,'encryption_failed');
    $env=['format'=>'mcma-v2','cipher'=>'AES-256-GCM','kdf'=>'HKDF-SHA256','key_version'=>$keyVersion,'key_id'=>$keyId,'logical_path'=>$path,'file'=>$file,'temperature'=>$temperature,'created_at'=>gmdate('c'),'iv_b64'=>base64_encode($iv),'tag_b64'=>base64_encode($tag),'ciphertext_b64'=>base64_encode($cipher)];
    echo json_encode(['ok'=>true,'envelope'=>$env],JSON_UNESCAPED_SLASHES); exit;
}
if($action==='decrypt'){
    $env=$body['envelope'] ?? null;
    if(!is_array($env) || ($env['format']??'')!=='mcma-v2') fail(400,'invalid_mcma_v2_envelope');
    if(($env['key_version']??'')!==$keyVersion || ($env['key_id']??'')!==$keyId || ($env['logical_path']??'')!==$path || ($env['file']??'')!==$file) fail(400,'mcma_identity_path_mismatch');
    $iv=b64((string)($env['iv_b64']??''),'invalid_iv_b64'); $tag=b64((string)($env['tag_b64']??''),'invalid_tag_b64'); $cipher=b64((string)($env['ciphertext_b64']??''),'invalid_ciphertext_b64');
    if(strlen($iv)!==12 || strlen($tag)!==16) fail(400,'invalid_gcm_parameters');
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad);
    if($plain===false) fail(400,'authentication_decryption_failed');
    echo json_encode(['ok'=>true,'plaintext_b64'=>base64_encode($plain),'temperature'=>$env['temperature']??null],JSON_UNESCAPED_SLASHES); exit;
}
fail(400,'action_must_be_encrypt_or_decrypt');
