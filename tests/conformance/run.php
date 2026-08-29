<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Crypto;
use MCMA\Core\Jcs;
use MCMA\Core\LocalLibrary;

function same(string $name, mixed $actual, mixed $expected): void
{
    if (!hash_equals((string) $expected, (string) $actual)) {
        fwrite(STDERR, "FAIL {$name}\nExpected: {$expected}\nActual:   {$actual}\n");
        exit(1);
    }
    echo "OK   {$name}\n";
}

$vectorPath = __DIR__ . '/../../spec/1.0/test-vectors/vector-001.json';
$vector = json_decode(file_get_contents($vectorPath), true, 512, JSON_THROW_ON_ERROR);
$master = hex2bin($vector['master_key_hex']);
$iv = hex2bin($vector['iv_hex']);
$payload = json_decode($vector['plaintext_jcs'], true, 512, JSON_THROW_ON_ERROR);

$salt = hash('sha256', 'MCMA1|' . $vector['library_id']);
same('salt', $salt, $vector['salt_hex']);

$key = Crypto::deriveKey($master, $vector['library_id'], $vector['object_id'], 'memory', 'key-1');
same('derived key', bin2hex($key), $vector['derived_key_hex']);

$protected = Crypto::buildProtected('object', $vector['library_id'], $vector['object_id'], 'key-1', $iv);
same('AAD JCS', Crypto::aad($protected), $vector['aad_jcs']);
same('plaintext JCS', Jcs::encode($payload), $vector['plaintext_jcs']);

$envelope = Crypto::encryptPayload($master, $vector['library_id'], $vector['object_id'], 'object', $payload, 'key-1', $iv);
same('ciphertext', $envelope['ciphertext_b64u'], $vector['ciphertext_b64u']);
same('tag', $envelope['tag_b64u'], $vector['tag_b64u']);
same('storage hash', $envelope['storage_hash'], $vector['storage_hash']);
same('storage path', LocalLibrary::objectPath('/tmp/library', $envelope['storage_hash']), '/tmp/library/' . $vector['default_storage_path']);

$decoded = Crypto::decryptPayload($master, $envelope);
same('decrypt/recanonicalize', Jcs::encode($decoded), $vector['plaintext_jcs']);

$mutated = $envelope;
$mutated['protected']['object_id'] = 'obj_bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee';
try {
    Crypto::decryptPayload($master, $mutated);
    fwrite(STDERR, "FAIL protected-header tamper accepted\n");
    exit(1);
} catch (Throwable) {
    echo "OK   protected-header tamper rejected\n";
}

echo "MCMA 1.0 conformance vector passed.\n";
