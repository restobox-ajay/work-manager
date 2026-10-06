<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Log\ErrorLogWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where signed-in users' browsers report JavaScript errors (ADR-093), sent by public/js/app.js with sendBeacon.
 * Same-origin only, small bodies only, and at most MAX_PER_MINUTE per session, so it cannot be used to flood the
 * Error Log. Always answers 204: a browser has nothing to do with the result.
 */
#[IsGranted('ROLE_USER')]
final class ClientErrorController extends AbstractWorkController
{
    private const MAX_BODY = 16384;
    private const MAX_PER_MINUTE = 20;

    #[Route('/logs/client-error', name: 'app_logs_client_error', methods: ['POST'])]
    public function __invoke(Request $request, ErrorLogWriter $writer): Response
    {
        $body = $request->getContent();
        $data = strlen($body) <= self::MAX_BODY && $this->isSameOrigin($request) && $this->withinRate($request) ? json_decode($body, true) : null;
        if (is_array($data) && is_string($data['message'] ?? null) && trim($data['message']) !== '') {
            $text = static fn (string $key) => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
            $writer->browser(trim($data['message']), $text('source'), is_int($data['line'] ?? null) ? $data['line'] : null, $text('stack'), $text('url'));
        }

        return new Response(null, 204);
    }

    private function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null) {
            return $site === 'same-origin';
        }
        $origin = $request->headers->get('Origin');

        return $origin !== null && $origin === $request->getSchemeAndHttpHost();
    }

    private function withinRate(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $session = $request->getSession();
        $minute = intdiv(time(), 60);
        $seen = $session->get('client_error_rate');
        $count = is_array($seen) && ($seen[0] ?? null) === $minute ? (int) $seen[1] : 0;
        if ($count >= self::MAX_PER_MINUTE) {
            return false;
        }
        $session->set('client_error_rate', [$minute, $count + 1]);

        return true;
    }
}
