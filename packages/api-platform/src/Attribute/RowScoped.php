<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

/**
 * Restricts which rows of a resource a user can reach at all.
 *
 * Tenancy answers "whose data is this"; this answers "which of *our* data is
 * yours". They are different questions and an ERP needs both: a warehouse
 * supervisor and a regional manager belong to the same company and must not see
 * the same rows.
 *
 * The restriction is applied to the query, not checked after loading. Filtering
 * a collection in PHP still lets a caller reach a hidden row by its identifier,
 * and still leaks its existence through the total count.
 *
 * ```php
 * #[ApiResource]
 * #[RowScoped(field: 'warehouse', claim: 'warehouses')]
 * class StockMovement { … }
 * ```
 *
 * `claim` names a method on the user (`getWarehouses()`) returning the values —
 * ids or entities — the user may reach. Returning null from it means unscoped,
 * which is how a manager is expressed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class RowScoped
{
    public function __construct(
        /** Property on the resource carrying the scope value. */
        public string $field,
        /** Property or accessor on the user holding the allowed values. */
        public string $claim,
        /**
         * Whether a user whose claim is empty sees nothing or everything.
         *
         * Defaults to nothing. An empty claim is far more often an account
         * nobody finished setting up than a deliberate grant of everything, and
         * the safe reading of "unset" is not "all".
         */
        public bool $emptyClaimSeesAll = false,
    ) {
        if ('' === $field || '' === $claim) {
            throw new \InvalidArgumentException('A row scope needs both a field and a claim.');
        }
    }
}
