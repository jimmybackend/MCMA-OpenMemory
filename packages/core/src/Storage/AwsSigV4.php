<?php
declare(strict_types=1);

namespace MCMA\Core\Storage;

use RuntimeException;

final class AwsSigV4
{
    public static function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        string $body,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service = 's3',
        ?string $amzDate = null,
        ?string $sessionToken = null
    ): array {
        if ($accessKey === '' || $secretKey === '') throw new RuntimeException('AWS credentials are required');
        $method = strtoupper($method);
        $amzDate ??= gmdate('Ymd\THis\Z');
        if (!preg_match('/^\d{8}T\d{6}Z$/', $amzDate)) throw new RuntimeException('Invalid SigV4 timestamp');
        $date = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);

        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || $name === 'authorization') continue;
            $normalized[$name] = self::normalizeHeaderValue((string)$value);
        }
        $normalized['host'] = self::normalizeHeaderValue($host);
        $normalized['x-amz-content-sha256'] = $payloadHash;
        $normalized['x-amz-date'] = $amzDate;
        if ($sessionToken !== null && $sessionToken !== '') $normalized['x-amz-security-token'] = self::normalizeHeaderValue($sessionToken);
        ksort($normalized, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) $canonicalHeaders .= $name . ':' . $value . "\n";
        $signedHeaders = implode(';', array_keys($normalized));
        $canonicalRequest = $method . "\n"
            . self::canonicalUri($path) . "\n"
            . self::canonicalQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $normalized['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ',SignedHeaders=' . $signedHeaders . ',Signature=' . $signature;

        return [
            'headers' => $normalized,
            'canonical_request' => $canonicalRequest,
            'string_to_sign' => $stringToSign,
            'signature' => $signature,
            'signed_headers' => $signedHeaders,
            'payload_hash' => $payloadHash,
        ];
    }

    public static function canonicalUri(string $path): string
    {
        if ($path === '') $path = '/';
        if ($path[0] !== '/') $path = '/' . $path;
        $segments = explode('/', $path);
        return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
    }

    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) $pairs[] = [rawurlencode((string)$name), rawurlencode((string)$item)];
            } else {
                $pairs[] = [rawurlencode((string)$name), rawurlencode((string)$value)];
            }
        }
        usort($pairs, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        return implode('&', array_map(static fn(array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    private static function normalizeHeaderValue(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }
}
