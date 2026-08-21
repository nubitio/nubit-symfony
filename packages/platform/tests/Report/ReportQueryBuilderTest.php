<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Report;

use Nubit\Platform\Report\ExportableReportInterface;
use Nubit\Platform\Report\ReportQueryBuilder;
use PHPUnit\Framework\TestCase;

final class ReportQueryBuilderTest extends TestCase
{
    public function testBuildsReportQueryWithFilterSqlParamsColumnsAndFilename(): void
    {
        $report = new class implements ExportableReportInterface {
            public function key(): string
            {
                return 'sales';
            }

            public function fieldMap(): array
            {
                return ['customer' => 'c.name'];
            }

            public function sql(string $gridFilter): string
            {
                return 'select * from sales s left join customer c on c.id = s.customer_id where 1=1 ' . $gridFilter;
            }

            public function columns(): array
            {
                return ['customer' => 'Customer'];
            }

            public function filename(): string
            {
                return 'sales-report';
            }
        };

        $query = (new ReportQueryBuilder())->build($report, ['tenant' => 'acme'], '["customer","contains","Ana"]');

        self::assertSame(
            'select * from sales s left join customer c on c.id = s.customer_id where 1=1 AND c.name LIKE :grid_filter_1',
            $query->sql,
        );
        self::assertSame(['tenant' => 'acme', 'grid_filter_1' => '%Ana%'], $query->params);
        self::assertSame(['customer' => 'Customer'], $query->columns);
        self::assertSame('sales-report', $query->filename);
    }
}
