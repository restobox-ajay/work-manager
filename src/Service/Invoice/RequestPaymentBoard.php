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
     * One client's page (ADR-110): the clients that have approved, uninvoiced tasks (for the dropdown, with how many),
     * the chosen one ($clientId, when it has any; none until one is picked) and that client's blocks, one per currency.
     *
     * @return array{clients: list<array{client: Client, count: int}>, client: ?Client, groups: list<array{client: Client, currency: string, tasks: list<Task>, total: int}>, totals: array<string, int>, noClient: list<Task>}
     */
    public function forClient(?int $clientId): array
    {
        $all = $this->build(null, null);
        $clients = [];
        foreach ($all['groups'] as $group) {
            $id = (int) $group['client']->getId();
            $clients[$id] ??= ['client' => $group['client'], 'count' => 0];
            $clients[$id]['count'] += count($group['tasks']);
        }
        $chosen = $clientId !== null && isset($clients[$clientId]) ? $clientId : null;
        $groups = array_values(array_filter($all['groups'], static fn (array $g) => (int) $g['client']->getId() === $chosen));
        $totals = [];
        foreach ($groups as $group) {
            $totals[$group['currency']] = ($totals[$group['currency']] ?? 0) + $group['total'];
        }

        return [
            'clients'  => array_values($clients),
            'client'   => $chosen !== null ? $clients[$chosen]['client'] : null,
            'groups'   => $groups,
            'totals'   => $totals,
            'noClient' => $all['noClient'],
        ];
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
