<?php
namespace App\Controller\Admin;

use App\Entity\Revendeur;
use App\Service\RevendeurGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Filters};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{TextField, EmailField, UrlField, BooleanField, IntegerField, NumberField, FormField, DateTimeField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class RevendeurCrudController extends AbstractCrudController
{
    public function __construct(private RevendeurGeocoder $geocoder) {}
    public static function getEntityFqcn(): string { return Revendeur::class; }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Revendeur')->setEntityLabelInPlural('Revendeurs')
            ->setSearchFields(['nom', 'adresse', 'ville', 'codePostal', 'email'])
            ->setDefaultSort(['priorite' => 'ASC', 'nom' => 'ASC']);
    }
    public function configureFilters(Filters $filters): Filters { return $filters->add('actif')->add('ville')->add('codePostal'); }
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $actions->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, fn ($action) => $action->setLabel('Ajouter un revendeur'));
    }
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Revendeur');
        yield TextField::new('nom', 'Nom');
        yield BooleanField::new('actif', 'Visible sur le site')->renderAsSwitch(false)->setTemplatePath('admin/retailer_visibility.html.twig');
        yield IntegerField::new('priorite', 'Ordre d’affichage')->hideOnIndex();
        yield FormField::addFieldset('Adresse');
        yield TextField::new('adresse', 'Adresse');
        yield TextField::new('complementAdresse', 'Complément d’adresse')->hideOnIndex();
        yield TextField::new('codePostal', 'Code postal');
        yield TextField::new('ville', 'Ville');
        yield TextField::new('pays', 'Pays')->hideOnIndex();
        yield FormField::addFieldset('Localisation sur la carte')->collapsible()->renderCollapsed()->setHelp('Calculée automatiquement. Ouvrez cette section seulement pour corriger les deux coordonnées.');
        yield NumberField::new('latitude', 'Latitude')->setNumDecimals(7)->setRequired(false)->hideOnIndex();
        yield NumberField::new('longitude', 'Longitude')->setNumDecimals(7)->setRequired(false)->hideOnIndex();
        yield FormField::addFieldset('Contact');
        yield TextField::new('telephone', 'Téléphone');
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield UrlField::new('siteWeb', 'Site web');
        yield DateTimeField::new('createdAt', 'Création')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->onlyOnDetail();
    }
    private function geocode(EntityManagerInterface $em, Revendeur $entity, bool $new): void
    {
        $original = $em->getUnitOfWork()->getOriginalEntityData($entity);
        $addressChanged = $new;
        foreach (['adresse', 'codePostal', 'ville', 'pays'] as $field) {
            $getter = 'get'.ucfirst($field);
            $addressChanged = $addressChanged || ($original[$field] ?? null) !== $entity->$getter();
        }
        $manual = $entity->getLatitude() !== null && $entity->getLongitude() !== null
            && ($new || ($original['latitude'] ?? null) !== $entity->getLatitude() || ($original['longitude'] ?? null) !== $entity->getLongitude());
        if (!$manual && ($addressChanged || $entity->getLatitude() === null || $entity->getLongitude() === null)) {
            if (!$this->geocoder->locate($entity)) {
                $this->addFlash('warning', 'Adresse non localisée ou service indisponible. Le revendeur est enregistré dans la liste ; renseignez latitude et longitude pour l’afficher sur la carte.');
            }
        }
    }
    #[\Symfony\Component\Routing\Attribute\Route('/admin/revendeur/{id}/visibilite', name: 'admin_revendeur_visibility', methods: ['POST'])]
    public function visibility(int $id, \Symfony\Component\HttpFoundation\Request $request, EntityManagerInterface $em): \Symfony\Component\HttpFoundation\Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('retailer_visibility_'.$id, $request->request->get('_token'))) throw $this->createAccessDeniedException();
        $entity = $em->find(Revendeur::class, $id);
        if (!$entity) throw $this->createNotFoundException('Revendeur introuvable.');
        $entity->setActif($request->request->get('visible') === '1');
        $em->flush();
        $this->addFlash('success', $entity->isActif() ? 'Le revendeur est visible sur le site.' : 'Le revendeur est masqué sur le site.');
        return $this->redirectToRoute('admin_revendeur_index');
    }
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->geocode($entityManager, $entityInstance, true);
        parent::persistEntity($entityManager, $entityInstance);
    }
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->geocode($entityManager, $entityInstance, false);
        parent::updateEntity($entityManager, $entityInstance);
    }
}
