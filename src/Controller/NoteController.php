<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Task;
use App\Security\Voter\NoteVoter;
use App\Service\Note\NoteService;
use App\Service\Pagination\Paginated;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Notes (ADR-101): the list, a note's page, add/edit/delete. Rules: NoteVoter; writes: NoteService. */
#[IsGranted('ROLE_USER')]
#[Route('/note')]
final class NoteController extends AbstractWorkController
{
    private const FORM_FIELDS = ['title', 'body', 'pinned'];

    public function __construct(private readonly NoteService $notes)
    {
    }

    #[Route('', name: 'app_note_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = $request->query->getString('type');
        $filters = [
            'term' => $request->query->getString('q'),
            'type' => array_key_exists($type, NoteService::TYPES) ? $type : null,
        ];

        return $this->render('note/index.html.twig', [
            'page'    => $this->notes->search($this->viewer(), $filters, Paginated::pageFrom($request->query->get('page'))),
            'filters' => $filters,
            'types'   => NoteService::TYPES,
            'params'  => array_filter(['q' => $filters['term'], 'type' => $filters['type']]),
        ]);
    }

    #[Route('/new', name: 'app_note_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $subject = $this->notes->subjectFrom($request->query->all());
        if ($subject === null && array_intersect_key($request->query->all(), NoteService::TYPES) !== []) {
            throw $this->createNotFoundException('That record no longer exists.');
        }
        $this->denyAccessUnlessGranted(NoteVoter::ATTACH, $subject);

        $values = ['title' => '', 'body' => null, 'pinned' => false];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'note_form');
            $values = $this->posted($request);
            $result = $this->notes->create($values, $subject, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Note saved.');

                return $this->redirectTo($result->record);
            }
            $errors = $result->errors;
        }

        return $this->renderForm(null, $subject, $values, $errors);
    }

    #[Route('/{id}', name: 'app_note_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(NoteVoter::VIEW, 'note')]
    public function view(#[MapEntity(id: 'id')] Note $note): Response
    {
        return $this->render('note/view.html.twig', ['note' => $note]);
    }

    #[Route('/{id}/edit', name: 'app_note_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(NoteVoter::EDIT, 'note')]
    public function edit(#[MapEntity(id: 'id')] Note $note, Request $request): Response
    {
        $values = $this->notes->valuesFrom($note);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'note_form');
            $values = $this->posted($request);
            $result = $this->notes->update($note, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', 'Note saved.');

                return $this->redirectTo($note);
            }
            $errors = $result->errors;
        }

        return $this->renderForm($note, $note->getSubject(), $values, $errors);
    }

    #[Route('/{id}/delete', name: 'app_note_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(NoteVoter::EDIT, 'note')]
    public function delete(#[MapEntity(id: 'id')] Note $note, Request $request): Response
    {
        $this->assertCsrf($request, 'note_delete_'.$note->getId());
        $subject = $note->getSubject();
        $this->notes->delete($note, $this->viewer());
        $this->addFlash('success', 'Note deleted.');

        return $subject !== null ? $this->redirectToSubject($subject) : $this->redirectToRoute('app_note_index');
    }

    /** @return array<string, mixed> */
    private function posted(Request $request): array
    {
        return array_intersect_key($request->request->all(), array_flip(self::FORM_FIELDS));
    }

    /** After a save: back to the record the note is on, or to the note itself when it is independent. */
    private function redirectTo(Note $note): Response
    {
        $subject = $note->getSubject();

        return $subject !== null ? $this->redirectToSubject($subject) : $this->redirectToRoute('app_note_view', ['id' => $note->getId()]);
    }

    private function redirectToSubject(Client|Project|Task $subject): Response
    {
        // app_client_view / app_project_view / app_task_view
        $route = sprintf('app_%s_view', Note::typeOf($subject));

        return $this->redirect($this->generateUrl($route, ['id' => $subject->getId()]).'#notes');
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    private function renderForm(?Note $note, Client|Project|Task|null $subject, array $values, array $errors): Response
    {
        return $this->render('note/form.html.twig', [
            'note'        => $note,
            'subject'     => $subject,
            'subjectType' => Note::typeOf($subject),
            'values'  => $values,
            'errors'  => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
