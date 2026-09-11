<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use Nubit\Platform\Exception\ServiceException;

/**
 * Thrown when an inline export would materialize more rows than
 * {@see XlsExporter} is willing to hold in memory at once.
 *
 * `XlsExporter` (via PhpSpreadsheet) builds the whole workbook in memory
 * before writing a single byte — the opposite of the queued export path,
 * which streams rows to disk with `openspout/openspout` and stays flat
 * regardless of size. A dataset too large for that inline path must be
 * refused, not silently truncated or allowed to exhaust memory.
 *
 * Extends `ServiceException` so `Nubit\ApiPlatform\Http\ExceptionListener`
 * turns it into a `413 Payload Too Large` problem-details response for any
 * inline-export controller that lets it propagate, instead of a bare 500.
 */
final class XlsExportTooLargeException extends ServiceException
{
    private const int HTTP_STATUS_PAYLOAD_TOO_LARGE = 413;

    public function __construct(
        public readonly int $maxRows,
    ) {
        parent::__construct(
            sprintf(
                'Inline XLSX export exceeds the %d-row limit. Use the streaming/queued export path for larger datasets.',
                $maxRows,
            ),
            self::HTTP_STATUS_PAYLOAD_TOO_LARGE,
        );
    }
}
