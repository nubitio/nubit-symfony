<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsValidationRule
{
    /**
     * @param list<string>|null $values
     */
    public function __construct(
        public ?array $values = null,
        public ?string $operator = null,
        public ?string $formula1 = null,
        public ?string $formula2 = null,
    ) {}
}
