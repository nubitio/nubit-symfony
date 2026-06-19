<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Session;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds the JSON body for {@code GET /api/me}. Alias your implementation
 * over the default to add application-specific fields (branch, currency,
 * restaurant context, etc.).
 */
interface MeResponseBuilderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function build(UserInterface $user): array;
}