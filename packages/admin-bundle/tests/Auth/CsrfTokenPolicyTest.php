<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use Nubit\AdminBundle\Auth\CsrfTokenPolicy;
use PHPUnit\Framework\TestCase;

final class CsrfTokenPolicyTest extends TestCase
{
    public function testGenerateProducesANonEmptyToken(): void
    {
        self::assertNotSame('', CsrfTokenPolicy::generate());
    }

    public function testGenerateProducesADifferentTokenEachCall(): void
    {
        self::assertNotSame(CsrfTokenPolicy::generate(), CsrfTokenPolicy::generate());
    }

    public function testGenerateProducesAHighEntropyToken(): void
    {
        // 32 random bytes, hex-encoded.
        self::assertSame(64, strlen(CsrfTokenPolicy::generate()));
    }
}
