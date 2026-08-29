<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use RuntimeException;

final class KeyStore
{
    private const RECOVERY_FORMAT = 'mcma-key-backup-1';
    private const RECOVERY_ITERATIONS = 600000;

    public static function createOrUse(string $libraryId): string
    {
        $env = getenv('MCMA_MASTER_KEY_B64');
        if (is_string($env) && trim($env) !== '') return self::decodeMasterKey(trim($env));

        $key = random_bytes(32);
        self::install($libraryId, $key, false);
        return $key;
    }

    public static function load(string $libraryId): string
    {
        Crypto::validateLibraryId($libraryId);
        $env = getenv('MCMA_MASTER_KEY_B64');
        if (is_string($env) && trim($env) !== '') return self::decodeMasterKey(trim($env));

        $path = self::keyPath($libraryId);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("No key available for {$libraryId}. Set MCMA_MASTER_KEY_B64 or restore {$path}");
        }
        return self::decodeMasterKey(trim($raw));
    }

    public static function keyPath(string $libraryId): string
    {
        Crypto::validateLibraryId($libraryId);
        return rtrim(self::keyDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $libraryId . '.key';
    }

    public static function install(string $libraryId, string $key, bool $replace = false): string
    {
        Crypto::validateLibraryId($libraryId);
        if (strlen($key) !== 32) throw new RuntimeException('MCMA master key must be exactly 32 bytes');

        $dir = self::keyDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create MCMA key directory: ' . $dir);
        @chmod($dir, 0700);

        $path = self::keyPath($libraryId);
        if (file_exists($path)) {
            $existingRaw = @file_get_contents($path);
            if ($existingRaw !== false) {
                $existing = self::decodeMasterKey(trim($existingRaw));
                if (hash_equals($existing, $key)) return $path;
            }
            if (!$replace) throw new RuntimeException('A different key already exists for ' . $libraryId . '; use explicit replacement only after verification');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, base64_encode($key) . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Unable to write MCMA key file');
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to install MCMA key file');
        }
        @chmod($path, 0600);
        return $path;
    }

    public static function exportRecovery(string $libraryId, string $outputPath, string $passphrase): array
    {
        Crypto::validateLibraryId($libraryId);
        self::validatePassphrase($passphrase);
        $masterKey = self::load($libraryId);

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $protected = [
            'format' => self::RECOVERY_FORMAT,
            'cipher' => 'AES-256-GCM',
            'kdf' => 'PBKDF2-HMAC-SHA256',
            'iterations' => self::RECOVERY_ITERATIONS,
            'salt_b64u' => Crypto::b64uEncode($salt),
            'iv_b64u' => Crypto::b64uEncode($iv),
        ];
        $payload = [
            'library_id' => $libraryId,
            'key_version' => Crypto::KEY_VERSION,
            'master_key_b64' => base64_encode($masterKey),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $recoveryKey = hash_pbkdf2('sha256', $passphrase, $salt, self::RECOVERY_ITERATIONS, 32, true);
        $tag = '';
        $ciphertext = openssl_encrypt(Jcs::encode($payload), 'aes-256-gcm', $recoveryKey, OPENSSL_RAW_DATA, $iv, $tag, Jcs::encode($protected), 16);
        if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Unable to encrypt recovery bundle');

        $bundle = [
            'protected' => $protected,
            'ciphertext_b64u' => Crypto::b64uEncode($ciphertext),
            'tag_b64u' => Crypto::b64uEncode($tag),
        ];
        self::atomicWrite($outputPath, Jcs::encode($bundle) . PHP_EOL, 0600);

        return ['library_id' => $libraryId, 'recovery_file' => $outputPath];
    }

    public static function importRecovery(string $inputPath, string $passphrase, bool $replace = false): array
    {
        self::validatePassphrase($passphrase);
        $raw = @file_get_contents($inputPath);
        if ($raw === false) throw new RuntimeException('Unable to read recovery bundle');

        try {
            $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid recovery bundle JSON', 0, $e);
        }
        if (!is_array($bundle) || !isset($bundle['protected'], $bundle['ciphertext_b64u'], $bundle['tag_b64u'])) {
            throw new RuntimeException('Malformed recovery bundle');
        }

        $protected = $bundle['protected'];
        if (!is_array($protected)
            || ($protected['format'] ?? null) !== self::RECOVERY_FORMAT
            || ($protected['cipher'] ?? null) !== 'AES-256-GCM'
            || ($protected['kdf'] ?? null) !== 'PBKDF2-HMAC-SHA256'
            || ($protected['iterations'] ?? null) !== self::RECOVERY_ITERATIONS) {
            throw new RuntimeException('Unsupported recovery bundle profile');
        }

        $salt = Crypto::b64uDecode((string) ($protected['salt_b64u'] ?? ''));
        $iv = Crypto::b64uDecode((string) ($protected['iv_b64u'] ?? ''));
        if (strlen($salt) !== 16 || strlen($iv) !== 12) throw new RuntimeException('Invalid recovery salt or IV');

        $recoveryKey = hash_pbkdf2('sha256', $passphrase, $salt, self::RECOVERY_ITERATIONS, 32, true);
        $plaintext = openssl_decrypt(
            Crypto::b64uDecode((string) $bundle['ciphertext_b64u']),
            'aes-256-gcm',
            $recoveryKey,
            OPENSSL_RAW_DATA,
            $iv,
            Crypto::b64uDecode((string) $bundle['tag_b64u']),
            Jcs::encode($protected)
        );
        if ($plaintext === false) throw new RuntimeException('Recovery bundle authentication/decryption failed');

        try {
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Invalid decrypted recovery payload', 0, $e);
        }
        if (!is_array($payload)) throw new RuntimeException('Malformed decrypted recovery payload');

        $libraryId = (string) ($payload['library_id'] ?? '');
        Crypto::validateLibraryId($libraryId);
        if (($payload['key_version'] ?? null) !== Crypto::KEY_VERSION) throw new RuntimeException('Unsupported recovered key version');
        $masterKey = self::decodeMasterKey((string) ($payload['master_key_b64'] ?? ''));
        $path = self::install($libraryId, $masterKey, $replace);

        return ['library_id' => $libraryId, 'key_path' => $path];
    }

    private static function validatePassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 12) throw new RuntimeException('Recovery passphrase must be at least 12 bytes');
    }

    private static function keyDir(): string
    {
        $configured = getenv('MCMA_KEY_DIR');
        if (is_string($configured) && trim($configured) !== '') return rtrim(trim($configured), DIRECTORY_SEPARATOR);

        $home = getenv('HOME');
        if (!is_string($home) || trim($home) === '') throw new RuntimeException('HOME or MCMA_KEY_DIR is required for the local MCMA key store');
        return rtrim($home, DIRECTORY_SEPARATOR) . '/.config/mcma/keys';
    }

    private static function decodeMasterKey(string $b64): string
    {
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('MCMA master key must be Base64 encoding of exactly 32 bytes');
        return $key;
    }

    private static function atomicWrite(string $path, string $bytes, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create recovery directory');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new RuntimeException('Unable to write recovery bundle');
        @chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to install recovery bundle');
        }
        @chmod($path, $mode);
    }
}
