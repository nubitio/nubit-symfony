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
        #[\SensitiveParameter]
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
            $payload = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            $claims = get_object_vars($payload);
        } catch (Throwable) {
            return null;
        }

        $tenantId = self::claim($claims, $this->idClaim);
        if (!is_int($tenantId) && !(is_string($tenantId) && ctype_digit($tenantId))) {
            return null;
        }

        $name = self::claim($claims, $this->nameClaim);

        return new ResolvedTenant(
            (int) $tenantId,
            is_string($name) ? $name : null,
        );
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->headers->get($this->authHeader);
        $matches = [];
        if (is_string($header) && 1 === preg_match('/^\s*Bearer\s+(.+)$/i', $header, $matches)) {
            $token = trim($matches[1]);

            return '' !== $token ? $token : null;
        }

        $cookie = $request->cookies->get($this->authCookie);

        return is_string($cookie) && '' !== $cookie ? $cookie : null;
    }

    /**
     * @param array<array-key, mixed> $claims
     */
    private static function claim(array $claims, string $name): int|string|null
    {
        if (is_int($claims[$name] ?? null)) {
            return $claims[$name];
        }

        if (is_string($claims[$name] ?? null)) {
            return $claims[$name];
        }

        return null;
    }
}
