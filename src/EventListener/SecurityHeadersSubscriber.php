<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline security headers on every response (ADR-094, ADR-096). A page that sets one itself keeps its own (the
 * Password Manager sends a stricter CSP and no-referrer).
 *
 * - Anti-framing: only the app may frame its own pages (the invoice view embeds the print view), so a hostile site
 *   cannot overlay the admin pages to trick a click (clickjacking). X-Frame-Options for older browsers, CSP
 *   frame-ancestors for current ones.
 * - nosniff, and a referrer that never leaves the site (URLs can carry ids and tokens).
 * - HSTS: once a browser has seen the site over HTTPS it refuses plain http for a year, so a network attacker cannot
 *   strip TLS. Production only — a browser that pins HSTS for a dev host with a self-signed certificate cannot click
 *   past the warning — and only on responses that really went out over HTTPS (behind a proxy that needs
 *   `trusted_proxies`).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const BASELINE = [
        'X-Frame-Options'         => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'same-origin',
    ];
    private const HSTS_HEADER = 'Strict-Transport-Security';
    private const HSTS_MAX_AGE_SECONDS = 31_536_000;
    private const PRODUCTION_ENV = 'prod';

    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach (self::BASELINE as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
        if ($this->environment === self::PRODUCTION_ENV && $event->getRequest()->isSecure()) {
            $headers->set(self::HSTS_HEADER, sprintf('max-age=%d', self::HSTS_MAX_AGE_SECONDS));
        }
    }
}
