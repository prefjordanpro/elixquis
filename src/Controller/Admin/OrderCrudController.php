<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Action, Actions};
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\{IdField, DateField, TextField, NumberField, AssociationField, Field};
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator
    ) {}

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Commande')
            ->setEntityLabelInPlural('Commandes')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Liste des commandes')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Order $o) => sprintf('Commande #%d', $o->getId()));
    }

    public function configureActions(Actions $actions): Actions
    {
        $toPaid = Action::new('toPaid', 'Marquer payée', 'fa fa-credit-card')
            ->linkToCrudAction('updateToPaid')
            ->displayIf(fn ($e) => $e instanceof Order && $e->getState() === 0);

        $toPreparation = Action::new('toPreparation', 'Passer en préparation', 'fa fa-box-open')
            ->linkToCrudAction('updateToPreparation')
            ->displayIf(fn ($e) => $e instanceof Order && $e->getState() === 1);

        $toShipping = Action::new('toShipping', 'Expédier', 'fa fa-truck')
            ->linkToCrudAction('updateToShipping')
            ->displayIf(fn ($e) => $e instanceof Order && $e->getState() === 2);

        $toCanceled = Action::new('toCanceled', 'Annuler', 'fa fa-times')
            ->linkToCrudAction('updateToCanceled')
            ->displayIf(fn ($e) => $e instanceof Order && $e->getState() < 3);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)     // ⬅️ enlève "Modifier" dans le menu …
            ->remove(Crud::PAGE_INDEX, Action::DELETE)   // (optionnel) enlève "Supprimer"

            // Barre d’actions sur la page DÉTAIL
            ->remove(Crud::PAGE_DETAIL, Action::EDIT)    // ⬅️ enlève le bouton "Modifier"
            ->remove(Crud::PAGE_DETAIL, Action::DELETE)  // (optionnel) enlève "Supprimer"

            // Tes actions de statut
            ->add(Crud::PAGE_DETAIL, $toPaid)
            ->add(Crud::PAGE_DETAIL, $toPreparation)
            ->add(Crud::PAGE_DETAIL, $toShipping)
            ->add(Crud::PAGE_DETAIL, $toCanceled);
    }

    public function configureFields(string $pageName): iterable
    {
        $id           = IdField::new('id');
        $createdAt    = DateField::new('createdAt')->setLabel('Date');
        $state        = NumberField::new('state')->setLabel('Statut')->setTemplatePath('admin/state.html.twig');
        $user         = AssociationField::new('user')->setLabel('Utilisateur');
        $carrierName  = TextField::new('carrierName')->setLabel('Transporteur');
        $carrierPrice = NumberField::new('carrierPrice')->setLabel('Livraison');
        $totalTva     = NumberField::new('totalTva')->setLabel('Total TVA');
        $totalWt      = NumberField::new('totalWt')->setLabel('Total TTC');

        if ($pageName === Crud::PAGE_DETAIL) {
            $customDetail = Field::new('recap')
                ->setVirtual(true)
                ->setLabel(false)
                ->setTemplatePath('admin/order.html.twig')
                ->onlyOnDetail();

            return [$customDetail];
        }

        return [$id, $createdAt, $state, $user, $carrierName, $carrierPrice, $totalTva, $totalWt];
    }

    /** Récupère l'entité Order depuis l'URL (?entityId=...). */
    private function getOrderFromContext(AdminContext $context): Order
    {
        $id = $context->getRequest()->query->get('entityId');
        if (!$id) {
            throw new NotFoundHttpException('Aucune commande ciblée.');
        }
        $order = $this->em->getRepository(Order::class)->find($id);
        if (!$order) {
            throw new NotFoundHttpException('Commande introuvable.');
        }
        return $order;
    }

    private function redirectToDetail(int $id): RedirectResponse
    {
        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($id)
            ->generateUrl();

        return $this->redirect($url);
    }

    // ======== Actions de changement d'état ========

    public function updateToPaid(AdminContext $context): RedirectResponse
    {
        $order = $this->getOrderFromContext($context);
        if ($order->getState() !== 0) {
            $this->addFlash('warning', 'La commande ne peut pas être marquée payée depuis cet état.');
            return $this->redirectToDetail($order->getId());
        }
        $order->setState(1);
        $this->em->flush();
        $this->addFlash('success', 'Commande marquée comme payée.');
        return $this->redirectToDetail($order->getId());
    }

    public function updateToPreparation(AdminContext $context): RedirectResponse
    {
        $order = $this->getOrderFromContext($context);
        if ($order->getState() !== 1) {
            $this->addFlash('warning', 'La commande doit être payée avant la préparation.');
            return $this->redirectToDetail($order->getId());
        }
        $order->setState(2);
        $this->em->flush();
        $this->addFlash('success', 'Commande passée en préparation.');
        return $this->redirectToDetail($order->getId());
    }

    public function updateToShipping(AdminContext $context): RedirectResponse
    {
        $order = $this->getOrderFromContext($context);
        if ($order->getState() !== 2) {
            $this->addFlash('warning', 'La commande doit être en préparation avant expédition.');
            return $this->redirectToDetail($order->getId());
        }
        $order->setState(3);
        $this->em->flush();
        $this->addFlash('success', 'Commande expédiée.');
        return $this->redirectToDetail($order->getId());
    }

    public function updateToCanceled(AdminContext $context): RedirectResponse
    {
        $order = $this->getOrderFromContext($context);
        if ($order->getState() >= 3) {
            $this->addFlash('warning', 'Impossible d’annuler : la commande est déjà expédiée.');
            return $this->redirectToDetail($order->getId());
        }
        $order->setState(4);
        $this->em->flush();
        $this->addFlash('warning', 'Commande annulée.');
        return $this->redirectToDetail($order->getId());
    }
}
