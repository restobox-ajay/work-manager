<?php

declare(strict_types=1);

namespace App\Service\Client;

use App\Entity\Client;
use App\Entity\ClientAdmin;
use App\Entity\User;
use App\Repository\ClientAdminRepository;
use App\Repository\ClientRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\Work\WorkAccess;
use App\Service\Pagination\Paginated;
use App\Service\Validation\InputValue;
use App\Service\Validation\WriteResult;
use App\Service\Validation\WriteValidator;
use App\Service\WorkAuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Clients (ADR-070): list, create, edit, remove, and their Client Managers. Validation is work-platform's
 * ClientWriteService, ported: same fields, limits, uniqueness rules and messages.
 */
final class ClientService
{
    public const PAGE_SIZE = 20;

    /** Form field => [label, max length] for the plain string columns. */
    private const STRING_FIELDS = [
        'name'           => ['Name', 100],
        'email'          => ['Email', 100],
        'clientCode'     => ['Client Code', 10],
        'website'        => ['Website', 255],
        'phone'          => ['Phone', 20],
        'streetAddress1' => ['Street Address 1', 255],
        'streetAddress2' => ['Street Address 2', 255],
        'city'           => ['City', 100],
        'province'       => ['Province', 100],
        'state'          => ['State', 100],
        'zipCode'        => ['Zip Code', 10],
        'country'        => ['Country', 50],
    ];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientAdminRepository $clientAdmins,
        private readonly ProjectRepository $projects,
        private readonly UserRepository $users,
        private readonly WorkAccess $access,
        private readonly WriteValidator $validator,
        private readonly WorkAuditTrail $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{term?: ?string, isActive?: ?int} $filters
     *
     * @return Paginated<Client>
     */
    public function search(User $viewer, array $filters, int $page, ?string $sort): Paginated
    {
        $visibleIds = $this->access->visibleClientIds($viewer);

        return new Paginated(
            $this->clients->search($filters, $visibleIds, $page, self::PAGE_SIZE, $sort),
            $this->clients->countSearch($filters, $visibleIds),
            $page,
            self::PAGE_SIZE,
        );
    }

    /**
     * The extra columns of the list page, for a whole page of clients at once.
     *
     * @param Client[] $clients
     *
     * @return array{managers: array<int, User[]>, projectCounts: array<int, int>}
     */
    public function listDetails(array $clients): array
    {
        $ids = array_map(static fn (Client $client) => (int) $client->getId(), $clients);

        return [
            'managers'      => $this->clientAdmins->findManagersByClientIds($ids),
            'projectCounts' => $this->projects->countByClientIds($ids),
        ];
    }

    /** @return Client[] clients this user may pick (project form, filters) */
    public function selectable(User $viewer): array
    {
        return $this->clients->findSelectable($this->access->visibleClientIds($viewer));
    }

    /** @return User[] */
    public function managersOf(Client $client): array
    {
        return $this->clientAdmins->findManagers($client);
    }

    /**
     * The stored client in the form's field shape, so a template reads one array whether it is showing the
     * database or what the user just typed and got refused.
     *
     * @return array<string, mixed>
     */
    public function valuesFrom(Client $client): array
    {
        return [
            'name'           => $client->getName(),
            'email'          => $client->getEmail(),
            'clientCode'     => $client->getClientCode(),
            'website'        => $client->getWebsite(),
            'phone'          => $client->getPhone(),
            'streetAddress1' => $client->getStreetAddress1(),
            'streetAddress2' => $client->getStreetAddress2(),
            'city'           => $client->getCity(),
            'province'       => $client->getProvince(),
            'state'          => $client->getState(),
            'zipCode'        => $client->getZipCode(),
            'country'        => $client->getCountry(),
            'isActive'       => $client->getIsActive(),
        ];
    }

    /**
     * @param array<string, mixed> $values     form fields (see STRING_FIELDS, plus isActive)
     * @param int[]                $managerIds Client Managers to set
     *
     * @return WriteResult<Client>
     */
    public function create(array $values, array $managerIds, User $actor): WriteResult
    {
        $client = new Client();
        $errors = $this->validate($client, $values = $this->normalize($values), true);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $this->apply($client, $values);
        $client->setCreatedAt(time())->setCreatedBy($actor->getId())->setUpdatedAt(time())->setUpdatedBy($actor->getId());
        $this->em->persist($client);
        $this->em->flush();
        $this->syncManagers($client, $managerIds);

        $this->audit->record($actor, 'client.create', $this->describe($client));

        return WriteResult::saved($client);
    }

    /**
     * @param array<string, mixed> $values
     * @param int[]|null           $managerIds null leaves the managers alone (the editor may not change them)
     *
     * @return WriteResult<Client>
     */
    public function update(Client $client, array $values, ?array $managerIds, User $actor): WriteResult
    {
        $errors = $this->validate($client, $values = $this->normalize($values), false);
        if ($errors !== []) {
            return WriteResult::failed($errors);
        }

        $this->apply($client, $values);
        $client->setUpdatedAt(time())->setUpdatedBy($actor->getId());
        $this->em->flush();
        if ($managerIds !== null) {
            $this->syncManagers($client, $managerIds);
        }

        $this->audit->record($actor, 'client.update', $this->describe($client));

        return WriteResult::saved($client);
    }

    /**
     * "Archive and remove from the list" (is_deleted = 1). There is no undo screen, as in work-platform; its
     * projects' tasks stop showing on the unfiltered task list (TaskRepository).
     */
    public function remove(Client $client, User $actor): void
    {
        $client->setIsDeleted(1)->setUpdatedAt(time())->setUpdatedBy($actor->getId());
        $this->em->flush();

        $this->audit->record($actor, 'client.remove', $this->describe($client));
    }

    /**
     * @param int[] $userIds
     */
    private function syncManagers(Client $client, array $userIds): void
    {
        $wanted = [];
        $userIds = array_values(array_unique(array_filter($userIds)));
        foreach ($userIds === [] ? [] : $this->users->findBy(['id' => $userIds]) as $user) {
            $wanted[(int) $user->getId()] = $user;
        }

        foreach ($client->getClientAdmins() as $row) {
            $userId = (int) $row->getUser()->getId();
            if (isset($wanted[$userId])) {
                unset($wanted[$userId]);
            } else {
                $client->getClientAdmins()->removeElement($row);
                $this->em->remove($row);
            }
        }
        foreach ($wanted as $user) {
            $row = (new ClientAdmin())->setClient($client)->setUser($user);
            $client->getClientAdmins()->add($row);
            $this->em->persist($row);
        }

        $this->em->flush();
    }

    /**
     * Trims every value, turns '' into NULL for the nullable columns, and gives a website with no scheme
     * "http://" (Yii2's url validator with defaultScheme), before anything checks it.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        $normalized = [];
        foreach (array_keys(self::STRING_FIELDS) as $field) {
            if (array_key_exists($field, $values)) {
                $normalized[$field] = InputValue::text($values[$field]);
            }
        }
        if (array_key_exists('name', $normalized)) {
            $normalized['name'] ??= '';
        }
        if (($normalized['clientCode'] ?? null) !== null) {
            $normalized['clientCode'] = strtoupper($normalized['clientCode']);
        }
        if (array_key_exists('website', $normalized)) {
            $normalized['website'] = InputValue::url($normalized['website']);
        }
        if (array_key_exists('isActive', $values)) {
            $normalized['isActive'] = (int) $values['isActive'] === Client::STATUS_ARCHIVE ? Client::STATUS_ARCHIVE : Client::STATUS_ACTIVE;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function validate(Client $client, array $values, bool $isCreate): array
    {
        $errors = $this->validator->checkFields($values, $this->fieldRules());

        if ($isCreate && !array_key_exists('name', $values)) {
            $errors[] = 'Name is required.';
        }

        // Name and email must be unique among clients. On update only a changed value is checked: the imported
        // data may already hold duplicates, and a new rule should stop new ones, not strand edits to old rows.
        foreach (['name' => 'A client with this name already exists.', 'email' => 'A client with this email address already exists.'] as $field => $message) {
            $value = $values[$field] ?? null;
            $stored = $field === 'name' ? $client->getName() : $client->getEmail();
            if ($value !== null && $value !== ''
                && ($isCreate || mb_strtolower($value) !== mb_strtolower((string) $stored))
                && $this->clients->fieldValueExists($field, $value, $client->getId())
            ) {
                $errors[] = $message;
            }
        }

        if (($values['clientCode'] ?? null) !== null) {
            if (preg_match('/\d/', $values['clientCode'])) {
                $errors[] = 'Client code cannot contain digits.';
            }
            if ($this->clients->clientCodeExists($values['clientCode'], $client->getId())) {
                $errors[] = 'This client code already exists.';
            }
        }

        return $errors;
    }

    /** @return array<string, list<Constraint>> */
    private function fieldRules(): array
    {
        $rules = [];
        foreach (self::STRING_FIELDS as $field => [$label, $max]) {
            $rules[$field] = match ($field) {
                'name'    => [new Assert\NotBlank(message: 'Name is required.')],
                'email'   => [new Assert\Email(message: 'Enter a valid email address.')],
                'website' => [new Assert\Url(message: 'Website is not a valid URL.', requireTld: false)],
                default   => [],
            };
            $rules[$field][] = new Assert\Length(max: $max, maxMessage: "$label cannot be longer than {{ limit }} characters.");
        }

        return $rules;
    }

    /** @param array<string, mixed> $values */
    private function apply(Client $client, array $values): void
    {
        foreach (array_keys(self::STRING_FIELDS) as $field) {
            if (array_key_exists($field, $values)) {
                $field === 'name'
                    ? $client->setName((string) $values['name'])
                    : $client->{'set'.ucfirst($field)}($values[$field]);
            }
        }
        if (array_key_exists('isActive', $values)) {
            $client->setIsActive($values['isActive']);
        }
    }

    private function describe(Client $client): string
    {
        return sprintf('#%d %s', (int) $client->getId(), $client->getName());
    }
}
