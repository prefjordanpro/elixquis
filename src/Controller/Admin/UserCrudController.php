<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        return $actions->disable(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::NEW, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::DELETE, \EasyCorp\Bundle\EasyAdminBundle\Config\Action::BATCH_DELETE);
    }
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ;
    }

    
    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('firstname')->setLabel('Prénom'),
            TextField::new('lastname')->setLabel('Nom'),
            TextField::new('email')->setLabel('Email')->onlyOnIndex()
        ];
    }
}
