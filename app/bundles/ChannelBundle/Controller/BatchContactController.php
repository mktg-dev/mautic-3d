<?php

namespace Mautic\ChannelBundle\Controller;

use Mautic\ChannelBundle\Model\ChannelActionModel;
use Mautic\ChannelBundle\Model\FrequencyActionModel;
use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Form\Type\ContactChannelsType;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class BatchContactController extends AbstractFormController
{
    /**
     * @var ChannelActionModel
     */
    private $channelActionModel;

    /**
     * @var FrequencyActionModel
     */
    private $frequencyActionModel;

    /**
     * @var LeadModel
     */
    private $contactModel;

    public function __construct(
        CorePermissions $security,
        UserHelper $userHelper,
        ChannelActionModel $channelActionModel,
        FrequencyActionModel $frequencyActionModel,
        LeadModel $leadModel
    ) {
        $this->channelActionModel   = $channelActionModel;
        $this->frequencyActionModel = $frequencyActionModel;
        $this->contactModel         = $leadModel;
        parent::__construct($security, $userHelper);
    }

    /**
     * Execute the batch action.
     *
     * @return JsonResponse
     */
    public function setAction(Request $request)
    {
        $params = $request->get('contact_channels', []);
        $ids    = empty($params['ids']) ? [] : json_decode($params['ids']);

        if ($ids && is_array($ids)) {
            $subscribedChannels = isset($params['subscribed_channels']) ? $params['subscribed_channels'] : [];
            $preferredChannel   = isset($params['preferred_channel']) ? $params['preferred_channel'] : null;

            $this->channelActionModel->update($ids, $subscribedChannels);
            $this->frequencyActionModel->update($ids, $params, $preferredChannel);

            $this->addFlash('mautic.lead.batch_leads_affected', [
                '%count%'     => count($ids),
            ]);
        } else {
            $this->addFlash('mautic.core.error.ids.missing');
        }

        return new JsonResponse([
            'closeModal' => true,
            'flashes'    => $this->getFlashContent(),
        ]);
    }

    /**
     * View for batch action.
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $route = $this->generateUrl('mautic_channel_batch_contact_set');

        return $this->delegateView([
            'viewParameters' => [
                'form'         => $this->createForm(ContactChannelsType::class, [], [
                    'action'        => $route,
                    'channels'      => $this->contactModel->getPreferenceChannels(),
                    'public_view'   => false,
                    'save_button'   => true,
                ])->createView(),
            ],
            'contentTemplate' => '@MauticLead/Batch/channel.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'leadBatch',
                'route'         => $route,
            ],
        ]);
    }
}
