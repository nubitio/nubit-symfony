# Security policy

## Reporting a vulnerability

Report privately through GitHub: open the **Security** tab of this repository
and choose **Report a vulnerability**. That opens a private advisory visible
only to you and the maintainers.

Please do **not** open a public issue, pull request, or discussion for a
suspected vulnerability. These packages sit under other people's production
data, and a public report is a working exploit for every deployment that has
not patched yet.

Include what you have — the affected package and version, the configuration
that reproduces it (isolation strategy, enabled modules), and the impact you
believe it has. A reproduction against a scratch project is more useful than a
description, but do not withhold a report because you could not build one.

### What to expect

- **Acknowledgement within 5 business days.** If you have not heard back,
  assume the report was missed and escalate by opening a public issue that says
  only that you filed an advisory and got no reply — no details.
- An assessment of severity and affected versions, and a fix timeline, once the
  report is confirmed.
- Credit in the advisory unless you ask otherwise.
- We ask that you hold public disclosure until a patched release exists, or 90
  days from the report, whichever comes first.

There is no bug bounty. This is a small project and we would rather be honest
about that than imply otherwise.

## Supported versions

Only the current minor line receives security fixes.

| Line | Status |
| --- | --- |
| 0.15.x | Supported |
| < 0.15 | Not supported — upgrade to 0.15.x |

These packages are pre-1.0. A minor bump may contain breaking changes, and
older lines do not receive backports. If you need a fix, the upgrade path is
forward. A longer support window will be declared with 1.0, not before —
promising one now would be a commitment without a maintenance team behind it.

The frontend counterpart, [`nubit-react`](https://github.com/nubitio/nubit-react),
versions independently; `nubit-compatibility.json` in
[`nubit-skeleton`](https://github.com/nubitio/nubit-skeleton) declares which
lines are verified against each other.

## Scope

These are the failure classes we treat as highest severity, because they are
the ones this codebase is specifically responsible for:

- **Cross-tenant data access** — any path where one tenant reads, writes, or
  infers the existence of another tenant's rows, under any of the four
  isolation strategies (column, database, PostgreSQL schema, hybrid). This
  includes leaks through relations, direct `find()` by a foreign identifier,
  result counts, and background workers that run without a session.
- **Authentication bypass** — forged or replayed JWTs, refresh-token reuse
  after revocation, TOTP bypass, password-reset token reuse, invitation or API
  key flaws.
- **Authorization bypass** — reaching an operation without its derived
  `resource.action` permission, escaping a row-level scope, or exceeding a
  per-role amount limit.
- **Injection through grid parameters** — `filter`, `sort`, `searchValue` and
  `searchExpr` arrive from the query string and reach DQL.

Out of scope: findings that require an attacker to already hold administrator
credentials on the target deployment; missing hardening headers on an
application built with these packages (that is the application's configuration,
see `nubit-skeleton`); denial of service through unbounded but authenticated
requests, unless a single request can exhaust the process; and vulnerabilities
in Symfony, Doctrine, or API Platform themselves — report those upstream.

## Applications built with these packages

A vulnerability in an application that uses `nubitio/*` is not necessarily a
vulnerability in these packages. If you believe the framework caused it — a
default that is unsafe, or a documented pattern that produces an insecure
result — it is in scope and we want to hear about it.
