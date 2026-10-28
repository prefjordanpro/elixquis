<?php

namespace App\Controller\Admin;

use App\Entity\Header;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class HeaderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Header::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Image du carrousel')->setEntityLabelInPlural('Images du carrousel')
            ->addFormTheme('admin/content_image_theme.html.twig')->setDefaultSort(['position' => 'ASC', 'id' => 'ASC']);
    }

    
    public function configureFields(string $pageName): iterable
    {
        
        $required = true;

        if ($pageName == 'edit') {
            $required = false;
        }
        
        return [
            \EasyCorp\Bundle\EasyAdminBundle\Field\FormField::addFieldset('Affichage de cette image'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField::new('active', 'Actif')->renderAsSwitch(false),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('position', 'Ordre'),
            TextField::new('alt', 'Texte alternatif')->setRequired(false)->setFormTypeOption('empty_data', '')->setHelp('Description de l’image utilisée pour l’accessibilité.'),
            TextField::new('title')->setLabel('Titre'),
            TextareaField::new('content')->setLabel('Contenu'),
            TextField::new('buttonTitle')->setLabel('Titre du bouton'),
            TextField::new('buttonLink')->setLabel('Lien du bouton'),
            ContentImage::field('illustration', 'Photo')
                ->setLabel('Photo')
                ->setHelp('JPEG, PNG ou WebP. Taille maximale : 5 Mo.')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setBasePath('/uploads')
                ->setUploadDir('/public/uploads')
                ->setFileConstraints([ContentImage::constraint()])
                ->setRequired($required),

        ];
    }
    
}
