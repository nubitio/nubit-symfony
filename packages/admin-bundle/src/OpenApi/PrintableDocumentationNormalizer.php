<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\OpenApi;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Nubit\AdminBundle\Document\PrintableRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes `x-printable` on every resource that declares `#[Printable]`.
 *
 * The frontend renders the print action from this, exactly as it renders a grid
 * from `x-crud`: the backend states what the resource can do and the client
 * follows, so enabling printing on an entity is one attribute rather than an
 * attribute plus a matching change in a React page. It is also how an agent
 * discovers that a resource is printable without reading PHP.
 */
final class PrintableDocumentationNormalizer implements NormalizerInterface
{
    /** @var array<string, class-string>|null */
    private ?array $shortNameToClass = null;

    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly PrintableRegistry $registry,
        private readonly ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
    ) {}

    /** @return array<mixed> */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        /** @var array<mixed> $doc */
        $doc = $this->inner->normalize($data, $format, $context);

        foreach (['hydra:', ''] as $prefix) {
            $classesKey = $prefix . 'supportedClass';
            if (!isset($doc[$classesKey]) || !is_array($doc[$classesKey])) {
                continue;
            }

            foreach ($doc[$classesKey] as &$class) {
                if (!is_array($class)) {
                    continue;
                }

                $classId = $class['@id'] ?? null;
                if (!is_string($classId)) {
                    continue;
                }

                $fqcn = $this->resolveShortNameToClass(ltrim($classId, '#'));
                $printable = null === $fqcn ? null : $this->registry->find($fqcn);
                if (null === $printable || null === $fqcn) {
                    continue;
                }

                $segment = $this->urlSegment($fqcn);

                $class['x-printable'] = [
                    'title' => $printable->title,
                    'paper' => $printable->paper,
                    'orientation' => $printable->orientation,
                    'allowReissue' => $printable->allowReissue,
                    'numberProperty' => $printable->numberProperty,
                    // Absolute templates rather than a base plus rules: a client
                    // that has to assemble the URL itself will eventually
                    // assemble it differently from the server.
                    'issueUrl' => sprintf('/api/documents/%s/{id}', $segment),
                    'historyUrl' => sprintf('/api/documents/%s/{id}', $segment),
                ];
            }
            unset($class);
        }

        return $doc;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    /** @return array<string, bool|null> */
    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    private function urlSegment(string $fqcn): string
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short)) . 's';
    }

    private function resolveShortNameToClass(string $shortName): ?string
    {
        if (null === $this->shortNameToClass) {
            /** @var array<string, class-string> $map */
            $map = [];
            /** @var class-string $class */
            foreach ($this->resourceNameCollectionFactory->create() as $class) {
                $map[substr($class, strrpos($class, '\\') + 1)] = $class;
            }
            $this->shortNameToClass = $map;
        }

        return $this->shortNameToClass[$shortName] ?? null;
    }
}
