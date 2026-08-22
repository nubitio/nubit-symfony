<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Identity;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The bundle's only door into the application's User entity.
 *
 * The bundle deliberately does not own that entity — every application models
 * it differently — but password reset and invitation acceptance both have to
 * write to it. Naming the three operations they need keeps the coupling to
 * three methods instead of to a table layout.
 *
 * A Doctrine-backed default is provided; alias this interface to replace it.
 */
interface IdentityUserGatewayInterface
{
    public function findByIdentifier(string $identifier): ?UserInterface;

    public function changePassword(UserInterface $user, string $plainPassword): void;

    /**
     * Creates the account an invitation was addressed to.
     *
     * @param list<string> $roles
     */
    public function createUser(string $identifier, string $plainPassword, array $roles): UserInterface;
}
