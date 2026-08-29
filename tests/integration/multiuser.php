<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\MultiUser\MultiUserService;
use MCMA\Core\Storage\LocalFilesystemAdapter;
$base = sys_get_temp_dir() . '/mcma-multiuser-' . bin2hex(random_bytes(5));
$storageRoot = $base . '/storage';
$keyDir = $base . '/keys';

function rrmdir_multi(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rrmdir_multi($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function assert_multiuser(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_KEY_DIR=' . $keyDir);
    putenv('MCMA_MULTIUSER_PEPPER=0123456789abcdef0123456789abcdef0123456789abcdef');

    $root = new LocalFilesystemAdapter($storageRoot);
    $service = MultiUserService::fromEnvironment($root);

    $bootstrap = $service->bootstrap();
    assert_multiuser(($bootstrap['initialized'] ?? false) === true, 'Registry bootstrap failed');
    assert_multiuser(str_starts_with((string)$bootstrap['registry_library_id'], 'lib_'), 'Registry library id missing');

    $issuer = 'https://identity.example.test';
    $aliceSubject = 'alice@example.test';
    $bobSubject = 'bob@example.test';

    $alice = $service->register($issuer, $aliceSubject);
    $bob = $service->register($issuer, $bobSubject);

    assert_multiuser(($alice['created'] ?? null) === true, 'Alice should be newly created');
    assert_multiuser(($bob['created'] ?? null) === true, 'Bob should be newly created');
    assert_multiuser($alice['user_id'] !== $bob['user_id'], 'Users share user_id');
    assert_multiuser($alice['library_id'] !== $bob['library_id'], 'Users share library_id');
    assert_multiuser($alice['storage_prefix'] === 'memories/' . $alice['user_id'], 'Alice prefix mismatch');
    assert_multiuser($bob['storage_prefix'] === 'memories/' . $bob['user_id'], 'Bob prefix mismatch');
    assert_multiuser(!array_key_exists('identity_fingerprint', $alice), 'Public Alice record exposed identity fingerprint');

    $aliceAgain = $service->register($issuer, $aliceSubject);
    assert_multiuser(($aliceAgain['created'] ?? null) === false, 'Repeated registration should be idempotent');
    assert_multiuser($aliceAgain['user_id'] === $alice['user_id'], 'Repeated registration changed user_id');
    assert_multiuser($aliceAgain['library_id'] === $alice['library_id'], 'Repeated registration changed library_id');

    $aliceLibrary = $service->resolve($issuer, $aliceSubject);
    $bobLibrary = $service->resolve($issuer, $bobSubject);

    $aliceLibrary->writeAs(
        'owner',
        'memory://projects/alice-private',
        'Alice-only memory',
        'text',
        'hot',
        '90-projects',
        'user',
        'confirmed'
    );

    $aliceRead = $aliceLibrary->readAs('owner', 'memory://projects/alice-private');
    assert_multiuser(($aliceRead['payload']['content'] ?? null) === 'Alice-only memory', 'Alice memory write/read failed');

    $bobRefs = [];
    foreach ($bobLibrary->listAs('owner') as $entry) {
        foreach (($entry['logical_refs'] ?? []) as $ref) $bobRefs[] = $ref;
    }
    assert_multiuser(!in_array('memory://projects/alice-private', $bobRefs, true), 'Bob can see Alice logical reference');

    $bobReadBlocked = false;
    try {
        $bobLibrary->readAs('owner', 'memory://projects/alice-private');
    } catch (RuntimeException $e) {
        $bobReadBlocked = str_contains($e->getMessage(), 'Memory not found');
    }
    assert_multiuser($bobReadBlocked, 'Bob could read Alice memory');

    $users = $service->listUsers();
    assert_multiuser(count($users) === 2, 'Expected two registered users');
    foreach ($users as $record) {
        assert_multiuser(!array_key_exists('identity_fingerprint', $record), 'User list exposed identity fingerprint');
        assert_multiuser(isset($record['user_id'], $record['library_id'], $record['storage_prefix'], $record['status']), 'User list record incomplete');
    }

    $allLocators = $root->list('');
    assert_multiuser(in_array('system/user-registry/manifest.mcma', $allLocators, true), 'Encrypted registry manifest missing');
    assert_multiuser(in_array($alice['storage_prefix'] . '/manifest.mcma', $allLocators, true), 'Alice manifest missing');
    assert_multiuser(in_array($bob['storage_prefix'] . '/manifest.mcma', $allLocators, true), 'Bob manifest missing');

    foreach ($allLocators as $locator) {
        $bytes = $root->get($locator)['bytes'];
        assert_multiuser(!str_contains($bytes, $aliceSubject), 'Alice subject leaked in storage: ' . $locator);
        assert_multiuser(!str_contains($bytes, $bobSubject), 'Bob subject leaked in storage: ' . $locator);
        assert_multiuser(!str_contains($bytes, $issuer), 'Issuer leaked in storage: ' . $locator);
    }

    $disabled = $service->disable($issuer, $aliceSubject);
    assert_multiuser(($disabled['status'] ?? null) === 'disabled', 'Alice disable failed');

    $disabledBlocked = false;
    try {
        $service->resolve($issuer, $aliceSubject);
    } catch (RuntimeException $e) {
        $disabledBlocked = str_contains($e->getMessage(), 'not active');
    }
    assert_multiuser($disabledBlocked, 'Disabled Alice still resolved');

    $wrongPepper = new MultiUserService($root, 'fedcba9876543210fedcba9876543210fedcba9876543210');
    $wrongIdentityBlocked = false;
    try {
        $wrongPepper->resolve($issuer, $bobSubject);
    } catch (RuntimeException $e) {
        $wrongIdentityBlocked = str_contains($e->getMessage(), 'not registered');
    }
    assert_multiuser($wrongIdentityBlocked, 'Wrong pepper resolved existing identity');

    putenv('MCMA_MASTER_KEY_B64=' . base64_encode(random_bytes(32)));
    $globalKeyRejected = false;
    try {
        new MultiUserService($root, '0123456789abcdef0123456789abcdef0123456789abcdef');
    } catch (RuntimeException $e) {
        $globalKeyRejected = str_contains($e->getMessage(), 'per-library KeyStore');
    }
    assert_multiuser($globalKeyRejected, 'Multi-user mode accepted global master key');
    putenv('MCMA_MASTER_KEY_B64');

    echo "MCMA multi-user encrypted registry and isolation passed.\n";
} finally {
    putenv('MCMA_MASTER_KEY_B64');
    putenv('MCMA_MULTIUSER_PEPPER');
    putenv('MCMA_KEY_DIR');
    rrmdir_multi($base);
}
