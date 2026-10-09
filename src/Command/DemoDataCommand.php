<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Demo\DemoData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo data for trying out every module (ADR-103, ADR-108) — see App\Service\Demo\DemoData for what and how it is
 * marked. --purge removes exactly the demo records. Development databases only: refused when APP_ENV=prod.
 */
#[AsCommand(name: 'app:demo-data', description: 'Add demo data to every module (or remove it with --purge)')]
final class DemoDataCommand extends Command
{
    public function __construct(
        private readonly DemoData $demo,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Remove the demo data (and only the demo data) instead.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment === 'prod') {
            $io->error('Demo data is for development databases only (APP_ENV=prod).');

            return Command::FAILURE;
        }
        if ($input->getOption('purge')) {
            $io->success('Removed: '.self::describe($this->demo->purge()).'.');

            return Command::SUCCESS;
        }
        if ($this->demo->exists()) {
            $io->warning('Demo data is already there. Run with --purge first to start again.');

            return Command::SUCCESS;
        }
        [$actor, $assignees] = $this->demo->people();
        if ($actor === null) {
            $io->error('Create an admin account first (php bin/console app:create-superadmin): demo data is recorded as made by an admin.');

            return Command::FAILURE;
        }

        $result = $this->demo->seed($actor, $assignees);
        $io->success('Added: '.self::describe($result['counts']).'.');
        if ($assignees === []) {
            $io->note('There are no contractor accounts, so the demo tasks have no assignee.');
        }
        if ($result['problems'] !== []) {
            $io->warning(['Some demo records were refused:', ...$result['problems']]);
        }
        $io->text(['The Password Manager is not filled: its entries are encrypted in your browser with your master password.',
            'Remove all demo data again with: php bin/console app:demo-data --purge']);

        return Command::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private static function describe(array $counts): string
    {
        return implode(', ', array_map(static fn (string $what, int $n) => $n.' '.$what, array_keys($counts), $counts));
    }
}
