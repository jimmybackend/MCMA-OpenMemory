<?php
declare(strict_types=1);

namespace MCMA\Core;

use RuntimeException;

final class KeyStore
{
    public static function createOrUse(string $libraryId): string
    {
        $env = getenv('MCMA_MASTER_KEY_B64');
        if (is_string($env) && trim($env) !== '') return self::decodeMasterKey(trim($env));

        $key = random_bytes(32);
        self::writeKey($libraryId, $key);
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

    private static function writeKey(string $libraryId, string $key): void
    {
        $dir = self::keyDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create MCMA key directory: ' . $dir);
        @chmod($dir, 0700);

        $path = self::keyPath($libraryId);
        if (file_exists($path)) throw new RuntimeException('Refusing to overwrite existing MCMA library key: ' . $path);

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, base64_encode($key) . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Unable to write MCMA key file');
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to install MCMA key file');
        }
        @chmod($path, 0600);
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
}
