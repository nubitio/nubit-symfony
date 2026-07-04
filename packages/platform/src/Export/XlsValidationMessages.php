<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsValidationMessages
{
    public function __construct(
        public ?string $promptTitle = null,
        public ?string $prompt = null,
        public ?string $errorTitle = null,
        public ?string $error = null,
    ) {}
}
