<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\FeatureFlag;

use Nubit\Platform\FeatureFlag\FeatureFlagContext;
use Nubit\Platform\FeatureFlag\StaticFeatureFlagProvider;
use PHPUnit\Framework\TestCase;

final class StaticFeatureFlagProviderTest extends TestCase
{
    public function testReturnsTypedValuesAndFallsBackOnTypeMismatch(): void
    {
        $provider = new StaticFeatureFlagProvider([
            'new-grid' => true,
            'theme' => 'compact',
            'limit' => 25,
            'ratio' => 0.5,
            'config' => ['mode' => 'safe'],
        ]);
        $context = new FeatureFlagContext(tenantId: 42);

        self::assertTrue($provider->boolean('new-grid', false, $context));
        self::assertSame('compact', $provider->string('theme', 'classic', $context));
        self::assertSame(25, $provider->integer('limit', 10, $context));
        self::assertSame(0.5, $provider->float('ratio', 1.0, $context));
        self::assertSame(['mode' => 'safe'], $provider->object('config', [], $context));
        self::assertFalse($provider->boolean('theme', false, $context));
        self::assertTrue($provider->boolean('missing', true, $context));
    }
}
