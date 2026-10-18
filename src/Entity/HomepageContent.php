<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class HomepageContent
{
    // Clés fixes : seuls les contenus sont éditables, jamais le layout ni le CSS.
    public const DEFAULTS = [
        'announcement' => ['Bandeau supérieur', 'text', 'Rhum arrangé artisanal • Livraison en France'],
        'hero_label' => ['Hero · surtitre', 'text', 'Rhums arrangés artisanaux'],
        'hero_title' => ['Hero · titre', 'text', "Le caractère\ndu rhum."],
        'hero_italic' => ['Hero · titre italique', 'text', 'L’exquis des fruits.'],
        'hero_description' => ['Hero · description', 'text', 'Des rhums arrangés, des fruits et des épices. Un univers gourmand à découvrir, pour des moments conviviaux.'],
        'hero_cta' => ['Hero · bouton', 'text', 'Découvrir la boutique'],
        'hero_url' => ['Hero · destination', 'url', '/catalogue'],
        'hero_image' => ['Hero · image par défaut', 'image', 'images/editorial/degustation-ambree-v2.jpg'],
        'hero_alt' => ['Hero · alternative image', 'text', 'Illustration d’ambiance : un verre ambré et un zeste d’orange'],
        'hero_note' => ['Hero · légende', 'text', 'Fruits · Épices · Caractère'],
        'story_label' => ['Bloc 01 · surtitre', 'text', 'L’esprit Elixquis'],
        'story_title' => ['Bloc 01 · titre', 'text', 'L’élixir.'],
        'story_italic' => ['Bloc 01 · titre italique', 'text', 'L’exquis.'],
        'story_intro' => ['Bloc 01 · introduction', 'text', 'Deux mots, une invitation à découvrir.'],
        'story_text' => ['Bloc 01 · texte', 'text', 'Elixquis associe l’univers de l’élixir à la délicatesse de l’exquis. Les fruits et les épices y rencontrent le caractère du rhum, avec une place essentielle accordée aux ingrédients naturels.'],
        'story_cta' => ['Bloc 01 · lien', 'text', 'Explorer les recettes'],
        'story_url' => ['Bloc 01 · destination', 'url', '/catalogue'],
        'story_image' => ['Bloc 01 · image', 'image', 'images/editorial/degustation-ambree-v2.jpg'],
        'story_alt' => ['Bloc 01 · alternative image', 'text', 'Illustration d’ambiance : lumière chaude sur un verre de dégustation'],
        'story_caption' => ['Bloc 01 · légende', 'text', 'Le goût des moments partagés'],
        'selection_label' => ['Sélection · surtitre', 'text', 'La sélection Elixquis'],
        'selection_title' => ['Sélection · titre', 'text', 'À découvrir, à savourer.'],
        'selection_text' => ['Sélection · texte', 'text', 'Explorez les produits mis en avant dans notre boutique.'],
        'selection_cta' => ['Sélection · lien', 'text', 'Voir toute la boutique ↗'],
        'flavours_label' => ['Saveurs · surtitre', 'text', 'À chacun ses envies'],
        'flavours_title' => ['Saveurs · titre', 'text', 'Choisissez vos saveurs.'],
        'flavours_cta' => ['Saveurs · lien', 'text', 'Voir toutes les saveurs'],
        'passion_label' => ['Bloc 02 · surtitre', 'text', 'L’histoire d’un passionné'],
        'passion_title' => ['Bloc 02 · titre', 'text', "Tout commence\npar une"],
        'passion_italic' => ['Bloc 02 · titre italique', 'text', 'découverte.'],
        'passion_text' => ['Bloc 02 · texte', 'text', 'Lors d’un séjour au ski entre amis, Paul découvre les rhums arrangés. Le mélange du rhum, des fruits et des épices lui donne envie de créer ses propres compositions, d’abord pour lui et ses proches.'],
        'passion_second' => ['Bloc 02 · second paragraphe', 'text', 'Ces moments partagés font naître Elixquis : une passion des saveurs et l’envie de la transmettre.'],
        'passion_image' => ['Bloc 02 · image', 'image', 'images/editorial/fruits-epices-v2.jpg'],
        'passion_alt' => ['Bloc 02 · alternative image', 'text', 'Illustration d’ambiance : oranges, litchis, vanille et cannelle sur une table claire'],
        'passion_caption' => ['Bloc 02 · légende', 'text', 'Fruits & épices · L’inspiration'],
    ];
    #[ORM\Id, ORM\Column(length: 50)]
    private string $id;
    #[ORM\Column(type: 'text')]
    private string $value;
    public function __construct(string $id = 'announcement', ?string $value = null) { $this->id = $id; $this->value = $value ?? (self::DEFAULTS[$id][2] ?? ''); }
    public function getId(): string { return $this->id; }
    public function getLabel(): string { return self::DEFAULTS[$this->id][0] ?? $this->id; }
    public function getType(): string { return self::DEFAULTS[$this->id][1] ?? 'text'; }
    public function getValue(): string { return $this->value; }
    public function setValue(?string $value): static { if ($value !== null) { $this->value = $value; } return $this; }
    public function getImagePath(): string { return str_contains($this->value, '/') ? $this->value : 'uploads/homepage/'.$this->value; }
    #[Assert\Callback]
    public function validate(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (!isset(self::DEFAULTS[$this->id])) { $context->buildViolation('Contenu inconnu.')->addViolation(); }
        if ($this->getType() === 'url' && !preg_match('~^(?:/(?!/)|https?://)~i', $this->value)) { $context->buildViolation('Utilisez un chemin local ou une URL HTTP(S).')->atPath('value')->addViolation(); }
        if (mb_strlen($this->value) > 10000) { $context->buildViolation('Texte trop long (10 000 caractères maximum).')->atPath('value')->addViolation(); }
    }
}
