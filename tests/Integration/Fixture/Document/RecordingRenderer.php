<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Document;

use Nubit\ApiPlatform\Document\DocumentRendererInterface;

/**
 * Stands in for WeasyPrint.
 *
 * The rules under test — a document is issued once, reprinting returns the same
 * bytes, a correction supersedes rather than overwrites — are properties of the
 * issuing pipeline, not of the PDF engine. Substituting the renderer keeps the
 * suite runnable without a Python toolchain in the image, and makes the produced
 * bytes something the assertions can actually compare.
 */
final class RecordingRenderer implements DocumentRendererInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $renderedHtml = [];

    public bool $shouldFail = false;

    public function render(string $html, array $options = []): string
    {
        ++$this->calls;
        $this->renderedHtml[] = $html;

        if ($this->shouldFail) {
            throw new \RuntimeException('The rendering engine is unavailable.');
        }

        // Each call produces distinct bytes, so a test asserting that the same
        // bytes came back is really asserting nothing was re-rendered.
        return sprintf("%%PDF-fake\ncall=%d\n%s", $this->calls, $html);
    }

    public function mediaType(): string
    {
        return 'application/pdf';
    }
}
