<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads the bundle's own raw configuration back out of the container during
 * `prependExtension()`, and the one repeated Doctrine/API Platform mapping
 * shape every optional module's `prepend()` needs.
 *
 * `prependExtension()` runs in a per-extension temporary container that only
 * knows `nubit_admin`, so the processed config a module received in
 * `loadExtension()` is not available here — only the raw, unmerged config
 * array `getExtensionConfig()` exposes. Every module's `prepend()` reads its
 * own flag back through here rather than each re-implementing the same
 * config-tree walk.
 */
final class BundleConfig
{
    private function __construct() {}

    /**
     * Whether a module's `enabled` sub-key is on, read straight from the raw
     * config tree by nested key path (e.g. `'notification', 'in_app'`).
     */
    public static function isFeatureEnabled(ContainerBuilder $builder, string ...$path): bool
    {
        $enabled = false;
        foreach ($builder->getExtensionConfig('nubit_admin') as $config) {
            $node = $config;
            foreach ($path as $segment) {
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    continue 2;
                }
                /** @var array<string, mixed> $node */
                $node = $node[$segment];
            }

            if (isset($node['enabled'])) {
                $enabled = (bool) $node['enabled'];
            }
        }

        return $enabled;
    }

    /**
     * Reads a plain boolean leaf out of the raw config — for a flag that is
     * not itself an `enabled` sub-key (so {@see isFeatureEnabled} cannot
     * express it), such as `export.queued` or `time.enforce_utc`.
     *
     * @param list<string> $path
     */
    public static function readBoolean(ContainerBuilder $builder, array $path, bool $default): bool
    {
        $value = $default;

        foreach ($builder->getExtensionConfig('nubit_admin') as $config) {
            $node = $config;
            foreach ($path as $segment) {
                if (!is_array($node) || !array_key_exists($segment, $node)) {
                    continue 2;
                }
                /** @var array<string, mixed>|bool $node */
                $node = $node[$segment];
            }

            if (is_bool($node)) {
                $value = $node;
            }
        }

        return $value;
    }

    /** Maps one bundle-owned entity directory into `doctrine.orm.mappings`. */
    public static function mapEntities(ContainerBuilder $builder, string $name, string $dir, string $prefix): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    $name => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => $dir,
                        'prefix' => $prefix,
                        'alias' => $name,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Adds one bundle-owned entity directory to api_platform.mapping.paths.
     *
     * API Platform skips its project-dir defaults (src/Entity,
     * src/ApiResource, config/api_platform) as soon as mapping.paths is
     * non-empty — this prepend must not displace the app's own entities, so
     * re-add those defaults when the app relied on them.
     */
    public static function addApiResourcePath(ContainerBuilder $builder, string $entityDir): void
    {
        if (!$builder->hasExtension('api_platform')) {
            return;
        }

        $appPaths = [];
        foreach ($builder->getExtensionConfig('api_platform') as $config) {
            $appPaths = array_merge($appPaths, (array) ($config['mapping']['paths'] ?? []));
        }

        $paths = [$entityDir];

        if ($appPaths === []) {
            /** @var string $projectDir */
            $projectDir = $builder->getParameter('kernel.project_dir');
            foreach ([
                "$projectDir/config/api_platform",
                "$projectDir/src/ApiResource",
                "$projectDir/src/Entity",
            ] as $dir) {
                if (is_dir($dir)) {
                    $paths[] = $dir;
                }
            }
        }

        $builder->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => $paths],
        ]);
    }
}
