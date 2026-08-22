<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Authorization;

/**
 * A permission name: `invoice.approve`, `product.create`.
 *
 * Two parts, always: what it acts on, and what it does. The shape is not
 * decoration — it is what lets the catalogue be *derived* from the operations a
 * resource already declares instead of maintained as a second list beside them.
 * A parallel list of permission strings is a list that drifts, and the drift is
 * silent in the direction that matters: an operation nobody added a permission
 * for stays reachable.
 */
final readonly class Permission implements \Stringable
{
    /** The verbs derived from HTTP operations. Anything else is a domain action. */
    public const string CREATE = 'create';
    public const string READ = 'read';
    public const string UPDATE = 'update';
    public const string DELETE = 'delete';

    private function __construct(
        public string $resource,
        public string $action,
    ) {}

    public static function of(string $resource, string $action): self
    {
        $resource = self::normalize($resource, 'resource');
        $action = self::normalize($action, 'action');

        return new self($resource, $action);
    }

    /** Parses `invoice.approve`. */
    public static function parse(string $name): self
    {
        $parts = explode('.', trim($name));

        if (2 !== count($parts)) {
            throw new \InvalidArgumentException(sprintf('A permission is written "resource.action"; got "%s".', $name));
        }

        return self::of($parts[0], $parts[1]);
    }

    public static function isPermissionName(string $candidate): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/', $candidate);
    }

    /** The permission an HTTP method implies on a resource. */
    public static function forMethod(string $resource, string $method): self
    {
        return self::of($resource, match (strtoupper($method)) {
            'POST' => self::CREATE,
            'GET', 'HEAD' => self::READ,
            'PUT', 'PATCH' => self::UPDATE,
            'DELETE' => self::DELETE,
            default => strtolower($method),
        });
    }

    public function name(): string
    {
        return $this->resource . '.' . $this->action;
    }

    public function __toString(): string
    {
        return $this->name();
    }

    private static function normalize(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));

        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $normalized)) {
            throw new \InvalidArgumentException(sprintf(
                'A permission %s must be a lowercase identifier; got "%s".',
                $label,
                $value,
            ));
        }

        return $normalized;
    }
}
