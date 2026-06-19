<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Runtime;

use Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigProviderTest extends TestCase
{
    public function testAppCanReturnArbitraryPayload(): void
    {
        $provider = new class implements RuntimeConfigProviderInterface {
            public function getConfig(): array
            {
                return [
                    'ui' => ['compact' => true],
                    'defaults' => ['locale' => 'es'],
                ];
            }
        };

        self::assertSame([
            'ui' => ['compact' => true],
            'defaults' => ['locale' => 'es'],
        ], $provider->getConfig());
    }
}