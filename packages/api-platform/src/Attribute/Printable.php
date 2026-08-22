<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

/**
 * Marks a resource as something the application issues as a document.
 *
 * "Printable" here means more than rendering a PDF on demand. An invoice, a
 * purchase order or a delivery note is a *record*: once issued it must be
 * reproducible byte for byte, because the copy the customer holds and the copy
 * the archive holds have to be the same copy. Reprinting therefore returns the
 * stored bytes rather than rendering again — a template change six months later
 * must not silently rewrite last quarter's invoices.
 *
 * A correction is a new document that references the one it replaces, never an
 * edit of the original.
 *
 * ```php
 * #[ApiResource]
 * #[Printable(template: InvoiceTemplate::class, numberProperty: 'number')]
 * class Invoice { … }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Printable
{
    /**
     * @param class-string $template       Service implementing DocumentTemplateInterface.
     * @param string|null  $numberProperty Property holding the document number — typically
     *                                     allocated by nubitio/sequence-bundle. Recorded on the
     *                                     issued document so the archive is searchable by it.
     * @param string       $title          Translation key or literal shown in the print menu.
     * @param string       $paper          Page size passed to the renderer.
     * @param string       $orientation    `portrait` or `landscape`.
     * @param bool         $allowReissue   Whether a superseding copy may be issued at all. False
     *                                     for documents a jurisdiction forbids reissuing.
     */
    public function __construct(
        public string $template,
        public ?string $numberProperty = null,
        public string $title = 'document.print',
        public string $paper = 'A4',
        public string $orientation = 'portrait',
        public bool $allowReissue = true,
    ) {
        if (!in_array($orientation, ['portrait', 'landscape'], strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown page orientation "%s"; use portrait or landscape.',
                $orientation,
            ));
        }
    }
}
