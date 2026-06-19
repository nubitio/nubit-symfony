<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\EmbeddedLines;

use ApiPlatform\Metadata\IriConverterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class EmbeddedLinesRowSerializer
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private SerializerInterface $serializer,
        private IriConverterInterface $iriConverter,
    ) {
    }

    /**
     * @param list<object> $entities
     *
     * @return list<array<string, mixed>>
     */
    public function serializeRows(array $entities, EmbeddedLinesDefinition $definition): array
    {
        if ($entities === []) {
            return [];
        }

        $manager = $this->managerRegistry->getManagerForClass($definition->entityClass);
        if (!$manager instanceof EntityManagerInterface) {
            return [];
        }

        $metadata = $manager->getClassMetadata($definition->entityClass);
        $context = [];
        if ($definition->normalizationGroups !== []) {
            $context[AbstractNormalizer::GROUPS] = $definition->normalizationGroups;
        }

        $rows = [];
        foreach ($entities as $entity) {
            /** @var array<string, mixed> $row */
            $row = json_decode(
                $this->serializer->serialize($entity, 'json', $context),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($metadata->getAssociationNames() as $associationName) {
                if ($associationName === $definition->parentProperty) {
                    unset($row[$associationName]);

                    continue;
                }

                $related = $metadata->getFieldValue($entity, $associationName);
                if (!\is_object($related)) {
                    continue;
                }

                try {
                    $row[$associationName] = $this->iriConverter->getIriFromResource($related);
                } catch (\Throwable) {
                    // Association is not an ApiResource — keep the serialized shape.
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }
}