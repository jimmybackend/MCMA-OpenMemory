<?php
declare(strict_types=1);

namespace MCMA\Core\MultiUser;

use RuntimeException;

final class AuthenticatedIdentity
{
    private function __construct(
        private readonly string $fingerprint,
        private readonly string $userId
    ) {
    }

    public static function fromSubject(string $issuer, string $subject, string $pepper): self
    {
        $issuer = trim($issuer);
        $subject = trim($subject);

        if ($issuer === '' || strlen($issuer) > 512) {
            throw new RuntimeException('Authenticated issuer is required and must be <= 512 bytes');
        }
        if ($subject === '' || strlen($subject) > 2048) {
            throw new RuntimeException('Authenticated subject is required and must be <= 2048 bytes');
        }
        if (strlen($pepper) < 32) {
            throw new RuntimeException('MCMA multi-user pepper must be at least 32 bytes');
        }

        $canonical = strlen($issuer) . ':' . $issuer . '|' . strlen($subject) . ':' . $subject;
        $digest = hash_hmac('sha256', $canonical, $pepper);

        return new self('hmac-sha256:' . $digest, 'usr_' . $digest);
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function userId(): string
    {
        return $this->userId;
    }
}
