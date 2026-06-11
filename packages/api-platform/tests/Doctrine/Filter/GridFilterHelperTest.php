<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Tests\Doctrine\Filter;

use Nubit\ApiPlatform\Doctrine\Filter\GridFilterHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GridFilterHelperTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function operatorCases(): iterable
    {
        yield 'contains'    => ['contains', 'LIKE'];
        yield 'startswith'  => ['startswith', 'LIKE'];
        yield 'endswith'    => ['endswith', 'LIKE'];
        yield 'notcontains' => ['notcontains', 'NOT LIKE'];
        yield 'isnull'      => ['isnull', 'IS NULL'];
        yield 'isnotnull'   => ['isnotnull', 'IS NOT NULL'];
        yield 'equals'      => ['=', '='];
        yield 'gte'         => ['>=', '>='];
    }

    #[DataProvider('operatorCases')]
    public function testDqlOperator(string $gridOp, string $expected): void
    {
        self::assertSame($expected, GridFilterHelper::dqlOperator($gridOp));
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function valueCases(): iterable
    {
        yield 'contains wraps'    => ['contains', 'abc', '%abc%'];
        yield 'notcontains wraps' => ['notcontains', 'abc', '%abc%'];
        yield 'startswith'        => ['startswith', 'abc', 'abc%'];
        yield 'endswith'          => ['endswith', 'abc', '%abc'];
        yield 'isnull is null'    => ['isnull', 'ignored', null];
        yield 'equals passthrough' => ['=', 42, 42];
    }

    #[DataProvider('valueCases')]
    public function testValueForOperator(string $gridOp, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, GridFilterHelper::valueForOperator($gridOp, $value));
    }
}
