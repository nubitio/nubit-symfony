<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Metadata;

use Nubit\Platform\Privacy\SensitiveDataMetadata;
use ReflectionProperty;

final readonly class ClassPrivacyMetadata
{
    /**
     * @param array<string, ReflectionProperty> $properties
     * @param array<string, SensitiveDataMetadata> $propertyMetadata
     */
    public function __construct(
        public array $properties,
        public array $propertyMetadata,
        public ?SensitiveDataMetadata $classDefault,
    ) {}
}
