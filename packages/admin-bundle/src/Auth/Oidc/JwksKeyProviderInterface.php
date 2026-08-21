<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Auth\Oidc;

use Firebase\JWT\Key;

interface JwksKeyProviderInterface
{
    /**
     * @return array<string, Key>
     */
    public function keysFor(string $jwksUri): array;
}
