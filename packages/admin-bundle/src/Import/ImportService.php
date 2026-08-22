<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Nubit\AdminBundle\Import\Entity\ImportSession;
use Nubit\AdminBundle\Import\Exception\ImportException;
use Nubit\AdminBundle\Import\Reader\RowReaderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The use cases behind the import endpoints.
 *
 * Kept out of the controllers so the same flow is available to a console
 * command or a queue worker — a twenty-thousand-row file is not something an
 * HTTP request should always be asked to carry.
 */
final readonly class ImportService
{
    /** @param iterable<RowReaderInterface> $readers */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportableRegistry $registry,
        private ColumnMapper $mapper,
        private ImportRunner $runner,
        private ImportFileStorage $storage,
        private iterable $readers,
    ) {}

    /**
     * Stores the file, proposes a mapping and dry-runs it.
     *
     * The analysis happens on upload rather than on request: an import the user
     * has to remember to validate is an import that gets applied unvalidated.
     *
     * @param array<string, int|string> $mappingOverride
     */
    public function start(
        string $segment,
        UploadedFile $file,
        ?string $createdBy = null,
        array $mappingOverride = [],
        string $numberFormat = 'auto',
    ): ImportSession {
        $resourceClass = $this->registry->resolveClass($segment);
        $importable = $this->registry->get($resourceClass);

        $path = $this->storage->store($file);

        $session = new ImportSession();
        $session
            ->setResourceClass($resourceClass)
            ->setFilename($file->getClientOriginalName())
            ->setStoragePath($path)
            ->setCreatedBy($createdBy)
            ->setNumberFormat($numberFormat);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $source = $this->source($session);
        $headers = $source->headers();

        $mapping = [] === $mappingOverride
            ? $this->mapper->propose($headers, $importable)['mapping']
            : $this->mapper->sanitize($mappingOverride, $headers, $importable);

        $session->setMapping($mapping);

        $report = $this->runner->analyze($session, $source);
        $report['headers'] = $headers;
        $report['mapping'] = $mapping;
        $report['unmapped'] = $this->mapper->propose($headers, $importable)['unmapped'];

        $session->setReport($report)->setStatus(ImportSession::STATUS_ANALYZED);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Re-analyses an existing session with a corrected mapping.
     *
     * @param array<string, int|string> $mapping
     */
    public function remap(ImportSession $session, array $mapping, ?string $numberFormat = null): ImportSession
    {
        $this->assertNotApplied($session);

        $importable = $this->registry->get($session->getResourceClass());
        $source = $this->source($session);

        $session->setMapping($this->mapper->sanitize($mapping, $source->headers(), $importable));
        if (null !== $numberFormat) {
            $session->setNumberFormat($numberFormat);
        }

        $report = $this->runner->analyze($session, $source);
        $report['headers'] = $source->headers();
        $report['mapping'] = $session->getMapping();

        $session->setReport($report)->setStatus(ImportSession::STATUS_ANALYZED);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Applies an analysed session.
     *
     * A session that was never analysed cannot be applied. That is the point of
     * the module: the user has seen what the file does before it does it.
     */
    public function confirm(ImportSession $session): ImportSession
    {
        $this->assertNotApplied($session);

        if (ImportSession::STATUS_ANALYZED !== $session->getStatus()) {
            throw new ImportException('This import has not been analysed yet; nothing may be applied blind.');
        }

        $report = $this->runner->apply($session, $this->source($session));
        $session->setReport([...$session->getReport(), ...$report]);
        $this->entityManager->flush();

        return $session;
    }

    private function assertNotApplied(ImportSession $session): void
    {
        if ($session->isApplied()) {
            throw new ImportException('This import has already been applied.');
        }
    }

    private function source(ImportSession $session): RowSource
    {
        $path = $this->storage->absolutePath($session->getStoragePath());

        foreach ($this->readers as $reader) {
            if ($reader->supports($session->getFilename(), $this->storage->mediaType($path))) {
                return new RowSource($reader, $path);
            }
        }

        throw new ImportException(sprintf(
            'No reader handles "%s". Upload a CSV or an XLSX file.',
            $session->getFilename(),
        ));
    }
}
