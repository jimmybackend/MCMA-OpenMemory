<?php
declare(strict_types=1);

namespace MCMA\Core\Semantic;

interface EmbeddingProvider
{
    public function id(): string;

    /** @return list<float> */
    public function embed(string $text): array;
}
