<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fresh-install reset that keeps one login (ADR-122, owner request): every table of the current database is dropped
 * and rebuilt by the migrations (so statuses, types, currencies and other defaults are back to their seeded values),
 * then the kept account's rows are written back with the same ids — same email, password, roles, two-factor and
 * Password Manager key and entries. Everything else (clients, projects, tasks, invoices, logs, other users…) is gone.
 * Refused when APP_ENV=prod; asks for the email to be typed back unless --force.
 */
#[AsCommand(name: 'app:reset-database', description: 'Drop all data and rebuild the database, keeping one user account')]
final class ResetDatabaseCommand extends Command
{
    /** Tables holding the kept account, with the column naming its user; written back in this order. */
    private const ACCOUNT_TABLES = [
        'user'                => 'id',
        'password_meta'       => 'user_id',
        'password_history'    => 'user_id',
        'two_factor_settings' => 'user_id',
        'user_ip_whitelist'   => 'user_id',
        'vault_key'           => 'user_id',
        'vault_entry'         => 'user_id',
    ];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keep-email', InputArgument::REQUIRED, 'Email of the account to keep')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment === 'prod') {
            $io->error('Refused: APP_ENV=prod. This command wipes the database and is for development databases only.');

            return Command::FAILURE;
        }

        $email = trim((string) $input->getArgument('keep-email'));
        $userId = $this->connection->fetchOne('SELECT id FROM "user" WHERE LOWER(email) = LOWER(?)', [$email]);
        if ($userId === false) {
            $io->error(sprintf('No account with the email %s — nothing was changed.', $email));

            return Command::FAILURE;
        }
        $userId = (int) $userId;
        $schema = (string) $this->connection->fetchOne('SELECT DATABASE()');

        $io->warning([
            sprintf('This permanently deletes ALL data in the database "%s" and rebuilds it.', $schema),
            sprintf('Only the account %s (#%d) is kept, with its password, 2FA and Password Manager entries.', $email, $userId),
        ]);
        if (!$input->getOption('force')) {
            $typed = $io->ask('Type the email again to confirm');
            if (strtolower(trim((string) $typed)) !== strtolower($email)) {
                $io->text('Not confirmed — nothing was changed.');

                return Command::SUCCESS;
            }
        }

        $saved = $this->saveAccount($userId, $schema);
        $this->dropAllTables($schema);
        $io->text('All tables dropped. Running the migrations…');

        $migrate = $this->getApplication()?->find('doctrine:migrations:migrate');
        if ($migrate === null) {
            $io->error('Could not find doctrine:migrations:migrate. Run it yourself, then restore the account by hand.');

            return Command::FAILURE;
        }
        $migrateInput = new ArrayInput(['--allow-no-migration' => true]);
        $migrateInput->setInteractive(false);
        if ($migrate->run($migrateInput, $output) !== Command::SUCCESS) {
            $io->error('The migrations failed; the account was not restored.');

            return Command::FAILURE;
        }

        // The migrations' DDL commits implicitly and leaves the connection's transaction bookkeeping out of step with
        // the server; a fresh connection restores the account cleanly.
        $this->connection->close();
        $restored = $this->restoreAccount($saved, $schema);
        $io->success(sprintf('Database rebuilt. Kept %s (#%d): %s.', $email, $userId,
            implode(', ', array_map(static fn (string $t, int $n) => "$n $t", array_keys($restored), $restored))));

        return Command::SUCCESS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function saveAccount(int $userId, string $schema): array
    {
        $saved = [];
        foreach (self::ACCOUNT_TABLES as $table => $column) {
            if ($this->hasColumn($schema, $table, $column)) {
                $saved[$table] = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM "%s" WHERE "%s" = ?', $table, $column), [$userId]);
            }
        }

        return $saved;
    }

    private function dropAllTables(string $schema): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", [$schema]);
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->connection->executeStatement(sprintf('DROP TABLE "%s"', str_replace('"', '""', (string) $table)));
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Writes the saved rows back, keeping only columns the rebuilt tables still have (a migration may have changed
     * them since). @param array<string, list<array<string, mixed>>> $saved @return array<string, int>
     */
    private function restoreAccount(array $saved, string $schema): array
    {
        $restored = [];
        $this->connection->transactional(function (Connection $db) use ($saved, $schema, &$restored): void {
            foreach ($saved as $table => $rows) {
                $columns = array_flip($db->fetchFirstColumn(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, $table]));
                foreach ($rows as $row) {
                    $db->insert(sprintf('"%s"', $table), array_intersect_key($row, $columns));
                }
                $restored[$table] = count($rows);
            }
        });

        return $restored;
    }

    private function hasColumn(string $schema, string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$schema, $table, $column]);
    }
}
