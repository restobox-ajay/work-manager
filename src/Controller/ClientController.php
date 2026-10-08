<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\Voter\WorkVoter;
use App\Service\Client\ClientCodeGenerator;
use App\Service\Client\ClientService;
use App\Service\Note\NoteService;
use App\Service\Pagination\Paginated;
use App\Service\Validation\InputValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Work\WorkDeleter;

/** Clients (ADR-070). Rules: WorkAccess via WorkVoter; writes: ClientService. */
#[Route('/client')]
final class ClientController extends AbstractWorkController
{
    /** The form's fields, as ClientService names them. */
    private const FORM_FIELDS = [
        'name', 'companyName', 'email', 'clientCode', 'website', 'phone', 'streetAddress1', 'streetAddress2',
        'city', 'province', 'state', 'zipCode', 'country', 'isActive',
    ];

    public function __construct(private readonly ClientService $clients)
    {
    }

    #[Route('', name: 'app_client_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->getString('status');
        $filters = [
            'term'     => InputValue::text($request->query->get('q')),
            'isActive' => match ($status) {
                'active'   => Client::STATUS_ACTIVE,
                'archived' => Client::STATUS_ARCHIVE,
                default    => null,
            },
        ];
        $sort = $request->query->getString('sort');
        $page = $this->clients->search($this->viewer(), $filters, Paginated::pageFrom($request->query->get('page')), $sort);

        return $this->render('client/index.html.twig', [
            'page'    => $page,
            'details' => $this->clients->listDetails($page->items),
            'q'       => $filters['term'],
            'status'  => $status,
            'sort'    => $sort,
        ]);
    }

    #[Route('/new', name: 'app_client_create', methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::CLIENT_CREATE)]
    public function create(Request $request, UserRepository $users): Response
    {
        $values = ['isActive' => Client::STATUS_ACTIVE];
        $managerIds = [];
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'client_form');
            $values = $this->posted($request);
            $managerIds = InputValue::ints($request->request->all()['managers'] ?? []);
            $result = $this->clients->create($values, $managerIds, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Client created.');

                return $this->redirectToRoute('app_client_view', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('client/form.html.twig', [
            'client'         => null,
            'values'         => $values,
            'managerIds'     => $managerIds,
            'canSetManagers' => true,
            'people'         => $users->findActiveOrderedByName(),
            'errors'         => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** Suggests a unique client code for a name; the form calls it as you type (and works without it). */
    #[Route('/code', name: 'app_client_code', methods: ['GET'])]
    #[IsGranted(WorkVoter::CLIENT_CREATE)]
    public function code(Request $request, ClientCodeGenerator $generator): JsonResponse
    {
        $name = InputValue::text($request->query->get('name'));

        return new JsonResponse(['code' => $name === null ? null : $generator->generate($name)]);
    }

    #[Route('/{id}', name: 'app_client_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(WorkVoter::CLIENT_VIEW, 'client')]
    public function view(#[MapEntity(id: 'id')] Client $client, ProjectRepository $projects, NoteService $notes): Response
    {
        return $this->render('client/view.html.twig', [
            'client'   => $client,
            'managers' => $this->clients->managersOf($client),
            'projects' => $projects->findForClient((int) $client->getId()),
            'notes'    => $notes->forSubject($client),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_client_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(WorkVoter::CLIENT_EDIT, 'client')]
    public function edit(#[MapEntity(id: 'id')] Client $client, Request $request, UserRepository $users): Response
    {
        $canSetManagers = $this->isGranted(WorkVoter::CLIENT_ADMINISTER);
        $values = $this->clients->valuesFrom($client);
        $managerIds = array_map(static fn (User $user) => (int) $user->getId(), $this->clients->managersOf($client));
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'client_form');
            $values = $this->posted($request);
            $posted = $canSetManagers ? InputValue::ints($request->request->all()['managers'] ?? []) : null;
            $result = $this->clients->update($client, $values, $posted, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Client updated.');

                return $this->redirectToRoute('app_client_view', ['id' => $client->getId()]);
            }
            $errors = $result->errors;
            $managerIds = $posted ?? $managerIds;
        }

        return $this->render('client/form.html.twig', [
            'client'         => $client,
            'values'         => $values,
            'managerIds'     => $managerIds,
            'canSetManagers' => $canSetManagers,
            'people'         => $canSetManagers ? $users->findActiveOrderedByName() : [],
            'errors'         => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    #[Route('/{id}/remove', name: 'app_client_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(WorkVoter::CLIENT_ADMINISTER)]
    public function remove(#[MapEntity(id: 'id')] Client $client, Request $request): Response
    {
        $this->assertCsrf($request, 'client_remove_'.$client->getId());
        $this->clients->remove($client, $this->viewer());
        $this->addFlash('success', sprintf('Client "%s" archived and removed from the list.', $client->getName()));

        return $this->redirectToRoute('app_client_index');
    }

    /**
     * Permanent delete (ADR-109), admins only: GET shows what goes with the client, POST (with the client's name
     * typed to confirm) deletes it all. Invoices already emailed stay, unlinked.
     */
    #[Route('/{id}/delete', name: 'app_client_delete', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity(id: 'id')] Client $client, Request $request, WorkDeleter $deleter): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'client_delete_'.$client->getId());
            if (self::confirmed($request, $client->getName())) {
                $name = $client->getName();
                $done = $deleter->deleteClient($client, $this->viewer());
                $this->addFlash('success', sprintf('Client "%s" deleted with %d projects and %d tasks.', $name, $done['projects'], $done['tasks']));

                return $this->redirectToRoute('app_client_index');
            }
            $errors[] = 'Type the client\'s name exactly to confirm.';
        }
        $impact = $deleter->clientImpact($client);

        return $this->render('_work/confirm_delete.html.twig', [
            'kind' => 'client', 'name' => $client->getName(), 'errors' => $errors,
            'impact' => ['projects' => $impact['projects'], 'tasks' => $impact['tasks'], 'notes' => $impact['notes'], 'unsent invoices' => $impact['unsentInvoices']],
            'kept' => $impact['sentInvoices'] > 0 ? sprintf('%d invoice(s) already emailed will be kept, unlinked from the client.', $impact['sentInvoices']) : null,
            'action' => $this->generateUrl('app_client_delete', ['id' => $client->getId()]), 'cancel' => $this->generateUrl('app_client_view', ['id' => $client->getId()]),
            'token' => 'client_delete_'.$client->getId(),
        ], new Response(status: $errors === [] ? 200 : 422));
    }

    /** The typed name must match (case and surrounding spaces aside). */
    private static function confirmed(Request $request, string $name): bool
    {
        return mb_strtolower(trim($request->request->getString('confirm'))) === mb_strtolower(trim($name));
    }

    /** @return array<string, mixed> */
    private function posted(Request $request): array
    {
        return array_intersect_key($request->request->all(), array_flip(self::FORM_FIELDS));
    }
}
