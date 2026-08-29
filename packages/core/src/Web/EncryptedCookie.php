<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use JsonException;
use RuntimeException;

final class EncryptedCookie
{
    private readonly string $key;

    public function __construct(string $secret, private readonly string $purpose)
    {
        if (strlen($secret) < 32) throw new RuntimeException('Web session secret must be at least 32 bytes');
        if ($this->purpose === '' || strlen($this->purpose) > 64) throw new RuntimeException('Invalid cookie purpose');
        $this->key = hash('sha256', "MCMA-WEB\0" . $this->purpose . "\0" . $secret, true);
    }

    public function seal(array $payload): string
    {
        $iv = random_bytes(12);
        $aad = 'mcma-web-cookie-v1|' . $this->purpose;

        try {
            $plaintext = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode web cookie payload', 0, $e);
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt web cookie');
        }

        return 'v1.'
            . self::b64u($iv) . '.'
            . self::b64u($ciphertext) . '.'
            . self::b64u($tag);
    }

    public function open(string $value): array
    {
        $parts = explode('.', $value);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new WebException(401, 'invalid_session', 'Invalid web session cookie');
        }

        $iv = self::decode($parts[1]);
        $ciphertext = self::decode($parts[2]);
        $tag = self::decode($parts[3]);

        if (strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new WebException(401, 'invalid_session', 'Invalid web session cookie');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'mcma-web-cookie-v1|' . $this->purpose
        );
        if ($plaintext === false) {
            throw new WebException(401, 'invalid_session', 'Web session authentication failed');
        }

        try {
            $payload = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WebException(401, 'invalid_session', 'Web session payload is invalid', $e);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new WebException(401, 'invalid_session', 'Web session payload is invalid');
        }

        return $payload;
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new WebException(401, 'invalid_session', 'Invalid web session encoding');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $bytes = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($bytes === false) throw new WebException(401, 'invalid_session', 'Invalid web session encoding');
        return $bytes;
    }
}
