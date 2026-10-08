<?php

declare(strict_types=1);

namespace App\Service\Note;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\NoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Security\Work\WorkAccess;
use App\Service\Pagination\Paginated;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Notes (ADR-101): the list, the notes on a record, and the one write path. A note's record is set when it is
 * written and never moved; who may read or change it is NoteVoter's rule.
 */
final class NoteService
{
    public const PAGE_SIZE = 20;
    private const TITLE_MAX_LENGTH = 150;
    private const BODY_MAX_LENGTH = 20000;

    /** The list's type filter, value => label. */
    public const TYPES = [
        Note::TYPE_INDEPENDENT => 'Independent',
        Note::TYPE_CLIENT      => 'Client',
        Note::TYPE_PROJECT     => 'Project',
        Note::TYPE_TASK        => 'Task',
    ];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ClientRepository $clients,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly WorkAccess $access,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{term?: ?string, type?: ?string} $filters
     *
     * @return Paginated<Note>
     */
    public function search(User $viewer, array $filters, int $page): Paginated
    {
        $isAdmin = $this->access->isAdmin($viewer);

        return new Paginated(
            $this->notes->search($viewer, $isAdmin, $filters, $page, self::PAGE_SIZE),
            $this->notes->countSearch($viewer, $isAdmin, $filters),
            $page,
            self::PAGE_SIZE,
        );
    }

    /** @return Note[] */
    public function forSubject(Client|Project|Task $subject): array
    {
        return $this->notes->findForSubject($subject);
    }

    /** @return Note[] the notes on a project's tasks, for the project page; the caller filters by NoteVoter::VIEW */
    public function onTasksOf(Project $project): array
    {
        return $this->notes->findOnTasksOf($project);
    }

    /**
     * The record a new note is for, from "?client=5" / "?project=7" / "?task=9"; null for an independent note, or
     * when the record does not exist or is archived and removed from its list.
     *
     * @param array<string, mixed> $query
     */
    public function subjectFrom(array $query): Client|Project|Task|null
    {
        if (($id = InputValue::int($query[Note::TYPE_CLIENT] ?? null)) !== null) {
            $client = $this->clients->find($id);

            return $client !== null && !$client->isDeleted() ? $client : null;
        }
        if (($id = InputValue::int($query[Note::TYPE_PROJECT] ?? null)) !== null) {
            $project = $this->projects->find($id);

            return $project !== null && $project->getIsDeleted() !== 1 ? $project : null;
        }
        if (($id = InputValue::int($query[Note::TYPE_TASK] ?? null)) !== null) {
            $task = $this->tasks->find($id);

            return $task !== null && !$task->isDeleted() ? $task : null;
        }

        return null;
    }

    /** @return array{title: string, body: ?string, pinned: bool} */
    public function valuesFrom(Note $note): array
    {
        return ['title' => $note->getTitle(), 'body' => $note->getBody(), 'pinned' => $note->isPinned()];
    }

    /**
     * @param array<string, mixed> $values title, body, pinned
     *
     * @return WriteResult<Note>
     */
    public function create(array $values, Client|Project|Task|null $subject, User $actor): WriteResult
    {
        $note = (new Note())->attachTo($subject)->setAuthor($actor)->setCreatedAt(time());
        $result = $this->write($note, $values);
        if ($result->isSaved()) {
            $this->em->persist($note);
            $this->em->flush();
            $this->audit->record($actor, 'note.create', $this->describe($note));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return WriteResult<Note>
     */
    public function update(Note $note, array $values, User $actor): WriteResult
    {
        $result = $this->write($note, $values);
        if ($result->isSaved()) {
            $this->em->flush();
            $this->audit->record($actor, 'note.update', $this->describe($note));
        }

        return $result;
    }

    public function delete(Note $note, User $actor): void
    {
        $description = $this->describe($note);
        $this->em->remove($note);
        $this->em->flush();

        $this->audit->record($actor, 'note.delete', $description);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return WriteResult<Note>
     */
    private function write(Note $note, array $values): WriteResult
    {
        $input = [
            'title' => InputValue::text($values['title'] ?? null) ?? '',
            'body'  => InputValue::text($values['body'] ?? null),
        ];
        $errors = $this->validator->checkFields($input, [
            'title' => [
                new Assert\NotBlank(message: 'Title is required.'),
                new Assert\Length(max: self::TITLE_MAX_LENGTH, maxMessage: 'Title cannot be longer than {{ limit }} characters.'),
            ],
            'body'  => [new Assert\Length(max: self::BODY_MAX_LENGTH, maxMessage: 'Note cannot be longer than {{ limit }} characters.')],
        ]);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $note->setTitle($input['title'])
            ->setBody($input['body'])
            ->setPinned(filter_var($values['pinned'] ?? false, FILTER_VALIDATE_BOOL))
            ->setUpdatedAt(time());

        return WriteResult::saved($note);
    }

    /** The audit context: id, type and title — never the body, which may hold anything. */
    private function describe(Note $note): string
    {
        return sprintf('#%d %s: %s', (int) $note->getId(), $note->getType(), mb_substr($note->getTitle(), 0, 120));
    }
}
