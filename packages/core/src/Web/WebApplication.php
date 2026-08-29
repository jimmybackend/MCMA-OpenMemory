<?php
declare(strict_types=1);

namespace MCMA\Core\Web;

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Ask\AskService;
use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Semantic\SemanticIndexService;
use RuntimeException;
use Throwable;

final class WebApplication
{
    private const STATE_COOKIE = 'mcma_oidc_state';
    private const SESSION_COOKIE = 'mcma_session';

    public function __construct(
        private readonly MultiUserService $users,
        private readonly OidcClient $oidc,
        private readonly EncryptedCookie $stateCookieCipher,
        private readonly EncryptedCookie $sessionCookieCipher,
        private readonly ProviderFactory $providers,
        private readonly string $publicOrigin,
        private readonly bool $autoRegister,
        private readonly bool $selfRegister,
        private readonly array $providerOptions,
        private readonly int $sessionTtl = 28800
    ) {
        if (!preg_match('#^https://[^/]+$#', $this->publicOrigin)) {
            throw new RuntimeException('MCMA web public origin must be an HTTPS origin without a path');
        }
        if ($this->sessionTtl < 300 || $this->sessionTtl > 604800) {
            throw new RuntimeException('MCMA web session TTL must be between 300 and 604800 seconds');
        }
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->dispatch($request);
        } catch (WebException $e) {
            $response = HttpResponse::json([
                'ok'=>false,
                'error'=>$e->error(),
                'message'=>$e->getMessage(),
            ], $e->status());
        } catch (Throwable $e) {
            error_log('MCMA web error: ' . $e->getMessage());
            $response = HttpResponse::json([
                'ok'=>false,
                'error'=>'internal_error',
                'message'=>'Internal server error',
            ], 500);
        }

        return $this->secure($response);
    }

    private function dispatch(HttpRequest $request): HttpResponse
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';

        if ($method === 'GET' && $path === '/mcma/v1/health') {
            return HttpResponse::json([
                'ok'=>true,
                'service'=>'mcma-web',
                'version'=>'1.0',
                'multi_user'=>true,
            ]);
        }

        if ($method === 'GET' && $path === '/login') return $this->login();
        if ($method === 'GET' && $path === '/callback') return $this->callback($request);

        if ($method === 'POST' && $path === '/logout') {
            $this->assertOrigin($request);
            return HttpResponse::redirect('/', [
                'set-cookie'=>[$this->clearCookie(self::SESSION_COOKIE)],
            ], 303);
        }

        if ($method === 'GET' && $path === '/mcma/v1/me') {
            [$issuer,$subject] = $this->sessionIdentity($request);
            $this->ensureRegistered($issuer, $subject);
            return HttpResponse::json(['ok'=>true,'user'=>$this->users->info($issuer,$subject)]);
        }

        if ($method === 'POST' && $path === '/mcma/v1/register') {
            $this->assertOrigin($request);
            if (!$this->selfRegister && !$this->autoRegister) {
                throw new WebException(403, 'self_registration_disabled', 'Self-registration is disabled');
            }
            [$issuer,$subject] = $this->sessionIdentity($request);
            return HttpResponse::json(['ok'=>true,'user'=>$this->users->register($issuer,$subject)], 201);
        }

        if ($method === 'POST' && $path === '/mcma/v1/ask') {
            $this->assertOrigin($request);
            [$issuer,$subject] = $this->sessionIdentity($request);
            $this->ensureRegistered($issuer, $subject);
            $library = $this->users->resolve($issuer, $subject);

            $input = $request->json(65536);
            $question = trim((string)($input['question'] ?? ''));
            if ($question === '' || strlen($question) > 32768) {
                throw new WebException(400, 'invalid_question', 'question is required and must be <= 32768 bytes');
            }

            $current = $this->boolField($input, 'current', false);
            $remember = $this->boolField($input, 'remember', true);

            $knowledge = new KnowledgeService($library);
            $embedding = $this->providers->embedding($this->providerOptions, true);
            $semantic = $embedding !== null ? new SemanticIndexService($library) : null;
            $librarian = $embedding !== null
                ? new Librarian($knowledge, $semantic, $embedding)
                : new Librarian($knowledge);
            $generator = $this->providers->generation($this->providerOptions);

            $ask = new AskService($knowledge, $semantic, $embedding, $generator, $librarian);
            $freshness = (string)($this->providerOptions['capture-freshness'] ?? 'stable');
            $maxAge = $freshness === 'immutable'
                ? null
                : (int)($this->providerOptions['capture-max-age'] ?? 2592000);

            $result = $ask->ask(
                'ai',
                $question,
                $current,
                (float)($this->providerOptions['min-confidence'] ?? 0.75),
                (float)($this->providerOptions['min-similarity'] ?? 0.78),
                (int)($this->providerOptions['top-k'] ?? 5),
                $remember,
                [
                    'confidence'=>(float)($this->providerOptions['capture-confidence'] ?? 0.5),
                    'validation_state'=>(string)($this->providerOptions['capture-validation'] ?? 'unverified'),
                    'freshness_class'=>$freshness,
                    'max_age_seconds'=>$maxAge,
                    'reuse_policy'=>(string)($this->providerOptions['capture-reuse'] ?? 'reuse-unless-stale'),
                    'provenance'=>[],
                ]
            );

            return HttpResponse::json(['ok'=>true,'result'=>$result]);
        }

        throw new WebException(404, 'not_found', 'Route not found');
    }

    private function login(): HttpResponse
    {
        $state = self::token(32);
        $nonce = self::token(32);
        $verifier = self::token(48);
        $challenge = self::b64u(hash('sha256', $verifier, true));
        $now = time();

        $cookie = $this->stateCookieCipher->seal([
            'state'=>$state,
            'nonce'=>$nonce,
            'verifier'=>$verifier,
            'iat'=>$now,
            'exp'=>$now + 600,
        ]);

        return HttpResponse::redirect(
            $this->oidc->authorizationUrl($state, $nonce, $challenge),
            ['set-cookie'=>[$this->cookie(self::STATE_COOKIE, $cookie, 600, 'Lax')]]
        );
    }

    private function callback(HttpRequest $request): HttpResponse
    {
        $error = $request->query('error');
        if ($error !== null) throw new WebException(401, 'oidc_authorization_failed', 'OIDC authorization failed');

        $stateCookie = $request->cookie(self::STATE_COOKIE);
        if ($stateCookie === null) throw new WebException(400, 'missing_oidc_state', 'OIDC state cookie is missing');
        $state = $this->stateCookieCipher->open($stateCookie);

        $now = time();
        if (!is_int($state['exp'] ?? null) || $state['exp'] < $now) {
            throw new WebException(400, 'expired_oidc_state', 'OIDC login state expired');
        }
        $queryState = $request->query('state');
        if (!is_string($queryState) || !is_string($state['state'] ?? null) || !hash_equals($state['state'], $queryState)) {
            throw new WebException(400, 'invalid_oidc_state', 'OIDC state validation failed');
        }

        $code = $request->query('code') ?? '';
        $identity = $this->oidc->exchangeCode(
            $code,
            (string)($state['verifier'] ?? ''),
            (string)($state['nonce'] ?? '')
        );

        if ($this->autoRegister) {
            $this->users->register($identity['issuer'], $identity['subject']);
        }

        $expiresAt = min((int)$identity['expires_at'], $now + $this->sessionTtl);
        $session = $this->sessionCookieCipher->seal([
            'v'=>1,
            'iss'=>$identity['issuer'],
            'sub'=>$identity['subject'],
            'iat'=>$now,
            'exp'=>$expiresAt,
        ]);

        return HttpResponse::redirect('/', [
            'set-cookie'=>[
                $this->cookie(self::SESSION_COOKIE, $session, max(1, $expiresAt - $now), 'Strict'),
                $this->clearCookie(self::STATE_COOKIE),
            ],
        ], 303);
    }

    private function sessionIdentity(HttpRequest $request): array
    {
        $cookie = $request->cookie(self::SESSION_COOKIE);
        if ($cookie === null) throw new WebException(401, 'authentication_required', 'Authentication required');

        $session = $this->sessionCookieCipher->open($cookie);
        if (($session['v'] ?? null) !== 1) throw new WebException(401, 'invalid_session', 'Unsupported web session');

        $exp = $session['exp'] ?? null;
        if (!is_int($exp) || $exp < time()) throw new WebException(401, 'session_expired', 'Web session expired');

        $issuer = $session['iss'] ?? null;
        $subject = $session['sub'] ?? null;
        if (!is_string($issuer) || $issuer === '' || !is_string($subject) || $subject === '') {
            throw new WebException(401, 'invalid_session', 'Web session identity is invalid');
        }
        return [$issuer,$subject];
    }

    private function ensureRegistered(string $issuer, string $subject): void
    {
        if ($this->autoRegister) {
            $this->users->register($issuer, $subject);
            return;
        }

        try {
            $this->users->resolve($issuer, $subject);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not registered')) {
                throw new WebException(403, 'user_not_registered', 'Authenticated user is not registered');
            }
            if (str_contains($e->getMessage(), 'not active')) {
                throw new WebException(403, 'user_disabled', 'Authenticated user is disabled');
            }
            throw $e;
        }
    }

    private function assertOrigin(HttpRequest $request): void
    {
        $origin = $request->header('origin');
        if ($origin !== null && !hash_equals($this->publicOrigin, rtrim($origin, '/'))) {
            throw new WebException(403, 'origin_rejected', 'Request origin is not allowed');
        }
    }

    private function boolField(array $input, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $input)) return $default;
        if (!is_bool($input[$name])) throw new WebException(400, 'invalid_boolean', $name . ' must be boolean');
        return $input[$name];
    }

    private function cookie(string $name, string $value, int $maxAge, string $sameSite): string
    {
        return $name . '=' . rawurlencode($value)
            . '; Path=/; Max-Age=' . $maxAge
            . '; Secure; HttpOnly; SameSite=' . $sameSite;
    }

    private function clearCookie(string $name): string
    {
        return $name . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    private function secure(HttpResponse $response): HttpResponse
    {
        return new HttpResponse(
            $response->status(),
            $response->body(),
            $response->headers() + [
                'cache-control'=>'no-store',
                'x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer',
                'x-frame-options'=>'DENY',
                'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            ]
        );
    }

    private static function token(int $bytes): string
    {
        return self::b64u(random_bytes($bytes));
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
