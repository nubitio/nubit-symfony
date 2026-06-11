# nubitio/admin-bundle

One-line backend for the Nubit admin stack. Install it, point [`@nubitio/react-admin`](https://www.npmjs.com/package/@nubitio/react-admin) at your API, and you have a CRUD admin system.

```bash
composer require nubitio/admin-bundle
```

Registers automatically:

- The **API Platform bridge** from `nubitio/api-platform`: `DataGridFilter`, translated OpenAPI docs with `x-crud` hints, pagination headers, domain-exception mapping.
- **Dual JWT auth**: `POST /api/auth/login`, `/api/auth/refresh`, `/api/auth/logout`. Web clients get HttpOnly cookies; mobile/API clients get tokens in the body (`response_mode: json` or `X-Client-Type: android|ios`). Refresh tokens are rotated and stored hashed (Doctrine entity `nubit_refresh_token`).
- **Single-tenant defaults** for the `Nubit\Platform` contracts (registry, connection switcher, feature checker, quota enforcer) — multi-tenant apps override the aliases.
- **Autoconfiguration** for `GridVirtualFieldInterface` and `LoginResponseDecoratorInterface` implementations.

## Setup

1. Import the routes (`config/routes/nubit_admin.yaml`):

```yaml
nubit_admin:
    resource: '@NubitAdminBundle/config/routes.php'
```

2. Wire the firewall (`config/packages/security.yaml`) — the bundle cannot define firewalls for you:

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
    single_tenant_defaults: true
```

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
| `GridVirtualFieldInterface` | Grid fields without ORM mapping — autoconfigured by interface |
| `Nubit\Platform` tenant/feature/quota aliases | Override for multi-tenant SaaS |

## License

MIT
