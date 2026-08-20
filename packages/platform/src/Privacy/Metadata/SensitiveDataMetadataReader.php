<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Metadata;

use Nubit\Platform\Privacy\Attribute\SensitiveData;
use Nubit\Platform\Privacy\SensitiveDataMetadata;
use ReflectionClass;
use ReflectionProperty;

final class SensitiveDataMetadataReader
{
    /** @var array<class-string, ClassPrivacyMetadata> */
    private array $cache = [];

    /** @param class-string|object $class */
    public function forClass(string|object $class): ClassPrivacyMetadata
    {
        $className = is_object($class) ? $class::class : $class;

        return $this->cache[$className] ??= $this->read($className);
    }

    public function clear(): void
    {
        $this->cache = [];
    }

    /** @param class-string $className */
    private function read(string $className): ClassPrivacyMetadata
    {
        $reflection = new ReflectionClass($className);
        $classDefault = $this->attributeMetadata($reflection->getAttributes(SensitiveData::class)[0] ?? null);
        $properties = [];
        $propertyMetadata = [];

        foreach ($this->properties($reflection) as $property) {
            $name = $property->getName();
            $properties[$name] = $property;
            $metadata = $this->attributeMetadata($property->getAttributes(SensitiveData::class)[0] ?? null);
            if (null !== $metadata) {
                $propertyMetadata[$name] = $metadata;
            }
        }

        return new ClassPrivacyMetadata($properties, $propertyMetadata, $classDefault);
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function properties(ReflectionClass $reflection): array
    {
        $properties = [];
        do {
            foreach ($reflection->getProperties() as $property) {
                $properties[$property->getName()] ??= $property;
            }
            $reflection = $reflection->getParentClass();
        } while (false !== $reflection);

        return array_values($properties);
    }

    /** @param \ReflectionAttribute<SensitiveData>|null $attribute */
    private function attributeMetadata(?\ReflectionAttribute $attribute): ?SensitiveDataMetadata
    {
        if (null === $attribute) {
            return null;
        }

        $value = $attribute->newInstance();

        return new SensitiveDataMetadata($value->classification, $value->strategy, $value->purposes);
    }
}
