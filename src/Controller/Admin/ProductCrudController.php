<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\Validator\Constraints\Image;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class ProductCrudController extends AbstractCrudController
{
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $submitted = $this->getContext()->getRequest()->request->all('Product');
        try {
            $entityManager->lock($entityInstance, \Doctrine\DBAL\LockMode::OPTIMISTIC, (int) ($submitted['version'] ?? 0));
            parent::updateEntity($entityManager, $entityInstance);
        } catch (\Doctrine\ORM\OptimisticLockException $e) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Le produit a changé depuis l’ouverture du formulaire. Rechargez la page avant de modifier le stock.');
        }
    }

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ;
    }


    public function configureFields(string $pageName): iterable
    {
        $promotionChoices = [];
        foreach (Product::PROMOTION_PERCENTAGES as $percent) { $promotionChoices[$percent.' %'] = $percent; }
        if ($this->getContext()?->getEntity()?->getInstance()?->getPromotionSelection() === -1) { $promotionChoices['Conserver le prix promotionnel historique'] = -1; }
        $required = true;

        if ($pageName == 'edit') {
            $required = false;
        }
        
        return [
            \EasyCorp\Bundle\EasyAdminBundle\Field\Field::new('version')
                ->setFormType(\Symfony\Component\Form\Extension\Core\Type\HiddenType::class)
                ->setFormTypeOption('mapped', false)
                ->setFormTypeOption('data', $this->getContext()?->getEntity()?->getInstance()?->getVersion() ?? 1)
                ->onlyOnForms(),
            IntegerField::new('stock', 'Stock disponible'),
            IntegerField::new('stockLevel', 'Disponibilité')->setTemplatePath('admin/stock.html.twig')->hideOnForm(),
            IntegerField::new('shippingWeightGrams', 'Poids d’expédition (g)')->setRequired(false)->setFormTypeOption('attr', ['min' => 1, 'step' => 1])->setHelp('Poids réel d’une unité, bouteille pleine et bouchon compris, en grammes. L’emballage du colis est ajouté séparément.'),
            BooleanField::new('isActive', 'Produit actif'),
            TextField::new('name')
                ->setLabel('Nom')
                ->setHelp('Nom de votre produit'),
            BooleanField::new('isHomepage')->setLabel('Produit à la une ?')->setHelp("Vous permet d'afficher un produit sur la homepage"),
            SlugField::new('slug')
                ->setTargetFieldName('name')
                ->setLabel('URL')->setHelp('URL de votre catégorie générée automatiquement'),
            TextEditorField::new('description')
                ->setLabel('Description')
                ->setHelp('Description de votre produit'),
            ImageField::new('illustration')
                ->setLabel('image')
                ->setHelp('Image du produit')
                ->setUploadedFileNamePattern('[year]-[month]-[day]-[contenthash].[extension]')
                ->setBasePath('/uploads')
                ->setUploadDir('/public/uploads')
                ->setFormTypeOption('upload_delete', static function ($file): void { /* Les commandes conservent les anciennes illustrations. */ })
                ->setFileConstraints([new Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], mimeTypesMessage: 'Choisissez une image JPEG, PNG ou WebP.')])
                ->setRequired($required),
            NumberField::new('price')
                ->setLabel('Prix H.T')
                ->setHelp('Prix H.T du produit sans le sigle €.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField::new('regularPriceWt', 'Prix normal TTC')->setCurrency('EUR')->setStoredAsCents(false)->hideOnForm(),
            TextField::new('promotionSummary', 'Promotion')->hideOnForm(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField::new('priceWt', 'Prix appliqué TTC')->setCurrency('EUR')->setStoredAsCents(false)->hideOnForm(),
            ChoiceField::new('tva')
                ->setLabel('Taux de TVA')
                ->setChoices([
                '5.5%'=>'5.5',
                '10%'=>'10',
                '20%'=>'20'
            ]),
            AssociationField::new('category')->setLabel('Catégorie associée'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\FormField::addFieldset('Promotion'),
            ChoiceField::new('promotionSelection', 'Réduction')->setChoices($promotionChoices)->setRequired(false)->renderAsNativeWidget()
                ->setFormTypeOption('placeholder', 'Aucune promotion')->onlyOnForms()
                ->setHelp('De 5 à 90 %, par pas de 5. Sans dates, la promotion s’applique immédiatement. Choisissez « Aucune promotion » pour la retirer.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField::new('promotionStartsAt', 'Début promotion inclus')->setRequired(false)->onlyOnForms(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField::new('promotionEndsAt', 'Fin promotion exclue')->setRequired(false)->onlyOnForms(),
        ];
    }
}
