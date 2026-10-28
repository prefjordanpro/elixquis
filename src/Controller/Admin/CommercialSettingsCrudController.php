<?php
namespace App\Controller\Admin;
use App\Entity\CommercialSettings;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Actions, Action, Assets};
use EasyCorp\Bundle\EasyAdminBundle\Field\{FormField, IntegerField, ChoiceField, MoneyField};
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class CommercialSettingsCrudController extends AbstractCrudController
{
    public function __construct(private EntityManagerInterface $em) {}
    public static function getEntityFqcn(): string { return CommercialSettings::class; }
    private function stock(): bool { return $this->getContext()?->getRequest()->query->get('section') === 'stock'; }
    public function configureCrud(Crud $crud): Crud
    {
        $title = $this->stock() ? 'Seuil de stock faible' : 'Livraison offerte';
        return $crud->setEntityLabelInPlural($title)->setEntityLabelInSingular($title)->setPageTitle(Crud::PAGE_EDIT, $title);
    }
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, static fn (Action $a) => $a->setLabel('Enregistrer les réglages'));
    }
    public function configureAssets(Assets $assets): Assets { return $assets->addJsFile('assets/js/admin-commercial-settings.js'); }
    public function index(AdminContext $context): RedirectResponse
    {
        if (!$this->em->find(CommercialSettings::class, 1)) { $this->em->persist(new CommercialSettings()); $this->em->flush(); }
        return $this->redirect($this->configurationUrl());
    }
    private function configurationUrl(): string
    {
        $parameters = ['entityId' => 1];
        if ($this->stock()) $parameters['section'] = 'stock';
        return $this->generateUrl('admin_commercial_settings_edit', $parameters);
    }
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse { return $this->redirect($this->configurationUrl()); }
    public function configureFields(string $pageName): iterable
    {
        if ($this->stock()) {
            yield FormField::addFieldset('Stock');
            yield IntegerField::new('lowStockThreshold', 'Seuil de stock faible')->setHelp('Vous serez alerté lorsqu’un produit atteint cette quantité.')->setFormTypeOption('attr', ['min' => 1]);
            return;
        }
        yield FormField::addFieldset('Livraison offerte');
        yield ChoiceField::new('freeShippingMode', 'Quand offrir la livraison ?')->renderAsNativeWidget()
            ->setChoices(['Désactivée' => 'disabled', 'Toujours offerte' => 'all', 'Offerte à partir d’un montant TTC' => 'threshold'])
            ->setFormTypeOption('attr', ['data-commercial-shipping-mode' => '']);
        yield MoneyField::new('freeShippingThresholdCents', 'Livraison offerte à partir de')->setCurrency('EUR')->setStoredAsCents(true)
            ->setFormTypeOption('row_attr', ['data-commercial-shipping-threshold' => ''])
            ->setHelp('Montant TTC des articles après réductions, hors frais de livraison.');
    }
}
