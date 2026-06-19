<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Runtime;

use Nubit\AdminBundle\Runtime\NullRuntimeConfigProvider;
use PHPUnit\Framework\TestCase;

final class NullRuntimeConfigProviderTest extends TestCase
{
    public function testReturnsEmptyArray(): void
    {
        self::assertSame([], (new NullRuntimeConfigProvider())->getConfig());
    }
}