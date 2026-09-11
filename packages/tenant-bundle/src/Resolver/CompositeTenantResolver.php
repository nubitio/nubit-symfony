<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class CompositeTenantResolver implements TenantResolverInterface
{
    /**
     * @param list<TenantResolverInterface> $resolvers
     */
    public function __construct(
        private array $resolvers,
    ) {}

    /**
     * Tries every configured strategy, in order, and returns the first tenant
     * resolved. Every remaining strategy is still consulted — not to pick a
     * winner among them, but because two sources naming different tenants for
     * the same request (a JWT claim for tenant A, a header for tenant B) is
     * never a case where guessing which one to trust is the right answer.
     */
    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        $resolved = null;

        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request, $user);
            if (null === $tenant) {
                continue;
            }

            if (null !== $resolved && $resolved->id !== $tenant->id) {
                throw new ConflictHttpException('Conflicting tenant identifiers were resolved for this request.');
            }

            $resolved ??= $tenant;
        }

        return $resolved;
    }
}
