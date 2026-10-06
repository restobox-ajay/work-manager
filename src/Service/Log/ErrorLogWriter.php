<?php

declare(strict_types=1);

namespace App\Service\Log;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes Error Log rows (ADR-093). Plain DBAL rather than the EntityManager: the error being logged may have closed
 * the EntityManager, and flushing it here could save unrelated half-made changes. Logging never throws — a failure
 * to log falls back to PHP's error_log. Kept out on purpose: query strings (reset / magic-link tokens), trace
 * arguments (passwords passed to functions) and request bodies. Tokens that travel in the URL path instead (the
 * password-reset link) are blanked wherever a URL can appear: path, referrer, message, file and trace.
 */
final class ErrorLogWriter
{
    private const MAX_MESSAGE = 2000;
    private const MAX_TRACE = 12000;
    private const MAX_FRAMES = 40;
    /** URL path segments that are bearer secrets, and what they are replaced with. */
    private const SECRET_PATH_PATTERN = '#(/reset-password/)[^/?\#\s"\'<>]+#';
    private const SECRET_PLACEHOLDER = '[token]';

    private bool $writing = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function exception(\Throwable $e, string $source, ?int $statusCode = null): void
    {
        $this->write([
            'source'          => $source,
            'level'           => $statusCode !== null && $statusCode < 500 ? 'warning' : 'error',
            'status_code'     => $statusCode,
            'message'         => self::cut(self::redact($e->getMessage() !== '' ? $e->getMessage() : '(no message)'), self::MAX_MESSAGE),
            'exception_class' => self::cut($e::class, 255),
            'file'            => self::cut($e->getFile(), 500),
            'line'            => $e->getLine(),
            'trace'           => self::cut(self::redact(self::trace($e)), self::MAX_TRACE),
        ]);
    }

    /** A JavaScript error reported by a signed-in user's browser; every field is untrusted, length-capped text. */
    public function browser(string $message, ?string $source, ?int $line, ?string $stack, ?string $pageUrl): void
    {
        $this->write([
            'source'          => 'browser',
            'level'           => 'error',
            'message'         => self::cut(self::redact($message), self::MAX_MESSAGE),
            'exception_class' => null,
            'file'            => $source !== null ? self::cut(self::redact(self::withoutQuery($source)), 500) : null,
            'line'            => $line,
            'trace'           => $stack !== null ? self::cut(self::redact($stack), 4000) : null,
            // The page the error happened on (not this report's own POST).
            'method'          => null,
            'path'            => $pageUrl !== null ? self::cut(self::redact((string) (parse_url($pageUrl, \PHP_URL_PATH) ?? '/')), 500) : null,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): void
    {
        if ($this->writing) {
            return; // an error while logging an error: do not recurse
        }
        $this->writing = true;
        try {
            $request = $this->requestStack->getMainRequest();
            $user = $this->security->getUser();
            $row += [
                'method'     => $request?->getMethod(),
                'path'       => $request !== null ? self::cut(self::redact($request->getPathInfo()), 500) : null,
                'status_code' => null,
            ];
            $row['referrer'] = self::referrer($request);
            $row['user_email'] = $user instanceof User ? self::cut($user->getEmail(), 180) : null;
            $row['ip'] = $request?->getClientIp();
            $row['user_agent'] = $request !== null ? self::cut((string) $request->headers->get('User-Agent'), 255) : null;
            $row['created_at'] = time();
            $this->connection->insert('error_log', $row);
        } catch (\Throwable $failure) {
            error_log(sprintf('[error_log] could not record %s: %s', (string) ($row['message'] ?? ''), $failure->getMessage()));
        } finally {
            $this->writing = false;
        }
    }

    /** File:line and Class->method for each frame; never the arguments. */
    private static function trace(\Throwable $e): string
    {
        $lines = [];
        $current = $e;
        while ($current !== null) {
            if ($current !== $e) {
                $lines[] = sprintf('Caused by %s: %s (%s:%d)', $current::class, self::cut($current->getMessage(), 500), $current->getFile(), $current->getLine());
            }
            foreach (array_slice($current->getTrace(), 0, self::MAX_FRAMES) as $i => $frame) {
                $lines[] = sprintf('#%d %s%s%s() %s', $i, $frame['class'] ?? '', $frame['type'] ?? '', $frame['function'],
                    isset($frame['file']) ? $frame['file'].':'.($frame['line'] ?? 0) : '[internal]');
            }
            $current = $current->getPrevious();
        }

        return implode("\n", $lines);
    }

    private static function referrer(?Request $request): ?string
    {
        $referrer = $request?->headers->get('referer');

        return $referrer !== null && $referrer !== '' ? self::cut(self::redact(self::withoutQuery($referrer)), 500) : null;
    }

    private static function redact(string $text): string
    {
        return (string) preg_replace(self::SECRET_PATH_PATTERN, '$1'.self::SECRET_PLACEHOLDER, $text);
    }

    private static function withoutQuery(string $url): string
    {
        return (string) preg_replace('/[?#].*$/s', '', $url);
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
