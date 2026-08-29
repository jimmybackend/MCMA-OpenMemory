<?php
declare(strict_types=1);

namespace MCMA\Core;

use JsonException;
use RuntimeException;

final class Jcs
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return self::encodeValue($value);
    }

    private static function encodeValue(mixed $value): string
    {
        if ($value === null) return 'null';
        if ($value === true) return 'true';
        if ($value === false) return 'false';
        if (is_int($value)) return (string) $value;

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new RuntimeException('JCS does not allow non-finite numbers');
            }
            throw new RuntimeException('Floating-point JSON values are not supported by the first MCMA 1.0 PHP writer');
        }

        if (is_string($value)) {
            try {
                return json_encode($value, self::JSON_FLAGS);
            } catch (JsonException $e) {
                throw new RuntimeException('Invalid UTF-8 string for JCS', 0, $e);
            }
        }

        if (!is_array($value)) {
            throw new RuntimeException('Unsupported value type for JCS');
        }

        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) $parts[] = self::encodeValue($item);
            return '[' . implode(',', $parts) . ']';
        }

        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('JCS object keys must be strings');
            }
        }

        usort($keys, static function (string $a, string $b): int {
            if (function_exists('iconv')) {
                $ua = iconv('UTF-8', 'UTF-16BE', $a);
                $ub = iconv('UTF-8', 'UTF-16BE', $b);
                if ($ua !== false && $ub !== false) return strcmp($ua, $ub);
            }
            return strcmp($a, $b);
        });

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::encodeValue($key) . ':' . self::encodeValue($value[$key]);
        }
        return '{' . implode(',', $parts) . '}';
    }
}
