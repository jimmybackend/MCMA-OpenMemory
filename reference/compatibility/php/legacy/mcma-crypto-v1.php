<?php
declare(strict_types=1);

/*
 * Legacy MCMA v1 CLI utility preserved for compatibility/reference.
 *
 * php mcma-crypto-v1.php encrypt INPUT OUTPUT.mcma LOGICAL_PATH
 * php mcma-crypto-v1.php decrypt INPUT.mcma OUTPUT LOGICAL_PATH
 */

function abortWith(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if ($argc !== 5) {
    abortWith("Usage:\n  php mcma-crypto-v1.php encrypt INPUT OUTPUT.mcma LOGICAL_PATH\n  php mcma-crypto-v1.php decrypt INPUT.mcma OUTPUT LOGICAL_PATH");
}

[, $mode, $input, $output, $logicalPath] = $argv;

$logicalPath = trim(str_replace('\\', '/', $logicalPath), '/');
if ($logicalPath === '' || str_contains($logicalPath, '..') ||
    !preg_match('#^[A-Za-z0-9/_.-]+$#', $logicalPath)) {
    abortWith('Invalid logical path');
}

$masterB64 = getenv('MCMA_MASTER_KEY_B64') ?: '';
$masterKey = base64_decode($masterB64, true);
if ($masterKey === false || strlen($masterKey) !== 32) {
    abortWith('MCMA_MASTER_KEY_B64 must decode to exactly 32 bytes');
}

$fileName = basename($mode === 'encrypt' ? $output : $input);
if (!preg_match('/^[A-Za-z0-9._-]+\.mcma$/', $fileName)) {
    abortWith('MCMA filename must end in .mcma');
}

$version = 'mcma-key-v1';
$identity = $version . "\n" . $logicalPath . '/' . $fileName;
$key = hash_hkdf('sha256', $masterKey, 32, $identity, 'MCMA');
$keyId = substr(hash('sha256', $identity), 0, 16);
$aad = 'MCMA1|' . $keyId;

if ($mode === 'encrypt') {
    $plain = file_get_contents($input);
    if ($plain === false) abortWith('Unable to read input file');

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain, 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $iv, $tag, $aad, 16
    );
    if ($cipher === false) abortWith('Encryption failed');

    $envelope = [
        'format' => 'mcma-v1',
        'cipher' => 'AES-256-GCM',
        'key_version' => $version,
        'key_id' => $keyId,
        'logical_path' => $logicalPath,
        'file' => $fileName,
        'iv_b64' => base64_encode($iv),
        'tag_b64' => base64_encode($tag),
        'ciphertext_b64' => base64_encode($cipher)
    ];
    if (file_put_contents($output, json_encode($envelope, JSON_UNESCAPED_SLASHES)) === false) {
        abortWith('Unable to write output');
    }
    echo "Encrypted: $output\nKey ID: $keyId\n";
    exit(0);
}

if ($mode === 'decrypt') {
    $raw = file_get_contents($input);
    if ($raw === false) abortWith('Unable to read MCMA file');
    $env = json_decode($raw, true);
    if (!is_array($env) || ($env['format'] ?? '') !== 'mcma-v1') abortWith('Invalid MCMA envelope');
    if (($env['key_id'] ?? '') !== $keyId ||
        ($env['logical_path'] ?? '') !== $logicalPath ||
        ($env['file'] ?? '') !== $fileName) {
        abortWith('MCMA identity/path mismatch');
    }

    $iv = base64_decode((string)$env['iv_b64'], true);
    $tag = base64_decode((string)$env['tag_b64'], true);
    $cipher = base64_decode((string)$env['ciphertext_b64'], true);
    if ($iv === false || $tag === false || $cipher === false) abortWith('Invalid base64 data');

    $plain = openssl_decrypt(
        $cipher, 'aes-256-gcm', $key,
        OPENSSL_RAW_DATA, $iv, $tag, $aad
    );
    if ($plain === false) abortWith('Authentication/decryption failed');

    if (file_put_contents($output, $plain) === false) abortWith('Unable to write output');
    echo "Decrypted: $output\n";
    exit(0);
}

abortWith('Mode must be encrypt or decrypt');
