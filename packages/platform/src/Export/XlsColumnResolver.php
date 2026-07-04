<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final class XlsColumnResolver
{
    public function __construct(
        private readonly XlsColumnOptionsResolver $optionsResolver = new XlsColumnOptionsResolver(),
        private readonly XlsColumnTypeResolver $typeResolver = new XlsColumnTypeResolver(),
    ) {}

    /**
     * @param list<string> $fields
     * @param array<int, array<string, mixed>> $data
     * @param array<string, string|array<string, mixed>|XlsColumnSpec>|null $headers
     *
     * @return array<string, XlsColumn>
     */
    public function resolve(array $fields, array $data, ?array $headers): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $options = $this->optionsResolver->resolve($field, $headers[$field] ?? null);
            $type = $options->type ?? $this->typeResolver->infer(array_column($data, $field));
            $presentation = new XlsColumnPresentation(
                format: $options->presentation->format ?? $this->typeResolver->defaultFormat($type),
                width: $options->presentation->width,
                alignment: $options->presentation->alignment,
                validation: $options->presentation->validation,
            );
            $summary = $options->summary->type === null && $this->isNumericType($type)
                ? new XlsColumnSummary(type: XlsColumn::SUMMARY_SUM)
                : $options->summary;

            $columns[$field] = new XlsColumn(
                field: $field,
                label: $options->label,
                type: $type,
                presentation: $presentation,
                summary: $summary,
            );
        }

        return $columns;
    }

    private function isNumericType(string $type): bool
    {
        return $type === XlsColumn::TYPE_NUMBER || $type === XlsColumn::TYPE_INTEGER;
    }
}
