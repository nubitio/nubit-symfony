<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsColumnSpec
{
    public function __construct(
        public ?string $label = null,
        public ?string $type = null,
        public XlsColumnPresentation $presentation = new XlsColumnPresentation(),
        public XlsColumnSummary $summary = new XlsColumnSummary(),
    ) {}

    public static function text(?string $label = null): self
    {
        return new self(label: $label, type: XlsColumn::TYPE_STRING);
    }

    public static function number(?string $label = null, ?string $format = '#,##0.###', ?string $summary = XlsColumn::SUMMARY_SUM): self
    {
        return new self(
            label: $label,
            type: XlsColumn::TYPE_NUMBER,
            presentation: new XlsColumnPresentation(format: $format),
            summary: new XlsColumnSummary(type: $summary ?? XlsColumn::SUMMARY_NONE),
        );
    }

    public static function integer(?string $label = null, ?string $format = '#,##0', ?string $summary = XlsColumn::SUMMARY_SUM): self
    {
        return new self(
            label: $label,
            type: XlsColumn::TYPE_INTEGER,
            presentation: new XlsColumnPresentation(format: $format),
            summary: new XlsColumnSummary(type: $summary ?? XlsColumn::SUMMARY_NONE),
        );
    }

    public static function date(?string $label = null, ?string $format = 'yyyy-mm-dd'): self
    {
        return new self(label: $label, type: XlsColumn::TYPE_DATE, presentation: new XlsColumnPresentation(format: $format));
    }

    public static function datetime(?string $label = null, ?string $format = 'yyyy-mm-dd hh:mm'): self
    {
        return new self(label: $label, type: XlsColumn::TYPE_DATETIME, presentation: new XlsColumnPresentation(format: $format));
    }

    public function withSummary(string $summary, ?string $formula = null, ?string $label = null): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            presentation: $this->presentation,
            summary: new XlsColumnSummary(type: $summary, formula: $formula, label: $label),
        );
    }

    public function withWidth(float|string $width): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            presentation: $this->presentation->withWidth($width),
            summary: $this->summary,
        );
    }

    public function withAlignment(string $alignment): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            presentation: $this->presentation->withAlignment($alignment),
            summary: $this->summary,
        );
    }

    public function withValidation(XlsValidationSpec $validation): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            presentation: $this->presentation->withValidation($validation),
            summary: $this->summary,
        );
    }
}
