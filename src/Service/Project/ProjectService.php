<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStaffRepository;
use App\Security\Work\WorkAccess;
use App\Service\Pagination\Paginated;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Projects (ADR-070): list, create, edit, archive/remove. Staff rows are ProjectStaffService's.
 */
final class ProjectService
{
    public const PAGE_SIZE = 20;
    private const NAME_MAX_LENGTH = 100;
    private const URL_MAX_LENGTH = 255;

    /** The project's links, field => label (form, view and validation messages share the wording). */
    public const LINK_FIELDS = [
        'localUrl' => 'Local URL',
        'devUrl'   => 'Dev URL',
        'prodUrl'  => 'Prod URL',
        'docUrl'   => 'Doc / Specs URL',
    ];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectStaffRepository $projectStaff,
        private readonly ClientRepository $clients,
        private readonly ProjectStaffService $staff,
        private readonly WorkAccess $access,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{term?: ?string, clientId?: ?int, status?: ?int} $filters
     *
     * @return Paginated<Project>
     */
    public function search(User $viewer, array $filters, int $page, ?string $sort): Paginated
    {
        $visibleIds = $this->access->visibleProjectIds($viewer);

        return new Paginated(
            $this->projects->search($filters, $visibleIds, $page, self::PAGE_SIZE, $sort),
            $this->projects->countSearch($filters, $visibleIds),
            $page,
            self::PAGE_SIZE,
        );
    }

    /**
     * @param Project[] $projects
     *
     * @return array<int, User[]> project id => contractors
     */
    public function contractorsOf(array $projects): array
    {
        return $this->projectStaff->findContractorsByProjectIds(
            array_map(static fn (Project $project) => (int) $project->getId(), $projects),
        );
    }

    /** @return Project[] projects this user may pick (task form, filters) */
    public function selectable(User $viewer, ?int $clientId = null): array
    {
        return $this->projects->findSelectable($this->access->visibleProjectIds($viewer), $clientId);
    }

    /**
     * Why this project's tasks are hidden from the unfiltered task list — the wording of the project page's
     * banner and of the task list's "Archived" tag, so the two cannot drift. Empty when nothing is archived.
     *
     * @return list<string>
     */
    public function archivedReasons(Project $project): array
    {
        $reasons = [];
        if ($project->getIsDeleted() === 1) {
            $reasons[] = 'This project has been archived and removed from the project list.';
        } elseif (!$project->isActive()) {
            $reasons[] = 'This project\'s status is "Archive".';
        }

        $client = $project->getClient();
        if ($client !== null) {
            if ($client->isDeleted()) {
                $reasons[] = sprintf('Its client "%s" has been archived and removed from the client list.', $client->getName());
            } elseif (!$client->isActive()) {
                $reasons[] = sprintf('Its client "%s" status is "Archive".', $client->getName());
            }
        }

        return $reasons;
    }

    /** @return array{clientId: ?int, name: string, description: ?string, status: int, localUrl: ?string, devUrl: ?string, prodUrl: ?string, docUrl: ?string} */
    public function valuesFrom(Project $project): array
    {
        return [
            'clientId'    => $project->getClient()?->getId(),
            'name'        => $project->getName(),
            'description' => $project->getDescription(),
            'status'      => $project->getStatus(),
            'localUrl'    => $project->getLocalUrl(),
            'devUrl'      => $project->getDevUrl(),
            'prodUrl'     => $project->getProdUrl(),
            'docUrl'      => $project->getDocUrl(),
        ];
    }

    /**
     * Creating a project also makes its client's Client Managers project staff (work-platform's automatic rows).
     *
     * @param array<string, mixed> $values clientId, name, description, status
     *
     * @return WriteResult<Project>
     */
    public function create(array $values, User $actor): WriteResult
    {
        $project = new Project();
        $result = $this->write($project, $values, $actor);
        if (!$result->isSaved()) {
            return $result;
        }

        $project->setCreatedAt(time())->setCreatedBy($actor->getId());
        $this->em->persist($project);
        $this->em->flush();
        $this->staff->ensureAutomaticStaff($project, $actor);

        $this->audit->record($actor, 'project.create', $this->describe($project));

        return $result;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return WriteResult<Project>
     */
    public function update(Project $project, array $values, User $actor): WriteResult
    {
        $clientBefore = $project->getClient()?->getId();
        $result = $this->write($project, $values, $actor);
        if (!$result->isSaved()) {
            return $result;
        }

        $this->em->flush();
        if ($project->getClient()?->getId() !== $clientBefore) {
            $this->staff->ensureAutomaticStaff($project, $actor);
        }

        $this->audit->record($actor, 'project.update', $this->describe($project));

        return $result;
    }

    /** "Archive and remove from the list" (is_deleted = 1); status is left as it was. */
    public function remove(Project $project, User $actor): void
    {
        $project->setIsDeleted(1)->setUpdatedAt(time())->setUpdatedBy($actor->getId());
        $this->em->flush();

        $this->audit->record($actor, 'project.remove', $this->describe($project));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return WriteResult<Project>
     */
    private function write(Project $project, array $values, User $actor): WriteResult
    {
        $input = [
            'clientId'    => InputValue::int($values['clientId'] ?? null),
            'name'        => InputValue::text($values['name'] ?? null) ?? '',
            'description' => InputValue::text($values['description'] ?? null),
            'status'      => InputValue::int($values['status'] ?? null) ?? Project::STATUS_ACTIVE,
        ];
        $rules = [
            'name'   => [
                new Assert\NotBlank(message: 'Name is required.'),
                new Assert\Length(max: self::NAME_MAX_LENGTH, maxMessage: 'Name cannot be longer than {{ limit }} characters.'),
            ],
            'status' => [new Assert\Choice(choices: [Project::STATUS_ACTIVE, Project::STATUS_ARCHIVE], message: 'Status must be Active or Archive.')],
        ];
        foreach (self::LINK_FIELDS as $field => $label) {
            $input[$field] = InputValue::url($values[$field] ?? null);
            // requireTld off: a local URL is often http://localhost or a bare dev hostname.
            $rules[$field] = [
                new Assert\Url(message: "$label is not a valid URL.", requireTld: false),
                new Assert\Length(max: self::URL_MAX_LENGTH, maxMessage: "$label cannot be longer than {{ limit }} characters."),
            ];
        }

        $errors = $this->validator->checkFields($input, $rules);

        $client = $input['clientId'] !== null ? $this->clients->find($input['clientId']) : null;
        if ($client === null || $client->isDeleted()) {
            $errors[] = 'Client is required.';
        } elseif ($client->getId() !== $project->getClient()?->getId() && !$this->access->canCreateProject($actor, $client)) {
            // Moving a project to, or creating one under, a client you do not manage would hand it to someone else.
            $errors[] = 'You cannot add projects to this client.';
        }

        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $project->setClient($client)
            ->setName($input['name'])
            ->setDescription($input['description'])
            ->setStatus($input['status'])
            ->setLocalUrl($input['localUrl'])
            ->setDevUrl($input['devUrl'])
            ->setProdUrl($input['prodUrl'])
            ->setDocUrl($input['docUrl'])
            ->setUpdatedAt(time())
            ->setUpdatedBy($actor->getId());

        return WriteResult::saved($project);
    }

    private function describe(Project $project): string
    {
        return sprintf('#%d %s', (int) $project->getId(), $project->getName());
    }
}
