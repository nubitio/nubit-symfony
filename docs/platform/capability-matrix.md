# Capability matrix

Status: living document — regenerate the counts whenever a package's test suite
changes materially.
Scope: the six packages that make up `nubit-symfony` (see [`docs/README.md`](../README.md)).

This complements — does not replace — each package's own `README.md`, which is
the install/usage reference. This document answers a different question: for a
team deciding whether to depend on a capability, or a support engineer
triaging a ticket against it, *how much verification evidence actually backs
it*, and *does anything real run it in production yet*.

## How to read this table

- **Verification evidence** is counted directly from the repository — unit
  test methods (`packages/<name>/tests/`) plus PostgreSQL-backed integration
  test methods that boot the bundle (`tests/Integration/`) — as of the commit
  this document was last regenerated against. It is a floor, not a ceiling: a
  package can be well-designed with thin tests, or heavily tested and still
  wrong about something the tests don't cover. Read the "Known gaps" column
  alongside it, not instead of it.
- **Reference consumer** records whether [`nubit-skeleton`](https://github.com/nubitio/nubit-skeleton)
  — the one application this stack is validated end-to-end against — actually
  installs and configures the package. A package with no reference consumer
  has only ever been exercised by its own test suite, never by a booted
  application.
- **Support level** is *proposed* here from the evidence in the first two
  columns, not asserted. Whether a capability is commercially "supported" is a
  product and go-to-market decision this document cannot make on its own —
  see [Support level definitions](#support-level-definitions) for what each
  proposed label is meant to signal, and treat it as a starting point for
  that decision rather than its conclusion.
- **Customer evidence** — real production usage, support ticket volume,
  renewal/expansion tied to the capability — is commercial data this
  repository has no access to. Where it matters for a support-level decision,
  it has to come from Sales/Customer Success, not from static analysis of the
  code.

## Matrix

| Package | Purpose | Reference consumer | Unit tests | Integration tests | Known gaps | Proposed support level |
| --- | --- | --- | --- | --- | --- | --- |
| `nubitio/platform` | Framework-agnostic contracts and helpers the rest of the stack builds on (tenant context/contracts, quota, export, analytics ports). | Indirect, via `admin-bundle`. | 181 | 0 dedicated (exercised indirectly through admin-bundle and tenant-bundle integration tests) | No package-level integration coverage of its own; correctness is currently only demonstrated through the bundles that depend on it. | Supported |
| `nubitio/api-platform` | API Platform bridge: grid query protocol, row-scope enforcement, document rendering, CRUD documentation hints for `@nubitio/react-admin`. | Indirect, via `admin-bundle`. | 36 | ~40 across `tests/Integration/ApiPlatform` and `tests/Integration/Authorization` | `DataGridFilter`/`GridSummaryCalculator` bounds were only hardened in #10 (grid/export at scale); no benchmark yet at the ~2M-row scale the plan in [`enterprise-readiness-plan.md`](enterprise-readiness-plan.md) calls out as still missing. | Supported |
| `nubitio/admin-bundle` | The installable admin backend: auth (JWT cookie + Bearer + API keys), identity lifecycle, permissions, media, documents, audit, exports, OIDC. | **Yes** — `nubit-skeleton`'s only direct `nubitio/*` dependency. | 176 | ~120 across `tests/Integration/Identity`, `Auth`, `Authorization`, `AdminBundle` | Hard `require` on `nubitio/tenant-bundle` even for single-tenant installs (#12); `NubitAdminBundle.php` wires every optional capability from one 1300+ line class (#13). | Supported |
| `nubitio/tenant-bundle` | Opt-in multi-tenancy: column/database/schema/hybrid isolation, tenant resolution (user, JWT claim, credential, header, subdomain), quota enforcement. | No — required transitively by `admin-bundle`, never enabled or configured by `nubit-skeleton` (which is single-tenant). | 67 | ~30 across `tests/Integration/Tenant` (all four isolation modes) | Never run by the one application this stack validates end-to-end; its own test suite is the only evidence it works. Coupling to it is exactly what #12 proposes to make optional. | Pilot |
| `nubitio/workflow-bundle` | Declarative state-machine transitions (`#[Workflow]`), published as CRUD documentation metadata; row-scoped entity loading for custom transition routes. | No. | 0 (engine, metadata and registry are tested; `WorkflowTransitionController`, `RowScopedEntityLoader`, `WorkflowRouteLoader` and the bundle's own DI wiring have no unit tests) | 0 dedicated | No integration coverage at all, and the HTTP-facing controller and row-scope loader — the highest-risk parts, by the same reasoning that justified the integration harness in [`enterprise-readiness-plan.md`](enterprise-readiness-plan.md) — are entirely unverified beyond `mago`'s static analysis. | Incubator |
| `nubitio/sequence-bundle` | Transaction-safe document numbering (`#[Sequence]`), optionally scoped by domain fields. | No. | 3 (across 4 test files; most of the bundle's own DI wiring and the allocator's concurrency behaviour are not exercised) | 0 | Thinnest test suite in the stack relative to its surface; no test demonstrates the "transaction-safe" claim under concurrent allocation, which is the property most likely to matter and least likely to show up outside a real contention scenario. | Incubator |

Unit and integration counts are point-in-time measurements taken while
authoring this document; regenerate them from `rg -c "public function test" packages/<name>/tests`
and a count of the relevant `tests/Integration/**` files before relying on
them for a decision more than a few weeks old.

## Support level definitions

These are proposed working definitions, not policy — Product/Support should
ratify or replace them.

- **Supported**: has a reference consumer exercising it in `nubit-skeleton`,
  has integration coverage (not just unit tests) of its main paths, and a
  regression here is expected to be caught before release.
- **Pilot**: works and is tested in isolation, but nothing in this stack's own
  reference application runs it — so the only thing standing behind it is its
  own test suite, not an end-to-end path a real deployment would hit.
- **Incubator**: exists and does what its README says, but the highest-risk
  paths (HTTP controllers, anything touching concurrency or authorization)
  have no automated verification beyond static analysis. Treat as
  early/unstable until that changes.

## Known gaps this matrix surfaces (tracked separately)

- #12 — remove `admin-bundle`'s hard dependency on `tenant-bundle` for
  single-tenant consumers (the reason `tenant-bundle` is "Pilot" rather than
  "Supported" despite solid test coverage: nothing forces it to be exercised
  end-to-end today).
- #13 — split `NubitAdminBundle` into per-module service loaders.
- workflow-bundle and sequence-bundle integration coverage is not yet tracked
  by an issue; adding `tests/Integration/Workflow` and
  `tests/Integration/Sequence` suites analogous to `tests/Integration/Tenant`
  would be the natural next step before either could move off "Incubator".
