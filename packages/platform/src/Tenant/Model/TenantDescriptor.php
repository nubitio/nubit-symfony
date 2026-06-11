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
        $primaryDomain = $tenant['primary_domain'] ?? $tenant['domain'] ?? null;
        $connectionName = $tenant['connection'] ?? $tenant['connection_name'] ?? null;

        return new self(
            id: (int)($tenant['id'] ?? 0),
            name: (string)($tenant['name'] ?? ''),
            connectionName: self::nullableString($connectionName),
            primaryDomain: self::nullableString($primaryDomain),
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

        $stringValue = trim((string)$value);

        return $stringValue === '' ? null : $stringValue;
    }
}
