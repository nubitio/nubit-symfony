<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\OpenApi;

use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Decorates the API Platform Hydra documentation normalizer so that:
 *
 *  1. ApiProperty description strings that look like translation keys
 *     (e.g. "category.name.label") are resolved to their translated text
 *     and written into hydra:title — the field the frontend reads for
 *     human-readable property labels.
 *
 *  2. ApiProperty openapiContext entries that contain an "x-crud" key are
 *     forwarded into each hydra:supportedProperty entry so the frontend can
 *     read CRUD UI hints (filterable, sortable, hidden, order, width) from
 *     the single /api/docs.jsonld endpoint without a second fetch.
 *
 *  3. ApiProperty openapiContext "enum" lists are forwarded the same way so
 *     the frontend can render a select control instead of a free-text input.
 *     (Hydra docs don't carry OpenAPI enums natively.)
 *
 * Register in services.yaml:
 *
 *   Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer:
 *       decorates: api_platform.hydra.normalizer.documentation
 *       arguments:
 *           $inner: '@.inner'
 *           $translator: '@translator'
 *           $requestStack: '@request_stack'
 *           $apiLocale: '%env(APP_API_LOCALE)%'
 *
 * The remaining constructor arguments are autowired.
 */
final class TranslatedDocumentationNormalizer implements NormalizerInterface
{
    /**
     * Lazily-built map of short class name (e.g. "Category") → FQCN
     * (e.g. "App\Catalog\Entity\Category").  Populated on first use.
     *
     * @var array<string, string>|null
     */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
        private readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
        private readonly string $apiLocale = 'auto',
    ) {
    }

    /** @return array<mixed> */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        /** @var array<mixed> $doc */
        $doc = $this->inner->normalize($object, $format, $context);

        $locale = $this->resolveLocale();

        // Handle both prefixed ("hydra:") and unprefixed ("") key styles.
        foreach (['hydra:', ''] as $prefix) {
            $classesKey = $prefix . 'supportedClass';
            if (!isset($doc[$classesKey]) || !\is_array($doc[$classesKey])) {
                continue;
            }

            foreach ($doc[$classesKey] as &$class) {
                if (!\is_array($class)) {
                    continue;
                }

                // Resolve the FQCN for this Hydra class entry so we can call
                // PropertyMetadataFactoryInterface::create($fqcn, $propertyName).
                // The "@id" is "#ShortName", e.g. "#Category".
                $classId = $class['@id'] ?? null;
                $fqcn = \is_string($classId)
                    ? ($this->resolveShortNameToClass(\ltrim($classId, '#')) ?? null)
                    : null;

                $propertiesKey = $prefix . 'supportedProperty';
                if (!isset($class[$propertiesKey]) || !\is_array($class[$propertiesKey])) {
                    continue;
                }

                foreach ($class[$propertiesKey] as &$supportedProperty) {
                    if (!\is_array($supportedProperty)) {
                        continue;
                    }

                    $descriptionKey = $prefix . 'description';
                    $titleKey = $prefix . 'title';

                    // ── 1. Translate description key → hydra:title ──────────────
                    if (
                        isset($supportedProperty[$descriptionKey])
                        && \is_string($supportedProperty[$descriptionKey])
                        && $this->looksLikeTranslationKey($supportedProperty[$descriptionKey])
                    ) {
                        $translated = $this->translator->trans(
                            $supportedProperty[$descriptionKey],
                            [],
                            'api',
                            $locale,
                        );

                        // Only overwrite hydra:title if the key was actually translated
                        // (trans() returns the key itself when no translation is found).
                        if ($translated !== $supportedProperty[$descriptionKey]) {
                            $supportedProperty[$titleKey] = $translated;
                        }
                    }

                    // ── 2. Forward x-crud hints from openapiContext ─────────────
                    if ($fqcn !== null) {
                        $propertyName = $this->extractPropertyName($supportedProperty, $prefix);
                        if ($propertyName !== null) {
                            $this->injectXCrud($fqcn, $propertyName, $supportedProperty);
                        }
                    }
                }
                unset($supportedProperty);
            }
            unset($class);
        }

        return $doc;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    /** @return array<class-string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /**
     * A string looks like a translation key when it contains at least one dot
     * and has no whitespace — e.g. "category.name.label".
     *
     * Human-readable strings like "The collection of Foo resources" will never
     * match this heuristic, so they are left untouched.
     */
    private function looksLikeTranslationKey(string $value): bool
    {
        return str_contains($value, '.') && !str_contains($value, ' ');
    }

    /**
     * Resolve the locale to use for translation.
     *
     * Priority:
     *   1. APP_API_LOCALE env var (when set to a specific locale, e.g. "es", "en")
     *   2. Accept-Language header from the current request
     *   3. Symfony's default translator locale (fallback for CLI / cache warm-up)
     */
    private function resolveLocale(): string
    {
        if ($this->apiLocale !== 'auto') {
            return $this->apiLocale;
        }
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            return $request->getPreferredLanguage(['es', 'en']) ?? $request->getLocale();
        }

        return $this->translator->getLocale();
    }

    /**
     * Extract the property name from a hydra:supportedProperty entry.
     *
     * API Platform puts the raw property name in:
     *   - $supportedProperty[$prefix.'property']['label']   (preferred)
     *   - $supportedProperty[$prefix.'property']['@id']     (fallback: "#ShortName/propName")
     *
     * @param array<mixed> $supportedProperty
     */
    private function extractPropertyName(array $supportedProperty, string $prefix): ?string
    {
        $propKey = $prefix . 'property';
        if (!isset($supportedProperty[$propKey]) || !\is_array($supportedProperty[$propKey])) {
            return null;
        }

        $propData = $supportedProperty[$propKey];

        // Preferred: the "label" key contains exactly the property name.
        if (isset($propData['label']) && \is_string($propData['label'])) {
            return $propData['label'];
        }

        // Fallback: parse "#ShortName/propertyName" from the @id.
        if (isset($propData['@id']) && \is_string($propData['@id'])) {
            $slashPos = \strpos($propData['@id'], '/');
            if ($slashPos !== false) {
                return \substr($propData['@id'], $slashPos + 1);
            }
        }

        return null;
    }

    /**
     * Forward UI-relevant openapiContext keys ("x-crud" hints and "enum"
     * value lists) as top-level keys on the hydra:supportedProperty entry.
     *
     * @param array<mixed> $supportedProperty
     */
    private function injectXCrud(string $fqcn, string $propertyName, array &$supportedProperty): void
    {
        try {
            $metadata = $this->propertyMetadataFactory->create($fqcn, $propertyName);
        } catch (\Throwable) {
            // Property not found or metadata unavailable — skip silently.
            return;
        }

        $openapiContext = $metadata->getOpenapiContext();
        if (!\is_array($openapiContext)) {
            return;
        }

        if (isset($openapiContext['x-crud'])) {
            $supportedProperty['x-crud'] = $openapiContext['x-crud'];
        }

        if (isset($openapiContext['enum']) && \is_array($openapiContext['enum'])) {
            $supportedProperty['enum'] = \array_values($openapiContext['enum']);
        }
    }

    /**
     * Resolves a Hydra short class name (e.g. "Category") to its FQCN
     * (e.g. "App\Catalog\Entity\Category") by lazily building a map from all
     * registered API Platform resources.
     *
     * Returns null when no resource with the given short name is registered.
     */
    private function resolveShortNameToClass(string $shortName): ?string
    {
        if ($this->shortNameToClass === null) {
            $this->shortNameToClass = [];
            foreach ($this->resourceNameCollectionFactory->create() as $resourceClass) {
                $metadataCollection = $this->resourceMetadataCollectionFactory->create($resourceClass);
                foreach ($metadataCollection as $metadata) {
                    $name = $metadata->getShortName();
                    if (\is_string($name) && $name !== '') {
                        $this->shortNameToClass[$name] = $resourceClass;
                        break; // first operation in the collection carries the short name
                    }
                }
            }
        }

        return $this->shortNameToClass[$shortName] ?? null;
    }
}
