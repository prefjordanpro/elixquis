<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Singleton with typed accessors over sectioned JSON in the existing key/value table.
 * Legacy rows are retained. No raw JSON or layout editor is exposed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'homepage_content')]
class HomePageContent
{
    public const ID = 'home';
    public const DEFAULTS = [
'announcement' => [
'announcementEnabled' => true,
'announcementMessage' => 'Rhum arrangé artisanal • Livraison en France',
'pageTitle' => 'Elixquis — Découvrez nos rhums arrangés'
],
'hero' => [
'heroEyebrow' => 'Rhums arrangés artisanaux',
'heroTitle' => 'Le caractère',
'heroTitleSecondLine' => 'du rhum.',
'heroAccent' => 'L’exquis des fruits.',
'heroParagraph' => 'Des rhums arrangés, des fruits et des épices. Un univers gourmand à découvrir, pour des moments conviviaux.',
'heroCta' => 'Découvrir la boutique ',
'heroUrl' => '/catalogue',
'heroNote' => 'Fruits · Épices · Caractère',
'heroImage' => 'images/editorial/degustation-ambree-v2.jpg',
'heroAlt' => 'Illustration d’ambiance : un verre ambré et un zeste d’orange'
],
'story' => [
'storyNumber' => '01',
'storyEyebrow' => 'L’esprit Elixquis',
'storyTitle' => 'L’élixir.',
'storyAccent' => 'L’exquis.',
'storySubtitle' => 'Deux mots, une invitation à découvrir.',
'storyParagraph' => 'Elixquis associe l’univers de l’élixir à la délicatesse de l’exquis. Les fruits et les épices y rencontrent le caractère du rhum, avec une place essentielle accordée aux ingrédients naturels.',
'storyLinkLabel' => 'Explorer les recettes',
'storyUrl' => '/catalogue',
'storyCaption' => 'Le goût des moments partagés',
'storyImage' => 'images/editorial/degustation-ambree-v2.jpg',
'storyAlt' => 'Illustration d’ambiance : lumière chaude sur un verre de dégustation'
],
'selection' => [
'selectionEyebrow' => 'La sélection Elixquis',
'selectionTitle' => 'À découvrir, à savourer.',
'selectionDescription' => 'Explorez les produits mis en avant dans notre boutique.',
'selectionLinkLabel' => 'Voir toute la boutique ↗',
'selectionUrl' => '/catalogue'
],
'flavours' => [
'flavoursEyebrow' => 'À chacun ses envies',
'flavoursTitle' => 'Choisissez vos saveurs.',
'flavoursCta' => 'Voir toutes les saveurs ',
'flavoursUrl' => '/catalogue#catalogue-saveurs'
],
'passion' => [
'passionNumber' => '02',
'passionEyebrow' => 'L’histoire d’un passionné',
'passionTitle' => 'Tout commence',
'passionTitleSecondLine' => 'par une ',
'passionAccent' => 'découverte.',
'passionParagraph' => 'Lors d’un séjour au ski entre amis, Paul découvre les rhums arrangés. Le mélange du rhum, des fruits et des épices lui donne envie de créer ses propres compositions, d’abord pour lui et ses proches.',
'passionSecondParagraph' => 'Ces moments partagés font naître Elixquis : une passion des saveurs et l’envie de la transmettre.',
'passionCaption' => 'Fruits & épices · L’inspiration',
'passionImage' => 'images/editorial/fruits-epices-v2.jpg',
'passionAlt' => 'Illustration d’ambiance : oranges, litchis, vanille et cannelle sur une table claire'
],
'reassurance' => [
'paymentTitle' => 'Paiement via Stripe',
'paymentText' => 'Le règlement s’effectue sur la page sécurisée de Stripe.',
'deliveryTitle' => 'Livraison au choix',
'deliveryText' => 'Adresse, transporteur et frais affichés avant le paiement.',
'trackingTitle' => 'Votre suivi de commande',
'trackingText' => 'Retrouvez vos achats et leur statut dans votre espace client.'
],
'retailers' => [
'retailersIntro' => 'Retrouvez les saveurs Elixquis près de chez vous.'
]
];
    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $id = self::ID;
    #[ORM\Column(type: 'text')]
    private string $value = '{}';
    public function getId(): string { return $this->id; }
    private function read(string $section, string $key): string|bool
    {
        $sections = json_decode($this->value, true) ?? [];
        return $sections[$section][$key] ?? self::DEFAULTS[$section][$key];
    }
    private function write(string $section, string $key, string|bool $value): static
    {
        $sections = json_decode($this->value, true) ?? [];
        $sections[$section][$key] = $value;
        $this->value = json_encode($sections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }
    public function getAnnouncementEnabled(): bool { return $this->read('announcement', 'announcementEnabled'); }
    public function setAnnouncementEnabled(bool $value): static { return $this->write('announcement', 'announcementEnabled', $value); }
    public function getAnnouncementMessage(): string { return $this->read('announcement', 'announcementMessage'); }
    public function setAnnouncementMessage(string $value): static { return $this->write('announcement', 'announcementMessage', $value); }
    public function getPageTitle(): string { return $this->read('announcement', 'pageTitle'); }
    public function setPageTitle(string $value): static { return $this->write('announcement', 'pageTitle', $value); }
    public function getRetailersIntro(): string { return $this->read('retailers', 'retailersIntro'); }
    public function setRetailersIntro(string $value): static { return $this->write('retailers', 'retailersIntro', $value); }
    public function getHeroEyebrow(): string { return $this->read('hero', 'heroEyebrow'); }
    public function setHeroEyebrow(string $value): static { return $this->write('hero', 'heroEyebrow', $value); }
    public function getHeroTitle(): string { return $this->read('hero', 'heroTitle'); }
    public function setHeroTitle(string $value): static { return $this->write('hero', 'heroTitle', $value); }
    public function getHeroTitleSecondLine(): string { return $this->read('hero', 'heroTitleSecondLine'); }
    public function setHeroTitleSecondLine(string $value): static { return $this->write('hero', 'heroTitleSecondLine', $value); }
    public function getHeroAccent(): string { return $this->read('hero', 'heroAccent'); }
    public function setHeroAccent(string $value): static { return $this->write('hero', 'heroAccent', $value); }
    public function getHeroParagraph(): string { return $this->read('hero', 'heroParagraph'); }
    public function setHeroParagraph(string $value): static { return $this->write('hero', 'heroParagraph', $value); }
    public function getHeroCta(): string { return $this->read('hero', 'heroCta'); }
    public function setHeroCta(string $value): static { return $this->write('hero', 'heroCta', $value); }
    public function getHeroNote(): string { return $this->read('hero', 'heroNote'); }
    public function setHeroNote(string $value): static { return $this->write('hero', 'heroNote', $value); }
    public function getStoryNumber(): string { return $this->read('story', 'storyNumber'); }
    public function setStoryNumber(string $value): static { return $this->write('story', 'storyNumber', $value); }
    public function getStoryEyebrow(): string { return $this->read('story', 'storyEyebrow'); }
    public function setStoryEyebrow(string $value): static { return $this->write('story', 'storyEyebrow', $value); }
    public function getStoryTitle(): string { return $this->read('story', 'storyTitle'); }
    public function setStoryTitle(string $value): static { return $this->write('story', 'storyTitle', $value); }
    public function getStoryAccent(): string { return $this->read('story', 'storyAccent'); }
    public function setStoryAccent(string $value): static { return $this->write('story', 'storyAccent', $value); }
    public function getStorySubtitle(): string { return $this->read('story', 'storySubtitle'); }
    public function setStorySubtitle(string $value): static { return $this->write('story', 'storySubtitle', $value); }
    public function getStoryParagraph(): string { return $this->read('story', 'storyParagraph'); }
    public function setStoryParagraph(string $value): static { return $this->write('story', 'storyParagraph', $value); }
    public function getStoryLinkLabel(): string { return $this->read('story', 'storyLinkLabel'); }
    public function setStoryLinkLabel(string $value): static { return $this->write('story', 'storyLinkLabel', $value); }
    public function getStoryCaption(): string { return $this->read('story', 'storyCaption'); }
    public function setStoryCaption(string $value): static { return $this->write('story', 'storyCaption', $value); }
    public function getSelectionEyebrow(): string { return $this->read('selection', 'selectionEyebrow'); }
    public function setSelectionEyebrow(string $value): static { return $this->write('selection', 'selectionEyebrow', $value); }
    public function getSelectionTitle(): string { return $this->read('selection', 'selectionTitle'); }
    public function setSelectionTitle(string $value): static { return $this->write('selection', 'selectionTitle', $value); }
    public function getSelectionDescription(): string { return $this->read('selection', 'selectionDescription'); }
    public function setSelectionDescription(string $value): static { return $this->write('selection', 'selectionDescription', $value); }
    public function getSelectionLinkLabel(): string { return $this->read('selection', 'selectionLinkLabel'); }
    public function setSelectionLinkLabel(string $value): static { return $this->write('selection', 'selectionLinkLabel', $value); }
    public function getFlavoursEyebrow(): string { return $this->read('flavours', 'flavoursEyebrow'); }
    public function setFlavoursEyebrow(string $value): static { return $this->write('flavours', 'flavoursEyebrow', $value); }
    public function getFlavoursTitle(): string { return $this->read('flavours', 'flavoursTitle'); }
    public function setFlavoursTitle(string $value): static { return $this->write('flavours', 'flavoursTitle', $value); }
    public function getFlavoursCta(): string { return $this->read('flavours', 'flavoursCta'); }
    public function setFlavoursCta(string $value): static { return $this->write('flavours', 'flavoursCta', $value); }
    public function getPassionNumber(): string { return $this->read('passion', 'passionNumber'); }
    public function setPassionNumber(string $value): static { return $this->write('passion', 'passionNumber', $value); }
    public function getPassionEyebrow(): string { return $this->read('passion', 'passionEyebrow'); }
    public function setPassionEyebrow(string $value): static { return $this->write('passion', 'passionEyebrow', $value); }
    public function getPassionTitle(): string { return $this->read('passion', 'passionTitle'); }
    public function setPassionTitle(string $value): static { return $this->write('passion', 'passionTitle', $value); }
    public function getPassionTitleSecondLine(): string { return $this->read('passion', 'passionTitleSecondLine'); }
    public function setPassionTitleSecondLine(string $value): static { return $this->write('passion', 'passionTitleSecondLine', $value); }
    public function getPassionAccent(): string { return $this->read('passion', 'passionAccent'); }
    public function setPassionAccent(string $value): static { return $this->write('passion', 'passionAccent', $value); }
    public function getPassionParagraph(): string { return $this->read('passion', 'passionParagraph'); }
    public function setPassionParagraph(string $value): static { return $this->write('passion', 'passionParagraph', $value); }
    public function getPassionSecondParagraph(): string { return $this->read('passion', 'passionSecondParagraph'); }
    public function setPassionSecondParagraph(string $value): static { return $this->write('passion', 'passionSecondParagraph', $value); }
    public function getPassionCaption(): string { return $this->read('passion', 'passionCaption'); }
    public function setPassionCaption(string $value): static { return $this->write('passion', 'passionCaption', $value); }
    #[Assert\Regex(pattern: '~^(?:images/editorial/|uploads/)[a-zA-Z0-9_.-]+$~')]
    public function getHeroImage(): string { return $this->read('hero', 'heroImage'); }
    public function setHeroImage(string $value): static { return $this->write('hero', 'heroImage', $value); }
    #[Assert\Regex(pattern: '~^(?:images/editorial/|uploads/)[a-zA-Z0-9_.-]+$~')]
    public function getStoryImage(): string { return $this->read('story', 'storyImage'); }
    public function setStoryImage(string $value): static { return $this->write('story', 'storyImage', $value); }
    #[Assert\Regex(pattern: '~^(?:images/editorial/|uploads/)[a-zA-Z0-9_.-]+$~')]
    public function getPassionImage(): string { return $this->read('passion', 'passionImage'); }
    public function setPassionImage(string $value): static { return $this->write('passion', 'passionImage', $value); }
    public function getHeroAlt(): string { return $this->read('hero', 'heroAlt'); }
    public function setHeroAlt(string $value): static { return $this->write('hero', 'heroAlt', $value); }
    public function getStoryAlt(): string { return $this->read('story', 'storyAlt'); }
    public function setStoryAlt(string $value): static { return $this->write('story', 'storyAlt', $value); }
    public function getPassionAlt(): string { return $this->read('passion', 'passionAlt'); }
    public function setPassionAlt(string $value): static { return $this->write('passion', 'passionAlt', $value); }
    #[\App\Validator\PublicContentUrl]
    public function getHeroUrl(): string { return $this->read('hero', 'heroUrl'); }
    public function setHeroUrl(string $value): static { return $this->write('hero', 'heroUrl', $value); }
    #[\App\Validator\PublicContentUrl]
    public function getStoryUrl(): string { return $this->read('story', 'storyUrl'); }
    public function setStoryUrl(string $value): static { return $this->write('story', 'storyUrl', $value); }
    #[\App\Validator\PublicContentUrl]
    public function getSelectionUrl(): string { return $this->read('selection', 'selectionUrl'); }
    public function setSelectionUrl(string $value): static { return $this->write('selection', 'selectionUrl', $value); }
    #[\App\Validator\PublicContentUrl]
    public function getFlavoursUrl(): string { return $this->read('flavours', 'flavoursUrl'); }
    public function setFlavoursUrl(string $value): static { return $this->write('flavours', 'flavoursUrl', $value); }
    public function getPaymentTitle(): string { return $this->read('reassurance', 'paymentTitle'); }
    public function setPaymentTitle(string $value): static { return $this->write('reassurance', 'paymentTitle', $value); }
    public function getPaymentText(): string { return $this->read('reassurance', 'paymentText'); }
    public function setPaymentText(string $value): static { return $this->write('reassurance', 'paymentText', $value); }
    public function getDeliveryTitle(): string { return $this->read('reassurance', 'deliveryTitle'); }
    public function setDeliveryTitle(string $value): static { return $this->write('reassurance', 'deliveryTitle', $value); }
    public function getDeliveryText(): string { return $this->read('reassurance', 'deliveryText'); }
    public function setDeliveryText(string $value): static { return $this->write('reassurance', 'deliveryText', $value); }
    public function getTrackingTitle(): string { return $this->read('reassurance', 'trackingTitle'); }
    public function setTrackingTitle(string $value): static { return $this->write('reassurance', 'trackingTitle', $value); }
    public function getTrackingText(): string { return $this->read('reassurance', 'trackingText'); }
    public function setTrackingText(string $value): static { return $this->write('reassurance', 'trackingText', $value); }
}

