<?php

namespace Mautic\EmailBundle\Controller\Api;

use Doctrine\ORM\EntityNotFoundException;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\RandomHelper\RandomHelperInterface;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\MonitoredEmail\Processor\Reply;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @extends CommonApiController<Email>
 */
class EmailApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * @var EmailModel|null
     */
    protected $model = null;

    /**
     * @var array<string, mixed>
     */
    protected $extraGetEntitiesArguments = ['ignoreListJoin' => true];

    public function initialize(ControllerEvent $event)
    {
        $emailModel = $this->getModel('email');
        \assert($emailModel instanceof EmailModel);

        $this->model            = $emailModel;
        $this->entityClass      = Email::class;
        $this->entityNameOne    = 'email';
        $this->entityNameMulti  = 'emails';
        $this->serializerGroups = ['emailDetails', 'categoryList', 'publishDetails', 'assetList', 'formList', 'leadListList'];
        $this->dataInputMasks   = [
            'customHtml'     => 'html',
            'dynamicContent' => [
                'content' => 'html',
                'filters' => [
                    'content' => 'html',
                ],
            ],
        ];

        parent::initialize($event);
    }

    /**
     * Obtains a list of emails.
     *
     * @return Response
     */
    public function getEntitiesAction(Request $request)
    {
        //get parent level only
        $this->listFilters[] = [
            'column' => 'e.variantParent',
            'expr'   => 'isNull',
        ];

        return parent::getEntitiesAction($request);
    }

    /**
     * Sends the email to it's assigned lists.
     *
     * @param int $id Email ID
     *
     * @return Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function sendAction(Request $request, $id)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity || !$entity->isPublished()) {
            return $this->notFound();
        }

        if (!$this->checkEntityAccess($entity)) {
            return $this->accessDenied();
        }

        $lists = $request->request->get('lists', null);
        $limit = $request->request->get('limit', null);

        list($count, $failed) = $this->model->sendEmailToLists($entity, $lists, $limit);

        $view = $this->view(
            [
                'success'          => 1,
                'sentCount'        => $count,
                'failedRecipients' => $failed,
            ],
            Response::HTTP_OK
        );

        return $this->handleView($view);
    }

    /**
     * Sends the email to a specific lead.
     *
     * @param int $id     Email ID
     * @param int $leadId Lead ID
     *
     * @return Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function sendLeadAction(Request $request, $id, $leadId)
    {
        $entity = $this->model->getEntity($id);
        if (null !== $entity) {
            if (!$this->checkEntityAccess($entity)) {
                return $this->accessDenied();
            }

            /** @var Lead $lead */
            $lead = $this->checkLeadAccess($leadId, 'edit');
            if ($lead instanceof Response) {
                return $lead;
            }

            $post       = $request->request->all();
            $tokens     = (!empty($post['tokens'])) ? $post['tokens'] : [];
            $assetsIds  = (!empty($post['assetAttachments'])) ? $post['assetAttachments'] : [];
            $response   = ['success' => false];

            $cleanTokens = [];

            foreach ($tokens as $token => $value) {
                $value = InputHelper::clean($value);
                if (!preg_match('/^{.*?}$/', $token)) {
                    $token = '{'.$token.'}';
                }

                $cleanTokens[$token] = $value;
            }

            $leadFields = array_merge(['id' => $leadId], $lead->getProfileFields());
            // Set owner_id to support the "Owner is mailer" feature
            if ($lead->getOwner()) {
                $leadFields['owner_id'] = $lead->getOwner()->getId();
            }

            $result = $this->model->sendEmail(
                $entity,
                $leadFields,
                [
                    'source'            => ['api', 0],
                    'tokens'            => $cleanTokens,
                    'assetAttachments'  => $assetsIds,
                    'return_errors'     => true,
                    'ignoreDNC'         => true,
                    'email_type'        => 'transactional',
                ]
            );

            if (is_bool($result)) {
                $response['success'] = $result;
            } else {
                $response['failed'] = $result;
            }

            $view = $this->view($response, Response::HTTP_OK);

            return $this->handleView($view);
        }

        return $this->notFound();
    }

    /**
     * @param string $trackingHash
     *
     * @return Response
     */
    public function replyAction(Reply $replyService, RandomHelperInterface $randomHelper, $trackingHash)
    {
        try {
            $replyService->createReplyByHash($trackingHash, "api-{$randomHelper->generate()}");
        } catch (EntityNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->handleView(
            $this->view(['success' => true], Response::HTTP_CREATED)
        );
    }
}
