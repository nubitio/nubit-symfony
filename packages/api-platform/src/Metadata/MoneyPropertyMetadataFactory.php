<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Metadata;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Nubit\Platform\Money\Money;

/**
 * Publishes money-typed properties as money in the API documentation.
 *
 * The frontend and the agents that generate screens both read the contract, not
 * the PHP source, so a `Money` property that documents itself as a plain object
 * gets rendered as a plain object: three inputs where one currency field
 * belongs. Deriving the hint from the declared type means an entity gets the
 * right widget by using the right type, with nothing to remember and nothing to
 * keep in sync.
 *
 * An explicit `x-crud` on the property still wins — the inference fills a gap,
 * it does not overrule a decision.
 */
final readonly class MoneyPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    public function __construct(
        private PropertyMetadataFactoryInterface $decorated,
    ) {}

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $options
     */
    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->decorated->create($resourceClass, $property, $options);

        if (!$this->isMoney($resourceClass, $property)) {
            return $metadata;
        }

        $openapiContext = $metadata->getOpenapiContext() ?? [];
        $crud = is_array($openapiContext['x-crud'] ?? null) ? $openapiContext['x-crud'] : [];

        if (isset($crud['format'])) {
            return $metadata;
        }

        $crud['format'] = 'money';
        $openapiContext['x-crud'] = $crud;

        // Documenting the wire shape here is what lets a generated client know
        // the amount is a string before it ever sees a response.
        $openapiContext['type'] ??= 'object';
        $openapiContext['properties'] ??= [
            'amount' => ['type' => 'string', 'example' => '19.99'],
            'currency' => ['type' => 'string', 'example' => 'EUR'],
            'scale' => ['type' => 'integer', 'example' => 2],
            'minorAmount' => ['type' => 'integer', 'example' => 1999],
        ];

        return $metadata->withOpenapiContext($openapiContext);
    }

    /** @param class-string $resourceClass */
    private function isMoney(string $resourceClass, string $property): bool
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
        } catch (\ReflectionException) {
            return false;
        }

        return in_array(Money::class, $this->declaredTypes($reflection, $property), true);
    }

    /**
     * Every type the property could be described by.
     *
     * A money field is normally stored as embedded columns and exposed through
     * an accessor, so the declared property type and the getter's return type
     * disagree — and only the accessor speaks of Money. Both are collected so
     * whichever shape an entity uses is recognised.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<string>
     */
    private function declaredTypes(\ReflectionClass $reflection, string $property): array
    {
        $candidates = [];

        if ($reflection->hasProperty($property)) {
            $candidates[] = $reflection->getProperty($property)->getType();
        }

        foreach (['get' . ucfirst($property), $property] as $method) {
            if ($reflection->hasMethod($method)) {
                $candidates[] = $reflection->getMethod($method)->getReturnType();
            }
        }

        $names = [];
        foreach ($candidates as $candidate) {
            if ($candidate instanceof \ReflectionNamedType) {
                $names[] = $candidate->getName();
            }
        }

        return $names;
    }
}
