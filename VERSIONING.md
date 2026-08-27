# Versioning

From 1.0.0 these packages follow [semantic versioning](https://semver.org). This
document says what that promise covers, because "we follow semver" means nothing
until the surface it applies to is written down.

All `nubitio/*` packages release in lockstep from this monorepo and are split to
their read-only repositories by tag. A given version of any package is only
supported alongside the same version of the rest.

| Package | Read-only split |
| --- | --- |
| `nubitio/platform` | Domain foundation: exceptions, money, tenancy contracts, quotas |
| `nubitio/api-platform` | API Platform bridge: normalizers, Doctrine traits, OpenAPI hints |
| `nubitio/admin-bundle` | The bundle applications install |
| `nubitio/tenant-bundle` | Multi-tenancy layer |
| `nubitio/workflow-bundle` | Workflow routes and metadata |
| `nubitio/sequence-bundle` | Numbering sequences |

## What is public

- **Bundle configuration** — every key under `nubit_admin`, `nubit_tenant` and
  the other bundle roots, and its documented meaning.
- **Service ids and interfaces** you are meant to inject, decorate or replace.
  An interface is public; the class implementing it is not, unless the interface
  is the class.
- **Entities and traits applications extend or embed** — `MoneyColumns`,
  `TimestampableTrait`, the authorization entities.
- **The HTTP surface**: routes the bundles register, their request and response
  shapes, and the `x-crud` / `x-workflow` / `x-sequence` documentation keys the
  frontend reads.
- **Doctrine schema of shipped entities.** A column rename is a major, because
  applications have data in it.

## What is not

- Anything in an `Internal\` namespace or marked `@internal`.
- Concrete classes reachable only because PHP has no package-private. If you had
  to read the source to find it, it is internal.
- Anything under `tests/`.
- The exact wording of exception messages. Exception *classes* are public; their
  text is not, so do not match on it.

If you need something that is internal today, open an issue rather than
depending on it — that is the signal it should be promoted.

## What each bump means

**Patch** — bug fixes and internal refactors. No schema change, no config
change, no new required configuration.

**Minor** — new configuration keys with safe defaults, new services, new
optional entity fields with an additive migration. Existing applications keep
working without edits.

**Major** — removing or renaming public configuration, services or routes;
changing a response shape; any migration that is not additive.

Two things are deliberately *not* treated as breaking:

- **Fixing a bug so it behaves as documented**, even when output changes. Code
  that relied on the defect was relying on a defect.
- **Closing a security hole.** Access that should never have been granted being
  revoked is a fix, not a break — the 1.0 hardening scoping API keys to their
  owner is the example.

Security fixes ship in the smallest bump that carries them. See
[SECURITY.md](SECURITY.md).

## Supported versions

PHP and Symfony ranges are declared per package. Widening a range is a minor;
narrowing it is a major, since it can strand an application on an older line.

## Deprecation

Public API is deprecated for at least one minor before removal, marked
`@deprecated` with a pointer to the replacement, and triggering
`trigger_deprecation()` where a runtime signal helps. Removal follows in the
next major, never sooner.

## Release candidates

Prereleases are tagged `v1.0.0-rc.1` and split like any other tag. Composer
treats them as RC stability, so an application opts in explicitly — either with
`"nubitio/admin-bundle": "^1.0@RC"` on that one constraint, which is preferable,
or by lowering `minimum-stability` globally, which loosens the whole tree. The
API of a release candidate is not frozen: that is what the candidate period is
for.
