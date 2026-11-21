<?php

declare(strict_types=1);

namespace Nubit\Platform\Money;

/**
 * How a division that does not come out even is resolved.
 *
 * There is no sane default, which is why every operation that can lose
 * precision takes one explicitly. `Unnecessary` is the honest choice when the
 * caller believes the result is exact: it throws instead of silently rounding,
 * so a wrong belief surfaces as an error rather than as a missing cent.
 */
enum RoundingMode
{
    /** Refuses to round; throws when the result is not exact. */
    case Unnecessary;

    /** Away from zero on a tie. The rule invoices and most tax authorities use. */
    case HalfUp;

    /** Toward zero on a tie. */
    case HalfDown;

    /** Toward the even neighbour on a tie — banker's rounding, unbiased over many operations. */
    case HalfEven;

    /** Away from zero always. */
    case Up;

    /** Toward zero always — plain truncation. */
    case Down;

    /** Toward positive infinity. */
    case Ceiling;

    /** Toward negative infinity. */
    case Floor;
}
