<?php
declare(strict_types=1);

$roots = [
    __DIR__ . '/../../packages/core/src',
    __DIR__ . '/../../packages/connectors',
];

$violations = [];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) throw new RuntimeException('Unable to read ' . $file->getPathname());

        if (preg_match_all('/^function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(/m', $bytes, $matches)) {
            foreach ($matches[0] as $declaration) {
                $violations[] = $file->getPathname() . ': ' . trim($declaration);
            }
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException(
        "Global production functions are forbidden; use classes/interfaces instead:\n" .
        implode("\n", $violations)
    );
}

$entrypoint = file_get_contents(__DIR__ . '/../../apps/cli/mcma');
if ($entrypoint === false) throw new RuntimeException('Unable to read CLI entrypoint');
if (preg_match('/^function\s+/m', $entrypoint)) {
    throw new RuntimeException('CLI entrypoint must not declare global functions');
}
if (!str_contains($entrypoint, 'new MCMA\\Core\\Cli\\CliApplication()')) {
    throw new RuntimeException('CLI entrypoint must delegate to CliApplication');
}

echo "MCMA production OOP conformance passed.\n";
