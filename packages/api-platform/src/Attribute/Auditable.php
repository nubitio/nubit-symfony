<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

use Attribute;

/**
 * Marks an entity for change auditing: creates, updates and deletes are
 * recorded as field-level before/after diffs (admin-bundle's AuditTrailListener
 * writes them to nubit_audit_log, served at GET /api/audit-trail/{resource}/{id}).
 *
 * Opt-in by design — auditing every entity floods the log with noise from
 * high-churn rows (stock counters, tokens).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Auditable
{
    public function __construct(
        /**
         * Resource segment used in the audit-trail URL. Defaults to the
         * lowercased short class name (Product → "product").
         */
        public ?string $resource = null,
    ) {
    }
}
