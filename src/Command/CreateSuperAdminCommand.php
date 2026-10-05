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

#[AsCommand(
    name: 'app:create-superadmin',
    description: 'Provision a superadmin account',
    help: <<<'HELP'
        Creates a user account holding ROLE_SUPER_ADMIN.

        For a brand-new instance, provision the FIRST admin with
        <info>app:create-tech-support</info> instead: ROLE_TECH_SUPPORT has full control of the
        instance (superadmin powers plus more, via role_hierarchy) and can create every other
        admin — including superadmins — from the UI afterward. Use this command for additional
        client-facing superadmins once that maintainer account exists.
        HELP,
)]
class CreateSuperAdminCommand extends Command
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

        $generatedPassword = $this->provisioner->create($email, 'Superadmin', Role::SuperAdmin, $input->getOption('password'));

        $output->writeln('<info>Superadmin created successfully.</info>');
        if ($generatedPassword !== null) {
            $output->writeln("Auto-generated password: {$generatedPassword}");
        }

        return Command::SUCCESS;
    }
}
