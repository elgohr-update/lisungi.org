<?php

namespace App\Controller\Admin;

use App\Entity\BlockedMatch;
use App\Entity\Helper;
use App\MatchFinder\MatchFinder;
use App\Model\MatchedNeeds;
use App\Repository\HelpRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/admin")
 */
class MatchController extends AbstractController
{
    /**
     * @Route("/matches", name="admin_matches")
     */
    public function matches(MatchFinder $matchFinder): Response
    {
        return $this->render('admin/matches.html.twig', [
            'matches' => $matchFinder->searchMatches(),
        ]);
    }

    /**
     * @Route("/match/{ownerUuid}", name="admin_match")
     */
    public function match(MatchFinder $matchFinder, string $ownerUuid): Response
    {
        return $this->render('admin/match.html.twig', [
            'match' => $matchFinder->matchOwnerNeeds($ownerUuid),
        ]);
    }

    /**
     * @Route("/match/close/{type}/{ownerUuid}/{id}", defaults={"id"=null}, name="admin_match_close")
     */
    public function close(MailerInterface $mailer, HelpRequestRepository $repository, string $type, string $ownerUuid, ?Helper $helper, Request $request, string $sender, TranslatorInterface $translator): Response
    {
        $requests = $repository->findBy(['ownerUuid' => $ownerUuid, 'finished' => false]);
        if (!$requests) {
            throw $this->createNotFoundException();
        }

        if ($request->query->has('token')) {
            if (!$this->isCsrfTokenValid('admin_confirm', $request->query->get('token'))) {
                throw $this->createNotFoundException();
            }

            $template = $type;
            if ('vulnerable' === $requests[0]->jobType) {
                $template = 'vulnerable_'.($requests[0]->ccEmail ? 'other' : 'self');
            }

            $to = [$requests[0]->email, $helper->email];
            if ($requests[0]->ccEmail) {
                $to[] = $requests[0]->ccEmail;
            }

            $repository->closeRequestsOf($ownerUuid, $helper, $type);

            $email = (new TemplatedEmail())
                ->from($sender)
                ->to(...$to)
                ->subject($translator->trans('email.match-subject'))
                ->htmlTemplate('emails/match_'.$template.'.html.twig')
                ->context([
                    'requester' => $requests[0],
                    'needs' => new MatchedNeeds($requests),
                    'helper' => $helper,
                ])
            ;

            $mailer->send($email);

            return $this->redirectToRoute('admin_matches');
        }

        return $this->render('admin/confirm.html.twig', [
            'type' => $type,
            'requests' => $requests,
            'ownerUuid' => $ownerUuid,
            'helper' => $helper,
        ]);
    }

    /**
     * @Route("/history", name="admin_match_history")
     */
    public function history(HelpRequestRepository $repository): Response
    {
        return $this->render('admin/history.html.twig', [
            'owners' => $repository->findNeedsByOwner(['finished' => true], ['createdAt' => 'DESC']),
        ]);
    }

    /**
     * @Route("/cancel/{ownerUuid}/{type}", name="admin_match_cancel")
     */
    public function cancel(HelpRequestRepository $repository, EntityManagerInterface $manager, string $ownerUuid, string $type, Request $request): Response
    {
        $requests = $repository->findBy(['ownerUuid' => $ownerUuid, 'finished' => true, 'helpType' => $type]);
        if (!$requests) {
            throw $this->createNotFoundException();
        }

        if ($request->query->has('token')) {
            if (!$this->isCsrfTokenValid('admin_cancel', $request->query->get('token'))) {
                throw $this->createNotFoundException();
            }

            $helper = $requests[0]->matchedWith;
            $repository->cancelMatch($ownerUuid, $type);

            $remove = $request->query->get('remove');

            if ('helper' === $remove) {
                $manager->remove($helper);
            } elseif ('request' === $remove) {
                foreach ($requests as $request) {
                    $manager->remove($request);
                }
            } else {
                $manager->persist(new BlockedMatch(Uuid::fromString($ownerUuid), $requests[0]->matchedWith));
            }

            $manager->flush();

            return $this->redirectToRoute('admin_match_history');
        }

        return $this->render('admin/cancel.html.twig', [
            'type' => $type,
            'requests' => $requests,
            'ownerUuid' => $ownerUuid,
            'remove' => $request->query->get('remove'),
        ]);
    }

    /**
     * @Route("/match/block/{ownerUuid}/{id}", name="admin_match_block")
     */
    public function block(HelpRequestRepository $repository, EntityManagerInterface $manager, string $ownerUuid, Helper $helper, Request $request): Response
    {
        if ($request->query->has('token')) {
            if (!$this->isCsrfTokenValid('admin_block', $request->query->get('token'))) {
                throw $this->createNotFoundException();
            }

            $manager->persist(new BlockedMatch(Uuid::fromString($ownerUuid), $helper));
            $manager->flush();

            return $this->redirectToRoute('admin_match', ['ownerUuid' => $ownerUuid]);
        }

        return $this->render('admin/block.html.twig', [
            'requests' => $repository->findBy(['ownerUuid' => $ownerUuid]),
            'ownerUuid' => $ownerUuid,
            'helper' => $helper,
        ]);
    }
}
