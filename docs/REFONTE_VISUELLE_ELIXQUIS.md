# Refonte visuelle Elixquis — 5 octobre 2026

Branche : `feature/identite-visuelle-elixquis`. Aucun merge dans main. Les changements restent consultables avec `git diff` ; les captures initiales sont conservées séparément.

## Avant / après

| Zone | Avant | Après | Conservé |
| --- | --- | --- | --- |
| Header | Bandeau anthracite, nom discret, navigation sur aplats | Header blanc, nom espacé, barre verticale, filet actif doré | Routes, catégories, commandes, compte, panier, menu mobile et header simplifié du checkout |
| Accueil | Hero arrondi, CTA vert, thème vert/bleu | Hero asymétrique, grands visuels rectangulaires, titres condensés, CTA noir | Carrousel administré, flèches, indicateurs, autoplay et gestion du mouvement réduit |
| Produits | Cartes arrondies, images carrées, ombres au survol | Image 4:5, bordures fines, noms plus présents, accent orange/litchi pertinent | Prix, catégorie, disponibilité, recherche et tri |
| Éditorial | Texte générique sur la boutique | Origine élixir/exquis, histoire documentée de Paul, section beige | Position des sections produits et saveurs |
| Fiche produit | Photo carrée, description systématique | Grande photo 4:5, signature verticale, description conditionnelle | Prix TTC, stock, ajout protégé par CSRF, quantité par ajout, liens |
| Panier / checkout | Vert/bleu, cartes et choix très arrondis | Noir, ivoire, beige, rayons discrets, bordures fines et états lisibles | Actions, montants, étapes, adresses, méthodes, relais, validations |
| Compte | Cartes avec utilitaires Bootstrap d'ombres et grands rayons | Surfaces sobres, menu noir, badges sémantiques harmonisés | Profil, adresses, commandes, suivi, annulation et remboursement |
| Footer | Identité générique, « Rhum 100% Made in France » | Noir, phrase des planches, « Fabriqués en France », filet doré discret | Contact conditionnel, compte, boutique, avertissement alcool, état des informations légales |

L'harmonisation du panier, du checkout et du compte passe par le thème commun ; leurs templates métier n'ont pas été modifiés. Les fichiers PHP métier, la configuration, `.env.local`, Stripe, Sendcloud, les webhooks, le stock et les emballages restent intacts.

## Palette et choix graphiques

Palette centrale : noir `#1A1A1A`, blanc `#FFFFFF`, beige `#F4EAD0`, or `#BA9540`, orange `#E84D1F`, litchi `#EA5463`. Ivoire UI `#FAF8F4`, ambre UI `#8B451F`. Toutes les règles sont détaillées dans [le design system](DESIGN_SYSTEM_ELIXQUIS.md).

Repris : barres verticales, blancs généreux, asymétrie, contraste, couleurs fruitées, grands visuels, titrage condensé, fond beige chaleureux et photographie sombre quand les assets administrés le permettent. Non repris : flèches de présentation, planches comme images du site, dorure simulée, texture rouge omniprésente, grandes ombres, logo approximativement redessiné.

## Fichiers

- `public/assets/css/elixquis.css` : variables, thème commun, signatures éditoriales et responsive.
- `public/assets/css/purchase.css` : habillage des composants d'achat.
- `public/assets/css/custom.css` : ancien fond bleu raccordé au beige.
- `templates/base.html.twig` : header et footer.
- `templates/home/index.html.twig` : hero et storytelling.
- `templates/product/index.html.twig` : grand visuel et description conditionnelle.
- `templates/components/_product_card.html.twig` : accents fruités.
- `tests/BrandingPresentationTest.php` : cas carrousel administré et produit indisponible sans description.
- `tools/check-branding.cjs` : revue navigateur locale reproductible.
- `docs/branding-review/` : captures avant/après et contrôles JSON.

## Validation

- Avant modification : 114 tests, 1 573 assertions, tous réussis.
- Après modification : 115 tests, 1 583 assertions, tous réussis. Le test ajouté couvre les diapositives et commandes du carrousel, la description vide et le bouton désactivé en rupture de stock.
- Lint Twig : 29 fichiers valides. Lint YAML : 28 fichiers valides.
- Contrôles navigateur : 25 pages à quatre largeurs, soit 100 contrôles, sans débordement horizontal, image manquante ou erreur JavaScript. Pages rendues avec SQLite en mémoire, comptes et transactions de démonstration ; aucun appel réel Stripe/Sendcloud. Largeurs 1440, 768, 390 et 320 px. Voir les résultats exacts dans `branding-review/apres/controles.json`.
- Capture initiale : 19 pages à quatre largeurs, aucun débordement, aucune image manquante ou erreur JavaScript. Le contrôle final inclut également le carrousel et les états Sendcloud adresse/méthodes/relais/suivi.
- Huit vérifications interactives réussies : recherche vide, réinitialisation des filtres, menu mobile, transitions réduites, flèches, indicateurs, autoplay et absence d'autoplay en mouvement réduit. Le vérificateur charge les sources des diapositives masquées pour distinguer les images différées des fichiers absents.

Les notices de dépréciation Doctrine sont présentes avant la refonte ; aucune correction métier n'est incluse dans cette intervention.

Reproduire les exports et contrôles (PowerShell, module Playwright Core local déjà disponible) :

```powershell
$env:UX_EXPORT_DIR = Join-Path $env:TEMP 'elixquis-branding-after'
$env:SC_EXPORT_DIR = $env:UX_EXPORT_DIR
php vendor/phpunit/phpunit/phpunit --no-coverage
php bin/console lint:twig templates
php bin/console lint:yaml config
node tools/check-branding.cjs $env:UX_EXPORT_DIR docs/branding-review/apres "$env:TEMP\elixquis-ux-tools\node_modules\playwright-core"
```

## Comparaison et retour arrière

Ouvrir [la comparaison visuelle](branding-review/index.html). Les captures utilisent les mêmes produits et comptes fictifs avant/après. Les paysages présents dans les uploads de démonstration ne sont pas des photos officielles de rhum. Les vues du carrousel et du relais n'ont pas de capture initiale dédiée.

Consulter `git diff` pour la comparaison du code. Aucun commit ni fusion n'est effectué automatiquement. Pour revenir en arrière, utiliser les modifications sélectionnées dans l'IDE ou, après vérification qu'aucun autre travail n'a été ajouté, restaurer uniquement les sept fichiers CSS/Twig cités ci-dessus depuis HEAD. Les nouvelles documentations et captures peuvent rester comme référence.

## À finaliser avec le client

SVG officiel du logo et de l'emblème, PNG transparent, photos HD de dégustation et des bouteilles, détourages, polices et licences web, choix des étiquettes finales, textures sources éventuelles. Les véritables assets permettront de remplacer les photos temporaires et le nom textuel.

Une seule image produit est disponible dans le modèle actuel. Aucune galerie artificielle ni caractéristiques inventées : contenance, degré, ingrédients, notes aromatiques, conseils et inspiration attendent des données structurées réellement renseignées. Le contenu juridique définitif et les pages correspondantes restent à fournir.
