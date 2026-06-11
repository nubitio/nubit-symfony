<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Tests\OpenApi;

use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslatedDocumentationNormalizerTest extends TestCase
{
    /** @param array<mixed> $innerDoc */
    private function makeNormalizer(
        array $innerDoc = [],
        string $apiLocale = 'es',
        ?callable $translateFn = null,
    ): TranslatedDocumentationNormalizer {
        $inner = $this->createStub(NormalizerInterface::class);
        $inner->method('normalize')->willReturn($innerDoc);
        $inner->method('supportsNormalization')->willReturn(true);
        $inner->method('getSupportedTypes')->willReturn([]);

        $translator = $this->createStub(TranslatorInterface::class);
        if ($translateFn !== null) {
            $translator->method('trans')->willReturnCallback($translateFn);
        } else {
            // Default: return the key unchanged (no translation)
            $translator->method('trans')->willReturnArgument(0);
        }
        $translator->method('getLocale')->willReturn('es');

        $requestStack = new RequestStack();

        $propMetaFactory = $this->createStub(PropertyMetadataFactoryInterface::class);
        $nameCollFactory = $this->createStub(ResourceNameCollectionFactoryInterface::class);
        $nameCollFactory->method('create')->willReturn(new ResourceNameCollection([]));
        $metaCollFactory = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);

        return new TranslatedDocumentationNormalizer(
            inner: $inner,
            translator: $translator,
            requestStack: $requestStack,
            propertyMetadataFactory: $propMetaFactory,
            resourceNameCollectionFactory: $nameCollFactory,
            resourceMetadataCollectionFactory: $metaCollFactory,
            apiLocale: $apiLocale,
        );
    }

    // ── supportsNormalization / getSupportedTypes ──────────────────────────────

    public function testSupportsNormalizationDelegatesToInner(): void
    {
        $normalizer = $this->makeNormalizer();
        self::assertTrue($normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypesDelegatesToInner(): void
    {
        $normalizer = $this->makeNormalizer();
        self::assertSame([], $normalizer->getSupportedTypes(null));
    }

    // ── normalize — translation key detection ─────────────────────────────────

    public function testNormalizeTranslatesDescriptionKeysInHydraFormat(): void
    {
        $doc = [
            'hydra:supportedClass' => [
                [
                    '@id' => '#UnknownClass',
                    'hydra:supportedProperty' => [
                        [
                            'hydra:description' => 'product.name.label', // looks like translation key
                            'hydra:property' => ['label' => 'name'],
                        ],
                    ],
                ],
            ],
        ];

        $normalizer = $this->makeNormalizer(
            innerDoc: $doc,
            apiLocale: 'es',
            translateFn: static fn (string $key) => $key === 'product.name.label' ? 'Nombre del producto' : $key,
        );

        $result = $normalizer->normalize(new \stdClass());

        $property = $result['hydra:supportedClass'][0]['hydra:supportedProperty'][0];
        self::assertSame('Nombre del producto', $property['hydra:title']);
    }

    public function testNormalizeDoesNotOverwriteTitleWhenKeyNotTranslated(): void
    {
        // translator returns the key as-is → no translation found → title NOT set
        $doc = [
            'hydra:supportedClass' => [
                [
                    '@id' => '#Foo',
                    'hydra:supportedProperty' => [
                        [
                            'hydra:description' => 'foo.bar.label',
                        ],
                    ],
                ],
            ],
        ];

        $normalizer = $this->makeNormalizer(innerDoc: $doc);
        $result = $normalizer->normalize(new \stdClass());

        $property = $result['hydra:supportedClass'][0]['hydra:supportedProperty'][0];
        self::assertArrayNotHasKey('hydra:title', $property);
    }

    public function testNormalizeIgnoresPlainTextDescriptions(): void
    {
        // "The collection of Foo resources" is NOT a translation key (has spaces)
        $doc = [
            'hydra:supportedClass' => [
                [
                    '@id' => '#Foo',
                    'hydra:supportedProperty' => [
                        [
                            'hydra:description' => 'The collection of resources',
                        ],
                    ],
                ],
            ],
        ];

        $normalizer = $this->makeNormalizer(innerDoc: $doc);
        $result = $normalizer->normalize(new \stdClass());

        $property = $result['hydra:supportedClass'][0]['hydra:supportedProperty'][0];
        self::assertArrayNotHasKey('hydra:title', $property);
    }

    public function testNormalizeWorksWithUnprefixedKeys(): void
    {
        $doc = [
            'supportedClass' => [
                [
                    '@id' => '#Unknown',
                    'supportedProperty' => [
                        [
                            'description' => 'sale.total.label',
                        ],
                    ],
                ],
            ],
        ];

        $normalizer = $this->makeNormalizer(
            innerDoc: $doc,
            translateFn: static fn (string $k) => $k === 'sale.total.label' ? 'Total de venta' : $k,
        );

        $result = $normalizer->normalize(new \stdClass());
        $property = $result['supportedClass'][0]['supportedProperty'][0];
        self::assertSame('Total de venta', $property['title']);
    }

    // ── resolveLocale ─────────────────────────────────────────────────────────

    public function testFixedApiLocaleIsUsedWhenNotAuto(): void
    {
        // When apiLocale='en', normalize must pass 'en' to translator
        $translatedLocale = null;

        $inner = $this->createStub(NormalizerInterface::class);
        $inner->method('normalize')->willReturn([
            'hydra:supportedClass' => [[
                '@id' => '#X',
                'hydra:supportedProperty' => [[
                    'hydra:description' => 'x.label',
                ]],
            ]],
        ]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(function (string $key, array $params, string $domain, string $locale) use (&$translatedLocale): string {
                $translatedLocale = $locale;

                return 'Translated';
            });
        $translator->method('getLocale')->willReturn('es');

        $stack = new RequestStack();
        $nameCollFactory = $this->createStub(ResourceNameCollectionFactoryInterface::class);
        $nameCollFactory->method('create')->willReturn(new ResourceNameCollection([]));

        $normalizer = new TranslatedDocumentationNormalizer(
            inner: $inner,
            translator: $translator,
            requestStack: $stack,
            propertyMetadataFactory: $this->createStub(PropertyMetadataFactoryInterface::class),
            resourceNameCollectionFactory: $nameCollFactory,
            resourceMetadataCollectionFactory: $this->createStub(ResourceMetadataCollectionFactoryInterface::class),
            apiLocale: 'en',
        );

        $normalizer->normalize(new \stdClass());

        self::assertSame('en', $translatedLocale);
    }
}
