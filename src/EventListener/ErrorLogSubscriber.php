<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Log\ErrorLogWriter;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records server errors in the Error Log (ADR-093): exceptions that become a 5xx response, and console command
 * failures. 4xx (not found, access denied, bad input) are expected outcomes, not errors, and are left out.
 */
final class ErrorLogSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ErrorLogWriter $writer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Low priority: run after the listeners that may turn the exception into a handled response.
        return [KernelEvents::EXCEPTION => ['onException', -128], ConsoleEvents::ERROR => ['onConsoleError', -128]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        if ($status >= 500) {
            $this->writer->exception($e, 'server', $status);
        }
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->writer->exception($event->getError(), 'console');
    }
}
