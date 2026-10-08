<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Work\WorkAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may read and change a note (ADR-101). A note on a client, project or task is as visible as that record
 * (WorkAccess decides); an independent note is its author's alone. Only the author or an admin changes a note.
 *
 * @extends Voter<string, Note|Client|Project|Task|null>
 */
final class NoteVoter extends Voter
{
    public const VIEW = 'NOTE_VIEW';
    /** Edit or delete. */
    public const EDIT = 'NOTE_EDIT';
    /** Write a note on this client, project or task (null: an independent note). */
    public const ATTACH = 'NOTE_ATTACH';

    public function __construct(private readonly WorkAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW, self::EDIT => $subject instanceof Note,
            self::ATTACH           => $subject === null || $subject instanceof Client || $subject instanceof Project || $subject instanceof Task,
            default                => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => $this->canView($user, $subject),
            self::EDIT   => $this->canView($user, $subject) && ($this->isAuthor($user, $subject) || $this->access->isAdmin($user)),
            self::ATTACH => $this->canSeeRecord($user, $subject),
            default      => false,
        };
    }

    private function canView(User $user, Note $note): bool
    {
        $record = $note->getSubject();

        return $record === null ? $this->isAuthor($user, $note) : $this->canSeeRecord($user, $record);
    }

    private function canSeeRecord(User $user, Client|Project|Task|null $record): bool
    {
        return match (true) {
            $record === null            => true,
            $record instanceof Client  => $this->access->canViewClient($user, $record),
            $record instanceof Project => $this->access->canViewProject($user, $record),
            default                    => $this->access->canViewTask($user, $record),
        };
    }

    private function isAuthor(User $user, Note $note): bool
    {
        return $note->getAuthor()?->getId() === $user->getId();
    }
}
