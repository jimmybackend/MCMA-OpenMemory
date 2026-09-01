<?php
declare(strict_types=1);

require_once __DIR__ . '/../../packages/core/bootstrap.php';

use MCMA\Core\Agent\Librarian;
use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use MCMA\Core\Semantic\EmbeddingProvider;
use MCMA\Core\Semantic\SemanticIndexService;
use MCMA\Core\Storage\LocalFilesystemAdapter;

final class IncrementalSemanticProvider implements EmbeddingProvider
{
    public int $calls = 0;

    public function id(): string { return 'test:incremental-semantic-v1:dimensions=3'; }

    public function embed(string $text): array
    {
        $this->calls++;
        return match (KnowledgeRecord::normalizeIntent($text)) {
            'alpha fact' => [1.0, 0.0, 0.0],
            'beta fact' => [0.96, 0.04, 0.0],
            'semantic query' => [0.999, 0.001, 0.0],
            'hybrid fact' => [0.0, 1.0, 0.0],
            'hybrid query' => [0.0, 0.7, 0.714142842854285],
            default => [0.0, 0.0, 1.0],
        };
    }
}

$base = sys_get_temp_dir() . '/mcma-semantic-incremental-' . bin2hex(random_bytes(4));
putenv('MCMA_KEY_DIR=' . $base . '/keys');
putenv('MCMA_MASTER_KEY_B64');

function rr_semantic_incremental(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rr_semantic_incremental($path); else @unlink($path);
    }
    @rmdir($dir);
}

function ok_semantic_incremental(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $lib = Library::init(new LocalFilesystemAdapter($base . '/library'), 'private');
    $lib->initializeAccessControl();

    $knowledge = new KnowledgeService($lib);
    $provider = new IncrementalSemanticProvider();
    $semantic = new SemanticIndexService($lib);
    $librarian = new Librarian($knowledge, $semantic, $provider);

    $alpha = $librarian->remember('Alpha fact', 'Alpha needs revalidation.', [
        'confidence' => 0.60,
        'validation_state' => 'supported',
        'provenance' => [['source_type'=>'working-test','reference'=>'alpha']],
        'freshness_class' => 'stable',
        'max_age_seconds' => 86400,
    ]);
    ok_semantic_incremental($provider->calls === 1, 'First incremental remember should embed only one record');
    ok_semantic_incremental(($alpha['semantic_index']['total_entries'] ?? null) === 1, 'First incremental index should contain one entry');

    $indexRef = SemanticIndexService::indexRef($provider);
    $firstIndex = $lib->readAs('owner', $indexRef);

    $beta = $librarian->remember('Beta fact', 'Beta is reusable.', [
        'confidence' => 0.95,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'beta']],
        'freshness_class' => 'stable',
        'max_age_seconds' => 86400,
    ]);
    ok_semantic_incremental($provider->calls === 2, 'Second incremental remember rebuilt unrelated embeddings');
    ok_semantic_incremental(($beta['semantic_index']['total_entries'] ?? null) === 2, 'Incremental index should contain two entries');

    $secondIndex = $lib->readAs('owner', $indexRef);
    ok_semantic_incremental(hash_equals($firstIndex['object_id'], $secondIndex['object_id']), 'Semantic index object_id changed across revisions');
    ok_semantic_incremental(!hash_equals($firstIndex['storage_hash'], $secondIndex['storage_hash']), 'Semantic index storage_hash did not change');

    $top = $semantic->topK('ai', 'Semantic query', $provider, false, 0.75, 0.80, 5);
    ok_semantic_incremental($provider->calls === 3, 'Top-K query should generate exactly one query embedding');
    ok_semantic_incremental(count($top['candidates']) === 2, 'Top-K did not return both eligible semantic candidates');
    ok_semantic_incremental(($top['candidates'][0]['matched_question'] ?? null) === 'Beta fact', 'Deterministic reranker did not prioritize reusable knowledge');
    ok_semantic_incremental(($top['candidates'][0]['decision'] ?? null) === 'reuse', 'Top reranked candidate should be reusable');
    ok_semantic_incremental(($top['candidates'][0]['permission_eligible'] ?? false) === true, 'Top-K candidate permission eligibility missing');
    ok_semantic_incremental(isset($top['candidates'][0]['validation'], $top['candidates'][0]['confidence'], $top['candidates'][0]['freshness']), 'Top-K epistemic metadata missing');
    ok_semantic_incremental(!isset($top['candidates'][0]['answer'], $top['candidates'][0]['provenance']), 'Top-K metadata leaked answer or provenance');

    $answer = $semantic->answer('ai', 'Semantic query', $provider, false, 0.75, 0.80, 5);
    ok_semantic_incremental(($answer['matched_question'] ?? null) === 'Beta fact', 'Semantic answer did not use reranked reusable candidate');
    ok_semantic_incremental(($answer['answer']['value'] ?? null) === 'Beta is reusable.', 'Semantic answer payload mismatch');

    $beforeValidation = $knowledge->inspect('owner', 'Beta fact');
    $librarian->validate('Beta fact', 'verified', 0.97, 'Incremental validation refresh');
    ok_semantic_incremental($provider->calls === 5, 'Validation should refresh exactly one stored vector after one answer query');
    $afterValidation = $knowledge->inspect('owner', 'Beta fact');
    ok_semantic_incremental(hash_equals($beforeValidation['object_id'], $afterValidation['object_id']), 'Validation changed knowledge object_id');
    ok_semantic_incremental(!hash_equals($beforeValidation['storage_hash'], $afterValidation['storage_hash']), 'Validation did not create a new knowledge storage_hash');

    $callsBeforeUnchanged = $provider->calls;
    $unchanged = $semantic->indexOne($provider, KnowledgeRecord::logicalRef('Beta fact'), 'librarian');
    ok_semantic_incremental(($unchanged['unchanged'] ?? false) === true, 'Unchanged incremental index was not detected');
    ok_semantic_incremental(($unchanged['embedding_generated'] ?? true) === false, 'Unchanged incremental index regenerated embedding');
    ok_semantic_incremental($provider->calls === $callsBeforeUnchanged, 'Unchanged incremental index called embedding provider');

    $beforeRevision = $knowledge->inspect('owner', 'Beta fact');
    $librarian->remember('Beta fact', 'Beta was revised and remains reusable.', [
        'confidence' => 0.98,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'beta-revision']],
        'freshness_class' => 'stable',
        'max_age_seconds' => 86400,
    ]);
    $afterRevision = $knowledge->inspect('owner', 'Beta fact');
    ok_semantic_incremental(hash_equals($beforeRevision['object_id'], $afterRevision['object_id']), 'Knowledge revision changed stable object_id');
    ok_semantic_incremental(!hash_equals($beforeRevision['storage_hash'], $afterRevision['storage_hash']), 'Knowledge revision did not change storage_hash');

    $storedIndex = $lib->readAs('owner', $indexRef);
    $indexPayload = $storedIndex['payload']['content'];
    $betaRef = KnowledgeRecord::logicalRef('Beta fact');
    $betaEntries = array_values(array_filter($indexPayload['entries'], static fn(array $entry): bool => $entry['logical_ref'] === $betaRef));
    ok_semantic_incremental(count($betaEntries) === 1, 'Incremental index duplicated revised knowledge');
    ok_semantic_incremental(hash_equals($betaEntries[0]['storage_hash'], $afterRevision['storage_hash']), 'Incremental vector is not bound to latest storage_hash');

    $librarian->remember('Hybrid fact', 'Hybrid trusted answer.', [
        'confidence' => 0.97,
        'validation_state' => 'verified',
        'provenance' => [['source_type'=>'working-test','reference'=>'hybrid']],
        'freshness_class' => 'immutable',
        'max_age_seconds' => null,
    ]);

    $hybridTop = $semantic->topK('ai', 'Hybrid query', $provider, false, 0.75, 0.80, 5, null, 0.60);
    ok_semantic_incremental(($hybridTop['candidate_similarity'] ?? null) === 0.60, 'Hybrid candidate floor missing');
    $hybridCandidates = array_values(array_filter(
        $hybridTop['candidates'],
        static fn(array $candidate): bool => ($candidate['matched_question'] ?? null) === 'Hybrid fact'
    ));
    ok_semantic_incremental(count($hybridCandidates) === 1, 'Hybrid candidate below legacy min similarity was not admitted');
    ok_semantic_incremental(($hybridCandidates[0]['similarity'] ?? 1.0) < 0.80, 'Hybrid test candidate unexpectedly passed legacy similarity gate');

    $hybridAnswer = $semantic->answer('ai', 'Hybrid query', $provider, false, 0.75, 0.80, 5, 0.60, 0.85);
    ok_semantic_incremental(($hybridAnswer['matched_question'] ?? null) === 'Hybrid fact', 'Hybrid rerank gate did not select trusted lower-similarity knowledge');
    ok_semantic_incremental(($hybridAnswer['selection_gate'] ?? null) === 'rerank', 'Hybrid answer did not report rerank selection');
    ok_semantic_incremental(($hybridAnswer['answer']['value'] ?? null) === 'Hybrid trusted answer.', 'Hybrid rerank answer payload mismatch');

    $policy = $lib->permissions('owner');
    $policy['resources'][] = [
        'resource' => $betaRef,
        'subject' => 'ai',
        'deny' => ['read'],
    ];
    $lib->setPermissions($policy, 'owner');

    $visible = $semantic->topK('ai', 'Semantic query', $provider, false, 0.75, 0.80, 5);
    foreach ($visible['candidates'] as $candidate) {
        ok_semantic_incremental(($candidate['logical_ref'] ?? null) !== $betaRef, 'Top-K returned permission-denied candidate');
        ok_semantic_incremental(!isset($candidate['answer']), 'Top-K leaked answer after permission filtering');
    }

    $removed = $semantic->remove($provider, $betaRef, 'librarian');
    ok_semantic_incremental(($removed['removed'] ?? false) === true, 'Incremental semantic remove failed');
    $afterRemove = $lib->readAs('owner', $indexRef)['payload']['content'];
    foreach ($afterRemove['entries'] as $entry) {
        ok_semantic_incremental(($entry['logical_ref'] ?? null) !== $betaRef, 'Removed semantic entry still exists');
    }

    ok_semantic_incremental(($lib->verify()['ok'] ?? false) === true, 'Library verify failed after incremental semantic operations');

    echo "MCMA incremental semantic Top-K/reranking integration passed.\n";
} finally {
    rr_semantic_incremental($base);
}
