<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Export;

use Nubit\Platform\Export\XlsExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Turns whatever the normal API Platform normalizer chain already produces
 * for a resource (the same array shape "json" format returns: a flat list of
 * associative arrays for a collection, a single associative array for an
 * item) into an XLSX workbook. No per-resource wiring needed — any
 * ApiResource collection/item endpoint gets export for free once this format
 * is enabled, the same way "json"/"jsonld" are.
 */
final class XlsxEncoder implements EncoderInterface
{
    public const string FORMAT = 'xlsx';

    public function __construct(
        private readonly XlsExporter $exporter = new XlsExporter(),
    ) {
    }

    public function supportsEncoding(string $format): bool
    {
        return $format === self::FORMAT;
    }

    /**
     * Accepts whatever the normalizer chain produced: a list of row arrays for
     * a collection, a single associative array for an item, anything else
     * (null, a scalar) encodes as an empty workbook rather than failing.
     *
     * @param array<array-key, mixed> $context
     */
    public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->writeToString($this->exporter->makeSpreadsheetFromIterable($this->normalizeRows($data)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_is_list($data)
            ? array_values(array_filter($data, is_array(...)))
            : [$data];

        return $rows;
    }

    private function writeToString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream for XLSX encoding.');
        }

        try {
            $writer->save($stream);
            rewind($stream);
            $contents = stream_get_contents($stream);

            return $contents === false ? '' : $contents;
        } finally {
            fclose($stream);
        }
    }
}
