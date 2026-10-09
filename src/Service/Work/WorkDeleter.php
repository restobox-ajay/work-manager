<?php

declare(strict_types=1);

namespace App\Service\Work;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\User;
use App\Service\WorkAuditTrail;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanent deletion of a client or a project with everything under it (ADR-109, owner's choice): projects, tasks,
 * their notes and priority rows, and — for a client — the invoices that were never emailed.
 * Invoices already sent are financial records someone has received: they stay, unlinked from the deleted client
 * (they keep the billed-to name printed on them). One transaction; audited with what was removed.
 */
final class WorkDeleter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly WorkAuditTrail $audit,
    ) {
    }

    /** @return array{projects: int, tasks: int, notes: int, unsentInvoices: int, sentInvoices: int} what deleteClient() would touch */
    public function clientImpact(Client $client): array
    {
        $id = (int) $client->getId();
        $projectIds = $this->projectIds($id);
        $taskIds = $this->clientTaskIds($id, $projectIds);
        [$unsent, $sent] = $this->invoiceIds($id);

        return [
            'projects'       => count($projectIds),
            'tasks'          => count($taskIds),
            'notes'          => $this->countNotes([$id], $projectIds, $taskIds),
            'unsentInvoices' => count($unsent),
            'sentInvoices'   => count($sent),
        ];
    }

    /** @return array{tasks: int, notes: int} what deleteProject() would touch */
    public function projectImpact(Project $project): array
    {
        $taskIds = $this->projectTaskIds([(int) $project->getId()]);

        return ['tasks' => count($taskIds), 'notes' => $this->countNotes([], [(int) $project->getId()], $taskIds)];
    }

    /** @return array{projects: int, tasks: int, notes: int, unsentInvoices: int, sentInvoices: int} */
    public function deleteClient(Client $client, User $actor): array
    {
        $id = (int) $client->getId();
        $label = sprintf('#%d %s', $id, $client->getName());
        $impact = $this->clientImpact($client);
        $this->em->clear(); // the rows go behind the ORM's back: drop what it holds
        $this->connection->transactional(function (Connection $db) use ($id): void {
            $ints = ArrayParameterType::INTEGER;
            $projectIds = $this->projectIds($id);
            $this->removeTasks($db, $this->clientTaskIds($id, $projectIds));
            if ($projectIds !== []) {
                $db->executeStatement('DELETE FROM note WHERE project_id IN (?)', [$projectIds], [$ints]);
                $db->executeStatement('DELETE FROM project WHERE id IN (?)', [$projectIds], [$ints]);
            }
            [$unsent, $sent] = $this->invoiceIds($id);
            if ($unsent !== []) {
                $db->executeStatement('DELETE FROM invoice_log WHERE invoice_id IN (?)', [$unsent], [$ints]);
                $db->executeStatement('DELETE FROM invoice WHERE id IN (?)', [$unsent], [$ints]); // items cascade
            }
            if ($sent !== []) {
                $db->executeStatement('UPDATE invoice SET client_id = NULL WHERE id IN (?)', [$sent], [$ints]);
            }
            $db->executeStatement('DELETE FROM note WHERE client_id = ?', [$id]);
            $db->executeStatement('DELETE FROM client WHERE id = ?', [$id]);
        });
        $this->audit->record($actor, 'client.delete', sprintf('%s permanently deleted: %d projects, %d tasks, %d notes, %d unsent invoices (%d sent invoices kept)',
            $label, $impact['projects'], $impact['tasks'], $impact['notes'], $impact['unsentInvoices'], $impact['sentInvoices']));

        return $impact;
    }

    /** @return array{tasks: int, notes: int} */
    public function deleteProject(Project $project, User $actor): array
    {
        $id = (int) $project->getId();
        $label = sprintf('#%d %s (client %s)', $id, $project->getName(), $project->getClient()?->getName());
        $impact = $this->projectImpact($project);
        $this->em->clear();
        $this->connection->transactional(function (Connection $db) use ($id): void {
            $this->removeTasks($db, $this->projectTaskIds([$id]));
            $db->executeStatement('DELETE FROM note WHERE project_id = ?', [$id]);
            $db->executeStatement('DELETE FROM project WHERE id = ?', [$id]);
        });
        $this->audit->record($actor, 'project.delete', sprintf('%s permanently deleted: %d tasks, %d notes', $label, $impact['tasks'], $impact['notes']));

        return $impact;
    }

    /** @param list<int> $taskIds */
    private function removeTasks(Connection $db, array $taskIds): void
    {
        if ($taskIds === []) {
            return;
        }
        $ints = ArrayParameterType::INTEGER;
        foreach (['note', 'task_priority_order'] as $table) {
            $db->executeStatement(sprintf('DELETE FROM %s WHERE task_id IN (?)', $table), [$taskIds], [$ints]);
        }
        // Invoice lines keep their name and amount; only their link to the task goes.
        $db->executeStatement('UPDATE invoice_item SET task_id = NULL WHERE task_id IN (?)', [$taskIds], [$ints]);
        $db->executeStatement('DELETE FROM task WHERE id IN (?)', [$taskIds], [$ints]);
    }

    /** @return list<int> */
    private function projectIds(int $clientId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM project WHERE client_id = ?', [$clientId]));
    }

    /** @param list<int> $projectIds @return list<int> */
    private function projectTaskIds(array $projectIds): array
    {
        return $projectIds === [] ? [] : array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM task WHERE project_id IN (?)', [$projectIds], [ArrayParameterType::INTEGER]));
    }

    /** A client's tasks: those of its projects and those linked to it directly. @param list<int> $projectIds @return list<int> */
    private function clientTaskIds(int $clientId, array $projectIds): array
    {
        $direct = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM task WHERE client_id = ?', [$clientId]));

        return array_values(array_unique([...$this->projectTaskIds($projectIds), ...$direct]));
    }

    /** @return array{0: list<int>, 1: list<int>} [never emailed, emailed] invoice ids of a client */
    private function invoiceIds(int $clientId): array
    {
        $unsent = [];
        $sent = [];
        foreach ($this->em->getRepository(Invoice::class)->findBy(['clientId' => $clientId]) as $invoice) {
            $invoice->isSent() ? $sent[] = (int) $invoice->getId() : $unsent[] = (int) $invoice->getId();
        }

        return [$unsent, $sent];
    }

    /** @param list<int> $clientIds @param list<int> $projectIds @param list<int> $taskIds */
    private function countNotes(array $clientIds, array $projectIds, array $taskIds): int
    {
        $or = [];
        $params = [];
        $types = [];
        foreach (['client_id' => $clientIds, 'project_id' => $projectIds, 'task_id' => $taskIds] as $column => $ids) {
            if ($ids !== []) {
                $or[] = $column.' IN (?)';
                $params[] = $ids;
                $types[] = ArrayParameterType::INTEGER;
            }
        }

        return $or === [] ? 0 : (int) $this->connection->fetchOne('SELECT COUNT(*) FROM note WHERE '.implode(' OR ', $or), $params, $types);
    }
}
