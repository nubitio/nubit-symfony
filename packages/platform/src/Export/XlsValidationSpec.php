<?php

declare(strict_types=1);

namespace Nubit\Platform\Export;

final readonly class XlsValidationSpec
{
    /**
     * @param list<string>|null $values
     */
    public function __construct(
        public string $type,
        public XlsValidationRule $rule = new XlsValidationRule(),
        public bool $allowBlank = true,
        public XlsValidationMessages $messages = new XlsValidationMessages(),
    ) {}

    /**
     * @param list<string> $values
     */
    public static function list(array $values, bool $allowBlank = true): self
    {
        return new self(type: 'list', rule: new XlsValidationRule(values: $values), allowBlank: $allowBlank);
    }

    public static function decimal(
        ?string $operator = null,
        ?string $formula1 = null,
        ?string $formula2 = null,
        bool $allowBlank = true,
    ): self {
        return new self(
            type: 'decimal',
            rule: new XlsValidationRule(operator: $operator, formula1: $formula1, formula2: $formula2),
            allowBlank: $allowBlank,
        );
    }
}
