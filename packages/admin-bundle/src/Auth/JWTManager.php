<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LogicException;
use Psr\Log\LoggerInterface;

final readonly class JWTManager implements JWTManagerInterface
{
    private const string JWT_ALGORITHM = 'HS256';

    public function __construct(
        private string $secret,
        private LoggerInterface $logger,
    ) {
        if (strlen(trim($this->secret)) < 32) {
            throw new LogicException(
                'JWT secret must be at least 32 bytes for HS256. Configure APP_SECRET (or nubit_admin.auth.secret) accordingly.',
            );
        }
    }

    /**
     * No-op warm-up call used by AppSecretBootListener to force eager instantiation
     * when JWTManager is behind a Symfony lazy-service proxy.
     * The real side-effect is the LogicException thrown in __construct() when
     * APP_SECRET is empty — this method itself does nothing.
     */
    public function ping(): void
    {
        // Intentional no-op.
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload, ?int $expiresIn = null): string
    {
        if ($expiresIn !== null) {
            $payload['exp'] = time() + $expiresIn;
        }

        return JWT::encode($payload, $this->secret, self::JWT_ALGORITHM);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        /** @var array<string, mixed> $claims */
        $claims = get_object_vars(JWT::decode($token, new Key($this->secret, self::JWT_ALGORITHM)));

        return $claims;
    }

    public function verify(string $token): bool
    {
        try {
            $this->decode($token);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Invalid JWT token', ['exception' => $e]);
            return false;
        }
    }

    public function isExpired(string $token): bool
    {
        try {
            $payload = $this->decode($token);

            $expiresAt = self::numericClaim($payload, 'exp');
            if (null === $expiresAt) {
                return array_key_exists('exp', $payload);
            }

            return time() > $expiresAt;
        } catch (Exception $e) {
            $this->logger->error('Invalid JWT token', ['exception' => $e]);
            return true;
        }
    }

    /** @param array<array-key, mixed> $payload */
    private static function numericClaim(array $payload, string $name): int|float|null
    {
        if (is_int($payload[$name] ?? null) || is_float($payload[$name] ?? null)) {
            return $payload[$name];
        }

        return null;
    }
}
