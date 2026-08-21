<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsColumnPresentation
{
    public function __construct(
        public ?string $format = null,
        public float|string|null $width = null,
        public ?string $alignment = null,
        public ?XlsValidationSpec $validation = null,
    ) {}

    public function withWidth(float|string $width): self
    {
        return new self($this->format, $width, $this->alignment, $this->validation);
    }

    public function withAlignment(string $alignment): self
    {
        return new self($this->format, $this->width, $alignment, $this->validation);
    }

    public function withValidation(XlsValidationSpec $validation): self
    {
        return new self($this->format, $this->width, $this->alignment, $validation);
    }
}
