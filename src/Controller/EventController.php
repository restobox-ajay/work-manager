<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Event\Event;
use App\Repository\Event\EventCategoryRepository;
use App\Repository\Event\EventRepository;
use App\Service\Event\EventCalendar;
use App\Service\Event\EventService;
use App\Service\File\AttachmentStorage;
use App\Service\Validation\InputValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Events (ADR-126), admins only: important dates — upcoming (yearly ones rolled forward), all events, one event with
 * its files, add/edit/delete. Categories are maintained under Config › Event Categories.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/event')]
final class EventController extends AbstractWorkController
{
    public function __construct(
        private readonly EventService $service,
        private readonly EventRepository $events,
        private readonly EventCategoryRepository $categories,
        private readonly AttachmentStorage $files,
    ) {
    }

    #[Route('', name: 'app_event_upcoming', methods: ['GET'])]
    public function upcoming(Request $request): Response
    {
        $days = $request->query->getInt('days', EventService::WINDOWS[0]);
        $days = in_array($days, EventService::WINDOWS, true) ? $days : EventService::WINDOWS[0];

        return $this->render('event/upcoming.html.twig', [
            'rows'    => $this->service->upcoming($days),
            'days'    => $days,
            'windows' => EventService::WINDOWS,
        ]);
    }

    #[Route('/all', name: 'app_event_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $categoryId = InputValue::int($request->query->get('category'));
        $year = InputValue::int($request->query->get('year'));
        $term = InputValue::text($request->query->get('q'));

        return $this->render('event/index.html.twig', [
            'events'     => $this->events->findFiltered($categoryId, $year, $term),
            'categories' => $this->categories->findAllOrdered(),
            'years'      => $this->events->years(),
            'categoryId' => $categoryId,
            'year'       => $year,
            'q'          => $term,
        ]);
    }

    #[Route('/new', name: 'app_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form(null, $request, $this->service->defaults());
    }

    #[Route('/{id}', name: 'app_event_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Event $event): Response
    {
        $today = new \DateTimeImmutable('today');
        $next = EventCalendar::nextOccurrence($event, $today);

        return $this->render('event/show.html.twig', [
            'e'           => $event,
            'next'        => $next,
            'nextYears'   => $next !== null ? EventCalendar::yearsAt($event, $next) : null,
            'daysAway'    => $next !== null ? EventCalendar::daysBetween($today, $next) : null,
            'sinceDays'   => EventCalendar::daysBetween($event->getEventDate(), $today),
            'attachments' => $this->files->forOwner(Attachment::OWNER_EVENT, (int) $event->getId()),
            'remindChoices' => EventService::REMIND_CHOICES,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_event_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Event $event, Request $request): Response
    {
        return $this->form($event, $request, $this->service->valuesFrom($event));
    }

    #[Route('/{id}/delete', name: 'app_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Event $event, Request $request): Response
    {
        $this->assertCsrf($request, 'event_delete_'.$event->getId());
        $title = $event->getTitle();
        $this->service->delete($event, $this->viewer());
        $this->addFlash('success', sprintf('"%s" deleted, with its files.', $title));

        return $this->redirectToRoute('app_event_index');
    }

    #[Route('/{id}/files', name: 'app_event_files', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function upload(Event $event, Request $request): Response
    {
        $back = $this->generateUrl('app_event_show', ['id' => $event->getId()]).'#files';
        if ($request->request->count() === 0 && $request->files->count() === 0) {
            $this->addFlash('error', sprintf('The upload was too large for the server (at most %s per request). Try fewer or smaller files.', AttachmentStorage::humanSize($this->files->maxBytes())));

            return $this->redirect($back);
        }
        $this->assertCsrf($request, 'event_files_'.$event->getId());
        $files = $request->files->all()['files'] ?? [];
        $result = $this->files->upload(is_array($files) ? $files : [$files], Attachment::OWNER_EVENT, (int) $event->getId(),
            InputValue::text($request->request->get('label')), $this->viewer());
        foreach ($result['errors'] as $error) {
            $this->addFlash('error', $error);
        }
        if ($result['saved'] > 0) {
            $this->addFlash('success', $result['saved'] === 1 ? 'File uploaded.' : sprintf('%d files uploaded.', $result['saved']));
        }

        return $this->redirect($back);
    }

    /** @param array<string, mixed> $values */
    private function form(?Event $event, Request $request, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'event_form');
            $values = $request->request->all();
            $result = $this->service->save($event, $values, $this->viewer());
            if ($result->isSaved()) {
                $this->addFlash('success', $event === null ? 'Event added. You can attach files below.' : 'Event saved.');

                return $this->redirectToRoute('app_event_show', ['id' => $result->record->getId()]);
            }
            $errors = $result->errors;
        }

        return $this->render('event/form.html.twig', [
            'e'             => $event,
            'values'        => $values,
            'errors'        => $errors,
            'categories'    => $this->categories->findSelectable($event?->getCategory()?->getId()),
            'remindChoices' => EventService::REMIND_CHOICES,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
