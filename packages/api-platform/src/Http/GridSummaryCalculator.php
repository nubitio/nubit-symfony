<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Http;

use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Computes aggregate totals for grid columns marked with
 * {@code openapiContext: ['x-crud' => ['summable' => true]]}.
 *
 * Runs on the same filtered entity manager as the collection query (tenant
 * filters and soft-delete apply automatically).
 */
final readonly class GridSummaryCalculator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory,
        private ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
    ) {
    }

    /**
     * @return array<string, string|int|float>
     */
    public function compute(string $resourceClass, Request $request): array
    {
        if (!$this->isSummaryEnabled($resourceClass)) {
            return [];
        }

        $fields = $this->resolveSummableFields($resourceClass);
        if ($fields === []) {
            return [];
        }

        $alias = 'e';
        $selects = [];
        foreach ($fields as $property => $summaryType) {
            $dqlField = $alias.'.'.$property;
            $selects[] = match ($summaryType) {
                'count' => sprintf('COUNT(%s) AS %s_summary', $dqlField, $property),
                'avg' => sprintf('AVG(%s) AS %s_summary', $dqlField, $property),
                'min' => sprintf('MIN(%s) AS %s_summary', $dqlField, $property),
                'max' => sprintf('MAX(%s) AS %s_summary', $dqlField, $property),
                default => sprintf('SUM(%s) AS %s_summary', $dqlField, $property),
            };
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select(implode(', ', $selects))
            ->from($resourceClass, $alias);

        /** @var array<string, mixed> $row */
        $row = $qb->getQuery()->getSingleResult();

        $summary = [];
        foreach ($fields as $property => $_) {
            $value = $row[$property.'_summary'] ?? null;
            if ($value === null) {
                continue;
            }
            $summary[$property] = \is_string($value) ? $value : (is_numeric($value) ? (string) $value : $value);
        }

        return $summary;
    }

    private function isSummaryEnabled(string $resourceClass): bool
    {
        foreach ($this->resourceMetadataCollectionFactory->create($resourceClass) as $metadata) {
            $extra = $metadata->getExtraProperties()['x-crud'] ?? null;
            if (\is_array($extra) && ($extra['summary'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function resolveSummableFields(string $resourceClass): array
    {
        $fields = [];
        foreach ($this->propertyNameCollectionFactory->create($resourceClass) as $propertyName) {
            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);
            } catch (\Throwable) {
                continue;
            }

            $openapiContext = $propertyMetadata->getOpenapiContext();
            if (!\is_array($openapiContext)) {
                continue;
            }

            $crud = $openapiContext['x-crud'] ?? null;
            if (!\is_array($crud) || !($crud['summable'] ?? false)) {
                continue;
            }

            $summaryType = $crud['summaryType'] ?? 'sum';
            $fields[$propertyName] = \is_string($summaryType) ? $summaryType : 'sum';
        }

        return $fields;
    }
}