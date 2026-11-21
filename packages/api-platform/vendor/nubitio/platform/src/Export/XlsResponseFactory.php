<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class XlsResponseFactory
{
    public function response(Xlsx $writer, string $filename): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($writer): void {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s.xlsx"', $filename));

        return $response;
    }
}
