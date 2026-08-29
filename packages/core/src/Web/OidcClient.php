<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use JsonException;
use RuntimeException;

final class OidcClient
{
    /** @var null|callable */
    private $requester;
    /** @var callable */
    private $clock;
    private ?array $discoveryCache = null;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $scope = 'openid',
        ?callable $requester = null,
        ?callable $clock = null
    ) {
        self::assertHttpsUrl($this->issuer, 'OIDC issuer');
        if ($this->clientId === '' || strlen($this->clientId) > 512) throw new RuntimeException('OIDC client id is required');
        if ($this->redirectUri === '' || strlen($this->redirectUri) > 2048) throw new RuntimeException('OIDC redirect URI is required');
        self::assertHttpsUrl($this->redirectUri, 'OIDC redirect URI');
        if (!preg_match('/^openid(?: [A-Za-z0-9._:-]+)*$/', trim($this->scope))) throw new RuntimeException('OIDC scope must include openid and safe scope tokens');

        $this->requester = $requester;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        foreach ([$state, $nonce, $codeChallenge] as $value) {
            if ($value === '' || strlen($value) > 512) throw new RuntimeException('Invalid OIDC authorization parameter');
        }

        $discovery = $this->discovery();
        return $discovery['authorization_endpoint'] . '?' . http_build_query([
            'response_type'=>'code',
            'client_id'=>$this->clientId,
            'redirect_uri'=>$this->redirectUri,
            'scope'=>$this->scope,
            'state'=>$state,
            'nonce'=>$nonce,
            'code_challenge'=>$codeChallenge,
            'code_challenge_method'=>'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $expectedNonce): array
    {
        if ($code === '' || strlen($code) > 4096) throw new WebException(400, 'invalid_code', 'OIDC authorization code is invalid');
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier)) throw new WebException(400, 'invalid_pkce', 'OIDC PKCE verifier is invalid');
        if ($expectedNonce === '' || strlen($expectedNonce) > 512) throw new WebException(400, 'invalid_nonce', 'OIDC nonce is invalid');

        $discovery = $this->discovery();
        $form = [
            'grant_type'=>'authorization_code',
            'code'=>$code,
            'redirect_uri'=>$this->redirectUri,
            'client_id'=>$this->clientId,
            'code_verifier'=>$codeVerifier,
        ];
        if (($this->clientSecret ?? '') !== '') $form['client_secret'] = $this->clientSecret;

        [$status, $body] = $this->request(
            'POST',
            $discovery['token_endpoint'],
            ['content-type'=>'application/x-www-form-urlencoded','accept'=>'application/json'],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986)
        );
        if ($status !== 200) throw new WebException(401, 'token_exchange_failed', 'OIDC token exchange failed');

        $tokens = self::jsonObject($body, 'OIDC token response');
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new WebException(401, 'missing_id_token', 'OIDC token response did not include an ID token');
        }

        $claims = $this->validateIdToken($idToken, $expectedNonce, $discovery['jwks_uri']);
        return [
            'issuer'=>(string)$claims['iss'],
            'subject'=>(string)$claims['sub'],
            'expires_at'=>(int)$claims['exp'],
            'claims'=>$claims,
        ];
    }

    public function validateIdToken(string $jwt, string $expectedNonce, string $jwksUri): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new WebException(401, 'invalid_id_token', 'OIDC ID token is malformed');

        $header = self::jsonObject(self::b64uDecode($parts[0]), 'OIDC JWT header');
        $claims = self::jsonObject(self::b64uDecode($parts[1]), 'OIDC JWT claims');
        $signature = self::b64uDecode($parts[2]);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new WebException(401, 'unsupported_jwt_algorithm', 'OIDC ID token must use RS256');
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 512) {
            throw new WebException(401, 'missing_jwt_kid', 'OIDC ID token is missing kid');
        }

        self::assertHttpsUrl($jwksUri, 'OIDC JWKS URI');
        [$status, $jwksBody] = $this->request('GET', $jwksUri, ['accept'=>'application/json']);
        if ($status !== 200) throw new WebException(503, 'jwks_unavailable', 'OIDC JWKS could not be loaded');
        $jwks = self::jsonObject($jwksBody, 'OIDC JWKS response');

        $key = null;
        foreach (($jwks['keys'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            if (($candidate['kid'] ?? null) !== $kid) continue;
            if (($candidate['kty'] ?? null) !== 'RSA') continue;
            if (isset($candidate['use']) && $candidate['use'] !== 'sig') continue;
            if (isset($candidate['alg']) && $candidate['alg'] !== 'RS256') continue;
            $key = $candidate;
            break;
        }
        if ($key === null) throw new WebException(401, 'unknown_jwt_key', 'OIDC signing key was not found');

        $publicKey = openssl_pkey_get_public(self::jwkToPem($key));
        if ($publicKey === false) throw new WebException(401, 'invalid_jwt_key', 'OIDC signing key is invalid');

        $verified = openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) throw new WebException(401, 'invalid_id_token_signature', 'OIDC ID token signature validation failed');

        $now = (int)($this->clock)();
        $skew = 60;

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new WebException(401, 'invalid_issuer', 'OIDC issuer does not match configured issuer');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (!in_array($this->clientId, $audiences, true)) {
            throw new WebException(401, 'invalid_audience', 'OIDC audience does not include this client');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) {
            throw new WebException(401, 'invalid_authorized_party', 'OIDC azp is required for multiple audiences');
        }
        if (isset($claims['azp']) && $claims['azp'] !== $this->clientId) {
            throw new WebException(401, 'invalid_authorized_party', 'OIDC azp does not match this client');
        }

        $exp = $claims['exp'] ?? null;
        if (!is_int($exp) || $exp < $now - $skew) {
            throw new WebException(401, 'expired_id_token', 'OIDC ID token is expired');
        }
        if (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + $skew)) {
            throw new WebException(401, 'id_token_not_yet_valid', 'OIDC ID token is not yet valid');
        }
        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + $skew)) {
            throw new WebException(401, 'invalid_id_token_iat', 'OIDC ID token iat is invalid');
        }

        if (($claims['nonce'] ?? null) !== $expectedNonce) {
            throw new WebException(401, 'invalid_nonce', 'OIDC nonce validation failed');
        }
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '' || strlen($sub) > 2048) {
            throw new WebException(401, 'invalid_subject', 'OIDC subject is invalid');
        }

        return $claims;
    }

    private function discovery(): array
    {
        if ($this->discoveryCache !== null) return $this->discoveryCache;

        $url = rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
        [$status, $body] = $this->request('GET', $url, ['accept'=>'application/json']);
        if ($status !== 200) throw new WebException(503, 'oidc_discovery_unavailable', 'OIDC discovery could not be loaded');

        $discovery = self::jsonObject($body, 'OIDC discovery response');
        if (($discovery['issuer'] ?? null) !== $this->issuer) {
            throw new WebException(503, 'oidc_discovery_mismatch', 'OIDC discovery issuer mismatch');
        }
        foreach (['authorization_endpoint','token_endpoint','jwks_uri'] as $name) {
            if (!is_string($discovery[$name] ?? null)) throw new WebException(503, 'oidc_discovery_invalid', 'OIDC discovery is missing ' . $name);
            self::assertHttpsUrl($discovery[$name], 'OIDC ' . $name);
        }

        $this->discoveryCache = $discovery;
        return $discovery;
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        if ($this->requester !== null) {
            $result = ($this->requester)(strtoupper($method), $url, $headers, $body);
            if (!is_array($result) || count($result) < 2) throw new RuntimeException('Invalid OIDC requester result');
            return [(int)$result[0], (string)$result[1], is_array($result[2] ?? null) ? $result[2] : []];
        }
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for OIDC');

        $wire = [];
        foreach ($headers as $name=>$value) $wire[] = $name . ': ' . $value;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_HTTPHEADER=>$wire,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false,
        ]);
        if (strtoupper($method) === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new WebException(503, 'oidc_http_error', 'OIDC HTTP request failed: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string)$response, []];
    }

    private static function jwkToPem(array $jwk): string
    {
        $n = self::b64uDecode((string)($jwk['n'] ?? ''));
        $e = self::b64uDecode((string)($jwk['e'] ?? ''));
        if (strlen(ltrim($n, "\0")) < 256) {
            throw new WebException(401, 'weak_jwt_key', 'OIDC RSA signing key must be at least 2048 bits');
        }
        if ($e === '') throw new WebException(401, 'invalid_jwt_key', 'OIDC RSA exponent is missing');

        $rsa = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) throw new RuntimeException('Unable to build RSA algorithm identifier');
        $spki = self::derSequence($algorithm . self::derBitString($rsa));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '') $bytes = "\0";
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\0" . $bytes;
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derBitString(string $bytes): string
    {
        $bytes = "\0" . $bytes;
        return "\x03" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function jsonObject(string $body, string $label): array
    {
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WebException(401, 'invalid_oidc_json', $label . ' is not valid JSON', $e);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new WebException(401, 'invalid_oidc_json', $label . ' must be a JSON object');
        }
        return $value;
    }

    private static function b64uDecode(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new WebException(401, 'invalid_jwt_encoding', 'OIDC JWT contains invalid base64url');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) throw new WebException(401, 'invalid_jwt_encoding', 'OIDC JWT contains invalid base64url');
        return $decoded;
    }

    private static function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException($label . ' must be an absolute HTTPS URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException($label . ' must not include credentials or fragment');
        }
    }
}
