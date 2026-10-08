<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateSuperAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $userRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->userRepo = self::getContainer()->get(UserRepository::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (['cmd-superadmin@example.com', 'cmd-superadmin2@example.com'] as $email) {
            $admin = $this->userRepo->findByEmail($email);
            if ($admin) {
                $this->em->remove($admin);
                $this->em->flush();
            }
        }
    }

    private function getCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:create-superadmin');
        return new CommandTester($command);
    }

    public function testCreatesAdminWithSuperAdminRoleAndHashedPassword(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute([
            '--email' => 'cmd-superadmin@example.com',
            '--password' => 'SuperSecret123',
        ]);

        $this->assertSame(0, $exitCode);

        $admin = $this->userRepo->findByEmail('cmd-superadmin@example.com');
        $this->assertNotNull($admin);
        $this->assertContains('ROLE_SUPER_ADMIN', $admin->getRoles());

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($admin, 'SuperSecret123'));
    }

    public function testAutoGeneratesPasswordWhenOmittedAndPrintsIt(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute([
            '--email' => 'cmd-superadmin@example.com',
        ]);

        $this->assertSame(0, $exitCode);

        $admin = $this->userRepo->findByEmail('cmd-superadmin@example.com');
        $this->assertNotNull($admin);
        $this->assertStringContainsString('Auto-generated password:', $tester->getDisplay());
    }

    public function testFailsOnDuplicateEmail(): void
    {
        // Create first account
        $tester = $this->getCommandTester();
        $tester->execute([
            '--email' => 'cmd-superadmin@example.com',
            '--password' => 'FirstPassword123',
        ]);

        // Attempt duplicate
        $tester2 = $this->getCommandTester();
        $exitCode = $tester2->execute([
            '--email' => 'cmd-superadmin@example.com',
            '--password' => 'SecondPassword123',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already exists', $tester2->getDisplay());
    }

    /** ADR-068: admins and users share one table, so an existing plain user's email is taken too. */
    public function testFailsWhenAPlainUserAlreadyHoldsTheEmail(): void
    {
        $user = new User();
        $user->setEmail('cmd-superadmin2@example.com');
        $user->setName('Plain User');
        $user->setPassword(password_hash('irrelevant', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--email' => 'cmd-superadmin2@example.com', '--password' => 'SuperSecret123']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already exists', $tester->getDisplay());
        $this->em->clear();
        $this->assertSame(['ROLE_USER'], array_values($this->userRepo->findByEmail('cmd-superadmin2@example.com')->getRoles()), 'the existing account is not promoted');
    }

    public function testFailsWhenEmailOptionIsAbsent(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
    }
}
