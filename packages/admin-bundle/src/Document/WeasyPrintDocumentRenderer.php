<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Nubit\ApiPlatform\Document\DocumentRendererInterface;
use Nubit\Platform\Export\PdfExporter;

/** The bundled renderer: HTML to PDF through WeasyPrint. */
final readonly class WeasyPrintDocumentRenderer implements DocumentRendererInterface
{
    public function __construct(
        private PdfExporter $pdfExporter,
    ) {}

    public function render(string $html, array $options = []): string
    {
        return $this->pdfExporter->render($html, $options);
    }

    public function mediaType(): string
    {
        return 'application/pdf';
    }
}
