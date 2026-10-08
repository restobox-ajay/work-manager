<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Service\Invoice\InvoiceMoney;

/**
 * The project page's task summary (ADR-107): how many tasks in each status, and — only over the tasks whose fee
 * the viewer may see — the payout per currency: in total, approved and not yet paid, and paid.
 */
final class ProjectTaskSummary
{
    /**
     * @param Task[]                                $tasks
     * @param array<int, array{canSeeFee: bool}>   $rows       TaskListService::rowDetails()
     * @param array<int, string>                    $statuses   id => name
     * @param array<int, string>                    $currencies id => code
     *
     * @return array{count: int, byStatus: array<string, int>, fees: array<string, array{total: int, owed: int, paid: int}>, hiddenFees: int}
     */
    public function summarise(array $tasks, array $rows, array $statuses, array $currencies): array
    {
        $byStatus = [];
        $fees = [];
        $hiddenFees = 0;
        foreach ($tasks as $task) {
            $status = $statuses[$task->getTaskStatusId() ?? 0] ?? 'No status';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if (!($rows[(int) $task->getId()]['canSeeFee'] ?? false)) {
                ++$hiddenFees;
                continue;
            }
            $code = strtoupper($currencies[$task->getCurrencyId() ?? 0] ?? 'USD');
            $amount = InvoiceMoney::toHundredths($task->getTotalAmount() ?? '0') ?? 0;
            $fees[$code] ??= ['total' => 0, 'owed' => 0, 'paid' => 0];
            $fees[$code]['total'] += $amount;
            if ($task->getTaskStatusId() === TaskStatus::APPROVED_ID) {
                $fees[$code]['owed'] += $amount;
            } elseif ($task->getTaskStatusId() === TaskStatus::PAID_ID) {
                $fees[$code]['paid'] += $amount;
            }
        }
        arsort($byStatus);

        return ['count' => count($tasks), 'byStatus' => $byStatus, 'fees' => $fees, 'hiddenFees' => $hiddenFees];
    }
}
