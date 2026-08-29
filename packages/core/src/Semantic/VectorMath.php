<?php
declare(strict_types=1);

namespace MCMA\Core\Semantic;

use RuntimeException;

final class VectorMath
{
    /** @return list<float> */
    public static function normalize(array $vector, ?int $expectedDimensions = null): array
    {
        if ($vector === []) throw new RuntimeException('Embedding vector must not be empty');
        if ($expectedDimensions !== null && count($vector) !== $expectedDimensions) {
            throw new RuntimeException('Embedding vector dimension mismatch');
        }

        $values = [];
        $sum = 0.0;
        foreach ($vector as $value) {
            if (!is_int($value) && !is_float($value)) throw new RuntimeException('Embedding vector values must be numeric');
            $float = (float)$value;
            if (!is_finite($float)) throw new RuntimeException('Embedding vector values must be finite');
            $values[] = $float;
            $sum += $float * $float;
        }

        if (!is_finite($sum) || $sum <= 0.0) throw new RuntimeException('Embedding vector norm must be positive');
        $norm = sqrt($sum);

        return array_map(static fn(float $value): float => $value / $norm, $values);
    }

    public static function cosine(array $left, array $right): float
    {
        if (count($left) !== count($right) || $left === []) throw new RuntimeException('Embedding vectors must have equal non-zero dimensions');

        $a = self::normalize($left);
        $b = self::normalize($right, count($a));
        $dot = 0.0;
        foreach ($a as $i => $value) $dot += $value * $b[$i];

        if ($dot > 1.0 && $dot < 1.000000000001) return 1.0;
        if ($dot < -1.0 && $dot > -1.000000000001) return -1.0;
        return $dot;
    }
}
