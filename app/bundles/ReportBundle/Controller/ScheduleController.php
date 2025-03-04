<?php

namespace Mautic\ReportBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\ReportBundle\Scheduler\Date\DateBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;

class ScheduleController extends CommonAjaxController
{
    public function indexAction(DateBuilder $dateBuilder, $isScheduled, $scheduleUnit, $scheduleDay, $scheduleMonthFrequency)
    {
        $dates = $dateBuilder->getPreviewDays($isScheduled, $scheduleUnit, $scheduleDay, $scheduleMonthFrequency);

        $html = $this->render(
            '@MauticReport/Schedule/index.html.twig',
            [
                'dates' => $dates,
            ]
        )->getContent();

        return $this->sendJsonResponse(
            [
                'html' => $html,
            ]
        );
    }

    /**
     * Sets report to schedule NOW if possible.
     *
     * @param int $reportId
     *
     * @return JsonResponse
     */
    public function nowAction($reportId)
    {
        /** @var \Mautic\ReportBundle\Model\ReportModel $model */
        $model = $this->getModel('report');

        /** @var \Mautic\ReportBundle\Entity\Report $report */
        $report = $model->getEntity($reportId);

        /** @var \Mautic\CoreBundle\Security\Permissions\CorePermissions $security */
        $security = $this->security;

        if (empty($report)) {
            $this->addFlash('mautic.report.notfound', ['%id%' => $reportId], FlashBag::LEVEL_ERROR, 'messages');

            return $this->flushFlash();
        }

        if (!$security->hasEntityAccess('report:reports:viewown', 'report:reports:viewother', $report->getCreatedBy())) {
            $this->addFlash('mautic.core.error.accessdenied', [], FlashBag::LEVEL_ERROR);

            return $this->flushFlash();
        }

        if ($report->isScheduled()) {
            $this->addFlash('mautic.report.scheduled.already', ['%id%' => $reportId], FlashBag::LEVEL_ERROR);

            return $this->flushFlash();
        }

        $report->setAsScheduledNow($this->user->getEmail());
        $model->saveEntity($report);

        $this->addFlash(
            'mautic.report.scheduled.to.now',
            ['%id%' => $reportId, '%email%' => $this->user->getEmail()]
        );

        return $this->flushFlash();
    }

    /**
     * @return JsonResponse
     */
    private function flushFlash()
    {
        return new JsonResponse(['flashes' => $this->getFlashContent()]);
    }
}
