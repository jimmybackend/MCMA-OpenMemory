<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use RuntimeException;

final class Crypto
{
    public const FORMAT = 'mcma-1.0';
    public const KEY_VERSION = 'key-1';

    private const CONTEXTS = [
        'manifest' => 'manifest',
        'object' => 'memory',
        'index' => 'index',
        'vault' => 'vault',
    ];

    public static function validateLibraryId(string $id): void
    {
        if (!preg_match('/^lib_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new RuntimeException('Invalid MCMA 1.0 library_id');
        }
    }

    public static function validateObjectId(string $id): void
    {
        if (!preg_match('/^obj_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new RuntimeException('Invalid MCMA 1.0 object_id');
        }
    }

    public static function uuidV4(string $prefix): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
        return $prefix . '_' . $uuid;
    }

    public static function b64uEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new RuntimeException('Invalid base64url value');
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) throw new RuntimeException('Invalid base64url encoding');
        return $decoded;
    }

    public static function deriveKey(string $masterKey, string $libraryId, string $objectId, string $keyContext, string $keyVersion): string
    {
        if (strlen($masterKey) !== 32) throw new RuntimeException('MCMA master key must be exactly 32 bytes');
        self::validateLibraryId($libraryId);
        self::validateObjectId($objectId);
        if (!in_array($keyContext, self::CONTEXTS, true)) throw new RuntimeException('Invalid MCMA key context');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $keyVersion)) throw new RuntimeException('Invalid key_version');

        $salt = hash('sha256', 'MCMA1|' . $libraryId, true);
        $info = 'MCMA1|' . $keyContext . '|' . $objectId . '|' . $keyVersion;
        return hash_hkdf('sha256', $masterKey, 32, $info, $salt);
    }

    public static function buildProtected(string $container, string $libraryId, string $objectId, string $keyVersion, string $iv): array
    {
        if (!isset(self::CONTEXTS[$container])) throw new RuntimeException('Invalid MCMA container role');
        if (strlen($iv) !== 12) throw new RuntimeException('AES-GCM IV must be exactly 12 bytes');
        self::validateLibraryId($libraryId);
        self::validateObjectId($objectId);

        return [
            'format' => self::FORMAT,
            'container' => $container,
            'library_id' => $libraryId,
            'object_id' => $objectId,
            'crypto' => [
                'cipher' => 'AES-256-GCM',
                'kdf' => 'HKDF-SHA256',
                'key_version' => $keyVersion,
                'key_context' => self::CONTEXTS[$container],
                'iv_b64u' => self::b64uEncode($iv),
            ],
        ];
    }

    public static function aad(array $protected): string
    {
        self::validateProtected($protected);
        return Jcs::encode($protected);
    }

    public static function encryptPayload(
        string $masterKey,
        string $libraryId,
        string $objectId,
        string $container,
        array $payload,
        string $keyVersion = self::KEY_VERSION,
        ?string $iv = null
    ): array {
        $iv ??= random_bytes(12);
        $protected = self::buildProtected($container, $libraryId, $objectId, $keyVersion, $iv);
        $aad = self::aad($protected);
        $plaintext = Jcs::encode($payload);
        $key = self::deriveKey($masterKey, $libraryId, $objectId, $protected['crypto']['key_context'], $keyVersion);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('AES-256-GCM encryption failed');

        $envelope = [
            'protected' => $protected,
            'ciphertext_b64u' => self::b64uEncode($ciphertext),
            'tag_b64u' => self::b64uEncode($tag),
        ];
        $envelope['storage_hash'] = self::storageHash($envelope);
        return $envelope;
    }

    public static function storageHash(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['storage_hash']);
        return 'sha256:' . hash('sha256', Jcs::encode($copy));
    }

    public static function verifyEnvelope(array $envelope): void
    {
        foreach (['protected', 'ciphertext_b64u', 'tag_b64u', 'storage_hash'] as $required) {
            if (!array_key_exists($required, $envelope)) throw new RuntimeException('Missing envelope field: ' . $required);
        }
        if (count(array_diff(array_keys($envelope), ['protected', 'ciphertext_b64u', 'tag_b64u', 'storage_hash'])) > 0) {
            throw new RuntimeException('Unknown top-level envelope field');
        }
        if (!is_array($envelope['protected'])) throw new RuntimeException('Invalid protected header');
        self::validateProtected($envelope['protected']);
        if (!is_string($envelope['ciphertext_b64u']) || $envelope['ciphertext_b64u'] === '') throw new RuntimeException('Invalid ciphertext');
        self::b64uDecode($envelope['ciphertext_b64u']);
        if (!is_string($envelope['tag_b64u']) || strlen(self::b64uDecode($envelope['tag_b64u'])) !== 16) throw new RuntimeException('Invalid GCM tag');
        if (!is_string($envelope['storage_hash']) || !preg_match('/^sha256:[0-9a-f]{64}$/', $envelope['storage_hash'])) throw new RuntimeException('Invalid storage_hash');
        if (!hash_equals($envelope['storage_hash'], self::storageHash($envelope))) throw new RuntimeException('MCMA storage_hash mismatch');
    }

    public static function decryptPayload(string $masterKey, array $envelope): array
    {
        self::verifyEnvelope($envelope);
        $protected = $envelope['protected'];
        $iv = self::b64uDecode($protected['crypto']['iv_b64u']);
        if (strlen($iv) !== 12) throw new RuntimeException('Invalid AES-GCM IV length');

        $key = self::deriveKey(
            $masterKey,
            $protected['library_id'],
            $protected['object_id'],
            $protected['crypto']['key_context'],
            $protected['crypto']['key_version']
        );

        $plaintext = openssl_decrypt(
            self::b64uDecode($envelope['ciphertext_b64u']),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            self::b64uDecode($envelope['tag_b64u']),
            self::aad($protected)
        );
        if ($plaintext === false) throw new RuntimeException('MCMA authentication/decryption failed');

        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Decrypted MCMA payload is not valid JSON', 0, $e);
        }
        if (!is_array($decoded)) throw new RuntimeException('Decrypted MCMA payload must be a JSON object');
        return $decoded;
    }

    private static function validateProtected(array $protected): void
    {
        $required = ['format', 'container', 'library_id', 'object_id', 'crypto'];
        foreach ($required as $name) {
            if (!array_key_exists($name, $protected)) throw new RuntimeException('Missing protected field: ' . $name);
        }
        if (count(array_diff(array_keys($protected), $required)) > 0) throw new RuntimeException('Unknown protected header field');
        if ($protected['format'] !== self::FORMAT) throw new RuntimeException('Unsupported MCMA format');
        if (!is_string($protected['container']) || !isset(self::CONTEXTS[$protected['container']])) throw new RuntimeException('Invalid container role');
        if (!is_string($protected['library_id']) || !is_string($protected['object_id'])) throw new RuntimeException('Invalid MCMA identity fields');
        self::validateLibraryId($protected['library_id']);
        self::validateObjectId($protected['object_id']);

        $crypto = $protected['crypto'];
        if (!is_array($crypto)) throw new RuntimeException('Invalid crypto header');
        $cryptoRequired = ['cipher', 'kdf', 'key_version', 'key_context', 'iv_b64u'];
        foreach ($cryptoRequired as $name) {
            if (!array_key_exists($name, $crypto)) throw new RuntimeException('Missing crypto field: ' . $name);
        }
        if (count(array_diff(array_keys($crypto), $cryptoRequired)) > 0) throw new RuntimeException('Unknown crypto header field');
        if ($crypto['cipher'] !== 'AES-256-GCM' || $crypto['kdf'] !== 'HKDF-SHA256') throw new RuntimeException('Unsupported MCMA cryptographic profile');
        if ($crypto['key_context'] !== self::CONTEXTS[$protected['container']]) throw new RuntimeException('Container/key_context mismatch');
        if (!is_string($crypto['key_version']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $crypto['key_version'])) throw new RuntimeException('Invalid key_version');
        if (!is_string($crypto['iv_b64u']) || strlen(self::b64uDecode($crypto['iv_b64u'])) !== 12) throw new RuntimeException('Invalid IV');
    }
}
