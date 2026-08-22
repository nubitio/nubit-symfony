<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

/**
 * Names the permission prefix a resource is guarded by, and any actions beyond
 * the four the HTTP methods imply.
 *
 * Optional. Without it the prefix is derived from the class name, which is what
 * makes the catalogue automatic. It is here for the two cases derivation cannot
 * cover: a resource whose class name is not what the business calls it, and
 * domain actions — approve, post, void — that no HTTP verb implies.
 *
 * ```php
 * #[ApiResource]
 * #[Authorized(resource: 'invoice', actions: ['approve', 'void'], limited: ['approve' => 'total'])]
 * class CustomerInvoice { … }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Authorized
{
    /**
     * @param string|null           $resource Permission prefix. Defaults to the class short name.
     * @param list<string>          $actions  Domain actions beyond create/read/update/delete.
     * @param array<string, string> $limited  Action => the Money property a role's limit is
     *                                        compared against. "Approve up to €5,000" is a rule
     *                                        about an amount, and the amount lives on the record.
     */
    public function __construct(
        public ?string $resource = null,
        public array $actions = [],
        public array $limited = [],
    ) {
        foreach ($limited as $action => $property) {
            if ('' === $property) {
                throw new \InvalidArgumentException(sprintf(
                    'Action "%s" declares a limit but names no property to compare it against.',
                    $action,
                ));
            }
        }
    }
}
