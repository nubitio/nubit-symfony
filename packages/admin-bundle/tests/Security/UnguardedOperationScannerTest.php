<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Tests\Security;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Nubit\AdminBundle\Security\UnguardedOperationScanner;
use PHPUnit\Framework\TestCase;

final class UnguardedOperationScannerTest extends TestCase
{
    public function testFlagsWriteOperationsWithoutASecurityExpression(): void
    {
        $findings = (new UnguardedOperationScanner())->scan([
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new Delete()],
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new Patch(uriTemplate: '/invoices/{id}')],
        ]);

        static::assertCount(2, $findings);
        static::assertSame('Invoice', $findings[0]->resourceShortName);
        static::assertSame('DELETE', $findings[0]->method);
        static::assertSame('PATCH', $findings[1]->method);
        static::assertSame('/invoices/{id}', $findings[1]->uriTemplate);
    }

    public function testDoesNotFlagAnOperationWithASecurityExpression(): void
    {
        $findings = (new UnguardedOperationScanner())->scan([
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new Delete(security: "is_granted('ROLE_ADMIN')")],
        ]);

        static::assertSame([], $findings);
    }

    public function testTreatsAnEmptySecurityExpressionAsUnguarded(): void
    {
        $findings = (new UnguardedOperationScanner())->scan([
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new Post(security: '   ')],
        ]);

        static::assertCount(1, $findings);
    }

    public function testIgnoresReadOperationsRegardlessOfSecurity(): void
    {
        $findings = (new UnguardedOperationScanner())->scan([
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new Get()],
            ['resourceClass' => 'App\\Entity\\Invoice', 'operation' => new GetCollection()],
        ]);

        static::assertSame([], $findings);
    }
}
