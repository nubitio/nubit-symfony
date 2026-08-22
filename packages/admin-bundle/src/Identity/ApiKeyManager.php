<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Identity\Entity\ApiKey;
use Nubit\AdminBundle\Identity\Exception\IdentityException;

/**
 * Issuing, presenting and retiring machine credentials.
 *
 * The whole plaintext is returned exactly once, at creation. Everything after
 * that works from the prefix — which is why the prefix exists: a key needs to
 * be identifiable in a list and in a log without the list or the log being a
 * way to use it.
 */
final readonly class ApiKeyManager
{
    /** Recognisable at a glance in a config file or a log line. */
    private const string TOKEN_PREFIX = 'nbk_';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<string> $roles
     *
     * @return array{key: string, record: ApiKey} The plaintext, once.
     */
    public function create(
        string $name,
        string $userIdentifier,
        array $roles = [],
        ?\DateTimeImmutable $expiresAt = null,
        ?string $createdBy = null,
    ): array {
        $name = trim($name);
        if ('' === $name) {
            throw new IdentityException('An API key needs a name, so somebody can tell later what it was for.');
        }

        $secret = bin2hex(random_bytes(24));
        $plain = self::TOKEN_PREFIX . $secret;

        $record = new ApiKey($name, substr($plain, 0, 16), self::hash($plain), $userIdentifier);
        $record->setRoles($roles)->setExpiresAt($expiresAt)->setCreatedBy($createdBy);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return ['key' => $plain, 'record' => $record];
    }

    /**
     * Resolves a presented key, or null.
     *
     * Looked up by hash rather than scanned: the prefix narrows nothing that
     * matters and a scan comparing every row is how this becomes the slowest
     * part of an integration's request.
     */
    public function resolve(string $presented): ?ApiKey
    {
        $presented = trim($presented);

        if ('' === $presented || !str_starts_with($presented, self::TOKEN_PREFIX)) {
            return null;
        }

        $record = $this->entityManager->getRepository(ApiKey::class)->findOneBy(['keyHash' => self::hash($presented)]);

        if (!$record instanceof ApiKey || !$record->isActive()) {
            return null;
        }

        if ($record->touch()) {
            $this->entityManager->flush();
        }

        return $record;
    }

    /**
     * Replaces a key, keeping its name, principal and roles.
     *
     * Rotation issues a new key and revokes the old one in one step, because
     * the two halves done separately are how an integration ends up either
     * broken or still holding a credential somebody thought was gone.
     *
     * @return array{key: string, record: ApiKey}
     */
    public function rotate(ApiKey $existing, ?string $rotatedBy = null): array
    {
        $issued = $this->create(
            $existing->getName(),
            $existing->getUserIdentifier(),
            $existing->getRoles(),
            $existing->getExpiresAt(),
            $rotatedBy ?? $existing->getCreatedBy(),
        );

        $existing->revoke();
        $this->entityManager->flush();

        return $issued;
    }

    public function revoke(ApiKey $record): void
    {
        $record->revoke();
        $this->entityManager->flush();
    }

    /** @return list<ApiKey> */
    public function all(): array
    {
        /** @var list<ApiKey> $keys */
        $keys = $this->entityManager->getRepository(ApiKey::class)->findBy([], ['createdAt' => 'DESC']);

        return $keys;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
