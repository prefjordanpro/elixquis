# Seconde passe visuelle Elixquis

Travail limité à `feature/identite-visuelle-elixquis`. Cette passe renforce les compositions du brief tout en préservant les formulaires, données, routes, contrôles et actions existants.

## Avant / après

| Première passe | Seconde passe | Référence Elixquis rendue visible |
| --- | --- | --- |
| Header blanc, touches dorées | Header sombre, filet doré, signature verticale, même hauteur | Contraste noir/blanc et barres des planches |
| Hero texte + photo avec espaces intercalaires | Duo clair/sombre bord à bord, rail vertical, titrage éditorial, bandeau de légende | Asymétrie, grandes zones photographiques, petits labels espacés |
| CTA encore classés comme succès | CTA `btn-brand` noirs, survol ambre, petit repère doré | Noir et doré mesuré ; vert limité aux états sémantiques |
| L’esprit présenté comme un bloc beige textuel | Grande photo sombre à gauche et texte légèrement chevauchant, numéro 01 | Superpositions légères et contrastes du brief |
| Histoire en colonnes de texte | Texte à gauche, grande image claire à droite, numéro 02 et cadre incomplet | Alternance gauche/droite et composition éditoriale |
| Cartes produits encadrées | Cartes ouvertes, seule l’image encadrée, photographie 3:4, lien CTA souligné | Blancs généreux, filets fins, hiérarchie épurée |
| Description dans la colonne d’achat | Section recette sous le duo photo/achat, titre et texte séparés par un filet | Mise en scène éditoriale de la recette |
| Bloc d’achat fermé par un cadre | Fond ivoire et barre dorée latérale | Signature verticale sans surcharge |
| Footer sobre | Signature verticale dorée, phrase serif italique, labels espacés | Contraste typographique des étiquettes et du brief |

La palette approuvée reste identique : noir `#1A1A1A`, blanc `#FFFFFF`, ivoire UI `#FAF8F4`, beige `#F4EAD0`, or `#BA9540`, ambre UI `#8B451F`, orange `#E84D1F`, litchi `#EA5463`. Le vert `#356047` sert à la disponibilité, aux statuts positifs et aux confirmations.

## Photos et limites

Deux illustrations d’ambiance provisoires ont été créées avec le skill imagegen et intégrées sous `public/images/editorial/` : dégustation ambrée sur fond sombre et fruits/épices sur fond clair. Elles ne représentent aucun produit réel Elixquis, aucun procédé de fabrication ni portrait de Paul. Les textes alternatifs indiquent leur rôle d’illustration. Aucun logo ou packaging n’a été reconstitué ; aucune planche du client n’a été utilisée comme image du site.

JPEG optimisés, 1536 × 1024 px, environ 246 Ko et 207 Ko ; chargement différé des sections éditoriales. Le visuel sombre est utilisé comme fallback du hero uniquement en l’absence de visuels administrés. Le carrousel conserve ses images et ses contenus administrés ainsi que tout son comportement. Les photos produit demeurent celles du catalogue, sans substitution par un faux produit.

Il reste à fournir les photos officielles, le SVG du logo et les polices de marque. Les intitulés de saveur, caractéristiques et descriptions restent les données existantes ; aucune rubrique vide ou information produit inventée n’a été ajoutée.

## Fichiers concernés

- `public/assets/css/elixquis.css` : thème, compositions, cartes, header/footer et responsive.
- `public/assets/css/purchase.css` : photo produit, achat, recette et filets discrets du checkout.
- `templates/base.html.twig`, `templates/home/index.html.twig`, `templates/product/index.html.twig`, `templates/components/_product_card.html.twig` : présentation.
- Templates publics de compte, connexion, inscription, panier, catégorie, paiement et livraison : remplacement des seules classes de CTA ; formulaires, actions, tokens, conditions et montants inchangés.
- `public/images/editorial/` : deux illustrations provisoires.
- `docs/DESIGN_SYSTEM_ELIXQUIS.md` : règles actualisées.
- `tools/check-branding.cjs` : ajout du contrôle de hauteur du header.

## Validation

115 tests PHPUnit réussis, 1 583 assertions. Lint Twig : 29 templates valides. Lint YAML : 28 fichiers valides. Les notices Doctrine déjà connues restent hors de ce chantier graphique.

Revue navigateur sur les HTML exportés des tests avec SQLite en mémoire et services simulés, sans paiement ou expédition réels. Largeurs : 1440, 768, 390 et 320 px. Le rapport final est dans [les contrôles de la seconde passe](branding-review/passe2/controles.json). Les pages couvrent notamment catalogue, fiche produit, panier, formulaires client, commandes et livraison Sendcloud avec point relais. Contrôles interactifs : recherche, réinitialisation, menu mobile, transitions réduites, flèches, indicateurs, autoplay et absence d’autoplay en mouvement réduit.

Résultat final : 25 pages × 4 largeurs, soit 100 contrôles réussis ; aucun débordement horizontal, image manquante ou erreur JavaScript. Les huit contrôles interactifs passent. La hauteur du header fermé est également vérifiée sur toutes les pages.

Les captures desktop et mobile ont également été examinées visuellement ; le header mobile a été resserré pour éviter le retour à la ligne. Les photos restent dominantes sur mobile, les chevauchements disparaissent et les boutons conservent une cible d’au moins 44 px.

## Comparer et revenir en arrière

[Ouvrir la comparaison première / seconde passe](branding-review/seconde-passe.html).

Les captures de la première passe restent dans `branding-review/passe1/captures/`. Une archive des fichiers CSS/Twig avant cette seconde passe est conservée dans `branding-review/passe1/sources.zip`, avec leurs chemins relatifs. Pour revenir à la première passe, extraire cette archive dans un dossier de revue puis restaurer uniquement les fichiers souhaités après comparaison. Aucun retour arrière automatique n’est effectué et aucun merge dans main n’a été réalisé.

`.env.local`, les fichiers PHP métier, les configurations Stripe/Sendcloud, le stock, les commandes, les remboursements, les webhooks, les cartons et le multi-colis n’ont pas été modifiés.
