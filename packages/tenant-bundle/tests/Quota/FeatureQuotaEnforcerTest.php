<?php

declare(strict_types=1);

namespace Nubit\TenantBundle\Tests\Quota;

use Nubit\Platform\Exception\QuotaExceededException;
use Nubit\Platform\Feature\Contract\FeatureCheckerInterface;
use Nubit\TenantBundle\Contract\QuotaUsageProviderInterface;
use Nubit\TenantBundle\Quota\FeatureQuotaEnforcer;
use PHPUnit\Framework\TestCase;

final class FeatureQuotaEnforcerTest extends TestCase
{
    public function testSkipsWhenFeatureDisabled(): void
    {
        $checker = $this->createMock(FeatureCheckerInterface::class);
        $checker->method('hasFeature')->with('team_users')->willReturn(false);
        $checker->expects(self::never())->method('getFeatureConfig');

        $usage = $this->createMock(QuotaUsageProviderInterface::class);
        $usage->expects(self::never())->method('count');

        (new FeatureQuotaEnforcer($checker, $usage))->enforce('team_users');
    }

    public function testSkipsWhenNoMaxConfigured(): void
    {
        $checker = $this->createMock(FeatureCheckerInterface::class);
        $checker->method('hasFeature')->willReturn(true);
        $checker->method('getFeatureConfig')->willReturn([]);

        $usage = $this->createMock(QuotaUsageProviderInterface::class);
        $usage->expects(self::never())->method('count');

        (new FeatureQuotaEnforcer($checker, $usage))->enforce('team_users');
    }

    public function testAllowsWhenBelowLimit(): void
    {
        $checker = $this->createMock(FeatureCheckerInterface::class);
        $checker->method('hasFeature')->willReturn(true);
        $checker->method('getFeatureConfig')->willReturn(['max' => 3]);

        $usage = $this->createMock(QuotaUsageProviderInterface::class);
        $usage->method('count')->with('team_users')->willReturn(2);

        (new FeatureQuotaEnforcer($checker, $usage))->enforce('team_users');
        self::assertTrue(true);
    }

    public function testThrowsWhenAtLimit(): void
    {
        $checker = $this->createMock(FeatureCheckerInterface::class);
        $checker->method('hasFeature')->willReturn(true);
        $checker->method('getFeatureConfig')->willReturn(['max' => 3]);

        $usage = $this->createMock(QuotaUsageProviderInterface::class);
        $usage->method('count')->with('team_users')->willReturn(3);

        $this->expectException(QuotaExceededException::class);
        $this->expectExceptionMessage('Quota exceeded for "team_users": 3/3');

        (new FeatureQuotaEnforcer($checker, $usage))->enforce('team_users');
    }
}