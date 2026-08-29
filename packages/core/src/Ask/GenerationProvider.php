<?php
declare(strict_types=1);

namespace MCMA\Core\Ask;

interface GenerationProvider
{
    public function id(): string;

    /**
     * @return array{text:string,usage?:array,stop_reason?:string|null}
     */
    public function generate(string $question, array $context = []): array;
}
