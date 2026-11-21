<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Identity;

use Nubit\Platform\Identity\Totp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Totp::class)]
final class TotpTest extends TestCase
{
    /**
     * The RFC 6238 test vectors, for the SHA-1 variant every authenticator app
     * implements. Pinning them is the only way to know the implementation is
     * interoperable rather than merely self-consistent — a wrong one agrees
     * with itself perfectly.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function referenceVectors(): iterable
    {
        // Secret "12345678901234567890", base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ.
        yield '1970-01-01 00:00:59' => [59, '287082'];
        yield '2005-03-18 01:58:29' => [1111111109, '081804'];
        yield '2005-03-18 01:58:31' => [1111111111, '050471'];
        yield '2009-02-13 23:31:30' => [1234567890, '005924'];
        yield '2033-05-18 03:33:20' => [2000000000, '279037'];
    }

    #[DataProvider('referenceVectors')]
    public function testTheRfcTestVectors(int $timestamp, string $expected): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        self::assertSame($expected, Totp::codeAt($secret, intdiv($timestamp, Totp::PERIOD)));
    }

    public function testAFreshSecretIsUsable(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(32, strlen($secret));
        self::assertNotNull(Totp::verify($secret, Totp::codeAt($secret, intdiv(time(), Totp::PERIOD))));
    }

    public function testTwoSecretsDiffer(): void
    {
        self::assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    /** A phone's clock and a server's are never quite the same. */
    public function testACodeFromTheAdjacentStepIsAccepted(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, Totp::PERIOD);

        self::assertSame($step - 1, Totp::verify($secret, Totp::codeAt($secret, $step - 1), at: $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::codeAt($secret, $step + 1), at: $now));
    }

    /** Widening the window multiplies the codes an attacker may guess. */
    public function testACodeFromTooFarAwayIsRefused(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, Totp::PERIOD);

        self::assertNull(Totp::verify($secret, Totp::codeAt($secret, $step + 2), at: $now));
        self::assertNull(Totp::verify($secret, Totp::codeAt($secret, $step - 2), at: $now));
    }

    public function testTheMatchedStepIsReturnedSoItCanBeBurned(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, Totp::PERIOD);

        self::assertSame($step, Totp::verify($secret, Totp::codeAt($secret, $step), at: $now));
    }

    public function testACodeFromAnotherSecretIsRefused(): void
    {
        $now = 1_700_000_000;
        $mine = Totp::generateSecret();
        $theirs = Totp::generateSecret();

        self::assertNull(Totp::verify($mine, Totp::codeAt($theirs, intdiv($now, Totp::PERIOD)), at: $now));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['12345'];
        yield 'too long' => ['1234567'];
        yield 'letters' => ['abcdef'];
    }

    #[DataProvider('malformedCodes')]
    public function testMalformedCodesAreRefused(string $code): void
    {
        self::assertNull(Totp::verify(Totp::generateSecret(), $code));
    }

    /** Authenticator apps show the code with a space; users paste it that way. */
    public function testSeparatorsInTheTypedCodeAreIgnored(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = Totp::codeAt($secret, intdiv($now, Totp::PERIOD));

        self::assertNotNull(Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), at: $now));
    }

    public function testBase32RoundTrips(): void
    {
        $bytes = random_bytes(20);

        self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function testTheProvisioningUriCarriesWhatAnAppNeeds(): void
    {
        $secret = Totp::generateSecret();

        $uri = Totp::provisioningUri($secret, 'admin@example.com', 'Acme ERP');

        self::assertStringStartsWith('otpauth://totp/Acme%20ERP:admin%40example.com?', $uri);
        self::assertStringContainsString('secret=' . $secret, $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
