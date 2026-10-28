<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ;
    }

    
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('name')->setLabel('Nom de la catégorie'),
        ];
    }
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $actions->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE);
    }
    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance->getProducts()->isEmpty()) {
            $this->addFlash('warning', 'Cette catégorie contient des produits. Déplacez-les dans une autre catégorie avant de la supprimer.');
            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
