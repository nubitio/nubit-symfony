<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who has to use a second factor.
 *
 * Expressed as a policy rather than a boolean because the useful middle ground
 * is the common one: an ERP does not force every warehouse clerk through an
 * authenticator app, but it does force whoever can change bank details.
 *
 * A user who enrolled voluntarily is always required to present it, whatever
 * the policy says — opting in and then having it silently ignored would be
 * worse than not offering it.
 */
final readonly class TotpPolicy
{
    /**
     * @param bool         $requiredForAll   Everyone must enrol.
     * @param list<string> $requiredForRoles Roles that must enrol, e.g. ROLE_ADMIN.
     */
    public function __construct(
        private bool $requiredForAll = false,
        private array $requiredForRoles = [],
    ) {}

    public function requires(UserInterface $user): bool
    {
        if ($this->requiredForAll) {
            return true;
        }

        return (
            [] !== array_intersect(
                array_map(strtoupper(...), $user->getRoles()),
                array_map(strtoupper(...), $this->requiredForRoles),
            )
        );
    }

    public function isMandatoryAnywhere(): bool
    {
        return $this->requiredForAll || [] !== $this->requiredForRoles;
    }
}
