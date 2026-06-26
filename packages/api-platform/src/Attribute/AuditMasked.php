<?php

declare(strict_types=1);

namespace Nubit\ApiPlatform\Attribute;

use Attribute;

/**
 * Prevents a property from being recorded in the audit trail.
 *
 * Apply to any property on an #[Auditable] entity that must not appear in
 * diff logs — PII fields (taxId, phone), secrets, or internal counters.
 * The field is still persisted normally; only the audit recording is suppressed.
 *
 * Usage:
 *
 *     #[AuditMasked]
 *     private string $taxId;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AuditMasked
{
}
