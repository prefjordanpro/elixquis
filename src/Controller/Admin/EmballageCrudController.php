<?php
declare(strict_types=1);
namespace App\Controller\Admin;
use App\Entity\{Emballage, ColisCommande};
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Actions, Action};
use EasyCorp\Bundle\EasyAdminBundle\Field\{TextField, IntegerField, NumberField, BooleanField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class EmballageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Emballage::class; }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Emballage')->setEntityLabelInPlural('Emballages')->setDefaultSort(['priorite' => 'ASC', 'capacite' => 'ASC']);
    }
    public function configureActions(Actions $actions): Actions { return $actions->disable(Action::BATCH_DELETE); }
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('nom', 'Nom');
        yield IntegerField::new('capacite', $pageName === Crud::PAGE_INDEX ? 'Capacité' : 'Capacité (bouteilles)')->setFormTypeOption('attr', ['min' => 1]);
        yield TextField::new('dimensions', 'L × l × H')->hideOnForm();
        yield IntegerField::new('poidsVideGrammes', $pageName === Crud::PAGE_INDEX ? 'Poids emballage (g)' : 'Poids emballage et protections (g)')->setRequired(false)
            ->setHelp('Mesurez le carton vide avec toutes ses protections. Obligatoire avant activation ; aucune valeur estimée automatique.')->setFormTypeOption('attr', ['min' => 1]);
        foreach (['longueurCm' => 'Longueur extérieure (cm)', 'largeurCm' => 'Largeur extérieure (cm)', 'hauteurCm' => 'Hauteur extérieure (cm)'] as $field => $label) {
            yield NumberField::new($field, $label)->setNumDecimals(2)->setRequired(false)->onlyOnForms()
                ->setHelp('Dimension extérieure réelle en cm. Les brouillons de référence contiennent des valeurs indicatives à vérifier avant activation.')
                ->setFormTypeOption('attr', ['min' => 0.01, 'step' => 0.01]);
        }
        yield IntegerField::new('poidsMaxGrammes', 'Poids maximal du colis complet (g)')->setRequired(false)->hideOnIndex()
            ->setHelp('Facultatif, produits + carton + protections.')->setFormTypeOption('attr', ['min' => 1]);
        // Pas de bascule AJAX : l'activation passe par le formulaire et ses validations.
        yield BooleanField::new('actif', 'Actif')->renderAsSwitch(false);
        yield IntegerField::new('priorite', 'Priorité')->hideOnIndex()->setHelp('À capacité identique, la plus petite valeur est prioritaire.');
    }
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityManager->getRepository(ColisCommande::class)->count(['emballage' => $entityInstance])) {
            $this->addFlash('warning', 'Cet emballage est lié à une commande historique. Désactivez-le au lieu de le supprimer.');
            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
