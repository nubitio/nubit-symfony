<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Model;

use InvalidArgumentException;

/**
 * Portable tenant description for future Platform ports.
 *
 * Legacy ControlPlane registries still return arrays during the SPEC-004
 * migration. New Platform-facing code should prefer this value object when a
 * typed tenant boundary is required.
 */
final readonly class TenantDescriptor
{
    /**
     * @param array<string, mixed> $attributes Extra provider-specific metadata kept outside the stable contract.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $connectionName = null,
        public ?string $primaryDomain = null,
        public ?string $plan = null,
        public ?string $status = null,
        public array $attributes = [],
    ) {
        if ($id <= 0) {
            throw new InvalidArgumentException('Tenant id must be a positive integer.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('Tenant name must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public static function fromArray(array $tenant): self
    {
        return new self(
            id: self::positiveId($tenant['id'] ?? null),
            name: self::requiredString($tenant['name'] ?? null, 'name'),
            connectionName: self::nullableString(self::firstPresent($tenant, 'connection', 'connection_name')),
            primaryDomain: self::nullableString(self::firstPresent($tenant, 'primary_domain', 'domain')),
            plan: self::nullableString($tenant['plan'] ?? null),
            status: self::nullableString($tenant['status'] ?? null),
            attributes: $tenant,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_replace($this->attributes, [
            'id' => $this->id,
            'name' => $this->name,
            'connection' => $this->connectionName,
            'primary_domain' => $this->primaryDomain,
            'plan' => $this->plan,
            'status' => $this->status,
        ]);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Tenant string attributes must be scalar values or null.');
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private static function positiveId(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Tenant id must be a positive integer.');
        }

        $id = (int) $value;
        if ($id <= 0) {
            throw new InvalidArgumentException('Tenant id must be a positive integer.');
        }

        return $id;
    }

    private static function requiredString(mixed $value, string $attribute): string
    {
        if (!is_string($value) || '' === trim($value)) {
            throw new InvalidArgumentException(sprintf('Tenant %s must be a non-empty string.', $attribute));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function firstPresent(array $values, string $primary, string $legacy): mixed
    {
        return array_key_exists($primary, $values) ? $values[$primary] : ($values[$legacy] ?? null);
    }
}
