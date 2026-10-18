<?php
namespace App\Controller\Admin;
use App\Entity\CommercialSettings;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Actions, Action, Assets};
use EasyCorp\Bundle\EasyAdminBundle\Field\{FormField, IntegerField, ChoiceField, MoneyField};
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class CommercialSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return CommercialSettings::class; }
    public function configureCrud(Crud $crud): Crud { return $crud->setEntityLabelInPlural('Configuration commerciale')->setEntityLabelInSingular('Configuration commerciale')->setPageTitle(Crud::PAGE_EDIT, 'Configuration commerciale'); }
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->update(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN, static fn (Action $action) => $action->setLabel('Enregistrer la configuration'));
    }
    public function configureAssets(Assets $assets): Assets { return $assets->addJsFile('assets/js/admin-commercial-settings.js'); }
    public function index(AdminContext $context): RedirectResponse { return $this->redirect($this->configurationUrl()); }
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse { return $this->redirect($this->configurationUrl()); }
    private function configurationUrl(): string
    {
        return $this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction(Action::EDIT)->setEntityId(1)->generateUrl();
    }
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('STOCKS')->setHelp('Stock nul : Rupture de stock. De 1 au seuil inclus : Stock faible. Au-delà : Disponible.');
        yield IntegerField::new('lowStockThreshold', 'Seuil d’alerte stock faible')->setColumns(6)
            ->setHelp('Les produits dont le stock est inférieur ou égal à ce seuil seront signalés comme stock faible.');
        yield FormField::addFieldset('LIVRAISON');
        yield ChoiceField::new('freeShippingMode', 'Livraison offerte')->setColumns(6)->renderAsNativeWidget()
            ->setChoices(['Désactivée' => 'disabled', 'Toujours offerte' => 'all', 'À partir d’un seuil TTC' => 'threshold'])
            ->setFormTypeOption('attr', ['data-commercial-shipping-mode' => '']);
        yield MoneyField::new('freeShippingThresholdCents', 'Seuil de gratuité TTC')->setColumns(6)->setCurrency('EUR')->setStoredAsCents(true)
            ->setFormTypeOption('row_attr', ['data-commercial-shipping-threshold' => ''])
            ->setHelp('Montant des articles après promotions et code, hors livraison. Utilisé uniquement pour la livraison à partir d’un seuil TTC.');
    }
}
