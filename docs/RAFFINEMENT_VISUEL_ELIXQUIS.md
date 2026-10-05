# Raffinement visuel Elixquis

Branche conservée : `feature/identite-visuelle-elixquis`. Palette, textes, CTA, numéros, photos et hero conservés.

## Compositions et images

L’esprit Elixquis : image à gauche, texte à droite. L’histoire d’un passionné : texte à gauche, image à droite. La grille garde cette alternance sur desktop et tablette, jusqu’à 768 px. En dessous, les deux sections suivent le même ordre texte puis image. Le contenu de L’esprit a été remis en premier dans le DOM pour garder aussi un ordre de lecture cohérent au clavier et avec les technologies d’assistance.

Les grandes photos éditoriales n’utilisent plus une hauteur déterminée par un ratio qui les faisait grandir avec le conteneur :

| Écran | Hauteur affichée |
| --- | --- |
| Desktop, dès 992 px | `clamp(280px, 24vw, 360px)` |
| Tablette, 768–991 px | `clamp(240px, 30vw, 290px)` |
| Mobile, sous 768 px | `clamp(220px, 65vw, 260px)` |

`object-fit: cover` est conservé ; point de cadrage à 70 % pour la dégustation ambrée et 35 %/60 % pour les fruits. Marges globales inchangées ; espace entre colonnes resserré sur tablette. Titres adaptés à deux colonnes sur tablette. Le hero et les photos produit ne sont pas redimensionnés dans cette passe.

## Trois traitements de CTA

- Principal : fond noir devenant ambre au survol/focus, texte blanc, repère doré légèrement renforcé.
- Secondaire : fond transparent devenant sombre sur surface claire et ivoire dans la section sombre ; texte et bordure suivent le contraste.
- Lien éditorial : fond très légèrement teinté, soulignement renforcé par un filet supplémentaire de 1 px, mêmes états sur les cartes produit et le storytelling.

Transitions de couleur/fond/bordure de 200 ms. Aucun déplacement, zoom ou ombre portée ajouté. Les catégories reprennent un fond beige et une bordure dorée au survol/focus. L’outline clavier existant reste visible. Les effets sont immédiats avec `prefers-reduced-motion`. Les boutons désactivés ne prennent pas les effets des CTA actifs.

## Contrôles

Lint Twig : 29 fichiers valides. PHPUnit : 115 tests, 1 583 assertions réussies ; notices Doctrine préexistantes.

Résultat navigateur : 125 contrôles de pages et 15 contrôles d’interaction réussis, aucun débordement ni erreur détecté. Captures desktop, tablette et mobile inspectées visuellement.

La revue navigateur utilise les HTML de tests isolés, sur 1440, 1024, 768, 390 et 320 px. Elle contrôle les débordements, images, hauteur du header, grille du hero, hauteur et alternance des photos éditoriales. Elle vérifie également le survol et le focus clavier du principal du hero, du secondaire de sélection, du secondaire sombre, du lien storytelling, du lien produit et des catégories, ainsi que les contrôles existants du carrousel.

[Rapport navigateur](branding-review/raffinement/controles.json) · [Accueil desktop](branding-review/raffinement/accueil-1440.png) · [Tablette](branding-review/raffinement/accueil-768.png) · [Mobile](branding-review/raffinement/accueil-390.png).

## Périmètre et suites

Fichiers modifiés dans cette passe : CSS commun, ordre du premier bloc éditorial dans le template d’accueil, outil de contrôle et documentation. Aucun changement métier, serveur, Stripe, Sendcloud, stock, commandes, remboursements ou `.env.local`. Aucune nouvelle bibliothèque ou image ajoutée.

Les assets officiels du client (photos et logo) restent à fournir pour remplacer les illustrations d’ambiance provisoires et le nom textuel. Aucun autre changement de direction artistique n’est nécessaire pour cette passe.
