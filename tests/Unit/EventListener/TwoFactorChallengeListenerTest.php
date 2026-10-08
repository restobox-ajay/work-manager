<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Bundle\Auth2fa\EventListener\TwoFactorChallengeListener;
use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\Auth2fa\Security\TrustedDeviceManager;
use App\Bundle\Auth2fa\Security\TwoFactorGuard;
use App\Entity\User;
use App\Security\TwoFactorEnforcementResolver;
use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Hardening regression for the "2FA required but unenrolled -> force setup" gate (ported from the admin
 * realm's listener, removed by ADR-068; the one TwoFactorChallengeListener now gates every account).
 *
 * The exemption is an EXACT match on the setup route, not an `/account/2fa` prefix match: a path that merely
 * shares that prefix (a future `/account/2fa-*` route) must NOT escape the enrolment gate.
 */
final class TwoFactorChallengeListenerTest extends TestCase
{
    private function listener(string $enforcement): TwoFactorChallengeListener
    {
        $admin = new User();
        $admin->setEmail('a@example.com');
        $admin->setRoles(['ROLE_SUPER_ADMIN']);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($admin, 'user', $admin->getRoles()));

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name): string => $name === 'app_2fa_setup' ? '/account/2fa/setup' : '/x'
        );

        // Real resolver backed by an in-memory ConfigService stub. ROLE_SUPER_ADMIN's per-role key
        // drives the resolved level, so the listener path decision runs against production logic.
        $config = new class ($enforcement) extends ConfigService {
            public function __construct(private string $level) {}

            public function getString(string $key, string $default = ''): string
            {
                return $key === '2fa.enforcement.role.ROLE_SUPER_ADMIN' ? $this->level : $default;
            }
        };
        $resolver = new TwoFactorEnforcementResolver($config);

        // Unenrolled: the listener redirects to setup before ever consulting the guard.
        $settings = $this->createStub(TwoFactorSettingsRepository::class);
        $settings->method('isEnabled')->willReturn(false);
        $guard = new TwoFactorGuard($config, new TrustedDeviceManager('test-secret'), $resolver, $settings);

        return new TwoFactorChallengeListener($tokenStorage, $router, $resolver, $guard, $settings);
    }

    private function eventFor(string $path): RequestEvent
    {
        $request = Request::create($path);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    /** A path sharing the /account/2fa prefix but that is NOT the setup route must be bounced to setup. */
    public function testPrefixSharingPathIsStillForcedToSetup(): void
    {
        $event = $this->eventFor('/account/2fa-report');
        $this->listener('required')->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/account/2fa/setup', $response->getTargetUrl());
    }

    /** The setup route itself is exempt (no setup->setup loop). */
    public function testSetupRouteIsExempt(): void
    {
        $event = $this->eventFor('/account/2fa/setup');
        $this->listener('required')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /** With 2FA merely optional, an unenrolled account is never forced to set it up. */
    public function testOptionalEnforcementDoesNotForceSetup(): void
    {
        $event = $this->eventFor('/admin/dashboard');
        $this->listener('optional')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
