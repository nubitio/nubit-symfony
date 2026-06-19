<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Resolver;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

final readonly class JwtClaimTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private string $jwtSecret,
        private string $idClaim = 'tenantId',
        private string $nameClaim = 'tenantName',
        private string $authHeader = 'Authorization',
        private string $authCookie = 'AUTH_TOKEN',
    ) {
    }

    public function resolve(Request $request, ?UserInterface $user): ?ResolvedTenant
    {
        $token = $this->extractToken($request);
        if (null === $token) {
            return null;
        }

        try {
            /** @var object $payload */
            $payload = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            $claims = (array) $payload;
        } catch (Throwable) {
            return null;
        }

        $tenantId = $claims[$this->idClaim] ?? null;
        if (!is_int($tenantId) && !(is_string($tenantId) && ctype_digit($tenantId))) {
            return null;
        }

        $name = $claims[$this->nameClaim] ?? null;

        return new ResolvedTenant(
            (int) $tenantId,
            is_string($name) ? $name : null,
        );
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->headers->get($this->authHeader);
        if (is_string($header) && 1 === preg_match('/^\s*Bearer\s+(.+)$/i', $header, $matches)) {
            $token = trim($matches[1]);

            return '' !== $token ? $token : null;
        }

        $cookie = $request->cookies->get($this->authCookie);

        return is_string($cookie) && '' !== $cookie ? $cookie : null;
    }
}