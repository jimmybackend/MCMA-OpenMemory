<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Web\OidcClient;
use MCMA\Core\Web\WebException;

function b64u_web(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function json_b64u_web(array $value): string
{
    return b64u_web(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function assert_web_oidc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = 1777500000;
$key = openssl_pkey_new([
    'private_key_bits'=>2048,
    'private_key_type'=>OPENSSL_KEYTYPE_RSA,
]);
if ($key === false) throw new RuntimeException('Unable to generate RSA key');
$details = openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
    throw new RuntimeException('Unable to read RSA public details');
}

$issuer = 'https://id.example.test';
$clientId = 'mcma-web-client';
$nonce = 'nonce-123';
$header = ['alg'=>'RS256','typ'=>'JWT','kid'=>'test-key'];
$claims = [
    'iss'=>$issuer,
    'sub'=>'subject-123',
    'aud'=>$clientId,
    'exp'=>$now + 600,
    'iat'=>$now,
    'nonce'=>$nonce,
];
$signingInput = json_b64u_web($header) . '.' . json_b64u_web($claims);
$signature = '';
if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Unable to sign test JWT');
}
$idToken = $signingInput . '.' . b64u_web($signature);

$requester = function(string $method, string $url, array $headers, string $body) use ($issuer, $clientId, $idToken, $details): array {
    if ($method === 'GET' && $url === $issuer . '/.well-known/openid-configuration') {
        return [200, json_encode([
            'issuer'=>$issuer,
            'authorization_endpoint'=>$issuer . '/authorize',
            'token_endpoint'=>$issuer . '/token',
            'jwks_uri'=>$issuer . '/jwks',
        ], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'POST' && $url === $issuer . '/token') {
        parse_str($body, $form);
        assert_web_oidc(($form['grant_type'] ?? null) === 'authorization_code', 'grant_type mismatch');
        assert_web_oidc(($form['client_id'] ?? null) === $clientId, 'client_id mismatch');
        assert_web_oidc(($form['code'] ?? null) === 'code-123', 'authorization code mismatch');
        assert_web_oidc(isset($form['code_verifier']) && strlen((string)$form['code_verifier']) >= 43, 'PKCE verifier missing');
        return [200, json_encode(['id_token'=>$idToken], JSON_THROW_ON_ERROR), []];
    }

    if ($method === 'GET' && $url === $issuer . '/jwks') {
        return [200, json_encode(['keys'=>[[
            'kty'=>'RSA',
            'kid'=>'test-key',
            'use'=>'sig',
            'alg'=>'RS256',
            'n'=>b64u_web($details['rsa']['n']),
            'e'=>b64u_web($details['rsa']['e']),
        ]]], JSON_THROW_ON_ERROR), []];
    }

    throw new RuntimeException('Unexpected OIDC request: ' . $method . ' ' . $url);
};

$client = new OidcClient(
    $issuer,
    $clientId,
    'client-secret',
    'https://memory.example.test/callback',
    'openid',
    $requester,
    static fn(): int => $now
);

$authUrl = $client->authorizationUrl('state-123', $nonce, str_repeat('A', 43));
assert_web_oidc(str_starts_with($authUrl, $issuer . '/authorize?'), 'Authorization URL mismatch');
assert_web_oidc(str_contains($authUrl, 'code_challenge_method=S256'), 'PKCE method missing');

$identity = $client->exchangeCode('code-123', str_repeat('v', 43), $nonce);
assert_web_oidc(($identity['issuer'] ?? null) === $issuer, 'Issuer mismatch');
assert_web_oidc(($identity['subject'] ?? null) === 'subject-123', 'Subject mismatch');
assert_web_oidc(($identity['expires_at'] ?? null) === $now + 600, 'Expiry mismatch');

$wrongNonceRejected = false;
try {
    $client->exchangeCode('code-123', str_repeat('v', 43), 'wrong-nonce');
} catch (WebException $e) {
    $wrongNonceRejected = $e->error() === 'invalid_nonce';
}
assert_web_oidc($wrongNonceRejected, 'Wrong nonce was accepted');

echo "MCMA OIDC RS256/JWKS validation passed.\n";
