# Claude Code Project Rules

## Mission

Build the application described in `docs/SPEC.md`.

The project is not complete until every feature in `.agent/feature_list.json` has `"passes": true`, `bin/verify-fast.sh` passes, and the reviewer pass approves the current change.

## Working Rules

- Work on one feature at a time.
- Keep diffs small and reviewable.
- Prefer test-first development.
- Write meaningful tests for behavior, not private implementation details.
- Do not mark a feature complete unless `bin/verify-fast.sh` passes.
- Do not skip, delete, weaken, or fake tests.
- Do not touch production `.env`, production databases, deployment keys, live servers, or real customer data.
- Use local/test environment only.
- If a requirement is ambiguous, record it in `.agent/OPEN_QUESTIONS.md`.
- If an architecture/product decision is made, record it in `.agent/DECISIONS.md`.
- Use `.agent/SCRATCHPAD.md` only for the current feature. It is temporary working memory, not permanent truth.
- Append durable progress to `.agent/claude-progress.txt`.

## Platform Constraints (read before writing SQL, migrations, DB or server config)

- **Database: MySQL 8.0+ only (ADR-066)** — InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. A non-`mysql://`
  `DATABASE_URL` fails at the first connection. Real credentials go in the gitignored `.env.local`; tests use
  `work_manager_test` (`.env.test`, override in `.env.test.local`).
- **Migrations:** write MySQL DDL with the same table options doctrine.yaml's `default_table_options` give the ORM
  (`DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB`), so `doctrine:schema:validate`
  stays green. MySQL commits DDL implicitly, so migrations are non-transactional (`transactional: false`): keep
  each migration small and its `down()` a true inverse. MySQL has no `CREATE INDEX IF NOT EXISTS` — declare
  indexes inline in a guarded `CREATE TABLE IF NOT EXISTS`. Satellite tables with FKs to `user` must sort after
  the core migration (bundle namespaces > `DoctrineMigrations`).
- **Connection baseline — every MySQL connection the app opens must run it:** `SET NAMES utf8mb4`, strict
  `sql_mode` (incl. `ANSI_QUOTES`, so `"user"` is an identifier and string literals are single-quoted),
  `innodb_lock_wait_timeout = 5`, `time_zone = '+00:00'` (ADR-056/061/063). The single definition is
  `App\Doctrine\MysqlConnectionBaseline`. Doctrine connections get it via `MysqlConnectionMiddleware`; the session
  handler and the db-console gateway open their own PDO, so they get it via `MysqlPdoFactory`. Never write
  `new PDO('mysql:…')` directly. The session DSN **is** `DATABASE_URL` (parsed by `MysqlDsn`).
- **Raw SQL dialect:** `INSERT IGNORE`, `REPLACE INTO`, `INSERT … ON DUPLICATE KEY UPDATE`; read-check-write races
  need a row lock (`SELECT … FOR UPDATE`) — InnoDB has no whole-database write lock to serialise them for you.
- **Web server: LiteSpeed/Apache reading `.htaccess`. No nginx.** Anything that edits `.htaccess` (Htaccess
  Lock, ADR-059) must be Apache-2.4-compatible `Require` syntax; nginx/Caddy ignore it.
- **API docs are contract-tested.** `docs/api/openapi.yaml` is held to the real routes and responses by
  `OpenApiSpecTest`, `OpenApiContractTest` and `ApiDocsCest` (ADR-060). Add or change an `/admin-api` or `/api`
  endpoint and you must update the spec and add its scenario in the same change, or the build is red.
  Endpoints only tech support may call carry `x-audience: tech-support` (and `#[IsGranted('ROLE_TECH_SUPPORT')]`);
  they are hidden from every other admin's docs, and a test ties the marker to the real role.
- **One gate for the Htaccess Lock (ADR-062).** All reads/changes of the lock — admin page, API, console — go
  through `App\Htaccess\HtaccessLockGate` (validation, lockout guard, file write, audit, change event happen
  only there). Never inject `HtaccessLockManager`/`HtaccessFile`/the validator outside `src/Htaccess/`, never
  write `admin.htaccess_lock_*` audit rows by hand; `HtaccessLockGateArchitectureTest` enforces it. New lock
  capability = a new gate method, then thin adapters.

## Test Strategy

<!-- Customize this section for your stack. Examples below are for a generic web app. -->

### Unit tests

Use unit tests for:
- pure services and utilities
- validators and value objects
- permission rules
- business calculations
- data transformation

### Integration / Functional tests

Use integration tests for:
- routes and controllers
- form handling
- security and permissions
- service integration
- database-backed workflows

### Acceptance / E2E tests

Use acceptance tests for real user workflows:
- login / auth flows
- create/edit/delete important records
- permissions
- major forms and error states

## Anti-Cheat Rules

Forbidden:
- `test.skip`
- `it.skip`
- `describe.skip`
- `markTestSkipped`
- `xit(` / `xdescribe(`
- returning early from tests to hide failure
- tests with no real assertions
- deleting features to make tests pass
- changing test expectations to match broken behavior
- placeholder controllers/views that do not implement the spec
- fake "success" output without real verification

## Verification

Use:

```bash
bin/verify-fast.sh
```

Definition of done for a feature:

1. Tests were added or updated.
2. `bin/verify-fast.sh` passes.
3. Reviewer pass returns `REVIEW_PASS`.
4. `.agent/feature_list.json` is updated with evidence.
5. `.agent/claude-progress.txt` is appended.
6. The work is committed.

## Coding Standards & Engineering Rules

Work as a senior engineer: code must be clean, maintainable, reusable, testable, secure and production-ready —
not merely "working". Priority order when principles conflict: **Correctness → Security → Maintainability →
Simplicity → Performance**; explain the trade-off before any major architectural decision.

1. **Understand before coding.** Inspect the related controllers, services, entities, repositories, config,
   routes, migrations and tests first. Reuse what exists; no duplicate functionality; no unnecessary
   architectural changes. Explain the approach briefly before a large change.
2. **Clean code.** SOLID, DRY, KISS, separation of concerns, single responsibility, composition over
   inheritance. Small focused methods, shallow nesting, no oversized classes/controllers, no needless abstraction.
3. **No repetition.** Search for similar functionality before writing a method; extract logic that repeats
   into a reusable component — but don't abstract just to save a few lines.
4. **Constants.** No magic numbers/strings for meaningful values: use class constants, enums, or config.
   Don't create constants for truly local, obvious values.
5. **Interfaces** only where they add a real abstraction (multiple implementations, external integrations,
   replaceable dependencies). No one-implementation `FooServiceInterface`.
6. **Responsibilities.** Thin controllers (validate input → call a service → return a response); business
   logic in services/domain classes. No god classes or grab-bag utility classes.
7. **Dependency injection** via the Symfony container and constructor injection; don't `new` injectable services.
8. **Configuration.** Never hard-code URLs, keys, passwords, credentials, environment paths/settings or
   changeable business config — use env vars, config files or Symfony secrets. Never commit secrets.
9. **Database.** Parameterized, efficient, ORM/QueryBuilder-consistent queries in repositories (not
   controllers). Avoid N+1, fetch only needed columns, consider indexes, reuse existing repository methods.
10. **Validation & errors.** Validate external input; handle expected failures explicitly; never swallow
    exceptions or use broad `catch (\Exception)` without a clear reason. Log safely; user-facing errors are
    safe and meaningful.
11. **Security.** Always consider authN/authZ, input validation, SQL injection, XSS, CSRF, mass assignment,
    uploads, path traversal, SSRF, data exposure, API auth, rate limiting. Never disable a security mechanism
    to make something work; flag a risky request before implementing it.
12. **Logging.** Enough context to diagnose; never passwords, API keys, tokens, card data or sensitive PII.
13. **External integrations** live in a dedicated client/service layer with timeouts, error handling,
    retries where appropriate, logging, response validation and secure auth.
14. **Tests.** Check existing tests first; add/update tests for success, validation failure, edge and error
    cases. Change a test expectation only when the expectation itself is wrong.
15. **Backward compatibility.** Find callers and API contracts before changing behaviour; explain any
    breaking change.
16. **Naming.** Descriptive names (`$paymentResponse`, not `$data`/`$tmp`/`$x`), per Symfony/PSR conventions.
17. **Comments** explain WHY, never restate WHAT.
18. **Refactoring.** Don't rewrite whole files; preserve working behaviour; don't mix unrelated refactors
    into a feature — report notable tech debt separately.
19. **Symfony conventions.** DI, proper services, Symfony components over reinvention; DTOs, validators,
    voters, Messenger, events where appropriate. Don't import other frameworks' patterns.
20. **Size & complexity.** Split classes with several responsibilities, but don't shatter code into dozens
    of tiny classes for appearance.
21. **Performance.** Correct and maintainable first; still catch N+1s, repeated expensive calls, large
    in-memory datasets, missing pagination; use caching/queues/batching where warranted.
22. **Git-friendly diffs.** Logical units; no unrelated formatting, mass rewrites or project-wide renames
    unless asked. Diffs must be easy to review.
23. **Process for substantial changes:** inspect → explain what must change → list files → smallest
    appropriate change → review → check duplication/security/edge cases/regressions → run tests/linters →
    summarise exactly what changed. Don't touch unrelated files.
24. **Self-review before calling a task done:** right layer and separation? duplication, magic values,
    unclear names, oversized methods? input validated, permissions checked, secrets protected? efficient
    queries, no N+1, transactions where needed? tests present for edge cases? consistent and understandable?
25. **Simplest sufficient architecture** — correct, maintainable, testable, able to grow, framework-
    conventional. Never pick complexity because it looks "enterprise".
