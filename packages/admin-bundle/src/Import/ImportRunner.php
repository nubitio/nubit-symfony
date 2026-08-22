<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nubit\AdminBundle\Import\Entity\ImportSession;
use Nubit\AdminBundle\Import\Exception\ImportException;
use Nubit\ApiPlatform\Attribute\Importable;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads a file into entities — twice.
 *
 * The first pass writes nothing and produces a report: which rows would insert,
 * which would update, and exactly what is wrong with the ones that would fail.
 * The second pass applies it. Splitting them is the whole design: the person
 * uploading a customer's twenty-thousand-row product list needs to see what it
 * will do *before* it does it, and "undo the import" is not a thing that exists
 * once foreign keys have been written.
 *
 * Both passes share this class, so what the report promised is what applying
 * performs, rather than two implementations that drift.
 */
final readonly class ImportRunner
{
    /** Enough to fix a file from; the full count is reported separately. */
    private const int MAX_REPORTED_ERRORS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportableRegistry $registry,
        private ValueCoercer $coercer,
        private ?ValidatorInterface $validator = null,
    ) {}

    /**
     * Dry run. Reads every row, resolves it, and reports — without writing.
     *
     * @return array<string, mixed>
     */
    public function analyze(ImportSession $session, RowSource $source): array
    {
        return $this->run($session, $source, apply: false);
    }

    /**
     * Applies a previously analysed session.
     *
     * Refuses a file with invalid rows: a partial import is the worst outcome
     * available, because the file can no longer be re-uploaded as a whole and
     * nobody knows which half landed.
     *
     * @return array<string, mixed>
     */
    public function apply(ImportSession $session, RowSource $source): array
    {
        if ($session->isApplied()) {
            throw new ImportException('This import has already been applied.');
        }

        $report = $this->run($session, $source, apply: true);

        $session->markApplied();
        $this->entityManager->flush();

        return $report;
    }

    /** @return array<string, mixed> */
    private function run(ImportSession $session, RowSource $source, bool $apply): array
    {
        $importable = $this->registry->get($session->getResourceClass());
        $metadata = $this->entityManager->getClassMetadata($session->getResourceClass());
        $coercer = $this->coercer->withNumberFormat(
            NumberFormat::tryFrom($session->getNumberFormat()) ?? NumberFormat::Auto,
        );

        $mapping = $session->getMapping();
        if ([] === $mapping) {
            throw new ImportException('The import has no column mapping yet.');
        }

        $counts = ['rows' => 0, 'inserts' => 0, 'updates' => 0, 'invalid' => 0];
        $errors = [];
        $pending = 0;

        // Applying runs inside one transaction: a file is a unit of work. Half
        // of a product catalogue is not a useful state for anyone.
        if ($apply) {
            $this->entityManager->beginTransaction();
        }

        try {
            foreach ($source->rows() as $line => $values) {
                ++$counts['rows'];

                if ($counts['rows'] > $importable->maxRows) {
                    throw new ImportException(sprintf(
                        'The file has more than %d rows, which is the limit this resource accepts.',
                        $importable->maxRows,
                    ));
                }

                $rowErrors = [];
                $attributes = $this->readRow($metadata, $mapping, $values, $importable, $coercer, $line, $rowErrors);

                if ([] !== $rowErrors) {
                    ++$counts['invalid'];
                    $errors = $this->collect($errors, $rowErrors);
                    continue;
                }

                $existing = $this->findExisting($metadata->getName(), $importable, $attributes);

                // Instantiated through the mapping's own reflection: the entity
                // constructor still runs (embeddables rely on it), and the class
                // comes from Doctrine rather than from a string in a database row.
                $entity = $existing ?? $metadata->getReflectionClass()->newInstance();

                $this->hydrate($entity, $attributes);

                $violations = $this->validate($entity, $line);
                if ([] !== $violations) {
                    ++$counts['invalid'];
                    $errors = $this->collect($errors, $violations);

                    // The entity was mutated in place; an existing row must not
                    // keep the rejected values when the unit of work flushes.
                    if (null !== $existing) {
                        $this->entityManager->refresh($existing);
                    }
                    continue;
                }

                null === $existing ? ++$counts['inserts'] : ++$counts['updates'];

                if (!$apply) {
                    // Nothing may reach the database on a dry run, including a
                    // new entity Doctrine would cascade on some later flush.
                    $this->entityManager->detach($entity);
                    continue;
                }

                if (null === $existing) {
                    $this->entityManager->persist($entity);
                }

                if (++$pending >= $importable->batchSize) {
                    $this->entityManager->flush();
                    $pending = 0;
                }
            }

            if ($apply) {
                if ($counts['invalid'] > 0) {
                    throw new ImportException(sprintf(
                        '%d row(s) are still invalid; fix the file and upload it again.',
                        $counts['invalid'],
                    ));
                }

                $this->entityManager->flush();
                $this->entityManager->commit();
            }
        } catch (\Throwable $exception) {
            if ($apply && $this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $exception;
        }

        return [
            'rows' => $counts['rows'],
            'valid' => $counts['rows'] - $counts['invalid'],
            'invalid' => $counts['invalid'],
            'inserts' => $counts['inserts'],
            'updates' => $counts['updates'],
            'errorCount' => count($errors),
            'errors' => array_slice($errors, 0, self::MAX_REPORTED_ERRORS),
            'truncatedErrors' => count($errors) > self::MAX_REPORTED_ERRORS,
            'applied' => $apply,
        ];
    }

    /**
     * @param ClassMetadata<object>          $metadata
     * @param array<string, int>             $mapping
     * @param list<string>                   $values
     * @param list<array<string, mixed>>     $rowErrors
     *
     * @return array<string, mixed>
     */
    private function readRow(
        ClassMetadata $metadata,
        array $mapping,
        array $values,
        Importable $importable,
        ValueCoercer $coercer,
        int $line,
        array &$rowErrors,
    ): array {
        $attributes = [];

        foreach ($mapping as $field => $column) {
            $raw = $values[$column] ?? '';

            try {
                $attributes[$field] = $coercer->coerce($metadata, $field, $raw, $this->propertyType($metadata, $field));
            } catch (\Throwable $exception) {
                $rowErrors[] = ['line' => $line, 'field' => $field, 'message' => $exception->getMessage()];
            }
        }

        foreach ($importable->required as $field) {
            if (null === ($attributes[$field] ?? null)) {
                $rowErrors[] = ['line' => $line, 'field' => $field, 'message' => 'This value is required.'];
            }
        }

        return $attributes;
    }

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $attributes
     */
    private function findExisting(string $resourceClass, Importable $importable, array $attributes): ?object
    {
        if ([] === $importable->naturalKey) {
            return null;
        }

        $criteria = [];
        foreach ($importable->naturalKey as $field) {
            $value = $attributes[$field] ?? null;
            if (null === $value) {
                return null;
            }
            $criteria[$field] = $value;
        }

        $existing = $this->entityManager->getRepository($resourceClass)->findOneBy($criteria);

        return is_object($existing) ? $existing : null;
    }

    /** @param array<string, mixed> $attributes */
    private function hydrate(object $entity, array $attributes): void
    {
        foreach ($attributes as $field => $value) {
            $setter = 'set' . ucfirst($field);

            if (method_exists($entity, $setter)) {
                $entity->{$setter}($value);
                continue;
            }

            $reflection = new \ReflectionObject($entity);
            if ($reflection->hasProperty($field)) {
                $property = $reflection->getProperty($field);
                $property->setValue($entity, $value);
                continue;
            }

            throw new ImportException(sprintf(
                'Field "%s" is declared importable but the entity exposes no way to write it.',
                $field,
            ));
        }
    }

    /** @return list<array<string, mixed>> */
    private function validate(object $entity, int $line): array
    {
        if (null === $this->validator) {
            return [];
        }

        $errors = [];
        foreach ($this->validator->validate($entity) as $violation) {
            $errors[] = [
                'line' => $line,
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $errors;
    }

    /** @param ClassMetadata<object> $metadata */
    private function propertyType(ClassMetadata $metadata, string $field): ?\ReflectionNamedType
    {
        $reflection = $metadata->getReflectionClass();

        if ($reflection->hasProperty($field)) {
            $type = $reflection->getProperty($field)->getType();
            if ($type instanceof \ReflectionNamedType) {
                return $type;
            }
        }

        // Money and other value objects live behind an accessor, with the
        // columns kept private under a different name.
        $getter = 'get' . ucfirst($field);
        if ($reflection->hasMethod($getter)) {
            $type = $reflection->getMethod($getter)->getReturnType();
            if ($type instanceof \ReflectionNamedType) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @param list<array<string, mixed>> $rowErrors
     *
     * @return list<array<string, mixed>>
     */
    private function collect(array $errors, array $rowErrors): array
    {
        foreach ($rowErrors as $error) {
            $errors[] = $error;
        }

        return $errors;
    }
}
