<?php
declare(strict_types=1);

namespace MCMA\Core\Semantic;

use RuntimeException;

final class DeterministicReranker
{
    public function rank(array $candidates): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) throw new RuntimeException('Semantic candidate must be an object');
            $candidate['rerank_score'] = $this->score($candidate);
            $ranked[] = $candidate;
        }

        usort($ranked, static function (array $a, array $b): int {
            $decisionRank = static fn(string $decision): int => match ($decision) {
                'reuse' => 0,
                'revalidate' => 1,
                'reject' => 2,
                default => 3,
            };

            $cmp = $decisionRank((string)($a['decision'] ?? '')) <=> $decisionRank((string)($b['decision'] ?? ''));
            if ($cmp !== 0) return $cmp;

            $cmp = ((float)($b['rerank_score'] ?? 0.0)) <=> ((float)($a['rerank_score'] ?? 0.0));
            if ($cmp !== 0) return $cmp;

            $cmp = ((float)($b['similarity'] ?? -1.0)) <=> ((float)($a['similarity'] ?? -1.0));
            if ($cmp !== 0) return $cmp;

            return ((string)($a['logical_ref'] ?? '')) <=> ((string)($b['logical_ref'] ?? ''));
        });

        return $ranked;
    }

    public function score(array $candidate): float
    {
        $similarity = (float)($candidate['similarity'] ?? -1.0);
        $confidence = (float)($candidate['confidence'] ?? 0.0);
        $validation = (string)($candidate['validation_state'] ?? 'unverified');
        $maturity = (string)($candidate['maturity'] ?? 'raw');
        $evidenceCount = max(0, (int)($candidate['evidence_count'] ?? 0));
        $recencySeconds = max(0, (int)($candidate['recency_seconds'] ?? 0));
        $freshness = $candidate['freshness'] ?? [];

        if (!is_finite($similarity) || $similarity < -1.0 || $similarity > 1.0) {
            throw new RuntimeException('Semantic candidate similarity must be between -1 and 1');
        }
        if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new RuntimeException('Semantic candidate confidence must be between 0 and 1');
        }
        if (!is_array($freshness)) throw new RuntimeException('Semantic candidate freshness must be an object');

        $validationFactor = match ($validation) {
            'verified' => 1.0,
            'supported' => 0.90,
            'plausible' => 0.55,
            'unverified' => 0.25,
            'disputed', 'retracted' => 0.0,
            default => 0.0,
        };

        $maturityFactor = match ($maturity) {
            'confirmed' => 1.0,
            'knowledge' => 0.85,
            'classified' => 0.65,
            'observed' => 0.40,
            'raw' => 0.20,
            default => 0.0,
        };

        $freshnessClass = (string)($freshness['class'] ?? 'stable');
        $stale = (bool)($freshness['stale'] ?? false);
        $freshnessFactor = $stale ? 0.0 : match ($freshnessClass) {
            'immutable' => 1.0,
            'stable' => 0.90,
            'dynamic' => 0.70,
            'volatile' => 0.55,
            default => 0.0,
        };

        $maxAge = $freshness['max_age_seconds'] ?? null;
        if ($freshnessClass === 'immutable') {
            $recencyFactor = 1.0;
        } elseif (!is_int($maxAge) || $maxAge < 0) {
            $recencyFactor = 0.0;
        } elseif ($maxAge === 0) {
            $recencyFactor = $recencySeconds === 0 ? 1.0 : 0.0;
        } else {
            $recencyFactor = max(0.0, 1.0 - min(1.0, $recencySeconds / $maxAge));
        }

        $similarityFactor = ($similarity + 1.0) / 2.0;
        $provenanceFactor = min(1.0, $evidenceCount / 5.0);

        return
            (0.45 * $similarityFactor) +
            (0.20 * $confidence) +
            (0.15 * $validationFactor) +
            (0.08 * $freshnessFactor) +
            (0.07 * $recencyFactor) +
            (0.03 * $maturityFactor) +
            (0.02 * $provenanceFactor);
    }
}
