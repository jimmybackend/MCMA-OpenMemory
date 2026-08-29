<?php
declare(strict_types=1);

namespace MCMA\Core\MultiUser;

use MCMA\Core\Library;
use MCMA\Core\Storage\PrefixStorageAdapter;
use MCMA\Core\Storage\StorageAdapter;
use RuntimeException;

final class MultiUserService
{
    private const REGISTRY_PREFIX = 'system/user-registry';
    private const USERS_ROOT = 'memories';
    private const REGISTRY_REF = 'memory://system/users';
    private const ACCOUNT_REF = 'memory://identity/account';

    public function __construct(
        private readonly StorageAdapter $rootStorage,
        private readonly string $pepper
    ) {
        if (strlen($this->pepper) < 32) {
            throw new RuntimeException('MCMA multi-user pepper must be at least 32 bytes');
        }

        $globalMaster = getenv('MCMA_MASTER_KEY_B64');
        if (is_string($globalMaster) && trim($globalMaster) !== '') {
            throw new RuntimeException('Multi-user mode requires per-library KeyStore keys; unset MCMA_MASTER_KEY_B64');
        }
    }

    public static function fromEnvironment(StorageAdapter $rootStorage): self
    {
        $pepper = getenv('MCMA_MULTIUSER_PEPPER');
        if (!is_string($pepper) || trim($pepper) === '') {
            throw new RuntimeException('MCMA_MULTIUSER_PEPPER is required for multi-user mode');
        }

        return new self($rootStorage, $pepper);
    }

    public function bootstrap(): array
    {
        $registry = $this->ensureRegistryLibrary();

        return [
            'initialized' => true,
            'registry_library_id' => $registry->libraryId(),
            'registry_storage' => $registry->storageId(),
            'users_root' => self::USERS_ROOT,
            'writer_model' => $this->rootStorage->capabilities()['writer_model'] ?? 'provider-default',
        ];
    }

    public function register(string $issuer, string $subject): array
    {
        $identity = AuthenticatedIdentity::fromSubject($issuer, $subject, $this->pepper);
        $registry = $this->ensureRegistryLibrary();

        $existing = $this->findRecord($registry, $identity->userId());
        if ($existing !== null) {
            $this->assertIdentityRecord($identity, $existing);
            $this->openAndVerifyUserLibrary($existing);
            return $this->publicRecord($existing) + ['created' => false];
        }

        $prefix = self::USERS_ROOT . '/' . $identity->userId();
        $library = $this->ensureUserLibrary($prefix, $identity->userId());

        $record = [
            'user_id' => $identity->userId(),
            'identity_fingerprint' => $identity->fingerprint(),
            'library_id' => $library->libraryId(),
            'storage_prefix' => $prefix,
            'status' => 'active',
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ];

        $stored = $this->mutateRegistry(function(array $payload) use ($record, $identity): array {
            $users = $payload['users'] ?? [];
            if (!is_array($users) || ($users !== [] && array_is_list($users))) {
                throw new RuntimeException('Malformed multi-user registry users map');
            }

            $existing = $users[$identity->userId()] ?? null;
            if ($existing !== null) {
                if (!is_array($existing)) throw new RuntimeException('Malformed multi-user registry record');
                $this->assertIdentityRecord($identity, $existing);
                return $payload;
            }

            $users[$identity->userId()] = $record;
            ksort($users, SORT_STRING);
            $payload['users'] = $users;
            $payload['updated_at'] = self::now();
            return $payload;
        });

        $finalRecord = $this->findRecord($stored['library'], $identity->userId());
        if ($finalRecord === null) throw new RuntimeException('Multi-user registry failed to persist user');
        $this->assertIdentityRecord($identity, $finalRecord);

        if (!hash_equals($library->libraryId(), (string)$finalRecord['library_id'])) {
            throw new RuntimeException('Multi-user registry/library identity mismatch after registration');
        }

        return $this->publicRecord($finalRecord) + ['created' => true];
    }

    public function resolve(string $issuer, string $subject): Library
    {
        $identity = AuthenticatedIdentity::fromSubject($issuer, $subject, $this->pepper);
        $registry = $this->ensureRegistryLibrary();
        $record = $this->findRecord($registry, $identity->userId());

        if ($record === null) throw new RuntimeException('Authenticated user is not registered');
        $this->assertIdentityRecord($identity, $record);

        if (($record['status'] ?? null) !== 'active') {
            throw new RuntimeException('Authenticated user is not active');
        }

        return $this->openAndVerifyUserLibrary($record);
    }

    public function info(string $issuer, string $subject): array
    {
        $identity = AuthenticatedIdentity::fromSubject($issuer, $subject, $this->pepper);
        $registry = $this->ensureRegistryLibrary();
        $record = $this->findRecord($registry, $identity->userId());

        if ($record === null) throw new RuntimeException('Authenticated user is not registered');
        $this->assertIdentityRecord($identity, $record);

        $library = $this->openAndVerifyUserLibrary($record);

        return $this->publicRecord($record) + [
            'library' => $library->info(),
        ];
    }

    public function disable(string $issuer, string $subject): array
    {
        $identity = AuthenticatedIdentity::fromSubject($issuer, $subject, $this->pepper);

        $result = $this->mutateRegistry(function(array $payload) use ($identity): array {
            $users = $payload['users'] ?? [];
            if (!is_array($users) || ($users !== [] && array_is_list($users))) {
                throw new RuntimeException('Malformed multi-user registry users map');
            }

            $record = $users[$identity->userId()] ?? null;
            if (!is_array($record)) throw new RuntimeException('Authenticated user is not registered');
            $this->assertIdentityRecord($identity, $record);

            $record['status'] = 'disabled';
            $record['updated_at'] = self::now();
            $users[$identity->userId()] = $record;
            $payload['users'] = $users;
            $payload['updated_at'] = self::now();
            return $payload;
        });

        $record = $this->findRecord($result['library'], $identity->userId());
        if ($record === null) throw new RuntimeException('Multi-user disable failed');
        return $this->publicRecord($record);
    }

    public function listUsers(): array
    {
        $registry = $this->ensureRegistryLibrary();
        $payload = $this->registryPayload($registry);
        $users = $payload['users'] ?? [];
        if (!is_array($users) || ($users !== [] && array_is_list($users))) {
            throw new RuntimeException('Malformed multi-user registry users map');
        }

        ksort($users, SORT_STRING);
        $out = [];
        foreach ($users as $record) {
            if (!is_array($record)) throw new RuntimeException('Malformed multi-user registry record');
            $out[] = $this->publicRecord($record);
        }
        return $out;
    }

    public function resolveUserIdForService(string $userId, bool $requireActive = true): Library
    {
        self::validateUserId($userId);
        $registry = $this->ensureRegistryLibrary();
        $record = $this->findRecord($registry, $userId);
        if ($record === null) throw new RuntimeException('User is not registered');
        if ($requireActive && ($record['status'] ?? null) !== 'active') {
            throw new RuntimeException('User is not active');
        }
        return $this->openAndVerifyUserLibrary($record);
    }

    public function infoUserId(string $userId): array
    {
        self::validateUserId($userId);
        $registry = $this->ensureRegistryLibrary();
        $record = $this->findRecord($registry, $userId);
        if ($record === null) throw new RuntimeException('User is not registered');
        $library = $this->openAndVerifyUserLibrary($record);
        return $this->publicRecord($record) + ['library'=>$library->info()];
    }

    public function setUserStatus(string $userId, string $status): array
    {
        self::validateUserId($userId);
        if (!in_array($status, ['active','disabled'], true)) throw new RuntimeException('Invalid user status');

        $result = $this->mutateRegistry(function(array $payload) use ($userId,$status): array {
            $users = $payload['users'] ?? [];
            if (!is_array($users) || ($users !== [] && array_is_list($users))) {
                throw new RuntimeException('Malformed multi-user registry users map');
            }
            $record = $users[$userId] ?? null;
            if (!is_array($record)) throw new RuntimeException('User is not registered');
            $record['status'] = $status;
            $record['updated_at'] = self::now();
            $users[$userId] = $record;
            $payload['users'] = $users;
            $payload['updated_at'] = self::now();
            return $payload;
        });

        $record = $this->findRecord($result['library'], $userId);
        if ($record === null) throw new RuntimeException('User status update failed');
        return $this->publicRecord($record);
    }

    public function userStoragePrefix(string $issuer, string $subject): string
    {
        $identity = AuthenticatedIdentity::fromSubject($issuer, $subject, $this->pepper);
        return self::USERS_ROOT . '/' . $identity->userId();
    }

    private function ensureRegistryLibrary(): Library
    {
        $storage = new PrefixStorageAdapter($this->rootStorage, self::REGISTRY_PREFIX);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                if (!$storage->exists('manifest.mcma')) {
                    $library = Library::init($storage, 'private');
                    $library->initializeAccessControl(null, 'owner');
                    $library->writeAs(
                        'owner',
                        self::REGISTRY_REF,
                        [
                            'registry_version' => '1.0',
                            'users' => [],
                            'created_at' => self::now(),
                            'updated_at' => self::now(),
                        ],
                        'json',
                        'warm',
                        '00-system',
                        'system',
                        'confirmed'
                    );
                    return $library;
                }

                $library = Library::open($storage);
                if (!$this->libraryHasRef($library, self::REGISTRY_REF)) {
                    $library->writeAs(
                        'owner',
                        self::REGISTRY_REF,
                        [
                            'registry_version' => '1.0',
                            'users' => [],
                            'created_at' => self::now(),
                            'updated_at' => self::now(),
                        ],
                        'json',
                        'warm',
                        '00-system',
                        'system',
                        'confirmed'
                    );
                }

                return $library;
            } catch (RuntimeException $e) {
                if ($attempt === 3 || !$this->isConcurrencyConflict($e)) throw $e;
            }
        }

        throw new RuntimeException('Unable to initialize multi-user registry');
    }

    private function ensureUserLibrary(string $prefix, string $userId): Library
    {
        $storage = new PrefixStorageAdapter($this->rootStorage, $prefix);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $library = $storage->exists('manifest.mcma')
                    ? Library::open($storage)
                    : Library::init($storage, 'private');

                $library->initializeAccessControl(null, 'owner');

                if (!$this->libraryHasRef($library, self::ACCOUNT_REF)) {
                    $library->writeAs(
                        'owner',
                        self::ACCOUNT_REF,
                        [
                            'user_id' => $userId,
                            'created_at' => self::now(),
                            'registry_version' => '1.0',
                        ],
                        'json',
                        'warm',
                        '10-self',
                        'user',
                        'confirmed'
                    );
                }

                $this->assertUserMarker($library, $userId);
                return $library;
            } catch (RuntimeException $e) {
                if ($attempt === 3 || !$this->isConcurrencyConflict($e)) throw $e;
            }
        }

        throw new RuntimeException('Unable to initialize user library');
    }

    private function openAndVerifyUserLibrary(array $record): Library
    {
        $prefix = (string)($record['storage_prefix'] ?? '');
        $userId = (string)($record['user_id'] ?? '');
        $libraryId = (string)($record['library_id'] ?? '');

        if ($prefix !== self::USERS_ROOT . '/' . $userId) {
            throw new RuntimeException('Multi-user storage prefix mismatch');
        }

        $library = Library::open(new PrefixStorageAdapter($this->rootStorage, $prefix));
        if (!hash_equals($libraryId, $library->libraryId())) {
            throw new RuntimeException('Multi-user library_id mismatch');
        }

        $this->assertUserMarker($library, $userId);
        return $library;
    }

    private function assertUserMarker(Library $library, string $userId): void
    {
        if (!$this->libraryHasRef($library, self::ACCOUNT_REF)) {
            throw new RuntimeException('User library identity marker is missing');
        }

        $payload = $library->read(self::ACCOUNT_REF)['payload'] ?? null;
        $content = is_array($payload) ? ($payload['content'] ?? null) : null;
        if (!is_array($content) || ($content['user_id'] ?? null) !== $userId) {
            throw new RuntimeException('User library identity marker mismatch');
        }
    }

    private function mutateRegistry(callable $mutator): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $library = $this->ensureRegistryLibrary();

            try {
                $result = $library->mutateJson(
                    self::REGISTRY_REF,
                    function(mixed $current) use ($mutator): array {
                        if (!is_array($current) || array_is_list($current)) {
                            throw new RuntimeException('Malformed multi-user registry payload');
                        }
                        if (($current['registry_version'] ?? null) !== '1.0') {
                            throw new RuntimeException('Unsupported multi-user registry version');
                        }

                        $next = $mutator($current);
                        if (($next['registry_version'] ?? null) !== '1.0') {
                            throw new RuntimeException('Multi-user registry version cannot be changed by mutation');
                        }
                        return $next;
                    },
                    'owner'
                );

                return ['library' => $library, 'mutation' => $result];
            } catch (RuntimeException $e) {
                if ($attempt === 4 || !$this->isConcurrencyConflict($e)) throw $e;
            }
        }

        throw new RuntimeException('Unable to update multi-user registry after retries');
    }

    private function registryPayload(Library $registry): array
    {
        $payload = $registry->read(self::REGISTRY_REF)['payload'] ?? null;
        $content = is_array($payload) ? ($payload['content'] ?? null) : null;

        if (!is_array($content) || array_is_list($content) || ($content['registry_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Malformed multi-user registry');
        }

        return $content;
    }

    private function findRecord(Library $registry, string $userId): ?array
    {
        $payload = $this->registryPayload($registry);
        $users = $payload['users'] ?? [];

        if (!is_array($users) || ($users !== [] && array_is_list($users))) {
            throw new RuntimeException('Malformed multi-user registry users map');
        }

        $record = $users[$userId] ?? null;
        if ($record === null) return null;
        if (!is_array($record) || array_is_list($record)) {
            throw new RuntimeException('Malformed multi-user registry record');
        }

        return $record;
    }

    private function assertIdentityRecord(AuthenticatedIdentity $identity, array $record): void
    {
        if (($record['user_id'] ?? null) !== $identity->userId()) {
            throw new RuntimeException('Multi-user user_id mismatch');
        }
        if (($record['identity_fingerprint'] ?? null) !== $identity->fingerprint()) {
            throw new RuntimeException('Multi-user authenticated identity mismatch');
        }
        if (!preg_match('/^lib_[0-9a-f-]{36}$/', (string)($record['library_id'] ?? ''))) {
            throw new RuntimeException('Multi-user record has invalid library_id');
        }
        if (!in_array($record['status'] ?? null, ['active', 'disabled'], true)) {
            throw new RuntimeException('Multi-user record has invalid status');
        }
    }

    private function publicRecord(array $record): array
    {
        return [
            'user_id' => (string)($record['user_id'] ?? ''),
            'library_id' => (string)($record['library_id'] ?? ''),
            'storage_prefix' => (string)($record['storage_prefix'] ?? ''),
            'status' => (string)($record['status'] ?? ''),
            'created_at' => (string)($record['created_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
        ];
    }

    private function libraryHasRef(Library $library, string $logicalRef): bool
    {
        foreach ($library->list() as $entry) {
            if (in_array($logicalRef, $entry['logical_refs'] ?? [], true)) return true;
        }
        return false;
    }

    private function isConcurrencyConflict(RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'version conflict')
            || str_contains($message, 'already contains')
            || str_contains($message, 'logical reference already exists')
            || str_contains($message, 'non-empty storage')
            || str_contains($message, 'already exists');
    }

    private static function validateUserId(string $userId): void
    {
        if (!preg_match('/^usr_[0-9a-f]{64}$/', $userId)) throw new RuntimeException('Invalid multi-user user_id');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
