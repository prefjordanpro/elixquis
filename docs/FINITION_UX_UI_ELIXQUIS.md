# Finition UX/UI Elixquis

Branche : `feature/identite-visuelle-elixquis`. Direction artistique de la seconde passe conservée : ivoire/noir/ambre, grands titres, photos ambrées et fruitées, filets fins, numérotation éditoriale.

## Corrections du hero

- Suppression des règles CSS répétées et contradictoires : une seule grille et des variantes responsive explicites.
- Deux zones jointives dès 992 px : 39 % pour le texte, 61 % pour le carrousel. Conteneur de 1440 px maximum avec marges latérales de 24 px. Le visuel est plus large, le texte garde sa hiérarchie et des espacements bornés.
- Image et carrousel partagent la même hauteur : 500–580 px sur desktop, 420 px sur tablette et 280–360 px sur mobile. Le fallback n'a plus de dimension implicite différente du carrousel.
- Rail « Épicé & fruité · Elixquis » supprimé. « Fruits · Épices · Caractère » devient une vraie légende dans le même cadre que la photo, séparée par un filet. Le numéro redondant du hero est supprimé.
- Titre, texte, CTA, données du carrousel, flèches, indicateurs, autoplay et respect du mouvement réduit conservés.
- Correction de l'indicateur actif : `background-color` conserve le `background-clip` de Bootstrap et évite le carré parasite dans sa zone de clic.

## Sections éditoriales

- Grille à deux colonnes égales et espaces de 32–64 px sur desktop ; empilement dès la tablette, écart de 24 px sur mobile.
- Chevauchement négatif retiré : la photo et le texte sont désormais associés sans panneau qui semble flotter.
- Les repères `01` et `02` et leur label avec barre verticale sont dans une seule ligne alignée, juste avant le titre. Les labels répétés sont retirés.
- Hauteur des photos définie par la largeur et le ratio : 5:4 sur desktop, 16:9 sur tablette, 4:3 sur mobile. Cadrage du verre à 70 % et des fruits à 35 %/60 %, pour préserver les sujets.
- Alternance photo gauche/texte droite puis texte gauche/photo droite maintenue. Photos existantes conservées, sans nouvelle génération.

## Cartes et CTA

- Photo sans cadre sur quatre côtés : fond ivoire, seul un filet inférieur reste visible. Padding réduit pour donner plus de place à l'image.
- Nom légèrement agrandi, interligne affiné, catégorie discrète ; prix et CTA alignés en bas malgré des noms de longueurs différentes.
- « Découvrir » devient le lien éditorial commun, à 16 px et avec une cible de 44 px minimum. Le symbole Unicode `↗` est supprimé.
- Disponibilité verte, indisponibilité rouge et données catalogue inchangées.
- Trois traitements publics centralisés : principal noir/ambre, secondaire à bordure fine, lien éditorial. Les anciennes classes outline sont des alias du même style secondaire. Sur fond sombre, seules les variables de contraste changent. Les boutons de quantité, suppression, livraison et paiement conservent leurs actions.

## Validation

- Lint Twig : 29 templates valides.
- Lint YAML : 28 fichiers valides.
- PHPUnit : 115 tests réussis, 1 583 assertions. Notices Doctrine préexistantes.
- Contrôles navigateur sur les pages rendues par les tests en SQLite mémoire et services simulés : 1440, 1024, 768, 390 et 320 px. Résultats dans [le rapport navigateur](branding-review/finition/controles.json).
- La revue couvre les pages publiques, fiche produit, panier, formulaires, compte, commandes, checkout et états Sendcloud. Elle contrôle aussi la largeur du visuel dans la grille desktop, l'absence d'espace entre les zones empilées et la hauteur du header.
- Captures dédiées du hero à chaque largeur, avec et sans carrousel, ainsi que des pages essentielles. Vérification visuelle des recadrages, titres, CTA et repères éditoriaux.

Résultat final : 25 pages × 5 largeurs, soit 125 contrôles réussis, sans débordement horizontal, image manquante ou erreur JavaScript. Les neuf contrôles interactifs passent, dont l'autoplay, les flèches, les indicateurs et le mouvement réduit. Le visuel du hero mesure environ 849 px de large à 1440 px et 595 px à 1024 px ; la grille reste à deux zones à ces deux largeurs. Le header conserve 77 px sur desktop/tablette et 69 px sur mobile.

Les tests de navigateur utilisent des données fictives ; aucun paiement, expédition ou changement de base locale n'est effectué. Les images de paysage du catalogue/carrousel de démonstration restent les uploads du dépôt. Les illustrations d'ambiance provisoires déjà intégrées sont conservées et restent à remplacer par les photos officielles du client.

## Comparaison et fichiers

[Comparer avant / après finition](branding-review/finition.html). Captures initiales conservées dans `branding-review/finition-avant/captures/`, sources avant cette passe dans `branding-review/finition-avant/sources.zip`.

Modifications de cette passe : `public/assets/css/elixquis.css`, `templates/home/index.html.twig`, `templates/components/_product_card.html.twig`, outil de contrôle et documentation. Le JavaScript du carrousel et les templates métier d'achat ne sont pas modifiés dans cette passe.

Aucune modification de `.env.local`, des fichiers PHP métier, Stripe, Sendcloud, commandes, stock, remboursements, cartons ou multi-colis. Aucun merge dans main.
