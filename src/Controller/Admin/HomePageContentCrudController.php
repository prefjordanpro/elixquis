<?php
namespace App\Controller\Admin;
use App\Entity\HomePageContent;
use EasyCorp\Bundle\EasyAdminBundle\Config\{Action, Actions, Crud};
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\{BooleanField, FormField, TextField, TextareaField};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class HomePageContentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return HomePageContent::class; }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Page d’accueil')->setEntityLabelInPlural('Page d’accueil')
            ->addFormTheme('admin/content_image_theme.html.twig');
    }
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->add(Crud::PAGE_EDIT, Action::new('carousel', 'Images du carrousel', 'fas fa-images')->linkToUrl($this->generateUrl('admin_header_index')))
            ->add(Crud::PAGE_EDIT, Action::new('site', 'Retour à Modifier le site')->linkToUrl($this->generateUrl('admin_site')));
    }
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Bandeau du haut');
        yield BooleanField::new('announcementEnabled', 'Afficher le bandeau')->renderAsSwitch(false);
        yield TextField::new('announcementMessage', 'Texte du bandeau')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('pageTitle', 'Titre de la page')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Grande image d’accueil');
        yield TextField::new('heroEyebrow', 'Petit texte au-dessus du titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroTitle', 'Titre — première ligne')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroTitleSecondLine', 'Titre — deuxième ligne')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroAccent', 'Partie du titre en italique')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextareaField::new('heroParagraph', 'Paragraphe')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroCta', 'Libellé du bouton')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroUrl', 'Lien du bouton')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('heroNote', 'Légende')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield ContentImage::field('heroImage', 'Image affichée si le carrousel est vide', true);
        yield TextField::new('heroAlt', 'Texte alternatif')->setHelp('Description de l’image utilisée pour l’accessibilité.')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Présentation Elixquis');
        yield TextField::new('storyNumber', 'Numéro')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyEyebrow', 'Petit texte au-dessus du titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyTitle', 'Titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyAccent', 'Titre en italique')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storySubtitle', 'Texte secondaire')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextareaField::new('storyParagraph', 'Paragraphe')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyLinkLabel', 'Texte du lien')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyUrl', 'Lien du bouton')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('storyCaption', 'Légende')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield ContentImage::field('storyImage', 'Image', true);
        yield TextField::new('storyAlt', 'Texte alternatif')->setHelp('Description de l’image utilisée pour l’accessibilité.')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Sélection produits');
        yield TextField::new('selectionEyebrow', 'Petit texte au-dessus du titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('selectionTitle', 'Titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('selectionDescription', 'Description')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('selectionLinkLabel', 'Texte du lien')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('selectionUrl', 'Lien du bouton')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Saveurs');
        yield TextField::new('flavoursEyebrow', 'Petit texte au-dessus du titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('flavoursTitle', 'Titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('flavoursCta', 'Texte du lien')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('flavoursUrl', 'Lien du bouton')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Bloc éditorial');
        yield TextField::new('passionNumber', 'Numéro')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionEyebrow', 'Petit texte au-dessus du titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionTitle', 'Titre — première ligne')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionTitleSecondLine', 'Titre — deuxième ligne')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionAccent', 'Titre en italique')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextareaField::new('passionParagraph', 'Premier paragraphe')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionSecondParagraph', 'Deuxième paragraphe')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('passionCaption', 'Légende')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield ContentImage::field('passionImage', 'Image', true);
        yield TextField::new('passionAlt', 'Texte alternatif')->setHelp('Description de l’image utilisée pour l’accessibilité.')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Réassurance');
        yield TextField::new('paymentTitle', 'Paiement — titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('paymentText', 'Paiement — texte')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('deliveryTitle', 'Livraison — titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('deliveryText', 'Livraison — texte')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('trackingTitle', 'Suivi — titre')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield TextField::new('trackingText', 'Suivi — texte')->setRequired(false)->setFormTypeOption('empty_data', '');
        yield FormField::addTab('Nos revendeurs');
        yield TextField::new('retailersIntro', 'Texte introductif de la page revendeurs')->setRequired(false)->setFormTypeOption('empty_data', '');
    }
    protected function getRedirectResponseAfterSave(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context, string $action): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('admin_home_page_content_edit', ['entityId' => HomePageContent::ID]);
    }
    public function createIndexQueryBuilder(\EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto $searchDto, \EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto $entityDto, \EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection $fields, \EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection $filters): \Doctrine\ORM\QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.id = :singleton')->setParameter('singleton', HomePageContent::ID);
    }
}

