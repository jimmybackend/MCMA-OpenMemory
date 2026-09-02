<?php
declare(strict_types=1);

namespace MCMA\Core\Semantic;

use MCMA\Core\Knowledge\KnowledgeRecord;
use MCMA\Core\Knowledge\KnowledgeService;
use MCMA\Core\Library;
use RuntimeException;
use Throwable;

final class SemanticIndexService
{
    public function __construct(private readonly Library $library) {}

    public static function indexRef(EmbeddingProvider $provider): string
    {
        return 'memory://system/semantic-index/p-' . hash('sha256', $provider->id());
    }

    public function indexAll(EmbeddingProvider $provider, string $actor = 'librarian'): array
    {
        $entries = [];
        $dimensions = null;

        foreach ($this->library->listAs($actor) as $indexEntry) {
            foreach ($indexEntry['logical_refs'] ?? [] as $logicalRef) {
                if (!is_string($logicalRef) || !str_starts_with($logicalRef, 'memory://knowledge/q-')) continue;

                $stored = $this->library->readAs($actor, $logicalRef);
                $record = $stored['payload']['content'] ?? null;
                if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
                KnowledgeRecord::validate($record);

                $vector = VectorMath::normalize($provider->embed($record['intent']['normalized']));
                $dimensions ??= count($vector);
                if (count($vector) !== $dimensions) throw new RuntimeException('Embedding provider returned inconsistent dimensions');

                $entries[] = [
                    'logical_ref' => $logicalRef,
                    'object_id' => $stored['object_id'],
                    'storage_hash' => $stored['storage_hash'],
                    'vector' => $vector,
                ];
            }
        }

        usort($entries, static fn(array $a, array $b): int => $a['logical_ref'] <=> $b['logical_ref']);
        $dimensions ??= 0;

        $payload = [
            'semantic_index_version' => '1.0',
            'provider_id' => $provider->id(),
            'dimensions' => $dimensions,
            'indexed_at' => self::now(),
            'entries' => $entries,
        ];
        self::validateIndex($payload);

        $persisted = $this->persistIndex($provider, $payload, $actor, $this->indexExists($provider));

        return [
            'provider_id' => $provider->id(),
            'logical_ref' => self::indexRef($provider),
            'dimensions' => $dimensions,
            'entries_indexed' => count($entries),
            'total_entries' => count($entries),
            'mode' => 'full',
            'object_id' => $persisted['object_id'],
            'storage_hash' => $persisted['storage_hash'],
            'revision' => $persisted['revision'],
        ];
    }

    public function indexOne(EmbeddingProvider $provider, string $logicalRef, string $actor = 'librarian'): array
    {
        self::validateKnowledgeRef($logicalRef);

        $stored = $this->library->readAs($actor, $logicalRef);
        $record = $stored['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Stored knowledge record is malformed');
        KnowledgeRecord::validate($record);

        [$index, $exists] = $this->loadIndex($provider);
        $position = self::entryPosition($index['entries'], $logicalRef);

        if ($position !== null) {
            $existing = $index['entries'][$position];
            if (
                hash_equals($existing['object_id'], $stored['object_id']) &&
                hash_equals($existing['storage_hash'], $stored['storage_hash'])
            ) {
                return [
                    'provider_id' => $provider->id(),
                    'logical_ref' => self::indexRef($provider),
                    'knowledge_logical_ref' => $logicalRef,
                    'object_id' => $stored['object_id'],
                    'storage_hash' => $stored['storage_hash'],
                    'dimensions' => $index['dimensions'],
                    'total_entries' => count($index['entries']),
                    'mode' => 'incremental',
                    'unchanged' => true,
                    'embedding_generated' => false,
                ];
            }
        }

        $vector = VectorMath::normalize($provider->embed($record['intent']['normalized']));
        if ($index['dimensions'] === 0) {
            $index['dimensions'] = count($vector);
        } elseif (count($vector) !== $index['dimensions']) {
            throw new RuntimeException('Embedding provider returned dimensions incompatible with existing semantic index');
        }

        $entry = [
            'logical_ref' => $logicalRef,
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
            'vector' => $vector,
        ];

        if ($position === null) $index['entries'][] = $entry;
        else $index['entries'][$position] = $entry;

        usort($index['entries'], static fn(array $a, array $b): int => $a['logical_ref'] <=> $b['logical_ref']);
        $index['indexed_at'] = self::now();
        self::validateIndex($index);

        $persisted = $this->persistIndex($provider, $index, $actor, $exists);

        return [
            'provider_id' => $provider->id(),
            'logical_ref' => self::indexRef($provider),
            'knowledge_logical_ref' => $logicalRef,
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
            'dimensions' => $index['dimensions'],
            'total_entries' => count($index['entries']),
            'mode' => 'incremental',
            'unchanged' => false,
            'embedding_generated' => true,
            'semantic_index_object_id' => $persisted['object_id'],
            'semantic_index_storage_hash' => $persisted['storage_hash'],
            'semantic_index_revision' => $persisted['revision'],
        ];
    }

    public function refreshStoredEntry(EmbeddingProvider $provider, string $logicalRef, string $actor = 'owner'): array
    {
        self::validateKnowledgeRef($logicalRef);
        $stored = $this->library->readAs($actor, $logicalRef);
        [$index, $exists] = $this->loadIndex($provider);

        if (!$exists) {
            return [
                'provider_id' => $provider->id(),
                'logical_ref' => self::indexRef($provider),
                'knowledge_logical_ref' => $logicalRef,
                'refreshed' => false,
                'reason' => 'semantic-index-not-found',
                'embedding_generated' => false,
            ];
        }

        $position = self::entryPosition($index['entries'], $logicalRef);
        if ($position === null) {
            return [
                'provider_id' => $provider->id(),
                'logical_ref' => self::indexRef($provider),
                'knowledge_logical_ref' => $logicalRef,
                'refreshed' => false,
                'reason' => 'semantic-entry-not-found',
                'total_entries' => count($index['entries']),
                'embedding_generated' => false,
            ];
        }

        $existing = $index['entries'][$position];
        if (
            hash_equals((string)$existing['object_id'], (string)$stored['object_id']) &&
            hash_equals((string)$existing['storage_hash'], (string)$stored['storage_hash'])
        ) {
            return [
                'provider_id' => $provider->id(),
                'logical_ref' => self::indexRef($provider),
                'knowledge_logical_ref' => $logicalRef,
                'refreshed' => false,
                'unchanged' => true,
                'total_entries' => count($index['entries']),
                'embedding_generated' => false,
            ];
        }

        // Validation/freshness metadata changed, but the normalized question did not.
        // Preserve the existing vector and refresh only the identity/hash linkage.
        $index['entries'][$position]['object_id'] = $stored['object_id'];
        $index['entries'][$position]['storage_hash'] = $stored['storage_hash'];
        $index['indexed_at'] = self::now();
        self::validateIndex($index);

        $persisted = $this->persistIndex($provider, $index, $actor, true);
        return [
            'provider_id' => $provider->id(),
            'logical_ref' => self::indexRef($provider),
            'knowledge_logical_ref' => $logicalRef,
            'refreshed' => true,
            'total_entries' => count($index['entries']),
            'embedding_generated' => false,
            'semantic_index_object_id' => $persisted['object_id'],
            'semantic_index_storage_hash' => $persisted['storage_hash'],
            'semantic_index_revision' => $persisted['revision'],
        ];
    }

    public function remove(EmbeddingProvider $provider, string $logicalRef, string $actor = 'librarian'): array
    {
        self::validateKnowledgeRef($logicalRef);
        [$index, $exists] = $this->loadIndex($provider);

        if (!$exists) {
            return [
                'provider_id' => $provider->id(),
                'logical_ref' => self::indexRef($provider),
                'knowledge_logical_ref' => $logicalRef,
                'removed' => false,
                'total_entries' => 0,
                'mode' => 'incremental',
            ];
        }

        $position = self::entryPosition($index['entries'], $logicalRef);
        if ($position === null) {
            return [
                'provider_id' => $provider->id(),
                'logical_ref' => self::indexRef($provider),
                'knowledge_logical_ref' => $logicalRef,
                'removed' => false,
                'total_entries' => count($index['entries']),
                'mode' => 'incremental',
            ];
        }

        array_splice($index['entries'], $position, 1);
        if ($index['entries'] === []) $index['dimensions'] = 0;
        $index['indexed_at'] = self::now();
        self::validateIndex($index);

        $persisted = $this->persistIndex($provider, $index, $actor, true);

        return [
            'provider_id' => $provider->id(),
            'logical_ref' => self::indexRef($provider),
            'knowledge_logical_ref' => $logicalRef,
            'removed' => true,
            'total_entries' => count($index['entries']),
            'mode' => 'incremental',
            'semantic_index_object_id' => $persisted['object_id'],
            'semantic_index_storage_hash' => $persisted['storage_hash'],
            'semantic_index_revision' => $persisted['revision'],
        ];
    }

    public function topK(
        string $actor,
        string $question,
        EmbeddingProvider $provider,
        bool $currentRequired = false,
        float $minConfidence = 0.75,
        float $minSimilarity = 0.78,
        int $topK = 5,
        ?DeterministicReranker $reranker = null,
        ?float $candidateSimilarity = null
    ): array {
        self::validateSearchOptions($minConfidence, $minSimilarity, $topK);
        self::validateHybridThresholds($minSimilarity, $candidateSimilarity, null);
        $candidateThreshold = $candidateSimilarity ?? $minSimilarity;

        [$semanticIndex, $exists] = $this->loadIndex($provider);
        if (!$exists) {
            return [
                'found' => false,
                'route' => 'semantic',
                'reason' => 'semantic-index-not-found',
                'provider_id' => $provider->id(),
                'top_k' => $topK,
                'candidates' => [],
                'stale_index_entries' => 0,
            ];
        }

        if ($semanticIndex['entries'] === []) {
            return [
                'found' => false,
                'route' => 'semantic',
                'reason' => 'no-semantic-candidates',
                'provider_id' => $provider->id(),
                'top_k' => $topK,
                'candidates' => [],
                'stale_index_entries' => 0,
            ];
        }

        $queryVector = VectorMath::normalize($provider->embed(KnowledgeRecord::normalizeIntent($question)));
        if (count($queryVector) !== $semanticIndex['dimensions']) {
            throw new RuntimeException('Semantic query vector dimension mismatch');
        }

        $visible = [];
        foreach ($this->library->listAs($actor) as $entry) {
            foreach ($entry['logical_refs'] ?? [] as $ref) {
                if (is_string($ref) && str_starts_with($ref, 'memory://knowledge/q-')) {
                    $visible[$ref] = [
                        'object_id' => $entry['object_id'],
                        'storage_hash' => $entry['storage_hash'],
                    ];
                }
            }
        }

        $candidates = [];
        $staleEntries = 0;
        $bestSimilarity = null;
        $now = time();

        foreach ($semanticIndex['entries'] as $entry) {
            $ref = $entry['logical_ref'];
            if (!isset($visible[$ref])) continue;

            if (!hash_equals($visible[$ref]['storage_hash'], $entry['storage_hash'])) {
                $staleEntries++;
                continue;
            }

            $similarity = VectorMath::cosine($queryVector, $entry['vector']);
            if ($bestSimilarity === null || $similarity > $bestSimilarity) $bestSimilarity = $similarity;
            if ($similarity < $candidateThreshold) continue;

            $stored = $this->library->readAs($actor, $ref);
            $record = $stored['payload']['content'] ?? null;
            if (!is_array($record)) throw new RuntimeException('Semantic knowledge candidate is malformed');
            KnowledgeRecord::validate($record);

            $assessment = KnowledgeRecord::assess($record, $currentRequired, $minConfidence, $now);
            $metadata = $stored['payload']['metadata'] ?? [];
            if (!is_array($metadata)) throw new RuntimeException('Semantic candidate metadata is malformed');

            $referenceTime = $record['epistemic']['last_validated_at'] ?? $record['epistemic']['captured_at'];
            $referenceEpoch = strtotime((string)$referenceTime);
            if ($referenceEpoch === false) throw new RuntimeException('Semantic candidate reference timestamp is invalid');

            $candidates[] = [
                'similarity' => $similarity,
                'object_id' => $stored['object_id'],
                'logical_ref' => $ref,
                'storage_hash' => $stored['storage_hash'],
                'matched_question' => $record['intent']['question'],
                'validation' => $record['epistemic']['validation_state'],
                'validation_state' => $record['epistemic']['validation_state'],
                'confidence' => $record['epistemic']['confidence'],
                'freshness' => [
                    'class' => $record['freshness']['class'],
                    'max_age_seconds' => $record['freshness']['max_age_seconds'],
                    'reuse_policy' => $record['freshness']['reuse_policy'],
                    'stale' => $assessment['stale'],
                ],
                'permission_eligible' => true,
                'reusable' => $assessment['reusable'],
                'decision' => $assessment['decision'],
                'reasons' => $assessment['reasons'],
                'maturity' => (string)($metadata['maturity'] ?? 'raw'),
                'evidence_count' => $record['epistemic']['evidence_count'],
                'recency_seconds' => max(0, $now - $referenceEpoch),
            ];
        }

        $reranker ??= new DeterministicReranker();
        $ranked = array_slice($reranker->rank($candidates), 0, $topK);

        return [
            'found' => $ranked !== [],
            'route' => 'semantic',
            'reason' => $ranked === [] ? ($staleEntries > 0 ? 'semantic-index-stale' : 'no-candidate-above-threshold') : null,
            'provider_id' => $provider->id(),
            'top_k' => $topK,
            'min_similarity' => $minSimilarity,
            'candidate_similarity' => $candidateThreshold,
            'min_confidence' => $minConfidence,
            'best_similarity' => $bestSimilarity,
            'stale_index_entries' => $staleEntries,
            'candidates' => $ranked,
        ];
    }

    public function answer(
        string $actor,
        string $question,
        EmbeddingProvider $provider,
        bool $currentRequired = false,
        float $minConfidence = 0.75,
        float $minSimilarity = 0.78,
        int $topK = 5,
        ?float $candidateSimilarity = null,
        ?float $minRerankScore = null
    ): array {
        self::validateSearchOptions($minConfidence, $minSimilarity, $topK);
        self::validateHybridThresholds($minSimilarity, $candidateSimilarity, $minRerankScore);

        $knowledge = new KnowledgeService($this->library);
        $exact = $knowledge->directAnswer($actor, $question, $currentRequired, $minConfidence);
        if (($exact['reusable'] ?? false) === true && isset($exact['answer'])) {
            $exact['route'] = 'exact';
            return $exact;
        }

        $ranked = $this->topK($actor, $question, $provider, $currentRequired, $minConfidence, $minSimilarity, $topK, null, $candidateSimilarity);
        if (($ranked['found'] ?? false) !== true) {
            return [
                'found' => false,
                'reusable' => false,
                'decision' => ($ranked['reason'] ?? null) === 'semantic-index-stale' ? 'reindex' : 'miss',
                'route' => 'semantic',
                'reasons' => [$ranked['reason'] ?? 'semantic-miss'],
                'stale_index_entries' => $ranked['stale_index_entries'] ?? 0,
                'best_similarity' => $ranked['best_similarity'] ?? null,
                'min_similarity' => $minSimilarity,
                'candidate_similarity' => $candidateSimilarity ?? $minSimilarity,
                'min_rerank_score' => $minRerankScore,
                'logical_ref' => KnowledgeRecord::logicalRef($question),
            ];
        }

        $hybridSelection = $candidateSimilarity !== null || $minRerankScore !== null;
        $top = $hybridSelection ? null : $ranked['candidates'][0];

        if ($hybridSelection) {
            foreach ($ranked['candidates'] as $candidate) {
                if (($candidate['reusable'] ?? false) !== true) continue;
                $similarityPass = (float)($candidate['similarity'] ?? -1.0) >= $minSimilarity;
                $rerankPass = $minRerankScore !== null
                    && (float)($candidate['rerank_score'] ?? -1.0) >= $minRerankScore;
                if ($similarityPass || $rerankPass) {
                    $top = $candidate;
                    break;
                }
            }
        }

        if ($top === null) {
            return [
                'found' => false,
                'reusable' => false,
                'decision' => 'miss',
                'route' => 'semantic',
                'reasons' => ['no-reusable-candidate-passed-selection-gates'],
                'stale_index_entries' => $ranked['stale_index_entries'] ?? 0,
                'best_similarity' => $ranked['best_similarity'] ?? null,
                'min_similarity' => $minSimilarity,
                'candidate_similarity' => $candidateSimilarity ?? $minSimilarity,
                'min_rerank_score' => $minRerankScore,
                'logical_ref' => KnowledgeRecord::logicalRef($question),
            ];
        }

        $result = [
            'found' => true,
            'route' => 'semantic',
            'logical_ref' => KnowledgeRecord::logicalRef($question),
            'matched_logical_ref' => $top['logical_ref'],
            'matched_question' => $top['matched_question'],
            'object_id' => $top['object_id'],
            'storage_hash' => $top['storage_hash'],
            'similarity' => $top['similarity'],
            'rerank_score' => $top['rerank_score'],
            'min_similarity' => $minSimilarity,
            'candidate_similarity' => $candidateSimilarity ?? $minSimilarity,
            'min_rerank_score' => $minRerankScore,
            'selection_gate' => $top['similarity'] >= $minSimilarity ? 'similarity' : 'rerank',
            'reusable' => $top['reusable'],
            'decision' => $top['decision'],
            'reasons' => $top['reasons'],
            'stale' => $top['freshness']['stale'],
            'intent_key' => str_replace('memory://knowledge/q-', 'sha256:', $top['logical_ref']),
            'validation_state' => $top['validation_state'],
            'confidence' => $top['confidence'],
            'freshness_class' => $top['freshness']['class'],
            'reuse_policy' => $top['freshness']['reuse_policy'],
            'top_k_considered' => count($ranked['candidates']),
        ];

        if ($top['reusable']) {
            $stored = $this->library->readAs($actor, $top['logical_ref']);
            $record = $stored['payload']['content'] ?? null;
            if (!is_array($record)) throw new RuntimeException('Semantic knowledge candidate is malformed');
            KnowledgeRecord::validate($record);
            $result['answer'] = $record['answer'];
            $result['provenance'] = $record['provenance'];
            $result['relations'] = $record['relations'];
            foreach($record['relations'] as $relation){
                if(is_string($relation) && str_starts_with($relation,'memory://user/')){
                    $result['canonical_memory_ref']=$relation;
                    break;
                }
            }
        }

        return $result;
    }

    public static function validateIndex(array $index): void
    {
        if (($index['semantic_index_version'] ?? null) !== '1.0') throw new RuntimeException('Unsupported semantic index version');
        if (!is_string($index['provider_id'] ?? null) || trim($index['provider_id']) === '' || strlen($index['provider_id']) > 512) {
            throw new RuntimeException('Invalid semantic provider id');
        }
        if (!is_int($index['dimensions'] ?? null) || $index['dimensions'] < 0 || $index['dimensions'] > 8192) {
            throw new RuntimeException('Invalid semantic index dimensions');
        }
        if (!is_string($index['indexed_at'] ?? null) || strtotime($index['indexed_at']) === false) {
            throw new RuntimeException('Invalid semantic index timestamp');
        }
        if (!isset($index['entries']) || !is_array($index['entries'])) throw new RuntimeException('Semantic index entries must be an array');

        $seen = [];
        foreach ($index['entries'] as $entry) {
            if (!is_array($entry)) throw new RuntimeException('Semantic index entry must be an object');
            $ref = $entry['logical_ref'] ?? null;
            if (!is_string($ref) || !preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#', $ref)) throw new RuntimeException('Invalid semantic knowledge reference');
            if (isset($seen[$ref])) throw new RuntimeException('Duplicate semantic knowledge reference');
            $seen[$ref] = true;
            if (!is_string($entry['object_id'] ?? null) || !preg_match('/^obj_[0-9a-f-]{36}$/', $entry['object_id'])) throw new RuntimeException('Invalid semantic object_id');
            if (!is_string($entry['storage_hash'] ?? null) || !preg_match('/^sha256:[0-9a-f]{64}$/', $entry['storage_hash'])) throw new RuntimeException('Invalid semantic storage_hash');
            VectorMath::normalize($entry['vector'] ?? [], $index['dimensions']);
        }

        if ($index['entries'] === [] && $index['dimensions'] !== 0) throw new RuntimeException('Empty semantic index must have zero dimensions');
        if ($index['entries'] !== [] && $index['dimensions'] === 0) throw new RuntimeException('Non-empty semantic index must declare dimensions');
    }

    private function loadIndex(EmbeddingProvider $provider): array
    {
        try {
            $stored = $this->library->read(self::indexRef($provider));
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Memory not found:')) {
                return [[
                    'semantic_index_version' => '1.0',
                    'provider_id' => $provider->id(),
                    'dimensions' => 0,
                    'indexed_at' => self::now(),
                    'entries' => [],
                ], false];
            }
            throw $e;
        }

        $index = $stored['payload']['content'] ?? null;
        if (!is_array($index)) throw new RuntimeException('Semantic index payload is malformed');
        self::validateIndex($index);
        if (!hash_equals($index['provider_id'], $provider->id())) throw new RuntimeException('Semantic index provider mismatch');
        return [$index, true];
    }

    private function persistIndex(EmbeddingProvider $provider, array $payload, string $actor, bool $exists): array
    {
        self::validateIndex($payload);
        $logicalRef = self::indexRef($provider);

        if ($exists) {
            return $this->library->updateAs($actor, $logicalRef, $payload, 'json', 'warm', '00-system', 'system', 'confirmed');
        }

        return $this->library->writeAs($actor, $logicalRef, $payload, 'json', 'warm', '00-system', 'system', 'confirmed');
    }

    private function indexExists(EmbeddingProvider $provider): bool
    {
        try {
            $this->library->read(self::indexRef($provider));
            return true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Memory not found:')) return false;
            throw $e;
        }
    }

    private static function entryPosition(array $entries, string $logicalRef): ?int
    {
        foreach ($entries as $position => $entry) {
            if (($entry['logical_ref'] ?? null) === $logicalRef) return $position;
        }
        return null;
    }

    private static function validateKnowledgeRef(string $logicalRef): void
    {
        if (!preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#', $logicalRef)) {
            throw new RuntimeException('Incremental semantic indexing requires a canonical knowledge logical reference');
        }
    }

    private static function validateHybridThresholds(
        float $minSimilarity,
        ?float $candidateSimilarity,
        ?float $minRerankScore
    ): void {
        if ($candidateSimilarity !== null) {
            if (!is_finite($candidateSimilarity) || $candidateSimilarity < -1.0 || $candidateSimilarity > 1.0) {
                throw new RuntimeException('Candidate similarity must be between -1 and 1');
            }
            if ($candidateSimilarity > $minSimilarity) {
                throw new RuntimeException('Candidate similarity must not exceed min similarity');
            }
        }
        if ($minRerankScore !== null && (!is_finite($minRerankScore) || $minRerankScore < 0.0 || $minRerankScore > 1.0)) {
            throw new RuntimeException('Minimum rerank score must be between 0 and 1');
        }
    }

    private static function validateSearchOptions(float $minConfidence, float $minSimilarity, int $topK): void
    {
        if (!is_finite($minConfidence) || $minConfidence < 0.0 || $minConfidence > 1.0) {
            throw new RuntimeException('Semantic confidence threshold must be between 0 and 1');
        }
        if (!is_finite($minSimilarity) || $minSimilarity < -1.0 || $minSimilarity > 1.0) {
            throw new RuntimeException('Semantic similarity threshold must be between -1 and 1');
        }
        if ($topK < 1 || $topK > 100) throw new RuntimeException('Semantic top_k must be between 1 and 100');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
