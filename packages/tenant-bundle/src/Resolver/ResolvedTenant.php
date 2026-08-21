<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

final readonly class ResolvedTenant
{
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $domain = null,
    ) {}

    /** @param array<string, mixed> $tenant */
    public static function fromRegistryRecord(array $tenant, ?string $fallbackDomain = null): ?self
    {
        $id = self::positiveId($tenant['id'] ?? null);
        if (null === $id) {
            return null;
        }

        return new self(
            $id,
            self::optionalString($tenant['name'] ?? null),
            self::optionalString($tenant['primary_domain'] ?? null) ?? $fallbackDomain,
        );
    }

    private static function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }
}
