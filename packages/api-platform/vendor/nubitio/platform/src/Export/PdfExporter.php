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
    ) {}

    /**
     * Render a PDF from an HTML string and return its bytes.
     *
     * Anything that has to keep the file — an issued document, a queued export
     * — needs the bytes, not a response. Streaming is a presentation choice
     * layered on top of this, not the other way round.
     *
     * @param array<int|string, mixed> $options WeasyPrint options passed verbatim.
     */
    public function render(string $content, array $options = []): string
    {
        $pdf = new Pdf($this->weasyprintBinary);
        $pdf->setOptions($options);
        $pdf->setTimeout(self::TIMEOUT);

        return $pdf->getOutputFromHtml($content);
    }

    /**
     * Stream a PDF generated from an HTML string to the browser.
     *
     * @param array<int|string, mixed> $options  WeasyPrint options passed verbatim.
     */
    public function export(string $content, string $filename, array $options = []): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($content, $options): void {
            echo $this->render($content, $options);
        });

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', sprintf('inline; filename="%s.pdf"', $filename));

        return $response;
    }
}
