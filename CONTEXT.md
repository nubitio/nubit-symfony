# Domain Glossary

Vocabulary for the Nubit Symfony packages. Keep this aligned with
`nubit-react/CONTEXT.md` and the executable contracts under
`packages/api-platform/contracts/`.

## Grid query protocol

The HTTP contract between `DataGridFilter` and frontend ResourceStore adapters.
It covers sorting, filter expressions, global search, pagination and summary
response headers. Its schema and examples live in
`packages/api-platform/contracts/x-grid-protocol.json` and
`x-grid-protocol.fixtures.json`.

## CRUD hints

The `x-crud` metadata published from API Platform documentation. CRUD hints
describe presentation and interaction facts about a resource field without
putting React implementation details in PHP.

## Embedded lines

A parent-owned collection submitted with an ERP document. `#[EmbeddedLines]`
publishes the collection contract and supplies the reload route; the parent
processor owns synchronization and totals.

## Application profile

The installation shape selected by `nubit_admin.app_profile`: `internal`,
`saas`, or `hybrid`. It changes tenant defaults but never replaces operation
security as the authorization gate.

## Tenant context

The currently resolved tenant identity and isolation strategy carried through
HTTP, Doctrine, Messenger and tenant-aware commands.

## Sequence

A transaction-safe identifier allocated on first persistence, optionally
scoped by domain fields such as organization or document type.

## Workflow

A closed set of named state transitions declared on a resource and published
as documentation metadata so frontend adapters can render permitted actions.

## Audit trail

Field-level changes for an auditable resource, attributed to the active user.
Sensitive values are excluded or masked before persistence.
