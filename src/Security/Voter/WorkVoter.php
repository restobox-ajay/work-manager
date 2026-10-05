<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Client;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Work\WorkAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Wires WorkAccess into is_granted() / #[IsGranted] / Twig for the client, project and task pages (ADR-070).
 * The rules themselves live in WorkAccess; this only maps an attribute and subject to the right question.
 *
 * @extends Voter<string, Client|Project|Task|null>
 */
final class WorkVoter extends Voter
{
    public const CLIENT_CREATE = 'CLIENT_CREATE';
    public const CLIENT_VIEW = 'CLIENT_VIEW';
    public const CLIENT_EDIT = 'CLIENT_EDIT';
    /** Choose a client's managers, remove the client. */
    public const CLIENT_ADMINISTER = 'CLIENT_ADMINISTER';
    /** Subject: a Client, or null for "any client". */
    public const PROJECT_CREATE = 'PROJECT_CREATE';
    public const PROJECT_VIEW = 'PROJECT_VIEW';
    public const PROJECT_EDIT = 'PROJECT_EDIT';
    public const TASK_CREATE = 'TASK_CREATE';
    public const TASK_VIEW = 'TASK_VIEW';
    public const TASK_EDIT = 'TASK_EDIT';
    public const TASK_STATUS_DETAIL = 'TASK_STATUS_DETAIL';
    public const TASK_DELETE = 'TASK_DELETE';
    public const TASK_FEE = 'TASK_FEE';

    private const SUBJECT_CLASS = [
        self::CLIENT_CREATE      => null,
        self::CLIENT_VIEW        => Client::class,
        self::CLIENT_EDIT        => Client::class,
        self::CLIENT_ADMINISTER  => null,
        self::PROJECT_CREATE     => Client::class,
        self::PROJECT_VIEW       => Project::class,
        self::PROJECT_EDIT       => Project::class,
        self::TASK_CREATE        => null,
        self::TASK_VIEW          => Task::class,
        self::TASK_EDIT          => Task::class,
        self::TASK_STATUS_DETAIL => Task::class,
        self::TASK_DELETE        => Task::class,
        self::TASK_FEE           => Task::class,
    ];

    public function __construct(private readonly WorkAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!array_key_exists($attribute, self::SUBJECT_CLASS)) {
            return false;
        }
        $class = self::SUBJECT_CLASS[$attribute];

        // PROJECT_CREATE takes an optional client; the others take exactly their subject, or none.
        return $class === null
            ? $subject === null
            : $subject instanceof $class || ($attribute === self::PROJECT_CREATE && $subject === null);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::CLIENT_CREATE      => $this->access->canCreateClient($user),
            self::CLIENT_VIEW        => $this->access->canViewClient($user, $subject),
            self::CLIENT_EDIT        => $this->access->canEditClient($user, $subject),
            self::CLIENT_ADMINISTER  => $this->access->canAdministerClient($user),
            self::PROJECT_CREATE     => $this->access->canCreateProject($user, $subject),
            self::PROJECT_VIEW       => $this->access->canViewProject($user, $subject),
            self::PROJECT_EDIT       => $this->access->canEditProject($user, $subject),
            self::TASK_CREATE        => $this->access->canCreateTask($user),
            self::TASK_VIEW          => $this->access->canViewTask($user, $subject),
            self::TASK_EDIT          => $this->access->canUpdateTask($user, $subject),
            self::TASK_STATUS_DETAIL => $this->access->canUpdateStatusDetail($user, $subject),
            self::TASK_DELETE        => $this->access->canDeleteTask($user, $subject),
            self::TASK_FEE           => $this->access->canAccessFee($user, $subject),
        };
    }
}
