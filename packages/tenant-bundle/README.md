# @nubitio/tenant-bundle

Opt-in multi-tenancy for Nubit Symfony apps: **column mode** (shared DB + Doctrine filter), **database mode** (per-tenant DSN), **schema mode** (PostgreSQL `search_path`), or **hybrid** routing, plus optional plan quota enforcement.

## Install

```bash
composer require nubitio/tenant-bundle
```

Register the bundle and enable it when the app profile is `saas` or `hybrid`:

```yaml
# config/packages/nubit_tenant.yaml
nubit_tenant:
    enabled: true
    isolation: column          # column | database | schema | hybrid
    resolution: [user, jwt_claim]
    tenant_entity: App\Entity\Restaurant   # or omit for Nubit\TenantBundle\Entity\Tenant
    quotas_enabled: false      # set true to enforce plan limits on prePersist
    rls_enabled: false
```

Pair with admin-bundle SaaS profile:

```yaml
# config/packages/nubit_admin.yaml
nubit_admin:
    app_profile: saas
    single_tenant_defaults: false
```

## Column isolation (default)

Mark tenant-owned entities with `#[TenantScoped]` and implement `TenantOwnedInterface`. The Doctrine `nubit_tenant` filter scopes queries to the active tenant.

```php
use Nubit\TenantBundle\Attribute\TenantScoped;
use Nubit\TenantBundle\Contract\TenantOwnedInterface;
use Nubit\TenantBundle\Entity\TenantOwnedTrait;

#[TenantScoped]
#[ORM\Entity]
class Order implements TenantOwnedInterface
{
    use TenantOwnedTrait;
}
```

Custom column + association stamping (RestoPOS pattern):

```php
#[TenantScoped(field: 'restaurant_id', relation: 'restaurant')]
class Order implements RestaurantOwnedInterface
{
    use RestaurantOwnedTrait;
}
```

Users should implement `TenantAwareUserInterface` so the `user` resolver can populate `TenantContext`.

## Database and hybrid isolation

Switch `isolation: database` to route every request to a tenant-specific database URL. For `isolation: hybrid`, the control-plane tenant entity chooses `column` or `database` per tenant via `getIsolationMode()`. Database targets must also expose `getDatabaseUrl()`:

```yaml
nubit_tenant:
    enabled: true
    isolation: hybrid
    tenant_connection: tenant
    control_plane_connection: default
```

Configure the tenant connection wrapper:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            tenant:
                wrapper_class: Nubit\TenantBundle\Doctrine\Connection\DynamicUrlConnection
```

`control_plane_connection` is used only for registry lookups; keep it on a control-plane entity manager, separate from the dynamically switched `tenant_connection`.

The default `Nubit\TenantBundle\Entity\Tenant` retains `databaseUrl` for compatibility with existing database-isolation deployments. New production applications should use a custom tenant entity whose `getDatabaseUrl()` resolves credentials from a dedicated control-plane secret store.

The bundle only routes an already-provisioned target. Database creation, credentials, grants, migrations, queues, and provider SDKs remain application-owned.

## PostgreSQL schema isolation

`isolation: schema` derives the tenant schema exclusively from the positive resolved tenant ID: with the default `schema_prefix: tenant_`, tenant ID `42` becomes `tenant_42`. Slugs, domains, request headers, and target-provider strings are never used as SQL identifiers.

```yaml
nubit_tenant:
    enabled: true
    isolation: schema
    tenant_connection: default
    schema_prefix: tenant_
    base_schemas: [public, extensions]
```

The schema switcher validates lowercase PostgreSQL identifiers (allowed characters and the 63-byte limit), executes `SET search_path TO "tenant_42", "public", "extensions"`, and explicitly restores `SET search_path TO "public", "extensions"` after the HTTP request. It rejects switching or resetting while a transaction is active; it never uses `SET LOCAL` or bare `RESET`.

For `isolation: hybrid`, an application-owned `TenantIsolationTargetProviderInterface` may return `new TenantIsolationTarget(TenantIsolationTarget::SCHEMA)`. The bundled registry provider intentionally rejects schema targets, so applications must own the placement decision. Column and database hybrid targets remain compatible.

`TenantSchemaProvisionerInterface` and `TenantSchemaMigrationRunnerInterface` are documentation markers only. Applications own schema creation, roles/grants, migrations, backups, and secret retrieval; this bundle supplies neither DDL nor provider credentials.

## Plan quotas

Enable `quotas_enabled: true` to block `prePersist` when a plan limit is reached. Limits come from `FeatureCheckerInterface::getFeatureConfig($resource)['max']`.

1. Tag entities with the quota resource name:

```php
use Nubit\TenantBundle\Attribute\QuotaResource;

#[QuotaResource('team_users')]
#[ORM\Entity]
class User { /* … */ }
```

2. Register usage counters via `QuotaUsageProviderInterface` (autoconfigured with tag `nubit.quota_usage_provider`):

```php
final readonly class TeamUsersQuotaUsageProvider implements QuotaUsageProviderInterface
{
    public function supports(string $resource): bool
    {
        return 'team_users' === $resource;
    }

    public function count(string $resource): int
    {
        // return current tenant usage
    }
}
```

When `quotas_enabled` is on, the bundle aliases `QuotaEnforcerInterface` to `FeatureQuotaEnforcer` (replacing admin-bundle's unlimited noop for SaaS apps).

## Commands

```bash
bin/console nubit:tenant:list
```

Per-tenant console jobs extend `Nubit\Platform\Symfony\Console\PerTenantCommand` — the bundle wires the connection switcher and a Doctrine-backed registry.

## Resolution strategies

| Strategy | Source |
|----------|--------|
| `user` | `TenantAwareUserInterface` on the authenticated user |
| `jwt_claim` | `tenantId` / `tenantName` claims in the access JWT |
| `header` | `X-Tenant-Id` (configurable) |
| `subdomain` | Tenant registry lookup by slug / domain |
