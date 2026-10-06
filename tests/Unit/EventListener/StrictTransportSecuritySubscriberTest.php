<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\StrictTransportSecuritySubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** ADR-094: HSTS goes out on production HTTPS responses only. */
final class StrictTransportSecuritySubscriberTest extends TestCase
{
    public function testAProductionHttpsResponseCarriesHsts(): void
    {
        $response = $this->dispatch('prod', 'https://work.example.com/vault');

        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function noHsts(): iterable
    {
        yield 'plain http in production' => ['prod', 'http://work.example.com/vault', HttpKernelInterface::MAIN_REQUEST];
        yield 'dev, even over https' => ['dev', 'https://work.local/vault', HttpKernelInterface::MAIN_REQUEST];
        yield 'a sub-request' => ['prod', 'https://work.example.com/vault', HttpKernelInterface::SUB_REQUEST];
    }

    #[DataProvider('noHsts')]
    public function testNoHstsOutsideProductionHttpsMainRequests(string $environment, string $uri, int $requestType): void
    {
        $response = $this->dispatch($environment, $uri, $requestType);

        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    private function dispatch(string $environment, string $uri, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = new Response();
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create($uri), $requestType, $response);
        (new StrictTransportSecuritySubscriber($environment))->onResponse($event);

        return $response;
    }
}
