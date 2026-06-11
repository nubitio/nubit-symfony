<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Auth;

use LogicException;
use Nubit\AdminBundle\Auth\JWTManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class JWTManagerTest extends TestCase
{
    private JWTManager $manager;

    protected function setUp(): void
    {
        $this->manager = new JWTManager('test-secret-key-with-32-or-more-chars!', new NullLogger());
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $token = $this->manager->encode(['username' => 'jane', 'custom' => 42], 3600);

        $payload = $this->manager->decode($token);

        self::assertSame('jane', $payload['username']);
        self::assertSame(42, $payload['custom']);
        self::assertGreaterThan(time(), $payload['exp']);
    }

    public function testVerifyRejectsTamperedToken(): void
    {
        $token = $this->manager->encode(['username' => 'jane'], 3600);
        $tampered = $token . 'x';

        self::assertTrue($this->manager->verify($token));
        self::assertFalse($this->manager->verify($tampered));
    }

    public function testVerifyRejectsTokenFromOtherSecret(): void
    {
        $other = new JWTManager('another-secret-also-32-plus-characters', new NullLogger());
        $token = $other->encode(['username' => 'jane'], 3600);

        self::assertFalse($this->manager->verify($token));
    }

    public function testIsExpired(): void
    {
        $expired = $this->manager->encode(['username' => 'jane', 'exp' => time() - 10]);

        self::assertTrue($this->manager->isExpired($expired));
        self::assertFalse($this->manager->isExpired($this->manager->encode(['username' => 'jane'], 3600)));
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(LogicException::class);

        new JWTManager('  ', new NullLogger());
    }

    public function testShortSecretIsRejected(): void
    {
        $this->expectException(LogicException::class);

        new JWTManager('too-short', new NullLogger());
    }
}
