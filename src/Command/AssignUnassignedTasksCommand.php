<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\WorkAuditTrail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off (ADR-114): makes one person the assignee of every task that has none — for a one-person setup, where
 * every task is that person's. Tasks that already have an assignee are left alone, unless --all is given (then every
 * task becomes theirs). --dry-run only counts.
 */
#[AsCommand(name: 'app:tasks:assign-unassigned', description: 'Assign every task without an assignee (or, with --all, every task) to one user (by email)')]
final class AssignUnassignedTasksCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly WorkAuditTrail $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The account to assign the tasks to')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every task, including those assigned to someone else')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only say how many tasks would be assigned');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneBy(['email' => (string) $input->getArgument('email')]);
        if ($user === null) {
            $io->error('No account with that email.');

            return Command::FAILURE;
        }
        $all = (bool) $input->getOption('all');
        $where = $all ? 'user_id IS NULL OR user_id <> ?' : 'user_id IS NULL';
        $params = $all ? [$user->getId()] : [];
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM task WHERE '.$where, $params);
        if ($input->getOption('dry-run')) {
            $io->note(sprintf('%d task(s) %s would be assigned to %s.', $count, $all ? 'not yet theirs' : 'without an assignee', $user->getEmail()));

            return Command::SUCCESS;
        }
        $done = $this->connection->executeStatement('UPDATE task SET user_id = ?, updated_at = ? WHERE '.$where, [$user->getId(), time(), ...$params]);
        if ($done > 0) {
            $this->audit->record($user, 'task.assign_unassigned', sprintf('%d %stask(s) assigned to %s', $done, $all ? '' : 'unassigned ', $user->getEmail()));
        }
        $io->success(sprintf('%d task(s) assigned to %s.', $done, $user->getEmail()));

        return Command::SUCCESS;
    }
}
