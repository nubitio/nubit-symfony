<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

use Pontedilana\PhpWeasyPrint\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfExporter
{
    public const int TIMEOUT = 30;

    public function __construct(
        private readonly string $weasyprintBinary,
    ) {
    }

    /**
     * Stream a PDF generated from an HTML string to the browser.
     *
     * @param array<int|string, mixed> $options  WeasyPrint options passed verbatim.
     */
    public function export(string $content, string $filename, array $options = []): StreamedResponse
    {
        $pdf = new Pdf($this->weasyprintBinary);
        $pdf->setOptions($options);
        $pdf->setTimeout(self::TIMEOUT);

        $response = new StreamedResponse(function () use ($pdf, $content): void {
            echo $pdf->getOutputFromHtml($content);
        });

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s.pdf"', $filename));

        return $response;
    }
}
