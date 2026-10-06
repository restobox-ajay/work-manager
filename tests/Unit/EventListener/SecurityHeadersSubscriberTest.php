<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** ADR-094 / ADR-096: baseline security headers on every page; HSTS on production HTTPS responses only. */
final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testEveryPageRefusesForeignFramingAndSniffing(): void
    {
        $response = $this->dispatch('dev', 'http://work.local/invoice');

        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertSame("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testAPagesOwnStricterHeadersAreKept(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        $this->dispatch('prod', 'https://work.example.com/vault', HttpKernelInterface::MAIN_REQUEST, $response);

        self::assertSame("default-src 'none'; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testAProductionHttpsResponseCarriesHsts(): void
    {
        $response = $this->dispatch('prod', 'https://work.example.com/vault');

        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function noHsts(): iterable
    {
        yield 'plain http in production' => ['prod', 'http://work.example.com/vault'];
        yield 'dev, even over https' => ['dev', 'https://work.local/vault'];
    }

    #[DataProvider('noHsts')]
    public function testNoHstsOutsideProductionHttps(string $environment, string $uri): void
    {
        $response = $this->dispatch($environment, $uri);

        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $response = $this->dispatch('prod', 'https://work.example.com/vault', HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('Strict-Transport-Security'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
    }

    private function dispatch(string $environment, string $uri, int $requestType = HttpKernelInterface::MAIN_REQUEST, ?Response $response = null): Response
    {
        $response ??= new Response();
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create($uri), $requestType, $response);
        (new SecurityHeadersSubscriber($environment))->onResponse($event);

        return $response;
    }
}
