<?php

declare(strict_types=1);

namespace Nubit\Platform\Tests\Privacy;

use InvalidArgumentException;
use Nubit\Platform\Privacy\Attribute\SensitiveData;
use Nubit\Platform\Privacy\DataClassification;
use Nubit\Platform\Privacy\RedactionStrategy;
use PHPUnit\Framework\TestCase;

final class SensitiveDataAttributeTest extends TestCase
{
    public function testRestrictedDataRejectsAWeakExplicitStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SensitiveData(DataClassification::Restricted, RedactionStrategy::Mask);
    }
}
