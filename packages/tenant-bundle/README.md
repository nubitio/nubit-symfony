# @nubitio/tenant-bundle

Opt-in **column-mode** multi-tenancy for Nubit Symfony apps.

## Install

```bash
composer require nubitio/tenant-bundle
```

Register the bundle and enable it when the app profile is `saas` or `hybrid`:

```yaml
# config/packages/nubit_tenant.yaml
nubit_tenant:
    enabled: true
    isolation: column
    resolution: [user, jwt_claim]
    tenant_entity: App\Entity\Restaurant   # or omit for Nubit\TenantBundle\Entity\Tenant
    rls_enabled: false
```

Pair with admin-bundle SaaS profile:

```yaml
# config/packages/nubit_admin.yaml
nubit_admin:
    app_profile: saas
    single_tenant_defaults: false
```

## Entity contract

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

## Commands

```bash
bin/console nubit:tenant:list
```

Per-tenant console jobs extend `Nubit\Platform\Symfony\Console\PerTenantCommand` — the bundle wires a column-mode connection switcher and a Doctrine-backed registry.

## Resolution strategies

| Strategy | Source |
|----------|--------|
| `user` | `TenantAwareUserInterface` on the authenticated user |
| `jwt_claim` | `tenantId` / `tenantName` claims in the access JWT |
| `header` | `X-Tenant-Id` (configurable) |
| `subdomain` | Tenant registry lookup by slug / domain |