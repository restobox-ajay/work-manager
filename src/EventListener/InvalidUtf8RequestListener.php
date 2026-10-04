<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Rejects any request whose query or form input is not valid UTF-8, before the firewall (priority 8) sees it.
 *
 * MySQL compares a utf8mb4 column against a parameter holding invalid bytes by cutting the parameter at the first
 * bad byte, with only a warning (ADR-066). So `victim@example.com\xFF` would load the real account while PHP-side
 * code (throttle buckets, lockout keys, audit) treats it as a different identifier — a fresh throttle bucket per
 * variant, defeating the per-account login limit and lockout. Every form in this app is UTF-8, so such input is
 * never legitimate: refusing it here closes that gap for every login, reset, magic-link and registration path at once.
 */
#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: self::PRIORITY)]
final class InvalidUtf8RequestListener
{
    /** Above the firewall's own kernel.request listener (8), so no authenticator ever receives the input. */
    public const PRIORITY = 30;

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!self::isValidUtf8($request->query->all()) || !self::isValidUtf8($request->request->all())) {
            // A plain response, not an exception: nothing about this request is an application error worth logging.
            $event->setResponse(new Response('The request contains input that is not valid UTF-8.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
    }

    /** @param array<mixed> $parameters */
    private static function isValidUtf8(array $parameters): bool
    {
        foreach ($parameters as $key => $value) {
            if (\is_string($key) && !mb_check_encoding($key, 'UTF-8')) {
                return false;
            }
            if (\is_array($value) ? !self::isValidUtf8($value) : (\is_string($value) && !mb_check_encoding($value, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }
}
