<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final class XlsColumnOptionsResolver
{
    public function __construct(
        private readonly XlsConfigReader $reader = new XlsConfigReader(),
        private readonly XlsValidationSpecResolver $validationResolver = new XlsValidationSpecResolver(),
    ) {}

    /**
     * @param string|array<string, mixed>|XlsColumnSpec|null $config
     */
    public function resolve(string $field, string|array|XlsColumnSpec|null $config): XlsColumnOptions
    {
        $defaultLabel = ucfirst(str_replace(search: '_', replace: ' ', subject: $field));

        if ($config instanceof XlsColumnSpec) {
            return new XlsColumnOptions(
                label: $config->label ?? $defaultLabel,
                type: $config->type,
                presentation: $config->presentation,
                summary: $config->summary,
            );
        }

        if (is_string($config)) {
            return new XlsColumnOptions(label: $config);
        }

        if (!is_array($config)) {
            return new XlsColumnOptions(label: $defaultLabel);
        }

        return new XlsColumnOptions(
            label: (string) ($config['label'] ?? $defaultLabel),
            type: $this->reader->string($config, 'type'),
            presentation: new XlsColumnPresentation(
                format: $this->reader->string($config, 'format'),
                width: $this->reader->width($config),
                alignment: $this->reader->string($config, 'alignment'),
                validation: $this->validationResolver->resolve($config),
            ),
            summary: new XlsColumnSummary(
                type: $this->reader->string($config, 'summary'),
                formula: $this->reader->string($config, 'summaryFormula'),
                label: $this->reader->string($config, 'summaryLabel'),
            ),
        );
    }
}
