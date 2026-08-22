<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Document;

/**
 * Turns document HTML into the bytes that get archived.
 *
 * A port rather than a concrete PDF class. WeasyPrint is the bundled
 * implementation, but plenty of deployments render through Gotenberg, a
 * headless browser or a print service behind an API, and the issuing rules —
 * immutability, supersession, checksums — have nothing to do with which one is
 * in use. It also lets the pipeline be tested without a PDF engine on the box.
 */
interface DocumentRendererInterface
{
    /**
     * @param array<string, mixed> $options Renderer hints such as paper size and orientation.
     */
    public function render(string $html, array $options = []): string;

    /** Media type of what {@see render()} produces. */
    public function mediaType(): string;
}
