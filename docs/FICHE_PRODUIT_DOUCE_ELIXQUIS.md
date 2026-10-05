# Finition de la fiche produit

Branche conservée : `feature/identite-visuelle-elixquis`. Aucun changement de logique serveur, formulaire panier, jeton CSRF, stock, paiement, livraison ou `.env.local`. Aucun merge.

## Présentation

- Image : fond ivoire, cadre sans bordure ni barre dorée épaisse, rayon 18 px ; image 12 px, `object-fit: contain`, ratio 3/4, padding 24–40 px. Les images restent entières.
- Titre/prix : titre de poids 500, respiration de 24 px après le titre et 32 px après le prix ; TVA à 0,8 rem. Palette conservée.
- Achat : fond ivoire, rayon 18 px, padding 28 px desktop et 20 px mobile ; suppression du trait vertical et des séparations du cadre. Quantité : rayon 10 px et bordure fine. Badge stock : pilule discrète, vert sémantique conservé.
- CTA principal : langage déjà validé, rayon 10 px, fond sombre puis ambre. Secondaire catégorie : pilule et bordure dorée déjà validées.
- Réassurance : icônes 18 px, poids typographique 500, lignes espacées de 20 px, sans cadre supplémentaire.
- Recette : suppression de la bordure supérieure et du filet vertical, lecture limitée à 62 caractères, interligne 1,9, grille conservée et empilement mobile.
- Retour : lien discret de hauteur cliquable 44 px, avant le contenu et après le fil d’Ariane. Route `app_category` prioritaire, `app_catalogue` en absence de catégorie ; aucun `history.back()`. « Retour aux épices » pour cette catégorie. Texte brun/noir puis ambre au survol/focus.
- CSS de la fiche versionné dans son template pour éviter une ancienne version en cache.

## Vérifications

Lint Twig : 29 fichiers valides. Aucun YAML modifié. PHPUnit : **115 tests / 1 587 assertions réussis** ; notices Doctrine préexistantes. Le parcours testé inclut ajout au panier, augmentation/diminution de quantité, suppression et stock inchangé. Retour catégorie et retour boutique vérifiés. Une attente de test devenue obsolète sur la flèche du carrousel a été remplacée par le contrôle de l’indicateur existant ; l’accueil n’a pas été modifié.

10 contrôles navigateur (produit disponible et produit épuisé) à 1440, 1024, 768, 390 et 320 px réussis. Aucune image manquante, aucun débordement ou erreur JavaScript. Cinq contrôles hover/focus réussis : retour, CTA catégorie et achat. Zones d’action de 44 px minimum ; focus visible de 3 px ; alternatives produit conservées. Couleurs de texte foncé/ivoire et ambre/ivoire vérifiées avec un contraste supérieur à 4,5:1. Réduction des animations conservée.

La fiche réelle « paysage sympa » a également été inspectée aux cinq largeurs et le retour « Aux épices » suivi avec succès. Apache local ne résout pas les routes produit sans `index.php`. La revue réelle a utilisé ce point d’entrée et un remplacement des chemins des assets uniquement dans le navigateur de test ; aucune configuration serveur modifiée. Ce problème local de réécriture reste distinct de la passe UI.

Les données de ce produit sont des données de démonstration : photo paysage et description contenant du Lorem Ipsum. Elles ont été conservées sans inventer une recette ou modifier la base. De vrais contenus devront les remplacer dans l’administration.

## Fichiers

- `templates/product/index.html.twig` : retour et version CSS.
- `public/assets/css/purchase.css` : règles de présentation de la fiche, sans changer les règles checkout.
- `tests/UxPresentationTest.php`, `tests/BrandingPresentationTest.php` : navigation et contrôle existant adaptés.
- `tools/check-product-ui.cjs` : revue navigateur des pages exportées par tests isolés.
- Ce compte rendu et captures dans `docs/branding-review/fiche-produit/`.

[Desktop](branding-review/fiche-produit/produit-1440.png) · [Mobile](branding-review/fiche-produit/produit-320.png) · [Contrôles](branding-review/fiche-produit/controles.json).
