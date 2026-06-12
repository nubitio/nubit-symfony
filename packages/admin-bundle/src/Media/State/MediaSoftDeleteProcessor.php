<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Media\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use Nubit\AdminBundle\Media\Entity\Media;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DELETE /api/media/{id} soft-deletes: the row gets `deleted_at` and vanishes
 * from HTTP reads (SoftDeletable filter), the file stays in storage until
 * `nubit:media:purge` runs. Keeps accidental deletes recoverable and avoids
 * breaking parents that still reference the IRI.
 *
 * @implements ProcessorInterface<Media, null>
 */
final readonly class MediaSoftDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
    ) {
    }

    /**
     * @param Media $data
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data->getDeletedAt() === null) {
            $data->setDeletedAt(new DateTimeImmutable());
        }

        $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        return null;
    }
}
