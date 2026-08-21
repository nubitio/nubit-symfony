<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth\Oidc;

use Nubit\AdminBundle\Auth\Oidc\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
    public function testVerifierIsUrlSafeAndWithinSpecLength(): void
    {
        $verifier = Pkce::generateVerifier();

        static::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $verifier);
        static::assertGreaterThanOrEqual(43, strlen($verifier));
        static::assertLessThanOrEqual(128, strlen($verifier));
    }

    public function testVerifiersAreRandomPerCall(): void
    {
        static::assertNotSame(Pkce::generateVerifier(), Pkce::generateVerifier());
    }

    public function testChallengeIsTheUrlSafeBase64OfTheSha256OfTheVerifier(): void
    {
        $verifier = 'a-fixed-test-verifier-value-for-this-assertion';

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, binary: true)), '+/', '-_'), '=');

        static::assertSame($expected, Pkce::challengeFor($verifier));
    }

    public function testChallengeIsDeterministicForTheSameVerifier(): void
    {
        $verifier = Pkce::generateVerifier();

        static::assertSame(Pkce::challengeFor($verifier), Pkce::challengeFor($verifier));
    }
}
