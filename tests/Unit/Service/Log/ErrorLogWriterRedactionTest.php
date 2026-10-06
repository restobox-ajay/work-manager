<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Log;

use App\Service\Log\ErrorLogWriter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ADR-096: a password-reset link carries its token in the URL path, so the Error Log blanks it wherever a URL can
 * appear — a 5xx on the reset page must not leave a working reset link for whoever reads the log.
 */
final class ErrorLogWriterRedactionTest extends TestCase
{
    private const TOKEN = 'Xy7-secret_ResetToken123';

    /** @var array<string, mixed> */
    private array $row = [];

    public function testAServerErrorOnTheResetPageKeepsNoToken(): void
    {
        $request = Request::create('https://work.example.com/reset-password/'.self::TOKEN, 'POST');
        $request->headers->set('Referer', 'https://work.example.com/reset-password/'.self::TOKEN.'?x=1');

        $this->writer($request)->exception(new \RuntimeException('Failed for /reset-password/'.self::TOKEN), 'server', 500);

        self::assertSame('/reset-password/[token]', $this->row['path']);
        self::assertSame('https://work.example.com/reset-password/[token]', $this->row['referrer']);
        self::assertSame('Failed for /reset-password/[token]', $this->row['message']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($this->row));
    }

    public function testABrowserReportFromTheResetPageKeepsNoToken(): void
    {
        $this->writer(Request::create('https://work.example.com/logs/client-error', 'POST'))->browser(
            'TypeError at https://work.example.com/reset-password/'.self::TOKEN,
            'https://work.example.com/js/app.js',
            12,
            'at https://work.example.com/reset-password/'.self::TOKEN.':12',
            'https://work.example.com/reset-password/'.self::TOKEN.'?a=b',
        );

        self::assertSame('/reset-password/[token]', $this->row['path']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($this->row));
    }

    public function testOtherPathsAreKeptAsTheyAre(): void
    {
        $this->writer(Request::create('https://work.example.com/invoice/42/edit'))->exception(new \RuntimeException('boom'), 'server', 500);

        self::assertSame('/invoice/42/edit', $this->row['path']);
    }

    private function writer(Request $request): ErrorLogWriter
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->row = $row;

            return 1;
        });
        $requests = new RequestStack();
        $requests->push($request);

        return new ErrorLogWriter($connection, $requests, $this->createStub(Security::class));
    }
}
