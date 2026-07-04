<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final class XlsConfigReader
{
    /**
     * @param array<string, mixed> $config
     */
    public function string(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        return (string) $config[$key];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function width(array $config): null|float|string
    {
        $value = $config['width'] ?? null;
        if ($value === null) {
            return null;
        }

        return is_float($value) || is_int($value)
            ? (float) $value
            : (string) $value;
    }
}
