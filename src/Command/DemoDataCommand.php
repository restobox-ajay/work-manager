<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Settings\TaskStatus;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo clients, projects and tasks for trying the app out (ADR-103): four clients in USD and CAD, two projects each
 * and tasks in every common status — approved ones with amounts, so Request Payment has something to invoice.
 * Every demo client's code starts with DEMO, which is how --purge finds (only) them again. Development only: it
 * refuses to run in the prod environment.
 */
#[AsCommand(name: 'app:demo-data', description: 'Add demo clients, projects and tasks (or remove them with --purge)')]
final class DemoDataCommand extends Command
{
    private const CODE_PREFIX = 'DEMO';

    /** [code, name, company, email, city, province, country, currency, projects => [name => [[task, status, amount], …]]] */
    private const CLIENTS = [
        ['DEMO1', 'Acme Retail', 'Acme Retail Ltd.', 'accounts@acme-retail.example', 'Toronto', 'ON', 'Canada', 'CAD', [
            'Online store redesign' => [
                ['Homepage and category page design', 'approved', '850.00'],
                ['Product page templates', 'approved', '640.00'],
                ['Checkout flow UX review', 'pending', '320.00'],
                ['Brand style guide update', 'paid', '400.00'],
            ],
            'Inventory sync' => [
                ['Shopify ↔ ERP stock sync script', 'approved', '1200.00'],
                ['Nightly sync error report email', 'reviewing', '180.00'],
                ['Barcode import for warehouse', 'pending', '260.00'],
            ],
        ]],
        ['DEMO2', 'Bluewave Foods', 'Bluewave Foods Inc.', 'billing@bluewave-foods.example', 'Vancouver', 'BC', 'Canada', 'CAD', [
            'Wholesale ordering portal' => [
                ['Customer login and price lists', 'approved', '960.00'],
                ['Order PDF and email confirmation', 'approved', '420.00'],
                ['Delivery route export', 'pending', '300.00'],
            ],
            'Marketing site' => [
                ['Recipe blog setup', 'paid', '350.00'],
                ['SEO audit fixes', 'approved', '275.00'],
            ],
        ]],
        ['DEMO3', 'Nimbus Software', 'Nimbus Software LLC', 'ap@nimbus-software.example', 'Austin', 'TX', 'United States', 'USD', [
            'Customer dashboard' => [
                ['Usage charts (daily / monthly)', 'approved', '1500.00'],
                ['Team invitations and roles', 'approved', '780.00'],
                ['Dark mode', 'pending', '240.00'],
                ['Billing page with Stripe portal link', 'reviewing', '520.00'],
            ],
            'API maintenance' => [
                ['Upgrade to PHP 8.3', 'paid', '600.00'],
                ['Rate-limit headers', 'approved', '190.00'],
            ],
        ]],
        ['DEMO4', 'Harbour Dental', 'Harbour Dental Clinic', 'office@harbour-dental.example', 'Seattle', 'WA', 'United States', 'USD', [
            'Appointment booking' => [
                ['Online booking widget', 'approved', '880.00'],
                ['SMS reminders', 'pending', '340.00'],
                ['Patient intake form', 'on hold', '410.00'],
            ],
        ]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly UserRepository $users,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Remove the demo clients and everything under them (projects, tasks, their invoices) instead.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment === 'prod') {
            $io->error('Demo data is for development databases only (APP_ENV=prod).');

            return Command::FAILURE;
        }
        if ($input->getOption('purge')) {
            return $this->purge($io);
        }
        if ($this->demoClientIds() !== []) {
            $io->warning('Demo data is already there. Run with --purge first to start again.');

            return Command::SUCCESS;
        }

        $statusIds = $this->statusIds();
        $currencyIds = $this->currencyIds();
        $taskTypeId = $this->connection->fetchOne('SELECT MIN(id) FROM task_type');
        [$creator, $assignees] = $this->people();
        $now = time();
        $counts = ['clients' => 0, 'projects' => 0, 'tasks' => 0, 'approved' => 0];
        $n = 0;

        foreach (self::CLIENTS as [$code, $name, $company, $email, $city, $province, $country, $currency, $projects]) {
            $client = (new Client())->setName($name)->setCompanyName($company)->setClientCode($code)->setEmail($email)
                ->setStreetAddress1(sprintf('%d Market Street', 100 + 25 * $counts['clients']))->setCity($city)->setProvince($province)
                ->setCountry($country)->setPhone('555-01'.str_pad((string) (10 + $counts['clients']), 2, '0', \STR_PAD_LEFT))
                ->setCreatedAt($now)->setUpdatedAt($now)->setCreatedBy($creator?->getId());
            $this->em->persist($client);
            ++$counts['clients'];

            foreach ($projects as $projectName => $tasks) {
                $project = (new Project())->setClient($client)->setName($projectName)
                    ->setDescription('Demo project for '.$name.'.')->setCreatedAt($now)->setUpdatedAt($now)->setCreatedBy($creator?->getId());
                $this->em->persist($project);
                ++$counts['projects'];

                foreach ($tasks as [$taskName, $status, $amount]) {
                    $daysAgo = 3 + ($n * 5) % 40;
                    $created = $now - ($daysAgo + 10) * 86400;
                    $statusId = $statusIds[$status] ?? TaskStatus::PENDING_ID;
                    $task = (new Task())->setProject($project)->setAssignee($assignees[$n % max(1, count($assignees))] ?? null)
                        ->setName($taskName)->setDescription('Demo task.')->setTaskTypeId($taskTypeId !== false ? (int) $taskTypeId : null)
                        ->setCurrencyId($currencyIds[$currency] ?? null)->setTotalAmount($amount)->setTaskStatusId($statusId)
                        ->setIsActive(1)->setIsDeleted(0)->setCreatedAt($created)->setUpdatedAt($now)->setCreatedBy($creator?->getId())
                        ->setCreationDate((new \DateTimeImmutable())->setTimestamp($created))->setDueDate($created + 14 * 86400);
                    if (in_array($status, ['approved', 'paid'], true)) {
                        $task->setApprovedDate($now - $daysAgo * 86400)->setApprovedBy($creator?->getId());
                        $counts['approved'] += $status === 'approved' ? 1 : 0;
                    }
                    if ($status === 'paid') {
                        $task->setPaidDate($now - max(1, $daysAgo - 2) * 86400)->setPaidBy($creator?->getId());
                    }
                    $this->em->persist($task);
                    ++$counts['tasks'];
                    ++$n;
                }
            }
        }
        $this->em->flush();

        $io->success(sprintf('Added %d clients, %d projects and %d tasks (%d approved, ready under Request Payment).',
            $counts['clients'], $counts['projects'], $counts['tasks'], $counts['approved']));
        if ($assignees === []) {
            $io->note('There are no contractor accounts, so the demo tasks have no assignee.');
        }
        $io->text('Remove it all again with: php bin/console app:demo-data --purge');

        return Command::SUCCESS;
    }

    private function purge(SymfonyStyle $io): int
    {
        $clientIds = $this->demoClientIds();
        if ($clientIds === []) {
            $io->success('No demo data to remove.');

            return Command::SUCCESS;
        }
        $deleted = $this->connection->transactional(function (Connection $db) use ($clientIds): array {
            $ints = ArrayParameterType::INTEGER;
            $projectIds = $db->fetchFirstColumn('SELECT id FROM project WHERE client_id IN (?)', [$clientIds], [$ints]);
            $invoiceIds = $db->fetchFirstColumn('SELECT id FROM invoice WHERE client_id IN (?)', [$clientIds], [$ints]);
            $counts = ['invoices' => count($invoiceIds)];
            if ($invoiceIds !== []) {
                $db->executeStatement('DELETE FROM invoice_log WHERE invoice_id IN (?)', [$invoiceIds], [$ints]);
                $db->executeStatement('DELETE FROM invoice WHERE id IN (?)', [$invoiceIds], [$ints]); // items cascade
            }
            $counts['tasks'] = $projectIds === [] ? 0 : $db->executeStatement('DELETE FROM task WHERE project_id IN (?)', [$projectIds], [$ints]);
            if ($projectIds !== []) {
                $db->executeStatement('DELETE FROM project_staff WHERE project_id IN (?)', [$projectIds], [$ints]);
            }
            $counts['projects'] = $db->executeStatement('DELETE FROM project WHERE client_id IN (?)', [$clientIds], [$ints]);
            $db->executeStatement('DELETE FROM client_admin WHERE client_id IN (?)', [$clientIds], [$ints]);
            $counts['clients'] = $db->executeStatement('DELETE FROM client WHERE id IN (?)', [$clientIds], [$ints]);

            return $counts;
        });
        $io->success(sprintf('Removed %d demo clients, %d projects, %d tasks and %d invoices.',
            $deleted['clients'], $deleted['projects'], $deleted['tasks'], $deleted['invoices']));

        return Command::SUCCESS;
    }

    /** @return list<int> */
    private function demoClientIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM client WHERE client_code LIKE ?', [self::CODE_PREFIX.'%']));
    }

    /** @return array<string, int> lower-case status name => id, so this works whatever ids the database uses */
    private function statusIds(): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id, name FROM task_status') as $id => $name) {
            $key = strtolower(trim((string) $name));
            $ids[$key] = (int) $id;
            if (str_starts_with($key, 'reviewing') && !isset($ids['reviewing'])) {
                $ids['reviewing'] = (int) $id;
            }
        }

        return $ids + ['approved' => TaskStatus::APPROVED_ID, 'paid' => TaskStatus::PAID_ID, 'pending' => TaskStatus::PENDING_ID];
    }

    /** @return array<string, int> currency code => id */
    private function currencyIds(): array
    {
        $ids = [];
        foreach ($this->connection->fetchAllKeyValue('SELECT id, short_name FROM currency') as $id => $code) {
            $ids[strtoupper((string) $code)] = (int) $id;
        }

        return $ids;
    }

    /** @return array{0: ?User, 1: list<User>} who "created" the demo data (an admin), and the contractors to assign */
    private function people(): array
    {
        $creator = null;
        $assignees = [];
        foreach ($this->users->findBy([], ['id' => 'ASC']) as $user) {
            $isAdmin = array_intersect($user->getRoles(), ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT']) !== [];
            if ($isAdmin && $creator === null && !in_array('ROLE_TECH_SUPPORT', $user->getRoles(), true)) {
                $creator = $user;
            } elseif (!$isAdmin && $user->isActive()) {
                $assignees[] = $user;
            }
        }

        return [$creator, $assignees];
    }
}
