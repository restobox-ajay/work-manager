<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Event\EventReminderMailer;
use App\Service\Validation\InputValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the event reminder emails that are due (ADR-126). Run it once a day from a scheduled task (Windows Task
 * Scheduler / cron); running it more often is harmless — each occurrence is emailed once.
 */
#[AsCommand(name: 'app:events:remind', description: 'Email the admins about events whose reminder is due')]
final class EventRemindCommand extends Command
{
    public function __construct(private readonly EventReminderMailer $reminders)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent, send nothing.')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Pretend today is this Y-m-d date (for checking).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');
        if (($date = $input->getOption('date')) !== null) {
            [$today, $ok] = InputValue::ymd($date);
            if (!$ok || $today === null) {
                $io->error('--date must be a Y-m-d date.');

                return Command::INVALID;
            }
        }

        $due = $this->reminders->due($today);
        if ($due === []) {
            $io->success(sprintf('No event reminders due on %s.', $today->format('Y-m-d')));

            return Command::SUCCESS;
        }
        $io->table(['Event', 'Date', 'When', 'Years'], array_map(static fn (array $r) => [
            $r['event']->getTitle(), $r['date']->format('Y-m-d'), EventReminderMailer::when($r), $r['years'] ?? '',
        ], $due));

        $recipients = $this->reminders->recipients();
        if ($recipients === []) {
            $io->warning('No active admin account to email.');

            return Command::FAILURE;
        }
        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Dry run: would email %s.', implode(', ', $recipients)));

            return Command::SUCCESS;
        }

        $result = $this->reminders->send($due);
        if ($result['failed'] !== []) {
            $io->error(sprintf('Could not email %s (see the error log). Sent to %d.', implode(', ', $result['failed']), $result['sent']));

            return $result['sent'] > 0 ? Command::SUCCESS : Command::FAILURE;
        }
        $io->success(sprintf('Emailed %d reminder%s to %s.', $result['events'], $result['events'] === 1 ? '' : 's', implode(', ', $recipients)));

        return Command::SUCCESS;
    }
}
