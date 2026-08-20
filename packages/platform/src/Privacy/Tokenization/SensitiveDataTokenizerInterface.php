<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Tokenization;

use Nubit\Platform\Privacy\SensitiveDataMetadata;

interface SensitiveDataTokenizerInterface
{
    public function tokenize(string $value, SensitiveDataMetadata $metadata): ?string;
}
