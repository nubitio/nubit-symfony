<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

/**
 * Common shape of the fixture entities, so the test controller can read an
 * identifier without the analyzer having to guess what `object` holds.
 */
interface FixtureEntity
{
    public function getId(): ?int;
}
