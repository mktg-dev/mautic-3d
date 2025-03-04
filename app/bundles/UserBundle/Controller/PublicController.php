<?php

namespace Mautic\UserBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\UserBundle\Form\Type\PasswordResetConfirmType;
use Mautic\UserBundle\Form\Type\PasswordResetType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class PublicController extends FormController
{
    /**
     * Generates a new password for the user and emails it to them.
     */
    public function passwordResetAction(Request $request)
    {
        /** @var \Mautic\UserBundle\Model\UserModel $model */
        $model = $this->getModel('user');

        $data   = ['identifier' => ''];
        $action = $this->generateUrl('mautic_user_passwordreset');
        $form   = $this->formFactory->create(PasswordResetType::class, $data, ['action' => $action]);

        ///Check for a submitted form and process it
        if ('POST' === $request->getMethod()) {
            if ($isValid = $this->isFormValid($form)) {
                //find the user
                $data = $form->getData();
                $user = $model->getRepository()->findByIdentifier($data['identifier']);

                if (null == $user) {
                    $form['identifier']->addError(new FormError($this->translator->trans('mautic.user.user.passwordreset.nouserfound', [], 'validators')));
                } else {
                    try {
                        $model->sendResetEmail($user);
                        $this->addFlash('mautic.user.user.notice.passwordreset');
                    } catch (\Exception $exception) {
                        $this->addFlash('mautic.user.user.notice.passwordreset.error', [], 'error');
                    }

                    return $this->redirectToRoute('login');
                }
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'form' => $form->createView(),
            ],
            'contentTemplate' => '@MauticUser/Security/reset.html.twig',
            'passthroughVars' => [
                'route' => $action,
            ],
        ]);
    }

    public function passwordResetConfirmAction(Request $request, UserPasswordEncoderInterface $encoder)
    {
        /** @var \Mautic\UserBundle\Model\UserModel $model */
        $model = $this->getModel('user');

        $data   = ['identifier' => '', 'password' => '', 'password_confirm' => ''];
        $action = $this->generateUrl('mautic_user_passwordresetconfirm');
        $form   = $this->formFactory->create(PasswordResetConfirmType::class, [], ['action' => $action]);
        $token  = $request->query->get('token');

        if ($token) {
            $request->getSession()->set('resetToken', $token);
        }

        ///Check for a submitted form and process it
        if ('POST' === $request->getMethod()) {
            if ($isValid = $this->isFormValid($form)) {
                //find the user
                $data = $form->getData();
                /** @var \Mautic\UserBundle\Entity\User $user */
                $user = $model->getRepository()->findByIdentifier($data['identifier']);

                if (null == $user) {
                    $form['identifier']->addError(new FormError($this->translator->trans('mautic.user.user.passwordreset.nouserfound', [], 'validators')));
                } else {
                    if ($request->getSession()->has('resetToken')) {
                        $resetToken = $request->getSession()->get('resetToken');

                        if ($model->confirmResetToken($user, $resetToken)) {
                            $encodedPassword = $model->checkNewPassword($user, $encoder, $data['plainPassword']);
                            $user->setPassword($encodedPassword);
                            $model->saveEntity($user);

                            $this->addFlash('mautic.user.user.notice.passwordreset.success');

                            $request->getSession()->remove('resetToken');

                            return $this->redirectToRoute('login');
                        }

                        return $this->delegateView([
                            'viewParameters' => [
                                'form' => $form->createView(),
                            ],
                            'contentTemplate' => '@MauticUser/Security/resetconfirm.html.twig',
                            'passthroughVars' => [
                                'route' => $action,
                            ],
                        ]);
                    } else {
                        $this->addFlash('mautic.user.user.notice.passwordreset.missingtoken');

                        return $this->redirectToRoute('mautic_user_passwordresetconfirm');
                    }
                }
            }
        }

        return $this->delegateView([
            'viewParameters' => [
                'form' => $form->createView(),
            ],
            'contentTemplate' => '@MauticUser/Security/resetconfirm.html.twig',
            'passthroughVars' => [
                'route' => $action,
            ],
        ]);
    }
}
