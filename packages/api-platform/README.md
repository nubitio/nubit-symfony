# nubitio/api-platform

API Platform bridge for the Nubit admin stack: everything a Symfony backend needs so [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) can auto-generate CRUD screens from your API docs.

```bash
composer require nubitio/api-platform
```

## What's inside

- **`DataGridFilter`** — implements the grid query contract (`sort`, `filter`, `searchValue`) that `@nubitio/core` serializes. Add `#[ApiFilter(DataGridFilter::class)]` to a resource and the React datagrid's filtering/sorting/search work end to end.
- **`GridVirtualFieldInterface`** — extension point for computed/joined fields with no ORM mapping. Tag implementations with `nubit.api_platform.grid_virtual_field`; `GridFilterHelper` provides the operator/parameter utilities.
- **`TranslatedDocumentationNormalizer`** — translates `ApiProperty` description i18n keys into the Hydra/OpenAPI docs and forwards `x-crud` hints that `@nubitio/hydra` turns into field definitions.
- **`ApiResponseListener`** — adds `X-Total-Count`, `X-Total-Pages`, `X-Current-Page` headers to collection responses.
- **`ExceptionListener`** — maps `Nubit\Platform\Exception\*` to consistent JSON error responses (422 with violations for `ValidationException`, etc.).
- **`BaseController`** — thin `AbstractController` with the `ApiResponse` envelope helpers.
- **Entity traits** — `TimestampableTrait`, `SoftDeletableTrait`.

## Service registration

Until the admin bundle ships, register the services in your `config/services.yaml`
(the listeners carry `#[AsEventListener]`, they just need to exist as services):

```yaml
services:
    # Gets the api_platform.filter tag via autoconfiguration; required for
    # resources declaring filters: [DataGridFilter::class]
    Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter: ~

    Nubit\ApiPlatform\OpenApi\TranslatedDocumentationNormalizer:
        decorates: api_platform.hydra.normalizer.documentation
        arguments:
            $inner: '@.inner'
            $translator: '@translator'
            $requestStack: '@request_stack'
            $apiLocale: '%env(APP_API_LOCALE)%'

    Nubit\ApiPlatform\Http\ApiResponseListener: ~
    Nubit\ApiPlatform\Http\ExceptionListener: ~

    # Your virtual grid fields, if any
    App\Grid\MyVirtualFields:
        tags: ['nubit.api_platform.grid_virtual_field']
```

## License

MIT
