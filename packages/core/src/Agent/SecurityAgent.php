<?php
declare(strict_types=1);

namespace MCMA\Core\Agent;

use MCMA\Core\Library;

final class SecurityAgent
{
    public function __construct(private readonly Library $library) {}

    public function decision(string $subject, string $action, string $resource): array
    {
        return $this->library->permissionDecision($subject, $action, $resource);
    }

    public function vaultMetadata(): array
    {
        return $this->library->vaultList('security-agent');
    }

    public function useSecret(string $name, callable $operation): mixed
    {
        return $this->library->useVaultSecret($name, 'security-agent', $operation);
    }
}
