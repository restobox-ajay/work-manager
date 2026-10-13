<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\ProjectLink;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Security\Work\WorkAccess;
use App\Service\Pagination\Paginated;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Projects (ADR-070): list, create, edit, archive/remove. (Project staff were removed, ADR-124.)
 */
final class ProjectService
{
    public const PAGE_SIZE = 20;
    private const NAME_MAX_LENGTH = 100;
    private const URL_MAX_LENGTH = 255;

    /** Names offered for a project's links (ADR-129); any other name may be typed. */
    public const LINK_NAMES = ['Local', 'Dev', 'Staging', 'Prod', 'Doc / Specs', 'Mockup', 'Repository', 'Admin panel'];
    public const MAX_LINKS = 30;
    private const LINK_NAME_MAX = 60;
    private const LINK_DESCRIPTION_MAX = 255;
    private const LINK_PROTOCOLS = ['http', 'https'];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ClientRepository $clients,
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

    /** @return array{clientId: ?int, name: string, description: ?string, status: int, links: list<array{name: string, url: string, description: ?string}>} */
    public function valuesFrom(Project $project): array
    {
        return [
            'clientId'    => $project->getClient()?->getId(),
            'name'        => $project->getName(),
            'description' => $project->getDescription(),
            'status'      => $project->getStatus(),
            'links'       => array_values(array_map(static fn (ProjectLink $l) => ['name' => $l->getName(), 'url' => $l->getUrl(), 'description' => $l->getDescription()], $project->getLinks()->toArray())),
        ];
    }

    /**
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
        $result = $this->write($project, $values, $actor);
        if (!$result->isSaved()) {
            return $result;
        }

        $this->em->flush();

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
        $errors = $this->validator->checkFields($input, $rules);
        [$links, $linkErrors] = $this->linksFrom($values['links'] ?? null);
        $errors = [...$errors, ...$linkErrors];

        // The client is optional (ADR-128); one that is chosen must exist and not be removed.
        $client = $input['clientId'] !== null ? $this->clients->find($input['clientId']) : null;
        if ($input['clientId'] !== null && ($client === null || ($client->isDeleted() && $client->getId() !== $project->getClient()?->getId()))) {
            $errors[] = 'Choose a client from the list, or none.';
        } elseif ($client !== null && $client->getId() !== $project->getClient()?->getId() && !$this->access->canCreateProject($actor, $client)) {
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
            ->replaceLinks($links)
            ->setUpdatedAt(time())
            ->setUpdatedBy($actor->getId());

        return WriteResult::saved($project);
    }

    /**
     * The form's link rows (ADR-129) → links, in order. A row left wholly blank is skipped; a row with anything in it
     * needs a URL ("http://" is added when typed without a scheme); a blank name becomes "Link".
     *
     * @return array{0: list<ProjectLink>, 1: list<string>}
     */
    private function linksFrom(mixed $rows): array
    {
        $links = [];
        $errors = [];
        foreach (is_array($rows) ? array_values($rows) : [] as $n => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = InputValue::text($row['name'] ?? null);
            $url = InputValue::url($row['url'] ?? null);
            $description = InputValue::text($row['description'] ?? null);
            if ($name === null && $url === null && $description === null) {
                continue;
            }
            $label = sprintf('Link %d%s', $n + 1, $name !== null ? ' ("'.$name.'")' : '');
            if ($url === null) {
                $errors[] = "$label: enter its URL.";
                continue;
            }
            $invalidUrl = "$label is not a valid URL. Enter a web address such as https://example.com or http://localhost:8000.";
            $urlErrors = $this->validator->checkFields(['url' => $url], ['url' => [
                new Assert\Url(message: $invalidUrl, protocols: self::LINK_PROTOCOLS, requireTld: false),
                new Assert\Length(max: self::URL_MAX_LENGTH, maxMessage: "$label: the URL cannot be longer than {{ limit }} characters."),
            ]]);
            if ($urlErrors === [] && !self::isWebHost((string) parse_url($url, \PHP_URL_HOST))) {
                $urlErrors[] = $invalidUrl;
            }
            array_push($errors, ...$urlErrors);
            foreach ($this->validator->checkFields(['name' => $name, 'description' => $description], [
                'name'        => [new Assert\Length(max: self::LINK_NAME_MAX, maxMessage: "$label: the name cannot be longer than {{ limit }} characters.")],
                'description' => [new Assert\Length(max: self::LINK_DESCRIPTION_MAX, maxMessage: "$label: the description cannot be longer than {{ limit }} characters.")],
            ]) as $error) {
                $errors[] = $error;
            }
            $links[] = (new ProjectLink())->setName($name ?? 'Link')->setUrl($url)->setDescription($description);
        }
        if (count($links) > self::MAX_LINKS) {
            $errors[] = sprintf('A project can have at most %d links.', self::MAX_LINKS);
        }

        return [$links, $errors];
    }

    /**
     * A host someone can open: localhost, an IP address, or a dotted domain name ending in a real-looking TLD — so a
     * stray word ("abc" → http://abc) is refused. requireTld alone cannot do this: it would refuse localhost and IPs.
     * The form's script applies the same rule as you type (templates/project/form.html.twig).
     */
    private static function isWebHost(string $host): bool
    {
        $host = trim($host, '[]');

        return strcasecmp($host, 'localhost') === 0
            || filter_var($host, \FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+\p{L}[\p{L}\p{N}-]*[\p{L}\p{N}]$/u', $host) === 1;
    }

    private function describe(Project $project): string
    {
        return sprintf('#%d %s', (int) $project->getId(), $project->getName());
    }
}
