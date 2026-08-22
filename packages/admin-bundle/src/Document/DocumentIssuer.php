<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Document;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Document\Entity\IssuedDocument;
use Nubit\AdminBundle\Document\Message\RenderDocument;
use Nubit\ApiPlatform\Attribute\Printable;
use Nubit\ApiPlatform\Document\DocumentRenderContext;
use Nubit\ApiPlatform\Document\DocumentRendererInterface;
use Nubit\ApiPlatform\Document\DocumentTemplateInterface;
use Nubit\Platform\Exception\NotFoundException;
use Nubit\Platform\Exception\ServiceException;
use Nubit\Platform\Tenant\Context\TenantContext;
use Nubit\Platform\Time\TimeZoneResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Issues documents, and refuses to issue the same one twice.
 *
 * The rule the whole module exists for: an issued document is a record. Asking
 * for an invoice that has already been issued returns the stored bytes, not a
 * fresh render. Otherwise a template edit, a changed logo or a corrected
 * address would rewrite documents that are already in someone else's hands,
 * and the archive would quietly stop matching what was sent.
 *
 * A correction goes through {@see reissue()}, which emits a *new* document
 * pointing back at the one it replaces. Both survive.
 */
final readonly class DocumentIssuer
{
    /**
     * Newest first, with the identifier breaking ties.
     *
     * `datetime_immutable` maps to `TIMESTAMP(0)`, so two documents issued in
     * the same second are indistinguishable by time alone — which happens
     * routinely on a scripted correction or a bulk reissue, and left the
     * history in an order the database was free to choose. The identifiers are
     * time-ordered UUIDs (v7), so they resolve the tie in issue order; even if
     * an application swaps in a random generator the result stays stable
     * instead of arbitrary.
     */
    private const array NEWEST_FIRST = ['issuedAt' => 'DESC', 'id' => 'DESC'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PrintableRegistry $registry,
        private DocumentStorage $storage,
        private DocumentRendererInterface $renderer,
        private ContainerInterface $templates,
        private TimeZoneResolver $timeZoneResolver,
        private bool $async = false,
        private ?MessageBusInterface $messageBus = null,
        private ?TenantContext $tenantContext = null,
        private LoggerInterface $logger = new NullLogger(),
        private string $defaultLocale = 'en',
    ) {}

    /**
     * Returns the document for this resource, issuing it if it does not exist.
     *
     * @param array<string, mixed> $options passed through to the template
     */
    public function issue(object $resource, ?string $issuedBy = null, array $options = []): IssuedDocument
    {
        $existing = $this->current($resource);
        if (null !== $existing && IssuedDocument::STATUS_FAILED !== $existing->getStatus()) {
            return $existing;
        }

        return $this->create($resource, $issuedBy, $options, supersedes: null);
    }

    /**
     * Emits a replacement copy, leaving the original in place and marked.
     *
     * The reason is recorded on the caller's side (audit trail); what matters
     * here is that the previous copy remains readable, because a customer
     * holding it must still be able to have it explained.
     *
     * @param array<string, mixed> $options
     */
    public function reissue(object $resource, ?string $issuedBy = null, array $options = []): IssuedDocument
    {
        $printable = $this->registry->get($resource);

        if (!$printable->allowReissue) {
            throw new ServiceException(sprintf(
                'Documents for "%s" cannot be reissued; the resource declares allowReissue: false.',
                $resource::class,
            ));
        }

        $previous = $this->current($resource);
        $document = $this->create($resource, $issuedBy, $options, supersedes: $previous?->getId());

        if (null !== $previous) {
            $previous->markSupersededBy((string) $document->getId());
            $this->entityManager->flush();
        }

        return $document;
    }

    /** The copy currently in force: the most recent one nothing supersedes. */
    public function current(object $resource): ?IssuedDocument
    {
        $document = $this->entityManager->getRepository(IssuedDocument::class)->findOneBy([
            'resourceClass' => $this->resourceClass($resource),
            'resourceId' => $this->resourceId($resource),
            'supersededById' => null,
        ], self::NEWEST_FIRST);

        return $document instanceof IssuedDocument ? $document : null;
    }

    /** @return list<IssuedDocument> */
    public function history(object $resource): array
    {
        /** @var list<IssuedDocument> $documents */
        $documents = $this->entityManager->getRepository(IssuedDocument::class)->findBy([
            'resourceClass' => $this->resourceClass($resource),
            'resourceId' => $this->resourceId($resource),
        ], self::NEWEST_FIRST);

        return $documents;
    }

    /**
     * Renders a pending document and stores the result.
     *
     * Called inline when rendering is synchronous, and from the message handler
     * when it is not. A document that is already ready is left alone — a
     * retried message must not replace bytes that were handed out.
     */
    public function render(IssuedDocument $document): IssuedDocument
    {
        if ($document->isReady()) {
            return $document;
        }

        /** @var class-string $resourceClass Written by create(), which reads it from Doctrine's metadata. */
        $resourceClass = $document->getResourceClass();

        $resource = $this->entityManager->find($resourceClass, $document->getResourceId());
        if (null === $resource) {
            $document->markFailed('The resource this document belongs to no longer exists.');
            $this->entityManager->flush();

            return $document;
        }

        $printable = $this->registry->get($resource);

        try {
            $html = $this->template($printable)->render(
                $resource,
                new DocumentRenderContext(
                    documentNumber: $document->getDocumentNumber(),
                    issuedAt: $document->getIssuedAt(),
                    locale: $this->defaultLocale,
                    timeZone: $this->timeZoneResolver->resolveIdentifier(),
                    issuedBy: $document->getIssuedBy(),
                    tenantId: $this->tenantContext?->getTenantId(),
                ),
            );

            $bytes = $this->renderer->render($html, [
                'format' => $printable->paper,
                'orientation' => $printable->orientation,
            ]);

            $path = $this->storage->pathFor((string) $document->getId(), $document->getIssuedAt());
            $this->storage->write($path, $bytes);

            $document->setMediaType($this->renderer->mediaType());
            $document->markReady($path, hash('sha256', $bytes), strlen($bytes));
        } catch (\Throwable $exception) {
            // The row stays, carrying the reason. A failed issue that vanished
            // would leave the user pressing a button that appears to do nothing.
            $this->logger->error('Rendering an issued document failed.', [
                'document' => $document->getId(),
                'resource' => $document->getResourceClass(),
                'exception' => $exception->getMessage(),
            ]);
            $document->markFailed($exception->getMessage());
        }

        $this->entityManager->flush();

        return $document;
    }

    public function bytes(IssuedDocument $document): string
    {
        $path = $document->getStoragePath();

        if (!$document->isReady() || null === $path) {
            throw new NotFoundException(sprintf(
                'Document "%s" is not ready (status: %s).',
                (string) $document->getId(),
                $document->getStatus(),
            ));
        }

        return $this->storage->read($path);
    }

    /** @param array<string, mixed> $options */
    private function create(object $resource, ?string $issuedBy, array $options, ?string $supersedes): IssuedDocument
    {
        $printable = $this->registry->get($resource);

        $document = new IssuedDocument();
        $document
            ->setResourceClass($this->resourceClass($resource))
            ->setResourceId($this->resourceId($resource))
            ->setDocumentNumber($this->documentNumber($resource, $printable))
            ->setTemplate($printable->template)
            ->setIssuedBy($issuedBy)
            ->setSupersedesId($supersedes);

        $this->entityManager->persist($document);
        // Flushed before rendering so the row — and its identifier, which the
        // storage path is built from — exists even if rendering fails or is
        // handed to a worker.
        $this->entityManager->flush();

        if ($this->async && null !== $this->messageBus) {
            $this->messageBus->dispatch(new RenderDocument((string) $document->getId(), $options));

            return $document;
        }

        return $this->render($document);
    }

    private function template(Printable $printable): DocumentTemplateInterface
    {
        if (!$this->templates->has($printable->template)) {
            throw new ServiceException(sprintf(
                'Document template "%s" is not registered. Templates are autoconfigured through '
                . 'DocumentTemplateInterface; check the class implements it.',
                $printable->template,
            ));
        }

        $template = $this->templates->get($printable->template);
        if (!$template instanceof DocumentTemplateInterface) {
            throw new ServiceException(sprintf(
                'Document template "%s" must implement DocumentTemplateInterface.',
                $printable->template,
            ));
        }

        return $template;
    }

    private function documentNumber(object $resource, Printable $printable): ?string
    {
        if (null === $printable->numberProperty) {
            return null;
        }

        $getter = 'get' . ucfirst($printable->numberProperty);
        /** @var mixed $value */
        $value = match (true) {
            method_exists($resource, $getter) => $resource->{$getter}(),
            property_exists($resource, $printable->numberProperty) => $resource->{$printable->numberProperty},
            default => null,
        };

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return class-string */
    private function resourceClass(object $resource): string
    {
        return $this->entityManager->getClassMetadata($resource::class)->getName();
    }

    private function resourceId(object $resource): string
    {
        $identifiers = $this->entityManager->getClassMetadata($resource::class)->getIdentifierValues($resource);

        if ([] === $identifiers) {
            throw new ServiceException('A document can only be issued for a persisted resource.');
        }

        return implode('-', array_map(strval(...), $identifiers));
    }
}
