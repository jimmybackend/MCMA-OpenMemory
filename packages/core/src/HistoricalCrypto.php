<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use RuntimeException;

final class HistoricalCrypto
{
    public static function readAndDecrypt(string $path, string $masterKey): array
    {
        if (strlen($masterKey) !== 32) throw new RuntimeException('Historical master key must be exactly 32 bytes');
        $raw = @file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Unable to read historical MCMA object: ' . $path);

        try {
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Historical MCMA object is not valid JSON', 0, $e);
        }
        if (!is_array($envelope)) throw new RuntimeException('Historical MCMA envelope must be a JSON object');

        return [
            'envelope' => $envelope,
            'plaintext' => self::decryptEnvelope($envelope, $masterKey),
        ];
    }

    public static function decryptEnvelope(array $envelope, string $masterKey): string
    {
        $format = (string) ($envelope['format'] ?? '');
        if (!in_array($format, ['mcma-v1', 'mcma-v2'], true)) throw new RuntimeException('Unsupported historical MCMA format');
        if (strlen($masterKey) !== 32) throw new RuntimeException('Historical master key must be exactly 32 bytes');

        $expectedVersion = $format === 'mcma-v1' ? 'mcma-key-v1' : 'mcma-key-v2';
        if (($envelope['key_version'] ?? null) !== $expectedVersion) throw new RuntimeException('Historical key version mismatch');
        if ($format === 'mcma-v2' && ($envelope['kdf'] ?? null) !== 'HKDF-SHA256') throw new RuntimeException('Historical V2 KDF mismatch');
        if (($envelope['cipher'] ?? null) !== 'AES-256-GCM') throw new RuntimeException('Unsupported historical cipher');

        $logicalPath = trim(str_replace('\\', '/', (string) ($envelope['logical_path'] ?? '')), '/');
        $file = (string) ($envelope['file'] ?? '');
        if ($logicalPath === '' || str_contains($logicalPath, '..') || !preg_match('#^[A-Za-z0-9/_.-]+$#', $logicalPath)) {
            throw new RuntimeException('Invalid historical logical path');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.mcma$/', $file)) throw new RuntimeException('Invalid historical MCMA filename');

        $identity = $expectedVersion . "\n" . $logicalPath . '/' . $file;
        $key = hash_hkdf('sha256', $masterKey, 32, $identity, 'MCMA');
        $keyId = substr(hash('sha256', $identity), 0, 16);
        if (($envelope['key_id'] ?? null) !== $keyId) throw new RuntimeException('Historical key_id/identity mismatch');

        $aad = $format === 'mcma-v1'
            ? 'MCMA1|' . $keyId
            : 'MCMA2|' . $keyId . '|' . $logicalPath . '|' . $file;

        $iv = base64_decode((string) ($envelope['iv_b64'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag_b64'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['ciphertext_b64'] ?? ''), true);
        if ($iv === false || $tag === false || $ciphertext === false) throw new RuntimeException('Invalid historical Base64');
        if (strlen($iv) !== 12 || strlen($tag) !== 16) throw new RuntimeException('Invalid historical AES-GCM parameters');

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plaintext === false) throw new RuntimeException('Historical MCMA authentication/decryption failed');
        return $plaintext;
    }

    public static function loadLegacyMasterKey(?string $keyFile = null, string $envName = 'MCMA_LEGACY_MASTER_KEY_B64'): string
    {
        if ($keyFile !== null) {
            $raw = @file_get_contents($keyFile);
            if ($raw === false) throw new RuntimeException('Unable to read legacy key file');
            return self::decodeMasterKey(trim($raw));
        }

        $value = getenv($envName);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Historical key required: use --legacy-key-file or {$envName}");
        }
        return self::decodeMasterKey(trim($value));
    }

    private static function decodeMasterKey(string $b64): string
    {
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('Historical master key must be Base64 encoding of exactly 32 bytes');
        return $key;
    }
}
