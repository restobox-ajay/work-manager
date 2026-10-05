<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Role;
use App\Service\AccountProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provisions a maintainer (ROLE_TECH_SUPPORT) account — the console is the bootstrap path,
 * mirroring app:create-superadmin (ADR-004). Kept as a SEPARATE command rather than a `--role` flag
 * on app:create-superadmin so the two roles' options never drift into a shared, easy-to-misuse
 * surface — NOT to hide this command's existence, which is discoverable via `bin/console list` and
 * pointed to from app:create-superadmin's own --help (ADR-050 amendment, Ken 2026-09-28: obscuring
 * a deployment command bought no real security and only slowed down bootstrapping a new instance).
 * Existing tech-support accounts can create more from Users in the UI.
 *
 * This is the command to run FIRST on a brand-new instance: ROLE_TECH_SUPPORT has full control
 * (superadmin powers plus more, via role_hierarchy) and can create every other admin — including
 * superadmins — from the UI afterward.
 */
#[AsCommand(
    name: 'app:create-tech-support',
    description: 'Provision the first admin on a new instance (full control; run this before app:create-superadmin)',
    help: <<<'HELP'
        Creates a user account holding ROLE_TECH_SUPPORT — full control of the instance (superadmin powers
        plus more, via role_hierarchy). This is the command to run FIRST when bootstrapping a
        brand-new instance; use the admin UI (or <info>app:create-superadmin</info>) to create
        client-facing superadmins afterward.

        Tech-support accounts are hidden from other admins' staff-list views (clients should not
        see maintainer accounts among their own staff), but this command itself is not secret —
        see `bin/console list`.
        HELP,
)]
class CreateTechSupportCommand extends Command
{
    public function __construct(
        private readonly AccountProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password (auto-generated if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption('email');
        if (!$email) {
            $output->writeln('<error>--email is required.</error>');
            return Command::FAILURE;
        }

        if ($this->provisioner->emailIsTaken($email)) {
            $output->writeln('<error>An account with this email already exists.</error>');
            return Command::FAILURE;
        }

        $generatedPassword = $this->provisioner->create($email, 'Tech Support', Role::TechSupport, $input->getOption('password'));

        $output->writeln('<info>Tech-support account created successfully.</info>');
        if ($generatedPassword !== null) {
            $output->writeln("Auto-generated password: {$generatedPassword}");
        }

        return Command::SUCCESS;
    }
}
