<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountManagementPolicy;
use App\Security\UserChecker;
use App\Service\AuditLogger;
use App\Service\ConfigService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Starts and ends an impersonation (ADR-041, reworked by ADR-068 for the single `user` firewall).
 *
 * Start swaps the security token to the target and records `_impersonating_as` / `_impersonating_by` in the
 * session. Exit restores the impersonator by IDENTIFIER, re-loaded and re-checked from the database — never by
 * unserialising a stored token (review C18) — and fails closed (signs the browser out) when that is not
 * possible. The 2FA and password-expiry gates skip only the account `_impersonating_as` names (ADR-063).
 */
final class ImpersonationManager
{
    public const ENABLED_KEY = 'impersonate.enabled';
    public const SESSION_TARGET = '_impersonating_as';
    public const SESSION_IMPERSONATOR = '_impersonating_by';
    private const FIREWALL = 'user';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserRepository $userRepository,
        private readonly AccountManagementPolicy $policy,
        private readonly UserChecker $userChecker,
        private readonly ConfigService $config,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->getBool(self::ENABLED_KEY, true);
    }

    public function isImpersonating(SessionInterface $session): bool
    {
        return $session->has(self::SESSION_TARGET);
    }

    /**
     * @return string|null why the impersonation was refused (user-facing), or null when it started
     */
    public function start(User $impersonator, User $target, SessionInterface $session, string $ip): ?string
    {
        if (!$this->isEnabled()) {
            return 'Impersonation is disabled (Config → Impersonation).';
        }
        if ($this->isImpersonating($session)) {
            return 'Exit the current impersonation first.';
        }
        if ($impersonator->getId() === $target->getId()) {
            return 'You cannot impersonate yourself.';
        }
        if (!$this->policy->canManage($impersonator, $target)) {
            return 'You cannot impersonate this account.';
        }

        // The same check a real login runs: an inactive, locked or unverified account cannot be impersonated into
        // a live session (review C23). Refused before any marker is written (issue #16).
        try {
            $this->userChecker->checkPreAuth($target);
        } catch (AuthenticationException) {
            return 'This account cannot be impersonated (it cannot sign in right now).';
        }

        $session->set(self::SESSION_TARGET, $target->getUserIdentifier());
        $session->set(self::SESSION_IMPERSONATOR, $impersonator->getUserIdentifier());
        $this->tokenStorage->setToken(new PostAuthenticationToken($target, self::FIREWALL, $target->getRoles()));
        $this->auditLogger->log($impersonator->getUserIdentifier(), 'admin', $ip, 'admin.impersonate_start', 'success', $target->getUserIdentifier());

        return null;
    }

    /**
     * @return bool true when the impersonator was restored, false when the browser had to be signed out instead
     */
    public function exit(SessionInterface $session, string $ip): bool
    {
        $impersonatorEmail = (string) $session->get(self::SESSION_IMPERSONATOR, '');
        $targetEmail = (string) $session->get(self::SESSION_TARGET, '');
        $session->remove(self::SESSION_TARGET);
        $session->remove(self::SESSION_IMPERSONATOR);

        $this->auditLogger->log($impersonatorEmail !== '' ? $impersonatorEmail : 'unknown', 'admin', $ip, 'admin.impersonate_exit', 'success', $targetEmail);

        $impersonator = $impersonatorEmail !== '' ? $this->userRepository->findByEmail($impersonatorEmail) : null;
        if ($impersonator === null || !$this->canStillSignIn($impersonator)) {
            $this->tokenStorage->setToken(null);
            $session->invalidate();

            return false;
        }

        $this->tokenStorage->setToken(new PostAuthenticationToken($impersonator, self::FIREWALL, $impersonator->getRoles()));

        return true;
    }

    private function canStillSignIn(User $user): bool
    {
        try {
            $this->userChecker->checkPostAuth($user);
        } catch (AuthenticationException) {
            return false;
        }

        return true;
    }
}
