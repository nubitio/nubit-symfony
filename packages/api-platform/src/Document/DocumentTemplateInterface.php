<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Document;

/**
 * Turns a resource into the HTML a document is rendered from.
 *
 * A PHP class rather than a template file path: it is a service, so it can
 * inject whatever the document needs — a tax calculator, a logo resolver, the
 * timezone resolver — and it is discoverable by name from the `#[Printable]`
 * attribute, which is what lets an agent find and modify the right template
 * without searching a directory of files.
 *
 * Implementations are autoconfigured through this interface; tag nothing.
 */
interface DocumentTemplateInterface
{
    /** @param object $resource The entity being issued. */
    public function render(object $resource, DocumentRenderContext $context): string;
}
