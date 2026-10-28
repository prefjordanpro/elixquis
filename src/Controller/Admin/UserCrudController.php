<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\{Field, IntegerField};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $actions->add(Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DETAIL)
            ->remove(Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::EDIT)
            ->update(Crud::PAGE_DETAIL, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::EDIT, fn ($action) => $action->setLabel('Modifier le client'))
            ->update(Crud::PAGE_DETAIL, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::INDEX, fn ($action) => $action->setLabel('Retour à la liste'))
            ->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE);
    }
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Client')
            ->setEntityLabelInPlural('Clients')->setSearchFields(['firstname', 'lastname', 'email'])
            ->setDefaultRowAction(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::DETAIL)
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier le client')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (User $user) => htmlspecialchars((string) $user, ENT_QUOTES, 'UTF-8'))
            ;
    }

    
    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_DETAIL) {
            $user = $this->getContext()->getEntity()->getInstance();
            $orders = $user->getOrders()->toArray();
            usort($orders, fn ($a, $b) => ($b->getCreatedAt() <=> $a->getCreatedAt()) ?: ($b->getId() <=> $a->getId()));
            $paidCents = 0;
            foreach ($orders as $order) {
                $state = $order->getState() === 7 ? $order->getLatestCancellationRequest()?->getPreviousState() : $order->getState();
                if (in_array($state, [1, 2, 3, 5], true) && $order->getStripeRefundStatus() !== 'succeeded') {
                    $paidCents += $order->getTotalCents();
                }
            }
            return [Field::new('clientSummary')->setVirtual(true)->setLabel(false)
                ->setTemplatePath('admin/user_detail.html.twig')
                ->setCustomOption('lastOrder', $orders[0] ?? null)->setCustomOption('paidCents', $paidCents)];
        }
        if ($pageName === Crud::PAGE_EDIT) {
            return [TextField::new('firstname', 'Prénom'), TextField::new('lastname', 'Nom'),
                TextField::new('email', 'Email')->setFormTypeOption('disabled', true)];
        }
        $status = TextField::new('emailVerificationStatus', 'Email vérifié')
            ->setSortable(false)->setTemplatePath('admin/user_email_verified.html.twig');
        return [
            TextField::new('firstname')->setLabel('Prénom'),
            TextField::new('lastname')->setLabel('Nom'),
            TextField::new('email', 'Email')->setFormTypeOption('disabled', true),
            $status,
            IntegerField::new('orderCount', 'Commandes')->setSortable(false)->onlyOnIndex(),
        ];
    }

    #[Route('/admin/client/{id}/renvoyer-verification', name: 'app_admin_user_resend_verification', methods: ['POST'])]
    public function resendVerification(int $id, Request $request, \App\Repository\UserRepository $users, \App\Service\AccountAccess $access): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('admin_user_verify_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user = $users->find($id);
        if (!$user) { throw $this->createNotFoundException(); }
        if ($user->getEmailVerifiedAt() === null) {
            $access->issue($user, 'verify');
            $this->addFlash('success', 'La demande de renvoi de vérification a été traitée.');
        }
        return $this->redirectToRoute('admin_user_detail', ['entityId' => $id]);
    }

}
