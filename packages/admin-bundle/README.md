# nubitio/admin-bundle

One-line backend for the Nubit admin stack. Install it, point [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) at your API, and you have a CRUD admin system.

```bash
composer require nubitio/admin-bundle
```

Registers automatically:

- The **API Platform bridge** from `nubitio/api-platform`: `DataGridFilter`, translated OpenAPI docs with `x-crud` hints, pagination headers, domain-exception mapping.
- **Dual JWT auth**: `POST /api/auth/login`, `/api/auth/refresh`, `/api/auth/logout`, `/api/auth/change-password`. Web clients get HttpOnly cookies; mobile/API clients get tokens in the body (`response_mode: json` or `X-Client-Type: android|ios`). Refresh tokens are rotated and stored hashed (Doctrine entity `nubit_refresh_token`); changing the password revokes every session and re-issues tokens for the current one. Purge old tokens with `bin/console nubit:auth:purge-refresh-tokens`.
- **Mercure** (`nubit_admin.mercure.enabled: true`): issues the `mercureAuthorization` subscriber-JWT cookie on login/refresh so the React grids receive live updates. Replace `MercureCookieDecorator` to scope topics per tenant/user.
- **Soft delete**: mark entities with `#[Nubit\ApiPlatform\Attribute\SoftDeletable]` and the registered Doctrine filter (`nubit_soft_delete`) hides rows whose `deleted_at` is set. Opt-in per entity by design.
- **Single-tenant defaults** for the `Nubit\Platform` contracts (registry, connection switcher, feature checker, quota enforcer) — multi-tenant apps override the aliases.
- **Autoconfiguration** for `GridVirtualFieldInterface` and `LoginResponseDecoratorInterface` implementations.

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

## Configuration (defaults shown)

```yaml
# config/packages/nubit_admin.yaml
nubit_admin:
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
    media:
        enabled: false                # true → media library (see below)
        storage:
            filesystem: null          # FilesystemOperator service id (e.g. S3); null → local
            local_directory: '%kernel.project_dir%/var/uploads'
        directory: media              # sub-directory inside the storage
        purge_retention_days: 30
    soft_delete: true                 # nubit_soft_delete Doctrine filter
    single_tenant_defaults: true
```

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
| `TokenClaimsProviderInterface` | Add claims (user id, role, branch, tenant) to JWTs and shape the login response `user` payload — alias your implementation over the default |
| `LoginResponseDecoratorInterface` | Attach extra cookies to the web login/refresh response (e.g. a Mercure subscriber JWT) — autoconfigured by interface |
| `RefreshTokenStoreInterface` | Swap the Doctrine store for Redis/other |
| `MediaUrlResolverInterface` | Emit direct S3/CDN URLs for media instead of the streaming route |
| `GridVirtualFieldInterface` | Grid fields without ORM mapping — autoconfigured by interface |
| `Nubit\Platform` tenant/feature/quota aliases | Override for multi-tenant SaaS |

## License

MIT
