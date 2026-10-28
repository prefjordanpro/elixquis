<?php
namespace App\Controller\Admin;
use App\Entity\PromoCode;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Assets};
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\{TextField, IntegerField, BooleanField, ChoiceField, DateTimeField, MoneyField, FormField};
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class PromoCodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return PromoCode::class; }
    public function configureCrud(Crud $crud): Crud { return $crud->setEntityLabelInPlural('Codes promo')->setEntityLabelInSingular('Code promo'); }
    public function configureAssets(Assets $assets): Assets { return $assets->addJsFile('assets/js/admin-promo-code.js'); }
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $actions->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, fn ($action) => $action->setLabel('Créer un code promo'));
    }
    public function configureFields(string $pageName): iterable
    {
        $context = $this->getContext();
        $type = $context?->getEntity()?->getInstance()?->getType() ?? 'percent';
        if ($context?->getRequest()->isMethod('POST')) { $type = $context->getRequest()->request->all('PromoCode')['type'] ?? 'percent'; }
        $fixed = $type === 'fixed';
        yield FormField::addFieldset('Le code');
        yield TextField::new('code', 'Code'); yield TextField::new('name', 'Nom interne');
        yield BooleanField::new('active', 'Actif')->renderAsSwitch(false);
        yield ChoiceField::new('type', 'Type')->setChoices(['Pourcentage' => 'percent', 'Montant fixe TTC' => 'fixed'])->renderAsNativeWidget()
            ->setFormTypeOption('attr', ['data-promo-code-type' => '']);
        $value = Field::new('value', 'Valeur')->setFormType($fixed ? \Symfony\Component\Form\Extension\Core\Type\MoneyType::class : \Symfony\Component\Form\Extension\Core\Type\IntegerType::class)
            ->setFormTypeOption('row_attr', ['data-promo-code-value' => ''])
            ->setHelp($fixed ? 'Montant de la remise en euros, par exemple 5,00 €.' : 'Pourcentage entier de réduction, de 1 à 100 %.')
            ->formatValue(static fn ($value, PromoCode $code) => $code->getType() === 'fixed' ? number_format($value / 100, 2, ',', ' ').' €' : $value.' %');
        if ($fixed) { $value->setFormTypeOption('currency', 'EUR')->setFormTypeOption('divisor', 100)->setFormTypeOption('scale', 2); }
        yield $value;
        yield FormField::addFieldset('Période et conditions')->setHelp('Sans dates, le code reste disponible tant qu’il est actif.');
        yield DateTimeField::new('startsAt', 'Date de début (facultative)')->setRequired(false);
        yield DateTimeField::new('endsAt', 'Fin exclue')->setRequired(false);
        yield MoneyField::new('minimumCents', 'Montant minimum du panier TTC')->setCurrency('EUR')->setRequired(false);
        yield IntegerField::new('usageLimit', 'Nombre maximum d’utilisations')->setRequired(false)->setHelp('Laisser vide pour un nombre illimité. Une utilisation est comptabilisée après paiement confirmé.');
        yield IntegerField::new('uses', 'Utilisations confirmées')->hideOnForm();
        yield BooleanField::new('stackable', 'Cumulable avec les promotions produits')->renderAsSwitch(false)->setHelp('Sinon, la remise porte uniquement sur les articles sans promotion produit.');
    }
}
