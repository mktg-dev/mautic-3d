<?php

namespace Mautic\LeadBundle\Controller\Api;

use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\LeadBundle\Controller\LeadAccessTrait;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * @extends CommonApiController<LeadList>
 */
class ListApiController extends CommonApiController
{
    use LeadAccessTrait;

    /**
     * @var ListModel|null
     */
    protected $model = null;

    public function initialize(ControllerEvent $event)
    {
        $listModel = $this->getModel('lead.list');
        \assert($listModel instanceof ListModel);

        $this->model            = $listModel;
        $this->entityClass      = LeadList::class;
        $this->entityNameOne    = 'list';
        $this->entityNameMulti  = 'lists';
        $this->serializerGroups = ['leadListDetails', 'userList', 'publishDetails', 'ipAddress', 'categoryList'];

        parent::initialize($event);
    }

    /**
     * @deprecated This conversion won't be needed in couple of years.
     *
     * The 'filter' and 'display' fields used to be part of each segment filter root array.
     * Those fields were moved to 'properties' subarray. We have to ensure BC and remove them
     * from filter root array so Symfony forms would not fail with unknown field error.
     */
    protected function prepareParametersForBinding(Request $request, $parameters, $entity, $action)
    {
        if (empty($parameters['filters']) || !is_array($parameters['filters'])) {
            return $parameters;
        }

        foreach ($parameters['filters'] as $key => $filter) {
            $bcFilterValue                                       = $filter['filter'] ?? null;
            $filterValue                                         = $filter['properties']['filter'] ?? $bcFilterValue;
            $parameters['filters'][$key]['properties']['filter'] = $filterValue;

            if (!empty($filter['display']) && !isset($filter['properties']['display'])) {
                $parameters['filters'][$key]['properties']['display'] = $filter['display'];
            }

            unset($parameters['filters'][$key]['filter'], $parameters['filters'][$key]['display']);
        }

        return $parameters;
    }

    /**
     * Obtains a list of smart lists for the user.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getListsAction()
    {
        $listModel = $this->getModel('lead.list');
        \assert($listModel instanceof ListModel);
        $lists   = $listModel->getUserLists();
        $view    = $this->view($lists, Response::HTTP_OK);
        $context = $view->getContext()->setGroups(['leadListList']);
        $view->setContext($context);

        return $this->handleView($view);
    }

    /**
     * Adds a lead to a list.
     *
     * @param int $id     List ID
     * @param int $leadId Lead ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function addLeadAction($id, $leadId)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        $contact = $this->checkLeadAccess($leadId, 'edit');
        if ($contact instanceof Response) {
            return $contact;
        }

        // Does the user have access to the list
        $lists = $this->model->getUserLists();
        if (!isset($lists[$id])) {
            return $this->accessDenied();
        }

        $leadModel = $this->getModel('lead');
        \assert($leadModel instanceof LeadModel);
        $leadModel->addToLists($leadId, $entity);

        $view = $this->view(['success' => 1], Response::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * Adds a leads to a list.
     *
     * @param int $id segement ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function addLeadsAction(Request $request, $id)
    {
        $contactIds = $request->request->get('ids');
        if (null === $contactIds) {
            return $this->returnError('mautic.core.error.badrequest', Response::HTTP_BAD_REQUEST);
        }

        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        // Does the user have access to the list
        $lists = $this->model->getUserLists();
        if (!isset($lists[$id])) {
            return $this->accessDenied();
        }

        $responseDetail = [];
        foreach ($contactIds as $contactId) {
            $contact = $this->checkLeadAccess($contactId, 'edit');
            if ($contact instanceof Response) {
                $responseDetail[$contactId] = ['success' => false];
            } else {
                $leadModel = $this->getModel('lead');
                \assert($leadModel instanceof LeadModel);
                /* @var \Mautic\LeadBundle\Entity\Lead $contact */
                $leadModel->addToLists($contact, $entity);
                $responseDetail[$contact->getId()] = ['success' => true];
            }
        }

        $view = $this->view(['success' => 1, 'details' => $responseDetail], Response::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * Removes given contact from a list.
     *
     * @param int $id     List ID
     * @param int $leadId Lead ID
     *
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function removeLeadAction($id, $leadId)
    {
        $entity = $this->model->getEntity($id);

        if (null === $entity) {
            return $this->notFound();
        }

        $contact = $this->checkLeadAccess($leadId, 'edit');
        if ($contact instanceof Response) {
            return $contact;
        }

        // Does the user have access to the list
        $lists = $this->model->getUserLists();
        if (!isset($lists[$id])) {
            return $this->accessDenied();
        }

        $leadModel = $this->getModel('lead');
        \assert($leadModel instanceof LeadModel);
        $leadModel->removeFromLists($leadId, $entity);

        $view = $this->view(['success' => 1], Response::HTTP_OK);

        return $this->handleView($view);
    }

    /**
     * Checks if user has permission to access retrieved entity.
     *
     * @param mixed  $entity
     * @param string $action view|create|edit|publish|delete
     *
     * @return bool
     */
    protected function checkEntityAccess($entity, $action = 'view')
    {
        if ('create' == $action || 'edit' == $action || 'view' == $action) {
            return $this->security->isGranted('lead:leads:viewown');
        } elseif ('delete' == $action) {
            return $this->factory->getSecurity()->hasEntityAccess(
                true, 'lead:lists:deleteother', $entity->getCreatedBy()
            );
        }

        return parent::checkEntityAccess($entity, $action);
    }
}
