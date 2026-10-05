<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use App\Security\PasswordPolicyManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a privileged account from the console (app:create-superadmin / app:create-tech-support) — the
 * bootstrap path when no one can sign in yet (ADR-004). The account is active and pre-verified: whoever runs
 * the command controls the server, so there is no email to prove.
 */
final class AccountProvisioner
{
    private const GENERATED_PASSWORD_BYTES = 16;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordPolicyManagerInterface $passwordPolicy,
    ) {
    }

    public function emailIsTaken(string $email): bool
    {
        return $this->users->findByEmail($email) !== null;
    }

    /**
     * @return string|null the generated password when none was given (shown once by the caller), else null
     */
    public function create(string $email, string $name, Role $role, ?string $password): ?string
    {
        $generated = null;
        if ($password === null || $password === '') {
            $password = $generated = bin2hex(random_bytes(self::GENERATED_PASSWORD_BYTES));
        }

        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setRoles([$role->value])
            ->setIsVerified(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();
        $this->passwordPolicy->recordPasswordChange($user, $user->getPassword());

        return $generated;
    }
}
