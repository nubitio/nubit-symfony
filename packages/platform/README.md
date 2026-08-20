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
- **Infra helpers** — `CacheManager`, `FileManager` (Flysystem), `TenantRateLimiter`, `XlsExporter` (PhpSpreadsheet), `PdfExporter` (WeasyPrint), `PerTenantCommand` console base class, `TenantLogProcessor` (Monolog) and tenant-aware OpenTelemetry spans via `TenantTracer`.
- **Feature flags** — vendor-neutral, typed evaluation through `TenantFeatureFlags`; distinct from plan entitlements exposed by `FeatureCheckerInterface`. `StaticFeatureFlagProvider` provides deterministic local defaults and the provider port can be adapted to OpenFeature.
- **Privacy** — `#[SensitiveData]`, `DataRedactor` and sink-specific policies protect structured logs, traces, analytics and integrations.
- **Analytics** — typed, versioned events with explicit purpose, consent checks, deduplication and provider-neutral sanitized delivery.
- **HTTP** — `ApiResponse` JSON envelope (`success`/`message`/`data`).

Heavy integrations (Flysystem, PhpSpreadsheet, WeasyPrint, Monolog, OpenTelemetry) are `suggest`-ed — install them only if you use the corresponding helper.

## Sensitive data

Classify the canonical DTO/entity property once:

```php
use Nubit\Platform\Privacy\Attribute\SensitiveData;
use Nubit\Platform\Privacy\DataClassification;

final readonly class LoginContext
{
    public function __construct(
        public string $username,
        #[SensitiveData(DataClassification::Confidential)]
        public string $email,
        #[SensitiveData(DataClassification::Restricted)]
        public string $accessToken,
    ) {
    }
}
```

For values inserted into an untyped array, classify them explicitly:

```php
$context = [
    'email' => new SensitiveValue($email, DataClassification::Confidential),
    'token' => new SensitiveValue($token, DataClassification::Restricted),
];
```

`SensitiveDataProcessor` sanitizes structured Monolog `context` and `extra` fields.
`TraceAttributeSanitizer` applies the same kernel to OpenTelemetry attributes. Restricted
data is dropped by default. Confidential data is masked in logs and HMACed in traces or
analytics when a key is configured; without the key it is dropped.

The processor intentionally does not rewrite arbitrary log or exception messages. Never
interpolate secrets or complete request payloads into text:

```php
// Safe: the structured value passes through the redactor.
$logger->info('Authentication failed', ['email' => new SensitiveValue(
    $email,
    DataClassification::Confidential,
)]);
```

See the [ERP SaaS platform roadmap](../../docs/platform/saas-platform-roadmap.md) for the
classification matrix, threat model and phased rollout.

## Analytics

Analytics payloads must be DTOs so the privacy metadata is applied before a provider
receives them:

```php
$result = $publisher->publish(new AnalyticsEvent(
    id: $commandId, // stable idempotency key
    name: 'invoice.paid',
    schemaVersion: 1,
    purpose: AnalyticsPurpose::Operational,
    payload: new InvoicePaidAnalyticsPayload($channel, $customerEmail),
));
```

`AnalyticsProviderInterface` receives only `SanitizedAnalyticsEvent`. Product and
marketing events are denied by the safe default consent checker. The included
`InMemoryAnalyticsDeduplicator` is bounded and suitable for tests or one local process;
production must bind `AnalyticsDeduplicatorInterface` to an atomic shared store or use
an outbox table with a unique event ID.

## License

MIT
