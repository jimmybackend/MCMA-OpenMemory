<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Cli\ProviderFactory;
use MCMA\Core\Knowledge\KnowledgeService;
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
        'email'=>'alice@example.test',
        'name'=>'Alice Example',
        'picture'=>'https://images.example.test/alice.png',
        'email_verified'=>true,
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
    assert_web_app(($aliceBody['identity']['email']??null)==='alice@example.test','OIDC email was not exposed from encrypted session');
    assert_web_app(($aliceBody['identity']['name']??null)==='Alice Example','OIDC name was not exposed from encrypted session');
    assert_web_app(($aliceBody['identity']['picture']??null)==='https://images.example.test/alice.png','OIDC picture was not exposed from encrypted session');
    assert_web_app(($aliceBody['identity']['email_verified']??null)===true,'OIDC email verification flag missing');

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

    $aliceKnowledge = new KnowledgeService($users->resolve('https://id.example.test','alice-provider-subject'));
    $seed = $aliceKnowledge->capture(
        'librarian',
        'Saved explorer question',
        'Saved explorer answer.',
        'text',
        0.5,
        'unverified',
        [['source_type'=>'working-test','reference'=>'web-memory-explorer-seed']],
        'stable',
        86400,
        'reuse-unless-stale'
    );
    $memoryId = substr((string)$seed['logical_ref'], strlen('memory://knowledge/q-'));

    $memoryList = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/memories',
        [],
        ['q'=>'explorer','page'=>'1','limit'=>'25'],
        ['mcma_session'=>$aliceCookie]
    ));
    assert_web_app($memoryList->status() === 200, 'Memory explorer list failed');
    $memoryListBody = json_decode($memoryList->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($memoryListBody['memory']['total']??0) === 1, 'Memory explorer list count mismatch');
    assert_web_app(($memoryListBody['memory']['items'][0]['question']??null) === 'Saved explorer question', 'Memory explorer question missing');
    assert_web_app(($memoryListBody['memory']['ai_tokens_used']??-1) === 0, 'Memory explorer list used AI tokens');

    $memoryDetail = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/memories/'.$memoryId,
        [],
        [],
        ['mcma_session'=>$aliceCookie]
    ));
    assert_web_app($memoryDetail->status() === 200, 'Memory explorer detail failed');
    $memoryDetailBody = json_decode($memoryDetail->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($memoryDetailBody['memory']['answer']['value']??null) === 'Saved explorer answer.', 'Memory explorer did not decrypt answer');
    assert_web_app(($memoryDetailBody['memory']['ai_tokens_used']??-1) === 0, 'Memory explorer detail used AI tokens');

    $bobMemoryList = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/memories',
        [],
        ['page'=>'1','limit'=>'25'],
        ['mcma_session'=>$bobCookie]
    ));
    $bobMemoryListBody = json_decode($bobMemoryList->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($bobMemoryListBody['memory']['total']??-1) === 0, 'Bob could browse Alice memory');

    $confirmMemory = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/memories/'.$memoryId.'/validation',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['action'=>'confirm'], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($confirmMemory->status() === 200, 'Memory confirmation failed');
    $confirmBody = json_decode($confirmMemory->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($confirmBody['memory']['validation_state']??null) === 'verified', 'Memory confirmation did not verify record');
    assert_web_app(abs((float)($confirmBody['memory']['confidence']??0)-0.95) < 1e-12, 'Memory confirmation confidence mismatch');
    assert_web_app(($confirmBody['validation']['ai_tokens_used']??-1) === 0, 'Memory confirmation used AI tokens');
    assert_web_app(($confirmBody['validation']['credit_units_charged']??-1) === 0, 'Memory confirmation charged credits');

    $confirmAgain = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/memories/'.$memoryId.'/validation',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['action'=>'confirm'], JSON_THROW_ON_ERROR)
    ));
    $confirmAgainBody = json_decode($confirmAgain->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($confirmAgainBody['validation']['unchanged']??false) === true, 'Repeated memory confirmation was not idempotent');

    $crossOriginValidation = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/memories/'.$memoryId.'/validation',
        ['origin'=>'https://evil.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['action'=>'discard'], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($crossOriginValidation->status() === 403, 'Cross-origin memory validation was accepted');

    $discardMemory = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/memories/'.$memoryId.'/validation',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['action'=>'discard'], JSON_THROW_ON_ERROR)
    ));
    $discardBody = json_decode($discardMemory->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($discardBody['memory']['validation_state']??null) === 'retracted', 'Memory discard did not retract record');
    assert_web_app(($discardBody['validation']['ai_tokens_used']??-1) === 0, 'Memory discard used AI tokens');

    $explicitAsk = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/ask',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['question'=>'Guarda esto: Mi editor preferido es Vim.','remember'=>false], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($explicitAsk->status() === 200, 'Explicit memory through /ask failed');
    $explicitAskBody = json_decode($explicitAsk->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($explicitAskBody['result']['route']??null) === 'memory-capture', 'Explicit /ask intent was not routed to memory capture');
    assert_web_app(($explicitAskBody['result']['stored']??false) === true, 'Explicit /ask memory was not stored');
    assert_web_app(str_starts_with((string)($explicitAskBody['result']['logical_ref']??''),'memory://user/knowledge/'), 'Explicit /ask canonical route mismatch');
    assert_web_app(($explicitAskBody['result']['storage']['classification']['cognitive_layer']??null) === '40-semantic', 'No-provider explicit memory fallback classification mismatch');

    $explicitEndpoint = $app->handle(new HttpRequest(
        'POST',
        '/mcma/v1/memory',
        ['origin'=>'https://memory.example.test','content-type'=>'application/json'],
        [],
        ['mcma_session'=>$aliceCookie],
        json_encode(['text'=>'Las decisiones de despliegue de MCMA deben documentarse.'], JSON_THROW_ON_ERROR)
    ));
    assert_web_app($explicitEndpoint->status() === 200, 'Explicit /memory endpoint failed');
    $explicitEndpointBody = json_decode($explicitEndpoint->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($explicitEndpointBody['result']['route']??null) === 'memory-capture', 'Explicit /memory route mismatch');
    assert_web_app(($explicitEndpointBody['result']['stored']??false) === true, 'Explicit /memory endpoint did not store memory');

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
    assert_web_app(($askBody['result']['context_trace']['recorded']??false) === true, 'Ask context trace was not recorded');

    $aliceContext = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/context',
        [],
        [],
        ['mcma_session'=>$aliceCookie]
    ));
    assert_web_app($aliceContext->status() === 200, 'Alice context transparency route failed');
    $aliceContextBody = json_decode($aliceContext->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(($aliceContextBody['context']['ai_tokens_used']??-1) === 0, 'Context transparency read used AI tokens');
    assert_web_app(($aliceContextBody['context']['credit_units_charged']??-1) === 0, 'Context transparency read charged credits');
    assert_web_app(count($aliceContextBody['context']['traces']??[]) >= 1, 'Alice context trace list is empty');
    assert_web_app(($aliceContextBody['context']['traces'][0]['question']??null) === 'What is MCMA?', 'Context trace question mismatch');

    $bobContext = $app->handle(new HttpRequest(
        'GET',
        '/mcma/v1/context',
        [],
        [],
        ['mcma_session'=>$bobCookie]
    ));
    assert_web_app($bobContext->status() === 200, 'Bob context transparency route failed');
    $bobContextBody = json_decode($bobContext->body(), true, 64, JSON_THROW_ON_ERROR);
    assert_web_app(count($bobContextBody['context']['traces']??[]) === 0, 'Bob could see Alice context traces');

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
    assert_web_app(!str_contains($storageBytes, 'alice@example.test'), 'Alice email leaked into MCMA storage');
    assert_web_app(!str_contains($storageBytes, 'Alice Example'), 'Alice profile name leaked into MCMA storage');
    assert_web_app(!str_contains($storageBytes, 'bob-provider-subject'), 'Bob subject leaked into storage');

    echo "MCMA web multi-user session and ask routing passed.\n";
} finally {
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR');
    rrmdir_web($base);
}
