<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Security;

use Nubit\AdminBundle\Security\RouteGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RouteGuardTest extends TestCase
{
    public function testNoExpressionMeansNoExtraCheck(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        $guard = new RouteGuard($checker);
        $guard->assert(null);
        $guard->assert('');
        $guard->assert("   \n");
    }

    public function testAnExpressionTheCallerSatisfiesPasses(): void
    {
        $guard = new RouteGuard($this->checkerReturning(true));

        $guard->assert("is_granted('catalog.edit')");
        $this->addToAssertionCount(1);
    }

    public function testAnExpressionTheCallerDoesNotSatisfyIsForbidden(): void
    {
        $guard = new RouteGuard($this->checkerReturning(false));

        $this->expectException(AccessDeniedHttpException::class);
        $guard->assert("is_granted('catalog.edit')");
    }

    public function testTheExpressionAndItsSubjectReachTheChecker(): void
    {
        $seen = [];
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker
            ->method('isGranted')
            ->willReturnCallback(static function (mixed $attribute, mixed $subject) use (&$seen): bool {
                $seen = [$attribute, $subject];

                return true;
            });

        (new RouteGuard($checker))->assert("subject['resource'] == 'product'", ['resource' => 'product', 'id' => '7']);

        $this->assertInstanceOf(Expression::class, $seen[0]);
        $this->assertSame("subject['resource'] == 'product'", (string) $seen[0]);
        $this->assertSame(['resource' => 'product', 'id' => '7'], $seen[1]);
    }

    public function testAnAvailableExpressionLanguageLetsAConfiguredExpressionCompile(): void
    {
        RouteGuard::assertAvailable("is_granted('x')", 'nubit_admin.media.upload_security');
        RouteGuard::assertAvailable(null, 'nubit_admin.media.upload_security');
        $this->addToAssertionCount(1);
    }

    private function checkerReturning(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }
}
