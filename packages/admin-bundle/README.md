# nubitio/admin-bundle

One-line backend for the Nubit admin stack. Install it, point [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) at your API, and you have a CRUD admin system.

```bash
composer require nubitio/admin-bundle
```

Registers automatically:

- The **API Platform bridge** from `nubitio/api-platform`: `DataGridFilter`, translated OpenAPI docs with `x-crud` hints, pagination headers, domain-exception mapping.
- **Dual JWT auth**: `POST /api/auth/login`, `/api/auth/refresh`, `/api/auth/logout`, `/api/auth/change-password`, `GET /api/me`. Web clients get HttpOnly cookies; mobile/API clients get tokens in the body (`response_mode: json` or `X-Client-Type: android|ios`). Refresh tokens are rotated and stored hashed (Doctrine entity `nubit_refresh_token`); changing the password revokes every session and re-issues tokens for the current one. Purge old tokens with `bin/console nubit:auth:purge-refresh-tokens`.
- **Mercure** (`nubit_admin.mercure.enabled: true`): issues the `mercureAuthorization` subscriber-JWT cookie on login/refresh so the React grids receive live updates. Replace `MercureCookieDecorator` to scope topics per tenant/user.
- **Fail-safe Mercure publishing** (`mercure.fail_safe`, on by default whenever MercureBundle is installed): API Platform publishes `mercure: true` updates after the flush, so a dead hub used to turn an already-persisted write into a 500 — clients retry and duplicate data. The bundle decorates the default hub: during HTTP requests publish failures are logged and swallowed (response stays 2xx, live refresh degrades to manual); in messenger workers and console commands they are rethrown, so routing `Symfony\Component\Mercure\Update` to an async transport keeps full retry/delivery semantics. Apps with a custom hub name decorate it themselves with `Nubit\AdminBundle\Mercure\FailSafeHub`.
- **Soft delete**: mark entities with `#[Nubit\ApiPlatform\Attribute\SoftDeletable]` and the registered Doctrine filter (`nubit_soft_delete`) hides rows whose `deleted_at` is set. Opt-in per entity by design.
- **Single-tenant defaults** for the `Nubit\Platform` contracts (registry, connection switcher, feature checker, quota enforcer) — multi-tenant apps override the aliases.
- **Autoconfiguration** for `GridVirtualFieldInterface` and `LoginResponseDecoratorInterface` implementations.
- **Discovery CLI**: `bin/console nubit:discover` lists API Platform resources, embedded-lines routes, and (when installed) sequence/workflow features.
- **Security audit CLI**: `bin/console nubit:security:audit` flags write operations with no `security:` expression (`--strict` for CI).
- **Opt-in modules**, each off by default and covered below: spreadsheet export, SSO/OpenID Connect, notifications (email + in-app), tenant backups, analytics outbox, audit trail, media library.
- **Embedded lines in docs**: `x-embedded-lines` on parent resources lets `SchemaCrudPage` infer `formDetail` line fields automatically. Set an explicit `route` on `#[EmbeddedLines]` (omitting it is deprecated).

## Setup

1. Import the routes (`config/routes/nubit_admin.yaml`):

```yaml
nubit_admin:
    resource: '@NubitAdminBundle/config/routes.php'
```

2. Wire the firewall (`config/packages/security.yaml`) — the bundle cannot define firewalls for you.
   **Apps with more than one user provider** (e.g. an extra admin firewall) must also alias the one
   the API uses, otherwise autowiring is ambiguous:

```yaml
# config/services.yaml
Symfony\Component\Security\Core\User\UserProviderInterface: '@App\Security\ApiUserProvider'
```


```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'
    providers:
        app_users:
            entity: { class: App\Entity\User, property: email }
    firewalls:
        api:
            pattern: ^/api
            stateless: true
            provider: app_users
            custom_authenticator: Nubit\AdminBundle\Auth\JWTAuthenticator
    access_control:
        - { path: ^/api/auth/(login|refresh), roles: PUBLIC_ACCESS }
        - { path: ^/api/docs, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
```

3. Create the refresh-token table: `bin/console make:migration && bin/console doctrine:migrations:migrate` (the bundle's `RefreshToken` entity is auto-mapped).

## Session profile (`GET /api/me`)

The React `SessionProvider` calls this on boot. Default response:

```json
{ "username": "admin@example.com", "roles": ["ROLE_ADMIN"], "appProfile": "internal" }
```

| `app_profile` | Extra blocks |
| --- | --- |
| `internal` | none (single-org panel) |
| `saas` | `tenant` (when `TenantContext` is set), `features` (from `FeatureCheckerInterface::getEntitlements()`) |
| `hybrid` | same as `saas` — branch/context fields come from a custom `MeResponseBuilderInterface` |

Alias `MeResponseBuilderInterface` to add application-specific fields without forking the route.

## Embedded lines (master-detail forms)

Line entities that belong to a parent document use `#[EmbeddedLines]` on the
Doctrine class — the bundle registers `GET /api/{lines}` returning a **plain JSON
array** for SmartCrud `formDetail` reload (no Hydra envelope, no custom controller).

```php
#[EmbeddedLines(
    parentProperty: 'document',
    normalizationGroups: ['document:read'],
)]
#[ORM\Entity]
class SalesDocumentLine { ... }
```

Import embedded line routes in addition to the bundle routes:

```yaml
nubit_embedded_lines:
    resource: '@NubitAdminBundle/config/embedded_lines_routes.yaml'
```

On the parent processor, extend `AbstractEmbeddedLinesProcessor` to bind lines
before persist. Frontend:

```ts
formDetail: {
  propertyName: 'lines',
  url: embeddedLinesUrl('/api/sales_document_lines', 'document'),
  fields: [...],
}
```

## Runtime config (`GET /api/runtime-config`, opt-in)

Separate from `/api/me`: UI flags, defaults, capabilities, onboarding state — **free-form JSON**
defined by the application. Enable the route, implement the provider, alias it:

```yaml
# config/packages/nubit_admin.yaml
nubit_admin:
    runtime_config: true
```

```php
// src/Runtime/AppRuntimeConfigProvider.php
final readonly class AppRuntimeConfigProvider implements RuntimeConfigProviderInterface
{
    public function getConfig(): array
    {
        return [
            'ui' => ['showBranchPicker' => false],
            'defaults' => ['currency' => 'USD'],
        ];
    }
}
```

```yaml
# config/services.yaml
Nubit\AdminBundle\Runtime\RuntimeConfigProviderInterface: '@App\Runtime\AppRuntimeConfigProvider'
```

On the React side, `useRuntimeConfig()` from `@nubitio/react-admin` fetches the payload
(`RuntimeConfig` is `Record<string, unknown>` — type it per app). Disabled by default so
internal skeletons work with zero config.

## Configuration (defaults shown)

```yaml
# config/packages/nubit_admin.yaml
nubit_admin:
    app_profile: internal   # internal | saas | hybrid
    auth:
        secret: '%env(APP_SECRET)%'   # >= 32 bytes (HS256)
        access_token_ttl: 3600
        refresh_token_ttl: 1209600    # 14 days
        cookie_secure: true
    api:
        translated_docs: true
        docs_locale: '%env(default::APP_API_LOCALE)%'
    mercure:
        enabled: false                # true → mercureAuthorization cookie on login/refresh
        secret: '%env(MERCURE_JWT_SECRET)%'
        topics: ['*']
        hub_path: /.well-known/mercure
        fail_safe: true               # dead hub never turns a successful write into a 500
    audit:
        enabled: false                # true → audit trail (see below)
        ignored_fields: [createdAt, updatedAt, password]
        purge_retention_days: 365
    analytics:
        enabled: false                # typed events → transactional Doctrine outbox
        redaction_hmac_key: ''        # use an env secret; empty drops confidential hashes
        deduplication_capacity: 10000 # bounded per-process fast-path
        batch_size: 100
        maximum_retry_delay: 3600
        retention_days: 30
        delivery_endpoint: ''        # HTTPS webhook; empty keeps fail-closed provider
        delivery_token: ''           # use an env secret
        delivery_timeout: 5.0
        allow_insecure_http: false    # local tests only
    media:
        enabled: false                # true → media library (see below)
        storage:
            filesystem: null          # FilesystemOperator service id (e.g. S3); null → local
            local_directory: '%kernel.project_dir%/var/uploads'
        directory: media              # sub-directory inside the storage
        purge_retention_days: 30
    export:
        enabled: false                # true → "xlsx" format on every ApiResource (see below)
    oidc:
        enabled: false                # true → /api/auth/oidc/{provider}/… (see below)
        providers: {}                 # keyed by provider name
    notification:
        enabled: false                # true → NotificationDispatcherInterface (see below)
        from_address: ''              # "From" for the built-in email channel
        in_app:
            enabled: false            # true → Notification entity + GET /api/notifications
    backup:
        enabled: false                # true → pg_dump runner + nubit:tenant:backup (see below)
        storage:
            filesystem: null          # FilesystemOperator service id; null → local
            local_directory: '%kernel.project_dir%/var/backups'
        pg_dump_binary: pg_dump
        timeout_seconds: 300
    runtime_config: false             # true → GET /api/runtime-config
    soft_delete: true                 # nubit_soft_delete Doctrine filter
    single_tenant_defaults: true
```

## Analytics outbox (opt-in)

Enable `nubit_admin.analytics.enabled`, generate a Doctrine migration, and publish typed
events through `AnalyticsPublisher`. The bundle maps `nubit_analytics_outbox`; its provider
calls `persist()` but deliberately does not call `flush()`, so the event commits atomically
with the business change:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Call the publisher before the application's normal flush. Use a stable event ID. The table
has a unique constraint as the durable idempotency boundary. Payloads are sanitized before
persistence; exception text and original DTOs are never stored. Product/marketing consent
and the final delivery provider remain application extension points.

Alias `AnalyticsDeliveryProviderInterface` to the vendor adapter and route the ID-only
message asynchronously:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Nubit\AdminBundle\Analytics\Message\DeliverAnalyticsOutbox: async
```

Run `nubit:analytics:dispatch-outbox` on a short schedule. Concurrent workers lock each row;
duplicate messages become no-ops after delivery. Provider failures are committed with an
exponential next-attempt time before being rethrown to Messenger. The built-in unavailable
provider fails closed until the application replaces the interface alias.
Schedule `nubit:analytics:purge-outbox` daily to remove delivered rows past retention;
undelivered rows are never purged by this command.

Alternatively set `delivery_endpoint` to use the built-in webhook adapter. It sends a
vendor-neutral JSON envelope with a bearer token, accepts HTTPS by default, never reads or
stores provider response bodies, and reports only the HTTP status on failure. A small gateway
can translate this envelope to PostHog, Segment or an internal warehouse API.

## Audit trail (opt-in)

`audit.enabled: true` records field-level before/after diffs for entities
marked `#[Nubit\ApiPlatform\Attribute\Auditable]` (creates, updates, deletes —
captured from the Doctrine change set, written to `nubit_audit_log` in the
same request, attributed to the authenticated user). Serve them to the
`AuditTrailPanel` in `@nubitio/react-admin`:

```php
#[Auditable]                       // or #[Auditable(resource: 'products')]
#[ORM\Entity]
class Product { ... }
```

```ts
defineResource('/api/products', {
  auditTrail: { enabled: true, apiUrl: (id) => `/api/audit-trail/product/${id}` },
})
```

`GET /api/audit-trail/{resource}/{id}` returns newest-first entries in the
panel shape: `[{ id, timestamp, user, action, changes: { field: { before, after } } }]`.
Relations collapse to their id; `ignored_fields` are excluded from diffs;
collection contents are not audited. Create the table with a migration and
schedule `bin/console nubit:audit:purge`.

## Media library (opt-in)

`media.enabled: true` exposes a ready-made upload pipeline matching
`fileField()` / `imageField()` in `@nubitio/react-admin` (instant upload —
the form submits only the media IRI):

- `POST /api/media` — traditional `multipart/form-data` upload (field `file`),
  returns `{ id, path, originalName, mimeType, size }` where `path` is the
  resolved public URL.
- `GET /api/media/{id}` / `DELETE /api/media/{id}` — delete is a **soft**
  delete; files are removed later by `bin/console nubit:media:purge`
  (schedule it — instant uploads orphan files when forms are abandoned).
- `GET /api/media/{id}/file` — default streaming endpoint, works for any
  Flysystem storage behind the same `/api` firewall.

Storage is **local disk by default** (zero config). For S3 (or anything
Flysystem speaks), point `media.storage.filesystem` at a `FilesystemOperator`
service — e.g. with [oneup/flysystem-bundle](https://github.com/1up-lab/OneupFlysystemBundle):

```yaml
nubit_admin:
    media:
        enabled: true
        storage:
            filesystem: 'oneup_flysystem.default_filesystem_filesystem'
```

To serve direct S3/CDN URLs instead of streaming through PHP, implement
`Nubit\AdminBundle\Media\MediaUrlResolverInterface` and alias it in
`services.yaml`. Create the table with a migration (`doctrine:migrations:diff`
picks up `nubit_media` once enabled). Reference uploads from your entities as
a plain `ManyToOne` to `Nubit\AdminBundle\Media\Entity\Media`.

## Spreadsheet export (opt-in)

`export.enabled: true` registers `xlsx` as an API Platform **format**, which
turns it on for every `#[ApiResource]` at once — the same mechanism
`json`/`jsonld` use. No per-resource wiring, no export controller:

```bash
curl -b cookies 'https://api.example.com/api/products?_format=xlsx' -o products.xlsx
# or: -H 'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
```

The encoder serializes whatever the normal normalizer chain already produced,
so groups, `x-crud` hints and computed properties apply unchanged: a
collection becomes one row per item, an item becomes a one-row workbook.
Anything else (an empty result, a scalar) encodes as an empty workbook rather
than failing. A `Content-Disposition` filename is added automatically
(`products-2026-08-20.xlsx`) so browsers download instead of rendering bytes.

Requires **`phpoffice/phpspreadsheet` and `ext-zip`** — both are `suggest`, so
enabling the feature without them throws at container build with a message
naming what to install.

Frontend counterpart: `permissions: { canExport: true }` on `defineResource`
renders the grid's Export button, which exports every row matching the current
filters and sort (pagination dropped), not the page on screen.

For hand-built exports with column control, totals rows and cell validation,
use `Nubit\Platform\Export\XlsExporter` and friends directly instead.

## SSO / OpenID Connect (opt-in)

`oidc.enabled: true` adds an authorization-code + PKCE login against any
OpenID Connect-compliant IdP (Okta, Entra ID, Google Workspace, Auth0,
Keycloak…). Integration is by **issuer discovery**, so there is no
provider-specific SDK:

```yaml
nubit_admin:
    oidc:
        enabled: true
        providers:
            okta:
                issuer: 'https://example.okta.com'       # {issuer}/.well-known/openid-configuration must resolve
                client_id: '%env(OKTA_CLIENT_ID)%'
                client_secret: '%env(OKTA_CLIENT_SECRET)%'
                scopes: ['openid', 'email', 'profile']   # default
                redirect_uri: 'https://api.example.com/api/auth/oidc/okta/callback'
                post_login_redirect_uri: 'https://app.example.com/'
```

Two things the bundle deliberately does not decide for you:

```yaml
# config/packages/security.yaml — add the authenticator to the API firewall
firewalls:
    api:
        custom_authenticators:
            - Nubit\AdminBundle\Auth\Oidc\OidcAuthenticator

# config/services.yaml — provisioning policy is app-owned (this bundle does
# not know your User class, same as TokenClaimsProviderInterface)
Nubit\AdminBundle\Auth\Oidc\OidcUserResolverInterface:
    alias: App\Security\OidcUserResolver
```

`resolve(array $claims, OidcProviderConfig $provider): UserInterface` decides
everything policy-shaped: look up by `sub`/`email`, JIT-provision on first
login, reject unknown users, map IdP groups to roles. Throw
`OidcAuthenticationException` to refuse.

- Login starts at `GET /api/auth/oidc/{provider}/redirect` — a **top-level
  browser navigation**, not an XHR. `GET /api/auth/oidc/{provider}/callback`
  is handled by the authenticator; both routes need `PUBLIC_ACCESS`.
- `state`/`nonce`/PKCE verifier round-trip in an HMAC-signed `OIDC_FLOW`
  cookie (10 min TTL, `SameSite=Lax` — `Strict` would be dropped on the way
  back from the IdP and break every login). There is no server-side session.
- On success the callback issues the **same token pair as password login**, so
  from `GET /api/me` onward an SSO session is indistinguishable from a normal
  one. Failures redirect to `post_login_redirect_uri` with `?error=oidc_failed`
  and log the real reason — the query string never carries it.
- ID tokens are verified against the provider's JWKS by `kid` (the token
  header's `alg` is never trusted) plus `iss`, `aud`, `nonce` and `azp`: a
  multi-audience token with no `azp`, or one naming another client, is
  rejected.
- Needs `symfony/http-client` (discovery, JWKS, token exchange) and
  `symfony/cache` (caches discovery + JWKS for an hour).

## Notifications (opt-in)

`notification.enabled: true` registers a channel-agnostic dispatcher. Domain
code describes *what happened*; channels decide how it is delivered:

```php
use Nubit\Platform\Notification\Contract\NotificationDispatcherInterface;
use Nubit\Platform\Notification\NotificationMessage;

$dispatcher->dispatch(new NotificationMessage(
    recipient: $user->getUserIdentifier(),   // a plain identifier string, not a User FK
    subject: 'Invoice INV-0042 confirmed',
    body: 'The invoice was confirmed and is awaiting payment.',
    channels: ['email', 'in_app'],           // [] means every registered channel
    context: ['html' => $renderedHtml],      // channel-specific extras
));
```

Dispatch goes through **Messenger**, so a slow mail server never blocks the
request. Route `NotificationMessage` to a transport in `messenger.yaml` to
make it genuinely async — it runs synchronously otherwise.

- **`email`** — needs `symfony/mailer` and `notification.from_address`. Reads
  `context['html']` for a HTML part. The channel is skipped entirely when
  `symfony/mailer` isn't installed, so in-app-only setups don't need one.
- **`in_app`** (`notification.in_app.enabled: true`) — maps `nubit_notification`
  and exposes it as an `#[ApiResource]`: `GET /api/notifications` (`mercure: true`)
  and `PATCH /api/notifications/{id}` with `{ "read": true }`. Run
  `doctrine:migrations:diff` after enabling. Visibility is enforced by a
  Doctrine filter (`nubit_notification_recipient`) whose parameter comes from
  the authenticated token, not the request — there is no `recipient` filter to
  bypass.
- **Custom channels** — implement `NotificationChannelInterface`
  (`getIdentifier()` + `send()`); it is autoconfigured onto
  `nubit.admin.notification_channel`. Slack, SMS and push belong here.

Frontend counterpart: `useNotifications()` and `<NotificationPanel>` in
`@nubitio/admin`.

## Tenant backups (opt-in)

`backup.enabled: true` registers a PostgreSQL `TenantBackupRunnerInterface`
plus `bin/console nubit:tenant:backup <tenant> [--type=full] [--dry-run]`:

```yaml
nubit_admin:
    backup:
        enabled: true
        storage:
            filesystem: null          # FilesystemOperator service id; overrides local_directory
            local_directory: '%kernel.project_dir%/var/backups'
        pg_dump_binary: pg_dump       # must be on PATH
        timeout_seconds: 300
```

`pg_dump --format=custom`, with credentials read from the Doctrine connection
rather than re-parsed from `DATABASE_URL`, invoked through `Process` with an
argument array (never a shell string) and the password passed via `PGPASSWORD`
so it never appears in `ps aux`. Dumps are written through Flysystem, so
"local disk vs S3" is only which filesystem you point it at.

Scope is deliberately narrow: PostgreSQL only (it throws on any other driver
instead of writing a partial dump), and there is no backup-history table — the
returned `id` is a timestamp. Implement `TenantBackupRunnerInterface` yourself
for other engines or for a queryable history.

## Security audit

`bin/console nubit:security:audit` lists every POST/PUT/PATCH/DELETE operation
with no `security:` expression. Routes under `/api` already require `ROLE_USER`
via `access_control`, so an unguarded operation is not world-open — it is
reachable by *any authenticated user*, whatever their role. That is the right
default for most reads and a common accident on writes. `--strict` exits
non-zero, which makes it usable as a CI gate. Always registered; no config.

## Clients

**Web (`@nubitio/core`)** — works out of the box: login stores HttpOnly cookies; `CoreProvider` auto-refreshes via `auth/refresh`.

**Android / API** — send `response_mode: "json"` on login (or the `X-Client-Type: android` header on every auth call):

```json
POST /api/auth/login
{ "username": "user@example.com", "password": "...", "response_mode": "json" }
→ { "user": {...}, "token": "...", "refreshToken": "...", "expiresAt": 1789... }
```

Refresh with `{ "refreshToken": "..." }` in the body; send `Authorization: Bearer <token>` on every request.

## Extension points

| Hook | Purpose |
| --- | --- |
| `MeResponseBuilderInterface` | Shape `GET /api/me` (session profile for `@nubitio/react-admin`) — alias your implementation to add branch, currency, or domain context |
| `RuntimeConfigProviderInterface` | Shape `GET /api/runtime-config` (UI flags, defaults, capabilities) — alias your implementation; enable with `runtime_config: true` |
| `TokenClaimsProviderInterface` | Add claims (user id, role, branch, tenant) to JWTs and shape the login response `user` payload — alias your implementation over the default |
| `LoginResponseDecoratorInterface` | Attach extra cookies to the web login/refresh response (e.g. a Mercure subscriber JWT) — autoconfigured by interface |
| `RefreshTokenStoreInterface` | Swap the Doctrine store for Redis/other |
| `OidcUserResolverInterface` | Map verified ID token claims to an app user (lookup, JIT provisioning, role mapping) — **required** when `oidc.enabled` |
| `NotificationChannelInterface` | Extra delivery channels (Slack, SMS, push) — autoconfigured by interface |
| `TenantBackupRunnerInterface` | Replace the PostgreSQL/`pg_dump` runner for other engines or a queryable history |
| `MediaUrlResolverInterface` | Emit direct S3/CDN URLs for media instead of the streaming route |
| `GridVirtualFieldInterface` | Grid fields without ORM mapping — autoconfigured by interface |
| `Nubit\Platform` tenant/feature/quota aliases | Override for multi-tenant SaaS |

## License

MIT
