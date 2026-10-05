<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\EventListener;

use App\Bundle\AuthIpWhitelist\Repository\UserIpWhitelistRepository;
use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use App\Security\IpWhitelistedAuthenticatorInterface;
use App\Service\ConfigService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Enforces the login IP whitelist (FEATURE-146 / ADR-046). Moved into auth-ip-whitelist-bundle so that with
 * the bundle unregistered there is NO IP restriction on login at all: the listener is simply not in the
 * container. The global whitelist still lives in the core `config` store (edited via the bundle-owned
 * IpWhitelistConfigPage); the per-user override now lives in the bundle's `user_ip_whitelist` satellite,
 * read here through UserIpWhitelistRepository (core `User` no longer carries it).
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 100)]
final class IpWhitelistListener
{
    private const ADMIN_IPS_KEY = 'ip_whitelist.admin_ips';
    private const USER_IPS_KEY = 'ip_whitelist.user_ips';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly RequestStack $requestStack,
        private readonly UserRepository $userRepository,
        private readonly UserIpWhitelistRepository $whitelistRepository,
    ) {}

    public function __invoke(CheckPassportEvent $event): void
    {
        // Enforce the IP whitelist for every INTERACTIVE login authenticator:
        // form login and any authenticator that opts in
        // via App\Security\IpWhitelistedAuthenticatorInterface — the passwordless
        // magic link (owned by auth-magic-link-bundle, FEATURE-140) carries that
        // marker. A magic link must not be a whitelist bypass (review C20 /
        // FEATURE-113). The marker keeps this listener decoupled from the other
        // optional bundle: with that bundle absent no class implements the marker.
        //
        // Every other authenticator is intentionally exempt:
        //  - TokenAuthenticator (API PATs): non-interactive machine credentials,
        //    out of scope for the interactive-login whitelist.
        // (Impersonation is a token swap by an already signed-in account manager, not a login.)
        $authenticator = $event->getAuthenticator();
        if (!$authenticator instanceof FormLoginAuthenticator
            && !$authenticator instanceof IpWhitelistedAuthenticatorInterface
        ) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return;
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';

        // Per-account override first; otherwise the global list for the account's tier — accounts holding an
        // admin role (ADR-068) use the admin list, everyone else the user list.
        $user = null;
        $passport = $event->getPassport();
        if ($passport->hasBadge(UserBadge::class)) {
            $user = $this->userRepository->findByEmail($passport->getBadge(UserBadge::class)->getUserIdentifier());
        }

        $override = $user instanceof User ? $this->whitelistRepository->getAllowedIps($user) : null;
        if ($override !== null && $override !== '') {
            $effectiveWhitelist = $override;
        } else {
            $isAdmin = $user instanceof User && $user->getPrimaryRole()->rank() >= Role::Admin->rank();
            $effectiveWhitelist = $this->configService->getString($isAdmin ? self::ADMIN_IPS_KEY : self::USER_IPS_KEY, '');
        }

        $this->checkIp($ip, $effectiveWhitelist);
    }

    private function checkIp(string $ip, string $whitelist): void
    {
        if ($whitelist === '') {
            return;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
        if (empty($allowed)) {
            return;
        }

        if (!IpUtils::checkIp($ip, $allowed)) {
            throw new CustomUserMessageAuthenticationException('Login from your IP address is not allowed.');
        }
    }
}
