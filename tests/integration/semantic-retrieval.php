<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class TestSemanticProvider implements EmbeddingProvider
{
    public function id(): string { return 'test:semantic-v1:dimensions=3'; }

    public function embed(string $text): array
    {
        $text = KnowledgeRecord::normalizeIntent($text);
        return match ($text) {
            '¿qué es mcma?' => [1.0, 0.0, 0.0],
            'explica el archivo modular de memoria cognitiva' => [0.99, 0.01, 0.0],
            '¿qué es php?' => [0.0, 1.0, 0.0],
            default => [0.0, 0.0, 1.0],
        };
    }
}

$base = sys_get_temp_dir() . '/mcma-semantic-' . bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR=' . $base . '/keys');
putenv('MCMA_MASTER_KEY_B64');

function rr_semantic(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rr_semantic($path); else @unlink($path);
    }
    @rmdir($dir);
}

function ok_semantic(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $lib = Library::init(new LocalFilesystemAdapter($base . '/library'), 'private');
    $lib->initializeAccessControl();
    $knowledge = new KnowledgeService($lib);
    $provider = new TestSemanticProvider();
    $semantic = new SemanticIndexService($lib);

    $knowledge->capture(
        'librarian',
        '¿Qué es MCMA?',
        'MCMA es un archivo modular de memoria cognitiva portable.',
        'text',
        0.94,
        'verified',
        [['source_type'=>'working-test','reference'=>'semantic-mcma']],
        'stable',
        86400,
        'reuse-unless-stale'
    );

    $knowledge->capture(
        'librarian',
        '¿Qué es PHP?',
        'PHP es un lenguaje de programación.',
        'text',
        0.93,
        'verified',
        [['source_type'=>'working-test','reference'=>'semantic-php']],
        'stable',
        86400,
        'reuse-unless-stale'
    );

    $indexed = $semantic->indexAll($provider, 'librarian');
    ok_semantic($indexed['entries_indexed'] === 2, 'Semantic index count mismatch');
    ok_semantic($indexed['dimensions'] === 3, 'Semantic index dimensions mismatch');

    $indexRef = SemanticIndexService::indexRef($provider);
    try {
        $lib->readAs('ai', $indexRef);
        throw new RuntimeException('AI was able to read internal semantic index');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'AI was able to read internal semantic index') throw $e;
    }

    $answer = $semantic->answer('ai', 'Explica el archivo modular de memoria cognitiva', $provider, false, 0.75, 0.80);
    ok_semantic(($answer['route'] ?? null) === 'semantic', 'Semantic route missing');
    ok_semantic(($answer['decision'] ?? null) === 'reuse', 'Semantic reusable candidate was not reused');
    ok_semantic(($answer['matched_question'] ?? null) === '¿Qué es MCMA?', 'Wrong semantic candidate selected');
    ok_semantic(($answer['similarity'] ?? 0.0) > 0.99, 'Semantic similarity unexpectedly low');
    ok_semantic(($answer['answer']['value'] ?? null) === 'MCMA es un archivo modular de memoria cognitiva portable.', 'Semantic answer mismatch');

    $current = $semantic->answer('ai', 'Explica el archivo modular de memoria cognitiva', $provider, true, 0.75, 0.80);
    ok_semantic(($current['decision'] ?? null) === 'revalidate', 'Current semantic request should revalidate');
    ok_semantic(!isset($current['answer']), 'Revalidation leaked remembered answer');

    $knowledge->validateKnowledge('librarian', '¿Qué es MCMA?', 'disputed', 0.40, 'Contradictory evidence');
    $semantic->indexAll($provider, 'librarian');
    $disputed = $semantic->answer('ai', 'Explica el archivo modular de memoria cognitiva', $provider, false, 0.75, 0.80);
    ok_semantic(($disputed['decision'] ?? null) === 'reject', 'Disputed semantic candidate must be rejected');
    ok_semantic(!isset($disputed['answer']), 'Rejected semantic candidate leaked answer');

    $knowledge->validateKnowledge('librarian', '¿Qué es MCMA?', 'verified', 0.97, 'Revalidated by test');
    $semantic->indexAll($provider, 'librarian');

    $policy = $lib->permissions('owner');
    $policy['resources'][] = [
        'resource' => KnowledgeRecord::logicalRef('¿Qué es MCMA?'),
        'subject' => 'ai',
        'deny' => ['read'],
    ];
    $lib->setPermissions($policy, 'owner');

    $denied = $semantic->answer('ai', 'Explica el archivo modular de memoria cognitiva', $provider, false, 0.75, 0.80);
    ok_semantic(($denied['decision'] ?? null) !== 'reuse', 'Semantic retrieval bypassed resource permission');
    ok_semantic(!isset($denied['answer']), 'Permission-denied semantic candidate leaked answer');

    $before = $knowledge->inspect('owner', '¿Qué es MCMA?');
    $knowledge->capture(
        'librarian',
        '¿Qué es MCMA?',
        'MCMA conserva memoria cifrada propiedad del usuario.',
        'text',
        0.98,
        'verified',
        [['source_type'=>'working-test','reference'=>'semantic-revision']],
        'stable',
        86400,
        'reuse-unless-stale'
    );
    $after = $knowledge->inspect('owner', '¿Qué es MCMA?');
    ok_semantic(hash_equals($before['object_id'], $after['object_id']), 'Knowledge revision changed stable object_id');
    ok_semantic(!hash_equals($before['storage_hash'], $after['storage_hash']), 'Knowledge revision did not change storage_hash');

    $staleIndexResult = $semantic->answer('owner', 'Explica el archivo modular de memoria cognitiva', $provider, false, 0.75, 0.80);
    ok_semantic(($staleIndexResult['decision'] ?? null) !== 'reuse', 'Stale semantic vector reused revised knowledge');

    ok_semantic(($lib->verify()['ok'] ?? false) === true, 'Library verify failed after semantic operations');

    echo "MCMA semantic retrieval filters integration passed.\n";
} finally {
    rr_semantic($base);
}
