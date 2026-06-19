<?php

declare(strict_types=1);

namespace Nubit\SequenceBundle\Attribute;

use Attribute;

/**
 * Allocates a monotonic formatted value to {@see $field} on first persist when
 * the field is empty. Counters are isolated per {@see $scope} + {@see $name}.
 *
 * Example:
 *
 *   #[Sequence(field: 'number', name: 'order', prefix: 'ORD-', padding: 4, scope: ['restaurant'])]
 *   class Order { ... } // → ORD-0001, ORD-0002 per restaurant
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Sequence
{
    public function __construct(
        public string $field = 'number',
        /** Counter key within the resolved scope. */
        public string $name = 'default',
        public string $prefix = '',
        public int $padding = 0,
        /**
         * Property paths that form the scope key (e.g. ['restaurant'] reads
         * getRestaurant()?->getId()). Empty scope uses the global bucket.
         *
         * @var list<string>
         */
        public array $scope = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toOpenApi(): array
    {
        return [
            'field' => $this->field,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'padding' => $this->padding,
            'scope' => $this->scope,
        ];
    }
}