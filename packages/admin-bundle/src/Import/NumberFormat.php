<?php

declare(strict_types=1);

namespace Nubit\AdminBundle\Import;

/**
 * Which convention the uploaded file writes numbers in.
 *
 * Declared per import rather than inferred, because one case cannot be
 * inferred: "1,234" is one thousand two hundred and thirty-four to an English
 * reader and one point two three four to a Spanish one, and picking wrong moves
 * an amount by a factor of a thousand. {@see Auto} handles everything that is
 * unambiguous and refuses that case with a message instead of guessing.
 */
enum NumberFormat: string
{
    /** Infer per value; refuse the genuinely ambiguous ones. */
    case Auto = 'auto';

    /** 1,234.56 — comma groups, dot decides the decimals. */
    case DotDecimal = 'dot';

    /** 1.234,56 — dot groups, comma decides the decimals. */
    case CommaDecimal = 'comma';
}
