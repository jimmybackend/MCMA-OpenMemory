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
        if (is_int($value)) return (string)$value;

        if (is_float($value)) {
            return self::encodeFloat($value);
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
            if (!is_string($key)) throw new RuntimeException('JCS object keys must be strings');
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

    private static function encodeFloat(float $value): string
    {
        if (!is_finite($value)) throw new RuntimeException('JCS does not allow NaN or Infinity');
        if ($value == 0.0) return '0';

        try {
            $raw = strtolower(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to serialize JCS number', 0, $e);
        }

        if (!str_contains($raw, 'e')) return $raw;

        $sign = '';
        if ($raw[0] === '-') {
            $sign = '-';
            $raw = substr($raw, 1);
        }

        [$mantissa, $exponentRaw] = explode('e', $raw, 2);
        $exponent = (int)$exponentRaw;

        if (str_contains($mantissa, '.')) {
            $mantissa = rtrim($mantissa, '0');
            $mantissa = rtrim($mantissa, '.');
        }

        // ECMAScript JSON.stringify uses plain decimal notation for
        // 1e-6 <= abs(n) < 1e21 and scientific notation outside that range.
        if ($exponent >= -6 && $exponent < 21) {
            $dot = strpos($mantissa, '.');
            $integerDigits = $dot === false ? strlen($mantissa) : $dot;
            $digits = str_replace('.', '', $mantissa);
            $decimalPosition = $integerDigits + $exponent;

            if ($decimalPosition <= 0) {
                return $sign . '0.' . str_repeat('0', -$decimalPosition) . $digits;
            }

            if ($decimalPosition >= strlen($digits)) {
                return $sign . $digits . str_repeat('0', $decimalPosition - strlen($digits));
            }

            return $sign
                . substr($digits, 0, $decimalPosition)
                . '.'
                . substr($digits, $decimalPosition);
        }

        return $sign . $mantissa . 'e' . ($exponent >= 0 ? '+' : '') . $exponent;
    }
}
