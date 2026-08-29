<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\LocalLibrary;

$base = sys_get_temp_dir() . '/mcma-local-core-' . bin2hex(random_bytes(4));
$libraryPath = $base . '/library';
$keyDir = $base . '/keys';
putenv('MCMA_KEY_DIR=' . $keyDir);
putenv('MCMA_MASTER_KEY_B64');

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

try {
    $lib = LocalLibrary::init($libraryPath, 'private');
    $initial = $lib->verify();
    if ($initial['objects_verified'] !== 0) throw new RuntimeException('Expected empty library');

    $write = $lib->write(
        'memory://topics/integration-test',
        'hello MCMA 1.0',
        'text',
        'hot',
        '40-semantic',
        'global',
        'confirmed'
    );
    if (!str_starts_with($write['object_id'], 'obj_')) throw new RuntimeException('Missing object id');

    $reopened = LocalLibrary::open($libraryPath);
    $read = $reopened->read('memory://topics/integration-test');
    if (($read['payload']['content'] ?? null) !== 'hello MCMA 1.0') throw new RuntimeException('Read mismatch');
    if (($read['payload']['metadata']['temperature'] ?? null) !== 'hot') throw new RuntimeException('Temperature mismatch');

    $verify = $reopened->verify();
    if ($verify['objects_verified'] !== 1) throw new RuntimeException('Expected one verified object');
    if (count($reopened->list()) !== 1) throw new RuntimeException('Expected one index entry');

    echo "MCMA local-core integration passed.\n";
} finally {
    rrmdir($base);
}
