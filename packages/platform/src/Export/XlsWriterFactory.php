<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\SimpleCache\CacheInterface;

final readonly class XlsWriterFactory
{
    public function __construct(
        private ?CacheInterface $cache = null,
        private bool $preCalculateFormulas = false,
    ) {}

    public function newSpreadsheet(string $creator = 'Nubit', string $title = 'Nubit export'): Spreadsheet
    {
        if ($this->cache !== null) {
            Settings::setCache($this->cache);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($creator)
            ->setTitle($title);

        return $spreadsheet;
    }

    public function writer(Spreadsheet $spreadsheet): Xlsx
    {
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas($this->preCalculateFormulas);

        return $writer;
    }
}
