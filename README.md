# Symfony Auth Boilerplate

A minimal, auditable authentication foundation for Symfony 7.4. Small, hardened core; optional bundles you opt into. Less code in core = less attack surface, fewer bugs, easier audits.

It ships production-ready auth out of the box — separate user/admin identities, registration, login, password reset, email verification, session management, an admin panel, a token-authenticated admin REST API, and a full audit log. Everything else — 2FA, rate limiting, password policy, magic links, impersonation, IP whitelisting, personal access tokens, webhooks — is a separate bundle.

---

## 📚 Documentation

This README is the hub. Every feature is documented function-by-function on its own page.

### Core (always present)

| Page | What's in it |
|---|---|
| **[Core Reference →](docs/core.md)** | The two-entity model, all 9 core entities, the four firewalls & access control, every auth flow (registration, login, email verification, password reset, remember-me), account & session management, the admin panel, the `/admin-api` REST API, the audit log, login history & notifications, the configuration system, every core service/listener/command, and the security model. |
| [Specification](docs/SPEC.md) | Architecture decisions, bundle contracts, data model, non-goals. |

### Bundles (optional add-ons)

Each is independent — install only what your project needs. Every page is a full function-level reference (config keys, routes, controllers, services + every public method, entities, events, security notes, tests).

| Bundle | Description |
|---|---|
| [`auth-2fa-bundle`](docs/bundles/auth-2fa-bundle.md) | TOTP two-factor auth (replay-protected) with trusted devices and IP bypass |
| [`auth-magic-link-bundle`](docs/bundles/auth-magic-link-bundle.md) | Passwordless login via emailed magic link |
| [`auth-security-bundle`](docs/bundles/auth-security-bundle.md) | Login throttling (fail-closed), endpoint rate limiting, optional account lockout |
| [`auth-password-policy-bundle`](docs/bundles/auth-password-policy-bundle.md) | Length floor/ceiling, complexity, expiry, and no-reuse rules |
| [`auth-ip-whitelist-bundle`](docs/bundles/auth-ip-whitelist-bundle.md) | Restrict login to whitelisted IPs, per-role or per-user |
| [`auth-pat-bundle`](docs/bundles/auth-pat-bundle.md) | Personal access tokens for the user-side `/api` firewall |
| [`auth-impersonate-bundle`](docs/bundles/auth-impersonate-bundle.md) | Admin impersonation of users / super-admin of admins, fully audited |
| [`auth-webhook-bundle`](docs/bundles/auth-webhook-bundle.md) | Webhooks on auth events with SSRF-guarded delivery, retry, and logging |

**Also in core:** [Htaccess Lock](docs/htaccess-lock.md) — a tech-support-only, web-server-level IP-whitelist lock managed from the admin panel (writes a managed block of `.htaccess`; Apache/LiteSpeed).

---

## Requirements

- PHP 8.3+
- **MySQL 8.0+** (InnoDB, utf8mb4; the app is MySQL-only — see `CLAUDE.md` "Platform Constraints" and ADR-066)
- Symfony 7.4
- Doctrine ORM on MySQL (every environment — see ADR-066)
- A Symfony Mailer transport

## Quick start

```bash
composer create-project your-vendor/symfony-auth-boilerplate myapp
cd myapp
# set DATABASE_URL, MAILER_DSN, and APP_SECRET in .env.local (gitignored, never committed)
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:create-superadmin --email=admin@example.com
symfony serve
```

`APP_SECRET` must be supplied via the environment (or an untracked `.env.*.local`), never committed.

### Local development (MySQL)

`.env` defaults to a local WAMP-style MySQL (`mysql://root:@127.0.0.1:3306/work_manager`); put your real
credentials in the gitignored `.env.local`. Then:

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
sudo bin/serve-auth-localhost.sh          # optional: Caddy site + /etc/hosts entry for http://auth.localhost
```

Every environment uses MySQL — dev, test, acceptance and production alike (ADR-066). See the Core Reference
for the firewall and config details.

## Background worker (Messenger)

Notification **emails** and **webhook deliveries** are handled asynchronously via Symfony Messenger
so a slow/unreachable SMTP server or webhook endpoint can never hang or 500 a login (ADR-023). They
are enqueued on a **Doctrine transport** (`messenger_messages` table, same DB) and delivered by a
worker — **you must run one, or email/webhooks will silently queue and never send:**

```bash
# long-running worker (recommended: run under systemd / supervisor and auto-restart)
php bin/console messenger:consume async -vv

# or, if you prefer cron, a bounded run every minute:
* * * * * cd /path/to/app && php bin/console messenger:consume async --time-limit=55 --quiet
```

`MESSENGER_TRANSPORT_DSN` (in `.env`) selects the transport; it defaults to
`doctrine://default?auto_setup=0` (the `messenger_messages` table is created by a migration).
Delivery retries use Messenger's `retry_strategy` (exponential backoff, see
`config/packages/messenger.yaml`); messages that exhaust their retries land in the `failed`
transport and can be inspected/retried with `messenger:failed:show` / `messenger:failed:retry`.

## Maintenance (pruning)

A single unified command reclaims expired / retention-exceeded rows across every table that has a
registered pruner — the audit log, ephemeral reset/magic-link tokens, expired rows of the `sessions`
table itself plus dead session cross-references, terminal invitations, and the webhook delivery log
(bundle-owned pruners appear only when their bundle is installed). Schedule it via cron (ADR-048) — it is
what guarantees expired sessions are deleted, since PHP's own session GC depends on `php.ini`
(`session.gc_probability` is 0 on Debian/Ubuntu):

```bash
# preview what would be deleted, then prune for real
php bin/console app:prune --dry-run
php bin/console app:prune

# limit to specific pruners
php bin/console app:prune --only=audit_log --only=invitations

# nightly cron
0 3 * * * cd /path/to/app && php bin/console app:prune --quiet
```

Retention windows are admin-configurable (`audit_log.retention_days` 90, `invitation.retention_days`
30, `webhook.delivery_retention_days` 30). `login_history` / `password_history` are retained by policy
and never pruned (ADR-020).

The Messenger **failed** queue is deliberately **operator-owned**, not a pruner: inspect with
`messenger:failed:show`, retry with `messenger:failed:retry`, and remove dead-lettered messages with
`messenger:failed:remove` on your own schedule.

## Production deployment checklist

Work through this in order. Items marked **(silent if missed)** fail without an error message —
they're the ones that bite.

**1. Environment & secrets**
- [ ] `APP_ENV=prod`, strong random `APP_SECRET` — env or untracked `.env.local` only, never committed.
- [ ] `DATABASE_URL` → the production MySQL 8 database (`mysql://user:pass@host:3306/db?serverVersion=8.0.32&charset=utf8mb4`),
      in `.env.local` or the real environment, never committed; backed up. This project is MySQL-only (ADR-066).
- [ ] `MAILER_DSN` → a real transport (password reset / verification / notifications all depend on it).
- [ ] `DEFAULT_URI`, `ADMIN_DOMAIN`, `APP_DOMAIN` set — **required**; the kernel refuses to boot
      without them, and they pin `trusted_hosts` against Host-header attacks (ADR-029).
      Single-domain deploys: set both domain vars to the same host.

**2. TLS & proxy** *(silent if missed)*
- [ ] TLS terminates at your proxy (Caddy/nginx); HSTS + security headers set there.
- [ ] Uncomment `trusted_proxies` in `config/packages/framework.yaml` and set your proxy CIDR.
      Without it the app sees plain HTTP, so session/remember-me/trusted-device cookies are
      **never marked `Secure`** and `cookie_secure: auto` silently does nothing.

**3. Database**
- [ ] `php bin/console doctrine:migrations:migrate`
- [ ] `php bin/console app:create-superadmin --email=...` (console is the only bootstrap path — no setup UI).

**4. Required background processes** *(silent if missed)*
- [ ] Messenger worker under systemd/supervisor: `messenger:consume async` — **without it, all
      email and webhooks queue forever and never send** (see [Background worker](#background-worker-messenger)).
- [ ] Nightly cron: `app:prune --quiet` (see [Maintenance](#maintenance-pruning)).
- [ ] Monitor the failed queue on your own schedule: `messenger:failed:show` / `:retry` / `:remove`.

**5. Dependencies & build**
- [ ] `composer audit` is clean (add it to CI — advisories land between releases).
- [ ] `composer install --no-dev --optimize-autoloader`, then `php bin/console cache:warmup`.

**6. Runtime config review** (`/admin/config` — defaults are safe but generic)
- [ ] Registration mode (open vs invitation-only).
- [ ] Rate limits & lockout, 2FA enforcement per role, password policy, retention windows.
- [ ] Webhook `block_internal_targets` stays **ON** unless you knowingly deliver to internal hosts.

**7. Backups & scale assumptions**
- [ ] Back up the DB and the env secrets **as one unit**. The deliberate no-TOTP-encryption decision
      (see [Security posture](#security-posture)) assumes key and data always share a backup — if you
      ever split them (managed DB snapshots, separate secret store), revisit ADR-018 first.
- [ ] Multi-host or networked-DB deployment? Revisit the documented single-host assumptions
      (ADR-013 rate-limit storage, ADR-018, ADR-030 race handling, ADR-048 no-locking pruning)
      before scaling out — they are load-bearing, not incidental.

**8. Before real users**
- [ ] Run `bin/verify-fast.sh` on the deploy artifact/branch — the full gate must be green.
- [ ] Commission an independent penetration test of the auth surface.

## Installing a bundle

```bash
composer require your-vendor/auth-2fa-bundle
php bin/console doctrine:migrations:migrate
```

The bundle auto-registers its admin config sub-page (via `ConfigPageProviderInterface` + Symfony autoconfigure) — no manual wiring. See [Configuration System](docs/core.md#configuration-system).

## Admin REST API

The admin API lives under **`/admin-api/*`** on a dedicated **stateless firewall that authenticates `Admin` entities only** (the `app_admins` provider via `AdminTokenAuthenticator`). A normal `User` — whatever roles it carries — can never reach it. Issue a token with:

```bash
php bin/console app:admin:create-api-token --email=admin@example.com   # expires in 365 days (--expires-in-days=N, --no-expiry)
# → use as:  Authorization: Bearer <token>
php bin/console app:admin:list-api-tokens [--email=…] [--active]       # id, owner, name, last 6 chars, status
php bin/console app:admin:revoke-api-token --id=12                     # or: --email=… --all
```

Every admin sees and revokes their own tokens at **API Tokens** in the panel (`/admin/api-tokens`); tech support can open
any admin's token page from **Manage Admins → API Tokens**. Only a hash is stored, so tokens are identified by their
last 6 characters. Deactivating or deleting an admin revokes its tokens for good (ADR-064).

This is distinct from [`auth-pat-bundle`](docs/bundles/auth-pat-bundle.md), which issues **user** tokens for the separate user-side `/api` firewall. Full endpoint list: [Admin REST API](docs/core.md#admin-rest-api). **Interactive docs:** admin panel → *API Docs* (any admin class), backed by the contract-tested OpenAPI spec [`docs/api/openapi.yaml`](docs/api/openapi.yaml) — a test calls every documented endpoint for real and fails if the docs and the code disagree.

## Testing

```bash
bin/verify-fast.sh          # the canonical gate: migrate test db, phpunit, codeception, anti-cheat scan
php bin/phpunit             # unit + functional only
php vendor/bin/codecept run # E2E (Codeception PhpBrowser — no JS engine required)
```

Tests run against a local MySQL 8 server (database `work_manager_test`, created and migrated by `bin/verify-fast.sh`; override credentials in `.env.test.local`). The suite is fully no-JS so E2E needs no headless browser.

---

## Architecture at a glance

- **Two fully separate identities.** `User` and `Admin` are distinct entities, tables, firewalls, providers, and login pages — no shared base class. Admin authorization is **entity/firewall based**, never a role string on a user. See [Two-Entity Model](docs/core.md#two-entity-model).
- **Four firewalls:** `admin_api` (`^/admin-api`, stateless, Admin tokens) → `admin` (`^/admin`, session) → `api` (`^/api`, user PATs) → `user` (session). Order matters; `admin_api` precedes `admin`.
- **Role single source of truth.** `User::ALLOWED_ROLES` / `Admin::ALLOWED_ROLES` enforce a default-deny allowlist in `setRoles()`, so no caller can escalate a `User` into admin space.
- **Config-page hook.** Bundles implement `ConfigPageProviderInterface`; autoconfigure tags them `auth.config_page`; the core injects all of them via `#[AutowireIterator]`. Each bundle's settings appear in the admin config UI automatically.
- **Optional separate-domain mode.** `ADMIN_DOMAIN` / `APP_DOMAIN` env vars switch the firewalls to host matchers; unset = single-domain (no change).

## Security posture

The codebase has been through a security review; notable hardening (all documented on the relevant pages):

- Admin API re-homed onto **entity-based auth** so a leaked/elevated user role can't reach it ([core](docs/core.md#admin-rest-api)).
- **Login throttling fail-closed** by default; rate limiting on forgot-password, magic-link, resend-verification, and the 2FA challenge — applied *before* CSRF so it can't be bypassed ([security bundle](docs/bundles/auth-security-bundle.md)).
- **CSRF** on every state-changing admin/account POST, including admin config save and forgot-password.
- **TOTP replay protection** (last-counter tracking) + constant-time compare ([2fa bundle](docs/bundles/auth-2fa-bundle.md)).
- **Webhook SSRF guard** — http/https only, private/reserved/loopback IPs rejected, no redirects ([webhook bundle](docs/bundles/auth-webhook-bundle.md)).
- Password reset **invalidates sibling reset tokens, active sessions, and remember-me cookies**.
- **Password policy hard floor** (min 8, enforced even with empty config) on every password-setting path including admin create.
- Session cookies pinned (`httponly`, `samesite=lax`, `secure=auto`); `APP_SECRET` kept out of version control.

> **Deliberately *not* implemented:** TOTP-secret-at-rest encryption. For this deployment shape (single host, no separate DB-backup pipeline) the key and ciphertext always share a backup, so at-rest encryption buys nothing. Revisit only on a networked DB with separately-handled backups.

## Project layout

```
src/
  Controller/        core + admin + Api/ (admin-api) controllers
  Entity/            User, Admin, Config, AuditLog, LoginHistory, UserSession,
                     Invitation, PasswordResetToken, AdminAccessToken, + bundle entities
  Security/          UserChecker, AdminTokenAuthenticator, authenticators, rate limiting
  Service/           AuditLogger, ConfigService, password policy, webhook dispatchers, TOTP
  Config/            ConfigPageProviderInterface + per-bundle config pages
  EventListener/     login history, notifications, sessions, audit, 2FA, IP whitelist, webhooks
  Command/           create-superadmin, admin:create-api-token, prune
config/packages/security.yaml   the four firewalls + access control
docs/                core.md + bundles/*.md (this documentation)
bin/verify-fast.sh   the test/lint/anti-cheat gate
```

## License

MIT
# work-manager
