<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

interface TenantResolverInterface
{
    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant;
}