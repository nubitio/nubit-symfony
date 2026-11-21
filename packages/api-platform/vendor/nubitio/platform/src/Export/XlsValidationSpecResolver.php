<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final class XlsValidationSpecResolver
{
    public function __construct(
        private readonly XlsConfigReader $reader = new XlsConfigReader(),
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function resolve(array $config): ?XlsValidationSpec
    {
        $validation = $config['validation'] ?? null;
        if ($validation instanceof XlsValidationSpec) {
            return $validation;
        }

        if (!is_array($validation)) {
            return null;
        }

        $values = $validation['values'] ?? null;

        return new XlsValidationSpec(
            type: (string) ($validation['type'] ?? 'list'),
            rule: new XlsValidationRule(
                values: is_array($values) ? array_values(array_map('strval', $values)) : null,
                operator: $this->reader->string($validation, 'operator'),
                formula1: $this->reader->string($validation, 'formula1'),
                formula2: $this->reader->string($validation, 'formula2'),
            ),
            allowBlank: (bool) ($validation['allowBlank'] ?? true),
            messages: new XlsValidationMessages(
                promptTitle: $this->reader->string($validation, 'promptTitle'),
                prompt: $this->reader->string($validation, 'prompt'),
                errorTitle: $this->reader->string($validation, 'errorTitle'),
                error: $this->reader->string($validation, 'error'),
            ),
        );
    }
}
