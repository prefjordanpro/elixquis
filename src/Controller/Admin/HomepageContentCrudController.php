<?php
namespace App\Controller\Admin;
use App\Entity\HomepageContent;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Crud, Actions, Action};
use EasyCorp\Bundle\EasyAdminBundle\Field\{TextField, TextareaField, ImageField};
use Symfony\Component\Validator\Constraints\Image;
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
class HomepageContentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return HomepageContent::class; }
    public function configureCrud(Crud $crud): Crud { return $crud->setEntityLabelInPlural('Accueil du site')->setEntityLabelInSingular('Contenu d’accueil')->setDefaultSort(['id' => 'ASC']); }
    public function configureActions(Actions $actions): Actions { return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE); }
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Contenu')->hideOnForm();
        $entry = $this->getContext()?->getEntity()?->getInstance();
        if ($entry instanceof HomepageContent && $entry->getType() === 'image') {
            yield ImageField::new('value', $entry->getLabel())->setBasePath(str_contains($entry->getValue(), '/') ? '/' : '/uploads/homepage')->setUploadDir('public/uploads/homepage')
                ->setHelp('<img src="/'.htmlspecialchars($entry->getImagePath(), ENT_QUOTES).'" alt="Image actuelle" style="max-width:240px;max-height:160px;object-fit:contain">')
                ->setFormTypeOption('help_html', true)
                ->setUploadedFileNamePattern('[randomhash].[extension]')->setRequired(false)
                ->setFormTypeOption('allow_delete', false)
                ->setFormTypeOption('upload_delete', static function ($file): void { /* Conservation des images partagées. */ })
                ->setFileConstraints([new Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]);
            yield ImageField::new('imagePath', 'Image actuelle')->setBasePath('/')->hideOnForm();
        } else {
            yield TextareaField::new('value', $entry instanceof HomepageContent ? $entry->getLabel() : 'Valeur')->setRequired(false)->setTemplatePath('admin/homepage_value.html.twig');
        }
    }
}
