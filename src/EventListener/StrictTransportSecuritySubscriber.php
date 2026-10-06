<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HSTS (ADR-094): once a browser has seen the site over HTTPS it refuses plain http for a year, so a network
 * attacker cannot strip TLS and serve a fake Password Manager page that captures the master password. Production
 * only — a browser that pins HSTS for a dev host with a self-signed certificate cannot click past the warning — and
 * only on responses that really went out over HTTPS (behind a proxy that needs `trusted_proxies`).
 */
final class StrictTransportSecuritySubscriber implements EventSubscriberInterface
{
    private const HEADER = 'Strict-Transport-Security';
    private const MAX_AGE_SECONDS = 31_536_000;
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
        if ($this->environment !== self::PRODUCTION_ENV || !$event->isMainRequest() || !$event->getRequest()->isSecure()) {
            return;
        }
        $event->getResponse()->headers->set(self::HEADER, sprintf('max-age=%d', self::MAX_AGE_SECONDS));
    }
}
