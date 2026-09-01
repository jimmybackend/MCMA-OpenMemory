<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\LocalFilesystemAdapter;
use MCMA\Core\Web\EncryptedCookie;
use MCMA\Core\Web\HttpRequest;
use MCMA\Core\Web\OidcClient;
use MCMA\Core\Web\WebApplication;

function assert_web_app(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rrmdir_web(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rrmdir_web($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

$base = sys_get_temp_dir() . '/mcma-web-' . bin2hex(random_bytes(5));
try {
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR=' . $base . '/keys');

    $root = new LocalFilesystemAdapter($base . '/storage');
    $users = new MultiUserService($root, '0123456789abcdef0123456789abcdef0123456789abcdef');
    $sessionCipher = new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef', 'session');
    $stateCipher = new EncryptedCookie('session-secret-0123456789abcdef0123456789abcdef', 'oidc-state');
    $oidc = new OidcClient(
        'https://id.example.test',
        'mcma-client',
        null,
        'https://memory.example.test/callback',
        'openid',
        static function(string $method, string $url, array $headers, string $body): array {
            if($method==='GET'&&$url==='https://id.example.test/.well-known/openid-configuration'){
                return [200,json_encode([
                    'issuer'=>'https://id.example.test',
                    'authorization_endpoint'=>'https://id.example.test/authorize',
                    'token_endpoint'=>'https://id.example.test/token',
                    'jwks_uri'=>'https://id.example.test/jwks',
                ],JSON_THROW_ON_ERROR),[]];
            }
            return [500,'',[]];
        }
    );

    $app = new WebApplication(
        $users,
        $oidc,
        $stateCipher,
        $sessionCipher,
        new ProviderFactory(),
        'https://memory.example.test',
        true,
        true,
        [
            'embedding-provider'=>'none',
            'generation-provider'=>'none',
            'min-confidence'=>0.75,
            'min-similarity'=>0.78,
            'top-k'=>5,
        ],
        3600,
        null,
        null,
        null,
        false,
        1024,
        null,
        '/mcma'
    );

    $home = $app->handle(new HttpRequest('GET', '/mcma'));
    assert_web_app($home->status() === 302, 'Base path did not redirect to login');
    assert_web_app(($home->headers()['location'] ?? null) === '/mcma/login', 'Base path login redirect mismatch');

    $legacyRootLogin = $app->handle(new HttpRequest('GET', '/login'));
    assert_web_app($legacyRootLogin->status() === 404, 'Base-path web unexpectedly exposed root /login');

    $login = $app->handle(new HttpRequest('GET', '/mcma/login'));
    assert_web_app($login->status() === 302, 'Base-path login route failed');
    $loginCookies = $login->headers()['set-cookie'] ?? [];
    assert_web_app(is_array($loginCookies) && isset($loginCookies[0]) && str_contains($loginCookies[0], 'Path=/mcma;'), 'Base-path OIDC cookie scope mismatch');

    $callback = $app->handle(new HttpRequest('GET', '/mcma/callback'));
    assert_web_app($callback->status() === 400, 'Base-path callback route was not recognized');

    $legacyV2 = $app->handle(new HttpRequest('GET', '/mcma/v2/memory'));
    assert_web_app($legacyV2->status() === 404, 'New web app captured legacy /mcma/v2 route');

    $health = $app->handle(new HttpRequest('GET', '/mcma/v1/health'));
    assert_web_app($health->status() === 200, 'Health route failed');

    $unauth = $app->handle(new HttpRequest('GET', '/mcma/v1/me'));
    assert_web_app($unauth->status() === 401, 'Unauthenticated /me was accepted');

    $now = time();
    $aliceCookie = $sessionCipher->seal([
        'v'=>1,
        'iss'=>'https://id.example.test',
        'sub'=>'alice-provider-subject',
        'iat'=>$now,
        'exp'=>$now + 3600,
    ]);
    $bobCookie = $sessionCipher->seal([
        'v'=>1,
        'iss'=>'https://id.example.test',
        'sub'=>'bob-provider-subject',
        'iat'=>$now,
        'exp'=>$now + 3600,
    ]);

    $aliceMe = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/me',
        [],
        [],
        ['mcma_session'=>$aliceCookie]
    ));
    assert_web_app($aliceMe->status() === 200, 'Alice /me failed');
    $aliceBody = json_decode($aliceMe->body(), true, 64, JSON_THROW_ON_ERROR);
    $aliceLibrary = $aliceBody['user']['library_id'] ?? null;
    assert_web_app(is_string($aliceLibrary), 'Alice library id missing');

    $bobMe = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/me',
        [],
        [],
        ['mcma_session'=>$bobCookie]
    ));
    assert_web_app($bobMe->status() === 200, 'Bob /me failed');
    $bobBody = json_decode($bobMe->body(), true, 64, JSON_THROW_ON_ERROR);
    $bobLibrary = $bobBody['user']['library_id'] ?? null;
    assert_web_app(is_string($bobLibrary) && $bobLibrary !== $aliceLibrary, 'Web users share library');

    $ask = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/ask',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['question'=>'What is MCMA?','remember'=>true], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($ask->status() === 200, 'Authenticated ask failed');
    $askBody = json_decode($ask->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($askBody['ok'] ?? false) === true, 'Ask response not ok');
    assert_web_app(is_array($askBody['result'] ?? null), 'Ask result missing');

    $crossOrigin = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/ask',
        ['origin'=>'https://evil.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['question'=>'test'], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($crossOrigin->status() === 403, 'Cross-origin ask was accepted');

    $storageBytes = '';
    foreach ($root->list('') as $locator) {
        $storageBytes .= $root->get($locator)['bytes'];
    }
    assert_web_app(!str_contains($storageBytes, 'alice-provider-subject'), 'Alice subject leaked into storage');
    assert_web_app(!str_contains($storageBytes, 'bob-provider-subject'), 'Bob subject leaked into storage');

    echo "MCMA web multi-user session and ask routing passed.\n";
} finally {
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rrmdir_web($base);
}
