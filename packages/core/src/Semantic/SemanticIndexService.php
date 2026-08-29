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
            'indexed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'entries' => $entries,
        ];
        self::validateIndex($payload);

        $logicalRef = self::indexRef($provider);
        $exists = false;
        foreach ($this->library->listAs($actor) as $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) {
                $exists = true;
                break;
            }
        }

        if ($exists) {
            $result = $this->library->updateAs($actor, $logicalRef, $payload, 'json', 'warm', '00-system', 'system', 'confirmed');
        } else {
            $result = $this->library->writeAs($actor, $logicalRef, $payload, 'json', 'warm', '00-system', 'system', 'confirmed');
        }

        return [
            'provider_id' => $provider->id(),
            'logical_ref' => $logicalRef,
            'dimensions' => $dimensions,
            'entries_indexed' => count($entries),
            'object_id' => $result['object_id'],
            'storage_hash' => $result['storage_hash'],
            'revision' => $result['revision'],
        ];
    }

    public function answer(
        string $actor,
        string $question,
        EmbeddingProvider $provider,
        bool $currentRequired = false,
        float $minConfidence = 0.75,
        float $minSimilarity = 0.78
    ): array {
        if (!is_finite($minSimilarity) || $minSimilarity < -1.0 || $minSimilarity > 1.0) {
            throw new RuntimeException('Semantic similarity threshold must be between -1 and 1');
        }

        $knowledge = new KnowledgeService($this->library);
        $exact = $knowledge->directAnswer($actor, $question, $currentRequired, $minConfidence);
        if (($exact['found'] ?? false) === true) {
            $exact['route'] = 'exact';
            return $exact;
        }

        try {
            // Derived semantic index is an internal trusted cache. Candidate
            // visibility is still evaluated against the requesting actor below.
            $storedIndex = $this->library->read(self::indexRef($provider));
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Memory not found:')) {
                return [
                    'found' => false,
                    'reusable' => false,
                    'decision' => 'miss',
                    'route' => 'semantic',
                    'reasons' => ['semantic-index-not-found'],
                    'logical_ref' => KnowledgeRecord::logicalRef($question),
                ];
            }
            throw $e;
        }

        $semanticIndex = $storedIndex['payload']['content'] ?? null;
        if (!is_array($semanticIndex)) throw new RuntimeException('Semantic index payload is malformed');
        self::validateIndex($semanticIndex);
        if (!hash_equals($semanticIndex['provider_id'], $provider->id())) throw new RuntimeException('Semantic index provider mismatch');

        $queryVector = VectorMath::normalize($provider->embed(KnowledgeRecord::normalizeIntent($question)));
        if (count($queryVector) !== $semanticIndex['dimensions'] && $semanticIndex['dimensions'] !== 0) {
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
        foreach ($semanticIndex['entries'] as $entry) {
            $ref = $entry['logical_ref'];
            if (!isset($visible[$ref])) continue;
            if (!hash_equals($visible[$ref]['storage_hash'], $entry['storage_hash'])) {
                $staleEntries++;
                continue;
            }

            $similarity = VectorMath::cosine($queryVector, $entry['vector']);
            $candidates[] = ['entry' => $entry, 'similarity' => $similarity];
        }

        usort($candidates, static function (array $a, array $b): int {
            $cmp = $b['similarity'] <=> $a['similarity'];
            return $cmp !== 0 ? $cmp : ($a['entry']['logical_ref'] <=> $b['entry']['logical_ref']);
        });

        if ($candidates === []) {
            return [
                'found' => false,
                'reusable' => false,
                'decision' => $staleEntries > 0 ? 'reindex' : 'miss',
                'route' => 'semantic',
                'reasons' => [$staleEntries > 0 ? 'semantic-index-stale' : 'no-visible-semantic-candidates'],
                'stale_index_entries' => $staleEntries,
                'logical_ref' => KnowledgeRecord::logicalRef($question),
            ];
        }

        $top = $candidates[0];
        if ($top['similarity'] < $minSimilarity) {
            return [
                'found' => false,
                'reusable' => false,
                'decision' => 'miss',
                'route' => 'semantic',
                'reasons' => ['similarity-below-threshold'],
                'similarity' => $top['similarity'],
                'min_similarity' => $minSimilarity,
                'logical_ref' => KnowledgeRecord::logicalRef($question),
            ];
        }

        $matchedRef = $top['entry']['logical_ref'];
        $stored = $this->library->readAs($actor, $matchedRef);
        $record = $stored['payload']['content'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Semantic knowledge candidate is malformed');
        KnowledgeRecord::validate($record);

        $assessment = KnowledgeRecord::assess($record, $currentRequired, $minConfidence);
        $result = [
            'found' => true,
            'route' => 'semantic',
            'logical_ref' => KnowledgeRecord::logicalRef($question),
            'matched_logical_ref' => $matchedRef,
            'matched_question' => $record['intent']['question'],
            'object_id' => $stored['object_id'],
            'storage_hash' => $stored['storage_hash'],
            'similarity' => $top['similarity'],
            'min_similarity' => $minSimilarity,
        ] + $assessment;

        if ($assessment['reusable']) {
            $result['answer'] = $record['answer'];
            $result['provenance'] = $record['provenance'];
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

        foreach ($index['entries'] as $entry) {
            if (!is_array($entry)) throw new RuntimeException('Semantic index entry must be an object');
            $ref = $entry['logical_ref'] ?? null;
            if (!is_string($ref) || !preg_match('#^memory://knowledge/q-[0-9a-f]{64}$#', $ref)) throw new RuntimeException('Invalid semantic knowledge reference');
            if (!is_string($entry['object_id'] ?? null) || !preg_match('/^obj_[0-9a-f-]{36}$/', $entry['object_id'])) throw new RuntimeException('Invalid semantic object_id');
            if (!is_string($entry['storage_hash'] ?? null) || !preg_match('/^sha256:[0-9a-f]{64}$/', $entry['storage_hash'])) throw new RuntimeException('Invalid semantic storage_hash');
            VectorMath::normalize($entry['vector'] ?? [], $index['dimensions']);
        }
    }
}
