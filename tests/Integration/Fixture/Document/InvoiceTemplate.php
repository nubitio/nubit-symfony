<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Document;

use Nubit\ApiPlatform\Document\DocumentRenderContext;
use Nubit\ApiPlatform\Document\DocumentTemplateInterface;
use Nubit\Tests\Integration\Fixture\Entity\Payment;

/** Minimal template: enough markup to prove the resource and the context both reach it. */
final class InvoiceTemplate implements DocumentTemplateInterface
{
    public function render(object $resource, DocumentRenderContext $context): string
    {
        if (!$resource instanceof Payment) {
            throw new \InvalidArgumentException('This template renders payments.');
        }

        $amount = $resource->getAmount();

        return sprintf(
            '<html><body><h1>%s</h1><p>%s</p><p>%s</p><p>%s</p></body></html>',
            htmlspecialchars($context->documentNumber ?? 'DRAFT'),
            htmlspecialchars($resource->reference),
            htmlspecialchars(null === $amount ? '-' : (string) $amount),
            htmlspecialchars($context->issuedAtLocal()->format('Y-m-d H:i')),
        );
    }
}
