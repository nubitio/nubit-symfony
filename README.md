# Nubit Symfony

Backend packages for the Nubit admin stack — the Symfony / API Platform counterpart of [nubit-react](https://github.com/nubitio/nubit-react). Build CRUD-based admin systems (ERP, POS, vertical SaaS) where the backend publishes a Hydra/OpenAPI contract and [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) generates the screens.

| Package | Composer | Description |
| --- | --- | --- |
| [platform](packages/platform) | `nubitio/platform` | Domain exceptions, tenant contracts, feature gates, quota contracts, messenger middleware, cache/file/export helpers |
| [api-platform](packages/api-platform) | `nubitio/api-platform` | The frontend contract: grid filter (`sort`/`filter`/`searchValue`), translated OpenAPI docs with `x-crud` hints, pagination headers, entity traits |
| [admin-bundle](packages/admin-bundle) | `nubitio/admin-bundle` | One-line install: registers the bridge, dual cookie/Bearer JWT auth (login/refresh/logout), single-tenant defaults |
| [tenant-bundle](packages/tenant-bundle) | `nubitio/tenant-bundle` | Opt-in column, database, PostgreSQL schema, and hybrid tenant isolation |
| [sequence-bundle](packages/sequence-bundle) | `nubitio/sequence-bundle` | Transaction-safe, scoped document numbering from entity attributes |
| [workflow-bundle](packages/workflow-bundle) | `nubitio/workflow-bundle` | Attribute-driven state transitions published into API documentation |

Starter template: [`nubit-skeleton`](https://github.com/nubitio/nubit-skeleton) — Symfony + `@nubitio/react-admin` + Docker Compose + auth, Mercure, media, audit, and master-detail examples.

## Install

```bash
composer require nubitio/admin-bundle   # pulls api-platform + platform
```

Until Packagist listing, consume via VCS repositories:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/nubitio/platform" },
    { "type": "vcs", "url": "https://github.com/nubitio/api-platform" }
  ]
}
```

## The contract with @nubitio/hydra

Annotate an entity and the React frontend renders a full CRUD page for it:

```php
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;

#[ApiResource]
#[ApiFilter(DataGridFilter::class)]
class Product
{
    #[ApiProperty(
        description: 'product.name.label', // i18n key, translated into the docs
        openapiContext: ['x-crud' => ['filterable' => true, 'sortable' => true, 'order' => 0]],
    )]
    public string $name;
}
```

| Aspect | Backend | Frontend |
| --- | --- | --- |
| Docs | `/api/docs.jsonld` with `x-crud` hints | `@nubitio/hydra` schema discovery |
| Grid queries | `sort`, `filter`, `searchValue` params (`DataGridFilter`) | `@nubitio/crud` load options |
| Pagination | `X-Total-Count` / `X-Total-Pages` headers | `HydraRemoteDataSource` |
| Domain errors | `Nubit\Platform\Exception\*` → RFC-7807 / 422 | `@nubitio/core` HTTP client |

Fields without ORM mapping (computed columns, joins) plug in via `GridVirtualFieldInterface` (tag: `nubit.api_platform.grid_virtual_field`).

## Development

```bash
composer install
composer test              # unit suites, no database needed
composer test-integration  # boots real kernels against a throwaway PostgreSQL
vendor/bin/mago analyze
```

`composer test-integration` provisions PostgreSQL and a PHP runtime through
Docker. Point `NUBIT_TEST_DATABASE_URL` at a server of your own to skip the
provisioning and run `vendor/bin/phpunit --testsuite integration` directly.

The integration suite compiles containers and issues real HTTP requests, so it
covers what unit tests structurally cannot: tenant isolation in every mode,
bundle wiring with each optional module on and off, and the SQL the Doctrine
filters actually emit. See
[`docs/platform/enterprise-readiness-plan.md`](docs/platform/enterprise-readiness-plan.md).

Monorepo: packages are mirrored to read-only repos ([nubitio/platform](https://github.com/nubitio/platform), [nubitio/api-platform](https://github.com/nubitio/api-platform)) by the split workflow on every push/tag. Release = tag `vX.Y.Z` (lockstep; release notes in GitHub Releases, no changelog files).

All internal packages depend on the same `0.x` minor release line. A minor
release updates every internal constraint and tag together; the
`nubit-skeleton` compatibility declaration records the supported frontend and
backend line pair.

## License

MIT
