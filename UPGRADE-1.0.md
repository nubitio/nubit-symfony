# Upgrading to 1.0

From `0.15.x`. No class, interface or method was removed or renamed in this
range, so **no application code has to change**. What does change is one
database schema and one storage default, and both are silent if you skip them.

Everything else new in 1.0 — identity, authorization, queued exports — is a
module that ships off. Turning one on is its own decision, covered at the
bottom.

## 1. Migrate `nubit_refresh_token` (required)

1.0 records where a refresh token was issued and when it was last used, so a
person can review their own active sessions and spot one they do not recognise.
That is three nullable columns:

```sql
ALTER TABLE nubit_refresh_token ADD user_agent VARCHAR(255) DEFAULT NULL;
ALTER TABLE nubit_refresh_token ADD ip_address VARCHAR(45) DEFAULT NULL;
ALTER TABLE nubit_refresh_token ADD last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
```

Generate it the usual way rather than pasting the SQL, so the migration matches
your platform:

```console
$ php bin/console doctrine:migrations:diff --no-interaction
$ php bin/console doctrine:migrations:migrate --no-interaction
```

**Skipping this breaks login, and it does not look like a schema problem.**
Issuing a token pair throws inside the authenticator, which catches it and
answers `401` with `An authentication exception occurred.` — indistinguishable
from a wrong password. If login starts failing for everyone right after the
upgrade, this is why.

## 2. Decide about `enforce_utc` (on by default)

A `datetime` column carries no timezone, so what lands in it is whatever
wall-clock reading the PHP object happened to have. Doctrine's stock
`datetime_immutable` type both writes and reads in the server's local zone, so
two deployments of the same application disagree about what a stored instant
was, and nothing in the data says which is which.

1.0 replaces that type with one that converts to UTC on write and back on read.
It is on by default:

```yaml
nubit_admin:
    time:
        enforce_utc: true      # default
        default_timezone: UTC  # how instants are *presented*, not stored
```

**If your PHP default timezone is already UTC, this changes nothing** — the
conversion is a no-op and you can stop here. Check with
`php -i | grep date.timezone`, and remember the container's zone is the one that
counts, not your laptop's.

**If it is not UTC, read this paragraph twice.** Existing rows were written in
local time and 1.0 will read them back as if they were UTC, shifting every
historical timestamp by your offset. The columns themselves are correct going
forward; it is the existing data that now means something different. You have
two options:

- Set `enforce_utc: false`, keep the old behaviour, and change nothing. This is
  the safe move if you are upgrading a live database and are not ready to
  convert it.
- Convert the stored values to UTC once, then leave `enforce_utc` on. On
  PostgreSQL that is `AT TIME ZONE`, per column, and it is worth doing on a
  restored copy first.

There is no third option where you turn it on and the old rows quietly stay
right.

## 3. Routes of a module that is off now answer 404

Routes for optional modules live in one file so applications do not import them
per feature. Previously, hitting one while its module was disabled reached a
controller that was not registered as a service and returned `500`. It now
returns `404`.

Nothing to do — but if a monitor was treating those `500`s as the signal that a
module is off, it needs to look for `404` instead.

## 4. Optional modules

All of these default to `enabled: false`. Leave them off and 1.0 behaves like
`0.15.x` did.

| Module | Config | Adds tables |
| --- | --- | --- |
| Identity — TOTP, password reset, invitations, API keys, sessions | `nubit_admin.identity` | yes |
| Authorization — `resource.action` permissions, row scoping | `nubit_admin.authorization` | yes |
| Queued exports | `nubit_admin.export.queued` | yes |

Each one that adds tables needs a migration when you enable it;
`doctrine:migrations:diff` writes it. Enabling `identity` also means listing its
authenticator in the firewall — see the config reference the bundle generates.

`nubit_admin.authorization.enforce_by_default` is `true` when the module is on:
every operation without an explicit `security:` expression gets the permission it
implies. Turning it off leaves the catalogue advisory, which is not what you
want in production.

## Composer

While 1.0 is in release candidate:

```json
{
    "minimum-stability": "RC",
    "prefer-stable": true,
    "require": {
        "nubitio/admin-bundle": "^1.0@RC"
    }
}
```

`minimum-stability` has to be relaxed because the bundle pulls in sibling
`nubitio/*` packages that are RC too; `prefer-stable` keeps the rest of the tree
on stable releases. Both revert to `"stable"` and `"^1.0"` once 1.0.0 ships.

See [VERSIONING.md](VERSIONING.md) for what the 1.0 semver promise covers.
