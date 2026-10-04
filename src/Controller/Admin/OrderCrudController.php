<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\{OrderManager, StripePayment};
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{AssociationField, DateField, Field, IdField, NumberField, TextField};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Order::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Commande')->setEntityLabelInPlural('Commandes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_DETAIL) {
            return [Field::new('recap')->setVirtual(true)->setLabel(false)->setTemplatePath('admin/order.html.twig')->onlyOnDetail()];
        }
        return [IdField::new('id'), DateField::new('createdAt', 'Date'),
            NumberField::new('state', 'Statut')->setTemplatePath('admin/state.html.twig'),
            AssociationField::new('user', 'Client'), TextField::new('carrierName', 'Transporteur'),
            NumberField::new('carrierPrice', 'Livraison'), NumberField::new('totalTva', 'TVA totale'), NumberField::new('totalWt', 'Total TTC')];
    }

    #[Route('/admin/commande/{id}/statut', name: 'app_admin_order_state', methods: ['POST'])]
    public function changeState(int $id, Request $request, OrderRepository $repository, OrderManager $orders, StripePayment $payment): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('order_state_'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $order = $repository->find($id);
        if (!$order) { throw $this->createNotFoundException('Commande introuvable.'); }
        $target = $request->request->getInt('state', -1);
        try {
            if ($target === 4) {
                $manualRefund = $payment->cancelForAdmin($order);
                if ($manualRefund) {
                    $this->addFlash('warning', 'Commande payée annulée : le remboursement doit être effectué manuellement dans Stripe. Aucun remboursement automatique n’a été déclenché.');
                }
            }
            elseif ($target === 1) { $payment->verify($order); }
            else { $orders->advance($order, $order->getState(), $target); }
            $this->addFlash('success', 'Statut de la commande mis à jour.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage()); }
        catch (\Stripe\Exception\ApiErrorException $e) { $this->addFlash('warning', 'Le service de paiement est indisponible.'); }
        return $this->redirectToRoute('admin_order_detail', ['entityId' => $id]);
    }
}
