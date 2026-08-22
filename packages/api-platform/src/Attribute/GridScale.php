<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

/**
 * Declares how a resource expects to be read once it is large.
 *
 * Every grid in an ERP is small on the day it ships. The ones that stop working
 * are the ones nobody decided anything about: page 4,000 of an offset-paginated
 * movements table asks PostgreSQL to fetch and discard 80,000 rows, and the
 * footer's `COUNT(*)` walks the relation on top of that.
 *
 * This is where a resource says which of those costs it is willing to pay. It
 * is published in the API documentation, so the frontend paginates the way the
 * backend expects rather than the way it always has.
 *
 * ```php
 * #[ApiResource(paginationPartial: true, paginationViaCursor: [['field' => 'id', 'direction' => 'DESC']])]
 * #[GridScale(cursorField: 'id', exactCount: false)]
 * class StockMovement { … }
 * ```
 *
 * The attribute describes; API Platform's own options enforce. Keeping them
 * separate would be a way to disagree, so {@see GridScaleDocumentationNormalizer}
 * reads both and publishes what is actually in force.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class GridScale
{
    public function __construct(
        /**
         * The field a cursor walks. Null means the resource stays on offsets.
         *
         * It has to be unique and ordered — an identifier, or a timestamp with
         * an identifier behind it — because a cursor that lands on a duplicate
         * either repeats a row or skips one.
         */
        public ?string $cursorField = null,
        /** Cursor direction. Only meaningful with a cursor field. */
        public string $cursorDirection = 'DESC',
        /**
         * Whether the footer's total is worth an exact count.
         *
         * False makes it an estimate on PostgreSQL, and absent elsewhere. Past a
         * few hundred thousand rows nobody reads the total as a precise number
         * anyway; they read it as "more than I want to scroll".
         */
        public bool $exactCount = true,
        /**
         * Rows above which an export is queued rather than streamed inline.
         *
         * A synchronous export is far nicer when it can finish: no waiting for a
         * worker, no email, no link. It stops being nicer at the point where the
         * request times out or the process runs out of memory.
         */
        public int $inlineExportLimit = 5_000,
    ) {
        if (!in_array(strtoupper($cursorDirection), ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Cursor direction must be ASC or DESC; got "%s".',
                $cursorDirection,
            ));
        }

        if ($inlineExportLimit < 0) {
            throw new \InvalidArgumentException('The inline export limit cannot be negative.');
        }
    }

    public function usesCursor(): bool
    {
        return null !== $this->cursorField;
    }
}
