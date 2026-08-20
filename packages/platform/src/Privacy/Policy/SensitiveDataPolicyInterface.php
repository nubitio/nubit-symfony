<?php

declare(strict_types=1);

namespace Nubit\Platform\Privacy\Policy;

use Nubit\Platform\Privacy\DataPurpose;
use Nubit\Platform\Privacy\DataSink;
use Nubit\Platform\Privacy\RedactionStrategy;
use Nubit\Platform\Privacy\SensitiveDataMetadata;

interface SensitiveDataPolicyInterface
{
    public function strategy(SensitiveDataMetadata $metadata, DataSink $sink, DataPurpose $purpose): RedactionStrategy;
}
