# nubitio/platform

Platform foundation for Nubit Symfony apps: the framework-agnostic contracts and helpers that the rest of the stack builds on.

```bash
composer require nubitio/platform
```

## What's inside

- **Exceptions** — `ServiceException`, `ValidationException`, `NotFoundException`, `DomainProblemException`, `QuotaExceededException` with `DomainErrorCode`. Throw these from services; `nubitio/api-platform` maps them to proper HTTP responses.
- **Tenant** — `TenantContext` plus contracts (`TenantRegistryInterface`, `TenantConnectionSwitcherInterface`, `TenantDescriptorRegistryInterface`, `TenantBackupRunnerInterface`). Single-tenant apps bind noop implementations; multi-tenant apps provide real ones.
- **Feature gates** — `#[RequiresFeature]` attribute + `FeatureCheckerInterface`.
- **Quota contracts** — `QuotaEnforcerInterface`, `QuotaResourceResolverInterface`.
- **Messenger** — `TenantStampMiddleware` / `TenantContextMiddleware` propagate tenant + actor through async messages.
- **Infra helpers** — `CacheManager`, `FileManager` (Flysystem), `TenantRateLimiter`, `XlsExporter` (PhpSpreadsheet), `PdfExporter` (WeasyPrint), `PerTenantCommand` console base class, `TenantLogProcessor` (Monolog).
- **HTTP** — `ApiResponse` JSON envelope (`success`/`message`/`data`).

Heavy integrations (Flysystem, PhpSpreadsheet, WeasyPrint, Monolog, OpenTelemetry) are `suggest`-ed — install them only if you use the corresponding helper.

## License

MIT
