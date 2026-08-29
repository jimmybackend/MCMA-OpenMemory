<?php
declare(strict_types=1);

namespace MCMA\Core\Billing;

interface UsageAwareEmbeddingProvider
{
    /** @return array{inputTokens:int,totalTokens:int,method:string} */
    public function lastUsage(): array;
}
