# Nubit Symfony

Backend packages for the Nubit admin stack — the Symfony / API Platform counterpart of [nubit-react](https://github.com/nubitio/nubit-react). Build CRUD-based admin systems (ERP, POS, vertical SaaS) where the backend publishes a Hydra/OpenAPI contract and [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) generates the screens.

| Package | Composer | Description |
| --- | --- | --- |
| [platform](packages/platform) | `nubitio/platform` | Domain exceptions, tenant contracts, feature gates, quota contracts, messenger middleware, cache/file/export helpers |
| [api-platform](packages/api-platform) | `nubitio/api-platform` | The frontend contract: grid filter (`sort`/`filter`/`searchValue`), translated OpenAPI docs with `x-crud` hints, pagination headers, entity traits |

Planned: `nubitio/admin-bundle` (one-line install: auth dual cookie/Bearer, service wiring, single-tenant defaults) and a full-stack skeleton.

## Install

```bash
composer require nubitio/api-platform   # pulls nubitio/platform
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
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Monorepo: packages are mirrored to read-only repos ([nubitio/platform](https://github.com/nubitio/platform), [nubitio/api-platform](https://github.com/nubitio/api-platform)) by the split workflow on every push/tag. Release = tag `vX.Y.Z` (lockstep; release notes in GitHub Releases, no changelog files).

## License

MIT
