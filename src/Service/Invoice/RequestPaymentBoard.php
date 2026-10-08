<?php

declare(strict_types=1);

namespace App\Service\Invoice;

use App\Entity\Client;
use App\Entity\Task;
use App\Repository\ClientRepository;
use App\Service\Task\TaskLookups;

/**
 * The Request Payment page (ADR-103): every approved task not yet on a live invoice, grouped the way an invoice
 * needs them — one client, one currency. Each group becomes one invoice. Tasks with no client cannot be invoiced
 * and are returned apart so the page can say so.
 */
final class RequestPaymentBoard
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ClientRepository $clients,
        private readonly TaskLookups $lookups,
    ) {
    }

    /**
     * @return array{groups: list<array{client: Client, currency: string, tasks: list<Task>, total: int}>, noClient: list<Task>, totals: array<string, int>}
     *         totals are per currency, in hundredths
     */
    public function build(?int $clientId, ?string $currency): array
    {
        $currencies = $this->lookups->currencies();
        $byId = [];
        foreach ($this->clients->findAll() as $client) {
            $byId[(int) $client->getId()] = $client;
        }

        $groups = [];
        $noClient = [];
        $totals = [];
        foreach ($this->invoices->invoiceableTasks($clientId, $currency) as $task) {
            $client = $task->getProject()?->getClient() ?? ($task->getClientId() !== null ? ($byId[$task->getClientId()] ?? null) : null);
            if ($client === null) {
                $noClient[] = $task;
                continue;
            }
            $code = strtoupper($currencies[$task->getCurrencyId() ?? 0] ?? 'USD');
            $key = $client->getId().'|'.$code;
            $groups[$key] ??= ['client' => $client, 'currency' => $code, 'tasks' => [], 'total' => 0];
            $groups[$key]['tasks'][] = $task;
            $amount = InvoiceMoney::toHundredths($task->getTotalAmount() ?? '0') ?? 0;
            $groups[$key]['total'] += $amount;
            $totals[$code] = ($totals[$code] ?? 0) + $amount;
        }
        uasort($groups, static fn (array $a, array $b) => [$a['client']->getName(), $a['currency']] <=> [$b['client']->getName(), $b['currency']]);

        return ['groups' => array_values($groups), 'noClient' => $noClient, 'totals' => $totals];
    }
}
