<?php

declare(strict_types=1);

namespace Nubit\Platform\Time;

/**
 * Implemented by a user or tenant entity that carries a display timezone.
 *
 * Optional on both: an application whose users all sit in one place never has
 * to implement it, and one that spans countries implements it on whichever of
 * the two actually decides — often the tenant, sometimes the individual.
 */
interface TimeZoneAwareInterface
{
    /** IANA identifier such as "America/Lima". Null means "no preference". */
    public function getTimeZone(): ?string;
}
