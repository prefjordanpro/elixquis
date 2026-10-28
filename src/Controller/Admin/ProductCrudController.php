<?php
namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{AssociationField, BooleanField, ChoiceField, DateTimeField, Field, FormField, IntegerField, MoneyField, TextEditorField, TextField};
use Doctrine\ORM\EntityManagerInterface;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Product::class; }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Produit')->setEntityLabelInPlural('Produits')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter un produit')->setPageTitle(Crud::PAGE_EDIT, 'Modifier le produit')
            ->addFormTheme('admin/content_image_theme.html.twig')->setSearchFields(['name', 'category.name'])->setDefaultSort(['name' => 'ASC']);
    }
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->setLabel('Ajouter un produit'));
    }
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Informations');
        yield Field::new('version')->setFormType(\Symfony\Component\Form\Extension\Core\Type\HiddenType::class)
            ->setFormTypeOption('mapped', false)->setFormTypeOption('data', $this->getContext()?->getEntity()?->getInstance()?->getVersion() ?? 1)->onlyOnForms();
        yield TextField::new('name', 'Nom');
        yield AssociationField::new('category', 'Catégorie')->setRequired(false);
        yield TextEditorField::new('description', 'Description')->setNumOfRows(6)->setRequired(false)->setFormTypeOption('empty_data', '')->hideOnIndex();
        yield ContentImage::field('illustration', 'Photo du produit')->setRequired($pageName === Crud::PAGE_NEW);
        yield BooleanField::new('isActive', 'Visible dans la boutique')->renderAsSwitch(false);
        yield BooleanField::new('isHomepage', 'Afficher dans la sélection de l’accueil')->renderAsSwitch(false)->hideOnIndex();
        yield FormField::addTab('Prix');
        yield MoneyField::new('price', 'Prix hors taxes (€)')->setCurrency('EUR')->setStoredAsCents(false)->onlyOnForms()->setHelp('La TVA est ajoutée automatiquement pour afficher le prix TTC au client.');
        yield ChoiceField::new('tva', 'TVA')->setChoices(['5,5 %' => 5.5, '10 %' => 10, '20 %' => 20])->onlyOnForms();
        yield MoneyField::new('regularPriceWt', 'Prix normal TTC')->setCurrency('EUR')->setStoredAsCents(false)->hideOnForm();
        yield MoneyField::new('priceWt', 'Prix appliqué TTC')->setCurrency('EUR')->setStoredAsCents(false)->hideOnForm();
        yield TextField::new('promotionSummary', 'Promotion')->hideOnForm()->setSortable(false);
        yield FormField::addTab('Stock');
        yield IntegerField::new('stock', 'Quantité disponible')->setFormTypeOption('attr', ['min' => 0])->setHelp('Nombre de bouteilles disponibles à la vente.');
        yield Field::new('stockLevel', 'État du stock')->setTemplatePath('admin/stock.html.twig')->hideOnForm()->setSortable(false);
        yield FormField::addTab('Promotion');
        $choices = ['Pas de promotion' => null];
        foreach (Product::PROMOTION_PERCENTAGES as $percent) $choices['−'.$percent.' %'] = $percent;
        if ($this->getContext()?->getEntity()?->getInstance()?->getPromotionSelection() === -1) $choices['Conserver la promotion existante'] = -1;
        yield ChoiceField::new('promotionSelection', 'Réduction')->setChoices($choices)->setRequired(false)->setFormTypeOption('placeholder', false)
            ->setFormTypeOption('choice_value', static fn (?int $value) => $value === null ? '' : (string) $value)
            ->renderAsNativeWidget()->onlyOnForms()->setHelp('Sans dates, la réduction reste active jusqu’à son retrait.');
        yield DateTimeField::new('promotionStartsAt', 'Date de début (facultative)')->setRequired(false)->onlyOnForms();
        yield DateTimeField::new('promotionEndsAt', 'Date de fin (facultative)')->setRequired(false)->onlyOnForms();
        yield FormField::addTab('Livraison');
        yield IntegerField::new('shippingWeightGrams', 'Poids d’une bouteille pleine (g)')->setRequired(false)->onlyOnForms()->setFormTypeOption('attr', ['min' => 1])->setHelp('Bouteille et bouchon compris. Le carton est ajouté automatiquement.');
    }
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $submitted = $this->getContext()->getRequest()->request->all('Product');
        try {
            $entityManager->lock($entityInstance, \Doctrine\DBAL\LockMode::OPTIMISTIC, (int) ($submitted['version'] ?? 0));
            parent::updateEntity($entityManager, $entityInstance);
        } catch (\Doctrine\ORM\OptimisticLockException) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Ce produit a été modifié depuis l’ouverture du formulaire. Rechargez la page avant d’enregistrer.');
        }
    }
}
