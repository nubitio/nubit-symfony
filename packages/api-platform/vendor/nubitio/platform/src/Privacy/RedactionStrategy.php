<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy;

enum RedactionStrategy: string
{
    case Drop = 'drop';
    case Redact = 'redact';
    case Mask = 'mask';
    case Hash = 'hash';
    case Tokenize = 'tokenize';
    case Allow = 'allow';
}
