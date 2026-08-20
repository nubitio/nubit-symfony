# RFC: Nubit ERP SaaS platform capabilities

Status: proposed  
Scope: `nubit-symfony`, `nubit-react`, `nubit-skeleton`  
Implementation policy: phased, opt-in first, backward compatible until a major release

## 1. Objective

Nubit needs a coherent platform for:

- privacy-aware observability;
- product and business analytics;
- feature flags and experiments;
- durable domain events and integrations;
- audit, jobs, notifications and webhooks;
- SaaS lifecycle, metering and operational reliability.

These capabilities must share context and delivery infrastructure without sharing
vendor-specific APIs. Application code must not depend directly on an analytics,
flagging or observability vendor.

## 2. Architectural principles

1. **Structured data at boundaries.** Automatic protection is guaranteed only for
   structured attributes, records and events processed by Nubit components. Text
   embedded in arbitrary log messages, SQL, exception messages or third-party code
   cannot be reliably discovered or redacted.
2. **Deny by default for restricted data.** Unknown output sinks cannot receive
   restricted fields.
3. **One classification model, sink-specific policy.** A field is classified once;
   log, trace, analytics, audit and export policies decide how to transform it.
4. **Transactional facts before asynchronous effects.** Business transactions write
   domain state and an outbox record atomically. Delivery occurs later.
5. **Vendor-neutral ports.** OpenTelemetry and OpenFeature are integration standards;
   PostHog, flagd, Grafana, Datadog or another backend remain replaceable adapters.
6. **Tenant context is explicit.** Tenant, actor, request, correlation and causation
   identifiers travel through controlled context objects.
7. **No high-cardinality operational metrics.** Tenant and user identifiers belong in
   traces, protected logs and analytics, not unrestricted metric labels.
8. **Entitlements are not rollout flags.** A plan entitlement answers whether a tenant
   bought a capability. A feature flag selects availability or a variant. Both checks
   may be required.

## 3. Target architecture

```text
HTTP / CLI / Messenger
        │
        ▼
ExecutionContext (tenant, actor, request, trace, purpose)
        │
        ├── Domain transaction ──► PostgreSQL
        │                            └── outbox_event (same transaction)
        │
        ├── OpenTelemetry API ──► SDK ──► OTLP Collector ──► backend(s)
        │
        └── Feature evaluation ──► Nubit port ──► OpenFeature/provider

Outbox worker
   ├── analytics adapter ──► PostHog / warehouse
   ├── webhook adapter ────► customer endpoints
   ├── notification adapter ► email / in-app / push
   └── integration adapter ─► external systems
```

## 4. Sensitive-data classification

### 4.1 Classification levels

| Level | Examples | Default external policy |
| --- | --- | --- |
| `public` | product SKU, published catalog name | allow |
| `internal` | internal IDs, workflow state | allow in controlled sinks |
| `confidential` | email, phone, address, commercial totals | mask or hash |
| `restricted` | passwords, tokens, tax IDs, bank data, health data | drop |

Classification is independent from authorization. A user may be allowed to view a
bank account in the ERP while analytics and traces must still never receive it.

### 4.2 PHP attribute proposal

```php
#[SensitiveData(
    classification: DataClassification::Confidential,
    strategy: RedactionStrategy::Mask,
    purposes: [DataPurpose::Operational, DataPurpose::Audit],
)]
private string $email;

#[SensitiveData(
    classification: DataClassification::Restricted,
    strategy: RedactionStrategy::Drop,
)]
private string $accessToken;
```

Proposed targets:

- property: canonical entity/DTO classification;
- promoted constructor parameter: immutable DTO support;
- method/getter: computed values;
- class: default classification inherited by properties;
- parameter: explicit instrumentation boundaries.

Proposed strategies:

- `drop`: remove key and value;
- `redact`: replace with `[REDACTED]`;
- `mask`: retain a small non-sensitive fragment;
- `hash`: keyed HMAC for stable, non-reversible grouping;
- `tokenize`: replace through an application-owned token vault;
- `allow`: explicit exception, prohibited for secrets.

Hashing must use a rotating application key and HMAC, not raw SHA hashes. Low-entropy
values such as phone numbers are otherwise reversible by dictionary attack.

### 4.3 Transformation pipeline

```text
object / structured array
        │
ClassificationMetadataReader (attributes + explicit schema)
        │
SensitiveDataPolicy(sink, purpose, environment)
        │
DataRedactor (cycle-safe, depth/size limited, cached metadata)
        │
safe payload
        ├── log context
        ├── span attributes/events
        ├── analytics event
        ├── user-visible audit view
        └── external webhook/export
```

Required safeguards:

- recursive traversal limits and cycle detection;
- Doctrine proxy and lazy-object handling without unintended database loads;
- deterministic treatment of arrays, enums, dates and value objects;
- no invocation of arbitrary getters by default;
- classification metadata cache;
- protection against double masking;
- maximum payload and property counts;
- fail closed when metadata or transformation fails;
- a diagnostic explaining why a field was removed, without recording its value.

### 4.4 Sink policy matrix

| Sink | Public | Internal | Confidential | Restricted |
| --- | --- | --- | --- | --- |
| application logs | allow | allow | mask/hash | drop |
| traces | allow | allow if low-volume | hash/drop | drop |
| metrics labels | allow if low-cardinality | aggregate only | drop | drop |
| product analytics | allow | allowlist | hash/drop | drop |
| business analytics | allow | allowlist | purpose-specific | drop/tokenize |
| audit storage | allow | allow | encrypted or protected | encrypted vault/drop |
| user audit view | allow | permission-based | mask/permission-based | drop |
| webhooks | contract-specific | contract-specific | explicit consent | prohibited by default |

The audit system may need an encrypted authoritative record and a separately redacted
projection. Redaction must not destroy evidence required by accounting or law.

### 4.5 Frontend metadata

The browser must never receive a secret merely because it knows how to mask it. Backend
authorization and serialization remain authoritative.

Frontend field metadata may contain only presentation policy:

```ts
type SensitivePresentation = {
  classification: 'internal' | 'confidential';
  display: 'masked' | 'last4';
  copyAllowed?: boolean;
};
```

`restricted` values are omitted from API payloads. React applies presentation policy
to grids, forms, clipboard, DevTools and client analytics. It is defense in depth, not
access control.

## 5. Durable event foundation

### 5.1 Domain event envelope

```json
{
  "event_id": "uuid",
  "event_name": "sales.invoice.issued.v1",
  "occurred_at": "RFC3339 timestamp",
  "tenant_id": 42,
  "actor_id": "user-id-or-null",
  "subject_type": "invoice",
  "subject_id": "123",
  "correlation_id": "request-or-job-id",
  "causation_id": "source-event-id-or-null",
  "schema_version": 1,
  "payload": {},
  "privacy_manifest": {}
}
```

### 5.2 Outbox requirements

- written in the same Doctrine transaction as the aggregate change;
- unique event ID and idempotency key;
- lease-based concurrent claiming with recovery;
- retry policy with exponential backoff and jitter;
- dead-letter state, inspection and replay;
- per-destination delivery records;
- payload schema/version validation;
- retention and partition strategy;
- trace context propagation without sensitive baggage;
- operational metrics for queue depth, oldest age, attempts and failures.

Exactly-once external delivery is not promised. Consumers must be idempotent; the
platform provides at-least-once delivery.

## 6. Analytics

### 6.1 Separate event families

- **Product events:** navigation, feature use, funnel steps, latency perceived by user.
- **Business events:** invoice issued, payment posted, stock adjusted, period closed.
- **Operational events:** job failed, webhook retried. These normally belong in
  observability but may feed operational reporting.

Business facts originate in the backend/outbox. Frontend events are never authoritative
for revenue, accounting or inventory.

### 6.2 Contract

```php
$analytics->track(
    event: 'sales.invoice.issued.v1',
    subject: new AnalyticsSubject('invoice', '123'),
    properties: ['currency' => 'PEN', 'total_bucket' => '1000-5000'],
);
```

Required components:

- `AnalyticsTrackerInterface` and `NullAnalyticsTracker`;
- event catalog with owner, purpose, schema and retention;
- compile/test-time schema validation;
- server and browser adapters;
- consent and tenant-level opt-out;
- batching, retry and rate limiting;
- identity merge policy for anonymous/authenticated sessions;
- exposure events for experiments;
- deletion/export workflow for privacy requests.

Raw money values, free-form descriptions and customer documents are not product
analytics properties. Prefer buckets, counts and controlled enums.

### 6.3 Business intelligence

Heavy analytical queries must not run against tenant OLTP tables. Use outbox/CDC into a
warehouse or OLAP store, then a semantic metrics layer. Every metric requires:

- name and business definition;
- grain and dimensions;
- currency/time-zone policy;
- owner and freshness SLO;
- reconciliation test against operational totals;
- tenant isolation and row-level security.

## 7. Feature flags and experiments

Evaluation order:

```text
authorization
  AND plan entitlement
  AND operational feature flag
  AND domain invariant
```

Flags do not replace permissions or validation.

Requirements:

- typed values and mandatory safe default;
- tenant/user/environment evaluation context;
- OpenFeature adapter and static fallback;
- server-authoritative evaluation for security/business behavior;
- allowlisted client projection for presentation-only flags;
- evaluation reason and variant in protected telemetry;
- exposure event emitted once per subject/experiment;
- owner, expiry date and cleanup issue for every temporary flag;
- kill switches cached locally and usable during provider outage;
- no PII in flag keys or variants.

## 8. Other ERP SaaS platform capabilities

### Reliability and execution

- background jobs with progress, cancellation, retry and result artifacts;
- idempotency keys for commands and public write APIs;
- dead-letter queues and replay tooling;
- SLI/SLO definitions and alert routing;
- tenant-aware rate limits and quotas;
- backup, restore and disaster-recovery drills per isolation mode.

### Security and governance

- RBAC plus resource/field/scope policies;
- separation of duties and approval rules;
- audited support impersonation;
- API keys with scopes, expiry and rotation;
- encryption key rotation and secrets management;
- retention, legal hold, export and erasure workflows;
- software bill of materials and dependency/security scanning.

### ERP correctness

- immutable accounting entries and reversals;
- decimal money types, currencies and historical exchange rates;
- legal numbering under concurrency;
- accounting periods and locks;
- tax/localization packs with effective dates;
- company, branch, warehouse and cost-center scopes;
- master-data deduplication and merge audit;
- reconciliation and invariant checks.

### Integration and communication

- signed webhooks with retry, history and replay;
- notification preferences, templates, localization and deduplication;
- import validation, dry run and row-level error report;
- object storage, malware scanning, signed URLs and retention;
- connectors built on the same outbox delivery model.

### SaaS lifecycle

- tenant provisioning/suspension/deletion state machine;
- trials, plans, entitlements and feature flags;
- metering, usage records and billing reconciliation;
- upgrade/downgrade compatibility checks;
- tenant data portability and offboarding.

## 9. Delivery phases

### Phase 0 — governance and contracts

Deliver:

- this RFC and architecture decision records;
- data classification vocabulary and event naming rules;
- event/metric/flag ownership templates;
- privacy threat model and sink policy matrix.

Exit criteria:

- security and product owners approve vocabulary;
- no unresolved question about authoritative sources or data purposes;
- baseline inventory of current logs/events and sensitive fields.

### Phase 1 — sensitive-data kernel

Deliver:

- PHP attributes/enums, metadata reader, redactor and sink policies;
- structured logging processor integration;
- test fixtures covering nested objects, proxies, cycles and failures;
- static check preventing known secret fields from unclassified emission.

Exit criteria:

- restricted canary values never appear in test sinks;
- redaction overhead benchmarked and within agreed budget;
- failures are closed and observable without leaking values.

### Phase 2 — observability production path

Deliver:

- SDK/exporter configuration in Skeleton;
- opt-in OTLP Collector Compose profile;
- HTTP, Doctrine, Messenger and custom domain spans;
- structured logs correlated with trace/request/tenant;
- low-cardinality RED metrics and starter dashboards;
- sampling and retention defaults.

Exit criteria:

- one request is traceable browser/API/DB/worker end-to-end;
- forced errors correlate logs and traces;
- no restricted canary reaches Collector output.

### Phase 3 — outbox and event catalog

Deliver:

- Doctrine outbox entity/migration/writer;
- worker, leases, retries, DLQ and replay command;
- schema registry/catalog in repository;
- idempotent test consumer.

Exit criteria:

- rollback produces no event;
- commit produces one durable event;
- worker crash and replay do not duplicate consumer effects.

### Phase 4 — product analytics

Deliver:

- neutral tracker, null/test adapters and PostHog adapter;
- frontend provider and consent controls;
- initial catalog: login, module viewed, CRUD completed, workflow transitioned;
- identity and deletion workflows;
- experiment exposure events tied to flag variant.

Exit criteria:

- event schemas validated in CI;
- server facts reconcile with database samples;
- opt-out suppresses delivery;
- PII canary tests pass.

### Phase 5 — feature flag operations

Deliver:

- OpenFeature PHP and React adapters;
- flagd/local provider example;
- backend-to-frontend allowlisted projection;
- kill-switch cache and provider outage behavior;
- flag registry with owners and expiry enforcement.

Exit criteria:

- same tenant receives consistent server/client presentation flag;
- server remains authoritative;
- outage uses defaults without blocking requests;
- stale temporary flags fail CI policy.

### Phase 6 — webhooks, notifications and jobs

Deliver:

- destination delivery abstraction over outbox;
- webhook signatures, retries, history and replay;
- job model with progress/cancel/result;
- notification inbox/preferences/templates.

Exit criteria:

- all external effects are retryable and idempotent;
- operators can diagnose and replay failures without database edits.

### Phase 7 — warehouse and ERP analytics

Deliver:

- CDC/outbox ingestion into selected OLAP/warehouse;
- tenant isolation and semantic metrics;
- sales, margin, receivables, inventory and job-health starter models;
- reconciliation and freshness monitoring.

Exit criteria:

- certified metrics reconcile with OLTP;
- analytical load does not affect transactional SLOs;
- cross-tenant access tests fail closed.

### Phase 8 — metering and SaaS lifecycle

Deliver:

- immutable usage ledger sourced from domain events;
- quota/entitlement/flag orchestration;
- billing reconciliation;
- provisioning and offboarding workflows;
- backup/restore evidence and tenant export.

Exit criteria:

- usage is replayable and reconcilable;
- plan changes are auditable and idempotent;
- offboarding meets retention and portability policies.

## 10. Testing strategy

- unit tests for every policy and typed adapter;
- property/fuzz tests for redaction and event payload traversal;
- golden tests containing canary secrets across every sink;
- contract tests shared by all analytics and flag providers;
- integration tests for transaction rollback/outbox commit;
- chaos tests for provider, Collector and consumer outages;
- concurrency tests for leases, sequences and accounting locks;
- tenant-isolation tests for database, cache, warehouse and telemetry queries;
- performance budgets for redaction, instrumentation and event publication;
- browser tests ensuring hidden/restricted values never enter DOM, clipboard or replay.

## 11. Rollout and compatibility

- all providers begin with null/static defaults;
- instrumentation and delivery are independently switchable;
- schema changes are additive and versioned;
- deprecated event versions remain readable through the retention window;
- migrations include rollback/forward-fix instructions;
- Skeleton enables local examples only after package releases exist;
- production activation requires endpoint credentials outside committed files.

## 12. Explicit non-goals

- automatic discovery of secrets inside arbitrary text;
- using flags as authorization;
- exactly-once delivery to external systems;
- storing complete ERP documents in product analytics;
- exposing restricted fields to the browser and relying on masking;
- using OpenTelemetry as the business warehouse;
- running BI workloads on primary transactional databases.

## 13. Initial implementation sequence

The first implementation milestone should contain only Phase 1:

1. classification enums and `#[SensitiveData]`;
2. metadata reader and cache;
3. `DataRedactor` with log/trace/analytics policies;
4. Monolog integration and OpenTelemetry attribute sanitizer;
5. canary leak test suite;
6. documentation and migration examples.

The outbox follows immediately because analytics, notifications and webhooks depend on
it. Vendor adapters should not precede these foundations.
