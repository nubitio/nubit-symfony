<?php

declare(strict_types=1);

namespace Nubit\Platform\Tenant\Http;

/**
 * Request attribute a stateless credential authenticator (an API key, a
 * signed service token) uses to publish the tenant it was issued for.
 *
 * The credential and the tenant resolver that reads this attribute
 * deliberately do not depend on each other: an authenticator sets a plain
 * `int|null`, unconditionally, whether or not tenant-bundle is even
 * installed, and a resolver reads it back if it is. Both sides already
 * depend on this package, so it is the one place that can carry the
 * contract without admin-bundle depending on tenant-bundle's resolvers or
 * tenant-bundle depending on admin-bundle's authenticators.
 *
 * Unlike a header or a subdomain, this value is not attacker-controlled: it
 * is derived from a credential the server already verified, so a resolver
 * consuming it needs no membership check of its own.
 */
final class TenantCredentialRequestAttribute
{
    public const string NAME = '_nubit_credential_tenant_id';

    private function __construct() {}
}
