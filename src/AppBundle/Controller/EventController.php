<?php

namespace AppBundle\Controller;

use AppBundle\Committee\CommitteePermissions;
use AppBundle\Entity\EventRegistration;
use AppBundle\Event\EventCommand;
use AppBundle\Event\EventContactMembersCommand;
use AppBundle\Event\EventRegistrationCommand;
use AppBundle\Entity\Event;
use AppBundle\Event\Serializer\RegistrationsCsvSerializer;
use AppBundle\Form\ContactMembersType;
use AppBundle\Form\EventCommandType;
use AppBundle\Form\EventRegistrationType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * @Route("/evenements/{uuid}/{slug}", requirements={"uuid": "%pattern_uuid%"})
 */
class EventController extends Controller
{
    /**
     * @Route("", name="app_committee_show_event")
     * @Method("GET")
     */
    public function showAction(Event $event): Response
    {
        return $this->render('events/show.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
        ]);
    }

    /**
     * @Route("/inscription", name="app_committee_attend_event")
     * @Method("GET|POST")
     */
    public function attendAction(Request $request, Event $event): Response
    {
        $committee = $event->getCommittee();

        $command = new EventRegistrationCommand($event, $this->getUser());
        $form = $this->createForm(EventRegistrationType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.registration_handler')->handle($command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.registration.success'));

            return $this->redirectToRoute('app_committee_attend_event_confirmation', [
                'uuid' => (string) $event->getUuid(),
                'slug' => $event->getSlug(),
                'registration' => (string) $command->getRegistrationUuid(),
            ]);
        }

        return $this->render('events/attend.html.twig', [
            'committee_event' => $event,
            'committee' => $committee,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(
     *   path="/confirmation",
     *   name="app_committee_attend_event_confirmation",
     *   condition="request.query.has('registration')"
     * )
     * @Method("GET")
     */
    public function attendConfirmationAction(Request $request, Event $event): Response
    {
        $manager = $this->get('app.event.registration_manager');

        if (!$registration = $manager->findRegistration($uuid = $request->query->get('registration'))) {
            throw $this->createNotFoundException(sprintf('Unable to find event registration by its UUID: %s', $uuid));
        }

        if (!$registration->matches($event, $this->getUser())) {
            throw $this->createAccessDeniedException('Invalid event registration');
        }

        return $this->render('events/attend_confirmation.html.twig', [
            'committee_event' => $event,
            'committee' => $event->getCommittee(),
            'registration' => $registration,
        ]);
    }

    /**
     * @Route("/modifier", name="app_event_edit")
     * @Method("GET|POST")
     * @Security("is_granted('HOST_EVENT', event)")
     */
    public function editAction(Request $request, Event $event): Response
    {
        $command = EventCommand::createFromEvent($event);

        $form = $this->createForm(EventCommandType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.handler')->handleUpdate($event, $command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.update.success'));

            return $this->redirectToRoute('app_committee_show_event', [
                'uuid' => (string) $event->getUuid(),
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('events/edit.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/inscrits", name="app_event_registrations")
     * @Method("GET")
     * @Security("is_granted('HOST_EVENT', event)")
     */
    public function membersAction(Event $event): Response
    {
        $registrations = $this->getDoctrine()->getRepository(EventRegistration::class)->findByEvent($event);

        return $this->render('events/registrations.html.twig', [
            'event' => $event,
            'committee' => $event->getCommittee(),
            'registrations' => $registrations,
        ]);
    }

    /**
     * @Route("/inscrits/exporter", name="app_event_export_members")
     * @Method("POST")
     * @Security("is_granted('HOST_EVENT', event)")
     */
    public function exportMembersAction(Request $request, Event $event): Response
    {
        if (!$this->isCsrfTokenValid('event.export_members', $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF protection token to export members.');
        }

        $registrations = $this->getDoctrine()->getRepository(EventRegistration::class)->findByEvent($event);
        $exported = $this->get('app.event.registration_exporter')->export($registrations);

        return new Response($exported, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inscrits-a-l-evenement.csv"',
        ]);
    }

    /**
     * @Route("/inscrits/contacter", name="app_event_contact_members")
     * @Method("POST")
     * @Security("is_granted('HOST_EVENT', event)")
     */
    public function contactMembersAction(Request $request, Event $event): Response
    {
        if (!$this->isCsrfTokenValid('event.contact_members', $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF protection token to contact members.');
        }

        $registrations = $this->getDoctrine()->getRepository(EventRegistration::class)->findByEvent($event);

        if (empty($registrations)) {
            $this->addFlash('info', $this->get('translator')->trans('committee.event.contact.none'));

            return $this->redirectToRoute('app_event_registrations', [
                'uuid' => $event->getUuid(),
                'slug' => $event->getSlug(),
            ]);
        }

        $command = new EventContactMembersCommand($registrations, $this->getUser());

        $form = $this->createForm(ContactMembersType::class, $command, ['csrf_token_id' => 'event.contact_members'])
            ->add('submit', SubmitType::class)
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->get('app.event.contact_members_handler')->handle($command);
            $this->addFlash('info', $this->get('translator')->trans('committee.event.contact.success'));

            return $this->redirectToRoute('app_event_registrations', [
                'uuid' => $event->getUuid(),
                'slug' => $event->getSlug(),
            ]);
        }

        return $this->render('committee/contact.html.twig', [
            'committee' => $committee,
            'committee_hosts' => $committeeManager->getCommitteeHosts($committee),
            'contacts' => CommitteeUtils::getUuidsFromAdherents($adherents),
            'form' => $form->createView(),
        ]);
    }
}
