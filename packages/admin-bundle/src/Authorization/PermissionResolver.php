<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Authorization;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Authorization\Entity\Role;
use Nubit\Platform\Money\Money;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What a user may actually do, resolved from the roles they hold.
 *
 * The user's `getRoles()` stays the source of identity — a role name — and this
 * turns those names into a permission set. That ordering is deliberate: an
 * application already built on `ROLE_*` keeps working unchanged and gains
 * granularity where it needs it, rather than in one migration that has to be
 * right the first time.
 *
 * Resolution is memoised per request. A grid rendering a hundred rows asks the
 * same question a hundred times, and going to the database for each is how
 * authorization becomes the reason a page is slow.
 */
final class PermissionResolver
{
    /** @var array<string, list<string>> */
    private array $permissionCache = [];

    /** @var array<string, array<string, Money>> */
    private array $limitCache = [];

    /** @var list<Role>|null */
    private ?array $roles = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        /**
         * Roles that hold every permission without listing them.
         *
         * A system needs an account that cannot be locked out of its own
         * authorization screen, and "the super-admin forgot to grant themselves
         * role.update" is a support call nobody should ever have to make.
         *
         * @var list<string>
         */
        private readonly array $superRoles = ['ROLE_SUPER_ADMIN'],
        private readonly ?PermissionCatalog $catalog = null,
    ) {}

    /** @return list<string> */
    public function permissionsFor(UserInterface $user): array
    {
        $key = $this->cacheKey($user);

        return $this->permissionCache[$key] ??= $this->resolvePermissions($user);
    }

    public function hasPermission(UserInterface $user, string $permission): bool
    {
        return in_array(strtolower($permission), $this->permissionsFor($user), true);
    }

    /**
     * The cap on a permission, or null when the user is unlimited.
     *
     * A user holding several roles gets the **most permissive** limit, which is
     * the only reading that makes adding a role feel like adding authority. The
     * alternative — the smallest cap wins — makes a promotion able to reduce
     * what somebody can approve.
     */
    public function limitFor(UserInterface $user, string $permission): ?Money
    {
        $key = $this->cacheKey($user);
        $limits = $this->limitCache[$key] ??= $this->resolveLimits($user);

        return $limits[strtolower($permission)] ?? null;
    }

    /** @return array<string, mixed> The block `GET /api/me` publishes. */
    public function sessionBlock(UserInterface $user): array
    {
        $limits = [];
        foreach ($this->limitCache[$this->cacheKey($user)] ?? $this->resolveLimits($user) as $permission => $money) {
            $limits[$permission] = $money->jsonSerialize();
        }

        return ['permissions' => $this->permissionsFor($user), 'limits' => $limits];
    }

    public function reset(): void
    {
        $this->permissionCache = [];
        $this->limitCache = [];
        $this->roles = null;
    }

    /** @return list<string> */
    private function resolvePermissions(UserInterface $user): array
    {
        $roleNames = array_values(array_map(strtoupper(...), $user->getRoles()));

        if ([] !== array_intersect($roleNames, $this->superRoles)) {
            return $this->catalog?->names() ?? [];
        }

        $permissions = [];
        foreach ($this->rolesNamed($roleNames) as $role) {
            foreach ($role->getPermissions() as $permission) {
                $permissions[] = $permission;
            }
        }

        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /** @return array<string, Money> */
    private function resolveLimits(UserInterface $user): array
    {
        $roleNames = array_values(array_map(strtoupper(...), $user->getRoles()));

        if ([] !== array_intersect($roleNames, $this->superRoles)) {
            return [];
        }

        /** @var array<string, Money> $limits */
        $limits = [];
        foreach ($this->rolesNamed($roleNames) as $role) {
            foreach (array_keys($role->getLimits()) as $permission) {
                $permission = (string) $permission;
                $limit = $role->limitFor($permission);
                if (null === $limit) {
                    continue;
                }

                /** @var Money|null $existing */
                $existing = $limits[$permission] ?? null;

                // A permission the user holds *without* a cap through some other
                // role is unlimited; a stored cap cannot re-introduce a ceiling.
                if (null !== $existing && $existing->currency->is($limit->currency)) {
                    $limits[$permission] = $existing->isGreaterThan($limit) ? $existing : $limit;
                    continue;
                }

                $limits[$permission] = $limit;
            }
        }

        return $limits;
    }

    /**
     * @param list<string> $names
     *
     * @return list<Role>
     */
    private function rolesNamed(array $names): array
    {
        if ([] === $names) {
            return [];
        }

        // The whole table is loaded once rather than queried per role name:
        // role tables are tens of rows, and one query beats N.
        $this->roles ??= $this->loadRoles();

        return array_values(array_filter($this->roles, static fn(Role $role): bool => in_array(
            $role->getName(),
            $names,
            true,
        )));
    }

    /** @return list<Role> */
    private function loadRoles(): array
    {
        /** @var list<Role> $roles */
        $roles = $this->entityManager->getRepository(Role::class)->findAll();

        return $roles;
    }

    private function cacheKey(UserInterface $user): string
    {
        return $user->getUserIdentifier() . '|' . implode(',', $user->getRoles());
    }
}
