<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth;

interface JWTManagerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload, ?int $expiresIn = null): string;

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array;

    public function verify(string $token): bool;

    public function isExpired(string $token): bool;
}
