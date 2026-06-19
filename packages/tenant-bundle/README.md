# @nubitio/tenant-bundle

Opt-in multi-tenancy for Nubit Symfony apps: **column mode** (shared DB + Doctrine filter) or **database mode** (per-tenant DSN), plus optional plan quota enforcement.

## Install

```bash
composer require nubitio/tenant-bundle
```

Register the bundle and enable it when the app profile is `saas` or `hybrid`:

```yaml
# config/packages/nubit_tenant.yaml
nubit_tenant:
    enabled: true
    isolation: column          # column | database
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

## Database isolation

Switch `isolation: database` to route each request to a tenant-specific database URL. The control-plane registry (same `tenant_entity`) must expose `getDatabaseUrl()`:

```yaml
nubit_tenant:
    enabled: true
    isolation: database
    tenant_connection: default
    control_plane_connection: default
```

Configure the tenant connection wrapper:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            default:
                wrapper_class: Nubit\TenantBundle\Doctrine\Connection\DynamicUrlConnection
```

The default `Nubit\TenantBundle\Entity\Tenant` ships `isolationMode` and `databaseUrl` columns. Custom tenant entities (e.g. `Restaurant`) must add equivalent fields and `getDatabaseUrl()`.

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