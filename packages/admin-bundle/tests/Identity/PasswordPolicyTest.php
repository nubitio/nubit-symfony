<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Identity;

use Nubit\AdminBundle\Identity\Exception\IdentityException;
use Nubit\AdminBundle\Identity\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsALongEnoughPassword(): void
    {
        PasswordPolicy::assertAcceptable('12345678');
        $this->addToAssertionCount(1);
    }

    public function testRejectsAShortPassword(): void
    {
        $this->expectException(IdentityException::class);
        PasswordPolicy::assertAcceptable('short');
    }
}
