<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Security;

use App\Enum\Role;
use App\Service\ConfigService;
use App\Service\WebhookDispatcherInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Login form throttling + hard account lockout. Moved into auth-security-bundle (FEATURE-144 / ADR-044):
 * it is registered — and therefore login throttling / lockout only happens — when the bundle is enabled.
 * The lockout it applies now lives in the bundle-owned `account_lockouts` satellite (keyed by user_id via
 * a DBAL upsert on email) rather than a `locked_until` column on `user`; core enforces it via the
 * {@see \App\Security\AccountLockManagerInterface} port (aliased to the bundle repository).
 */
final class LoginRateLimitListener
{
    private const REALM = 'user';

    /**
     * Fail-closed default applied when the admin has never configured a value.
     * An explicit configured value of 0 still disables throttling (ConfigService
     * returns this default only when the key is absent). Shared with
     * {@see EndpointRateLimiter} so login and the other auth endpoints behave alike.
     */
    public const DEFAULT_MAX_ATTEMPTS = EndpointRateLimiter::DEFAULT_MAX_ATTEMPTS;

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigService $configService,
        private readonly RequestStack $requestStack,
        private readonly WebhookDispatcherInterface $webhookDispatcher,
    ) {}

    /**
     * Priority 2048: ahead of CsrfProtectionListener (512) and UserCheckerListener (256), like Symfony's own
     * LoginThrottlingListener. It reads only the UserBadge identifier and never loads the user, so a throttled
     * request is refused identically whether or not the account exists (issue #25 — at 200 it ran after the
     * user lookup, and an unknown email still got "Invalid credentials": an account-existence oracle).
     */
    #[AsEventListener(event: CheckPassportEvent::class, priority: 2048)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $maxAttempts = $this->configService->getInt('rate_limit.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        if ($maxAttempts <= 0) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $windowSeconds = $this->configService->getInt('rate_limit.window_seconds', 300);
        $cutoff = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        // login_attempts keeps its realm column for history; since ADR-068 every login is a user login.
        $realm = self::REALM;

        // Per-IP check
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $ipCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND ip = ? AND attempted_at > ?',
            [$realm, $ip, $cutoff]
        );
        if ($ipCount >= $maxAttempts) {
            throw new TooManyLoginAttemptsException();
        }

        // Per-account check
        $passport = $event->getPassport();
        if ($passport->hasBadge(UserBadge::class)) {
            // The same 254-char cut onLoginFailure() records (issue #22), so count and record always agree.
            $email = mb_substr($passport->getBadge(UserBadge::class)->getUserIdentifier(), 0, 254);
            $accountCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND email = ? AND attempted_at > ?',
                [$realm, $email, $cutoff]
            );
            if ($accountCount >= $maxAttempts) {
                throw new TooManyLoginAttemptsException();
            }
        }
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getException() instanceof TooManyLoginAttemptsException) {
            return;
        }

        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $ip = $event->getRequest()->getClientIp() ?? '0.0.0.0';
        $email = $this->accountIdentifier($event);
        $realm = self::REALM;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at, realm) VALUES (?, ?, ?, ?)',
            [$ip, $email, $now, $realm]
        );

        $pruneCutoff = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'DELETE FROM login_attempts WHERE attempted_at < ?',
            [$pruneCutoff]
        );

        $this->maybeApplyLockout($email, $realm);
    }

    /**
     * The account a failure counts against: exactly the identifier the firewall authenticated with — the
     * same value onCheckPassport() counts by — never the raw form field (issue #26). FormLoginAuthenticator
     * trim()s the username before loading the user, so 'victim@x.com ' checks the real victim's password; if
     * the raw padded string were recorded here, those failures would never count toward 'victim@x.com' and
     * the INSERT … SELECT lockout would match no user — unlimited per-account guessing from rotating IPs.
     * The fallback (no passport) applies the same trim() so the two can never disagree.
     */
    private function accountIdentifier(LoginFailureEvent $event): ?string
    {
        $passport = $event->getPassport();
        if ($passport !== null && $passport->hasBadge(UserBadge::class)) {
            $identifier = $passport->getBadge(UserBadge::class)->getUserIdentifier();
        } else {
            $identifier = trim($event->getRequest()->request->getString('email'));
        }

        // login_attempts.email is VARCHAR(254); strict-mode MySQL rejects a longer value, so an over-long identifier
        // (rejected before the throttle even runs) would make the failure INSERT itself fail and the attempt go
        // uncounted (issue #22). Nothing that long is a real account, so the cut cannot merge two accounts' counts.
        return $identifier !== '' ? mb_substr($identifier, 0, 254) : null;
    }

    private function maybeApplyLockout(?string $email, string $realm): void
    {
        if ($email === null) {
            return;
        }

        // Hard account lockout is INTENTIONALLY opt-in (default 0 = off), and that is the
        // safe default — not a fail-open gap. Brute force is already throttled fail-closed by
        // the rate limiter (rate_limit.max_attempts, default 10). Because this lockout is
        // account-keyed, enabling it by default would hand anyone a username-targeted DoS:
        // spam an account's failures to keep it locked. So it stays off unless an operator
        // deliberately turns it on.
        $lockoutMax = $this->configService->getInt('lockout.max_attempts', 0);
        if ($lockoutMax === 0) {
            return;
        }

        $cutoff = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s');
        $failureCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND email = ? AND attempted_at > ?',
            [$realm, $email, $cutoff]
        );

        if ($failureCount >= $lockoutMax) {
            $durationMinutes = $this->configService->getInt('lockout.duration_minutes', 15);
            $lockedUntil = (new \DateTimeImmutable())->modify("+{$durationMinutes} minutes")->format('Y-m-d H:i:s');

            // Was the account already under a live lock? Then the write below only refreshes it (issue #15).
            $alreadyLocked = $this->connection->fetchOne(
                'SELECT 1 FROM account_lockouts l JOIN "user" u ON u.id = l.user_id WHERE u.email = ? AND l.locked_until > ?',
                [$email, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
            ) !== false;

            // Accounts holding an admin role are never hard-locked (ADR-021, kept by ADR-068): account-keyed lockout
            // is a username-targeted DoS, and locking out the people who unlock others would be self-defeating.
            // They are still rate-limited above.
            // Write the lockout into the satellite, keyed by user_id resolved from the email. The
            // INSERT ... SELECT inserts nothing when no user has that email (so a non-existent account is
            // never "locked"); ON DUPLICATE KEY (the UNIQUE user_id) refreshes an existing lockout. This mirrors how login_attempts
            // is written here (raw DBAL), so no User is hydrated mid-failure-handling.
            $written = $this->connection->executeStatement(
                'INSERT INTO account_lockouts (user_id, locked_until) '
                . 'SELECT id, ? FROM "user" WHERE email = ? AND ' . self::notAnAdminCondition()
                . ' ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)',
                [$lockedUntil, $email]
            );

            // Announce a lockout only when an account was actually locked just now (issue #15): not for an email no
            // account has (nothing written — a false alert naming any address an attacker typed), and not again for
            // every further failure while the lock is already live.
            $webhookUrl = $this->configService->getString('webhook.lockout_url', '')
                ?: $this->configService->getString('webhook.global_url', '');
            if ($webhookUrl !== '' && $written > 0 && !$alreadyLocked) {
                $this->webhookDispatcher->dispatch($webhookUrl, [
                    'event_type'   => 'lockout',
                    'actor'        => $email,
                    'timestamp'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'ip'           => $this->requestStack->getCurrentRequest()?->getClientIp() ?? '0.0.0.0',
                    'locked_until' => $lockedUntil,
                ]);
            }
        }
    }

    /** SQL condition: the `user` row holds none of the admin roles (roles is a JSON array of strings). */
    private static function notAnAdminCondition(): string
    {
        $adminRoles = array_filter(Role::cases(), static fn (Role $role): bool => $role->rank() >= Role::Admin->rank());

        return 'NOT (' . implode(' OR ', array_map(
            static fn (Role $role): string => sprintf("roles LIKE '%%\"%s\"%%'", $role->value),
            $adminRoles,
        )) . ')';
    }
}
