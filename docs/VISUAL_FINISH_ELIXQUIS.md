# Ergonomie et présentation éditoriale Elixquis — 18 octobre 2026

Branche exclusive : `feature/refonte-design-elixquis`. Aucun commit. Aucune modification de la base locale, des migrations, de `.env.local` ou des logiques métier.

## Fichiers du chantier visuel (cumul des passes)

- `public/assets/css/palette.css` : palette partagée boutique / EasyAdmin, déplacée depuis `elixquis.css` sans changement des couleurs existantes.
- `public/assets/css/elixquis.css` : retrait des déclarations de couleurs maintenant centralisées.
- `public/assets/css/finish.css` : styles éditoriaux, responsive, header, hero, catalogue, fiche produit, achat et footer. Chargé après les styles spécifiques des pages ; les règles contradictoires des premières passes ont été regroupées.
- `public/assets/css/admin-responsive.css` : habillage discret d'EasyAdmin.
- `public/assets/js/navigation.js` : synchronisation de `aria-expanded` du burger et fermeture du sous-menu lors de la fermeture du panneau. Bootstrap conserve la gestion du focus, du clavier et du backdrop.
- `templates/base.html.twig` : bandeau, navigation desktop et panneau mobile, chargement des assets.
- `templates/home/index.html.twig` : réorganisation éditoriale, sélection de trois produits, présentation des saveurs.
- `templates/category/index.html.twig` : ancre accessible pour les saveurs du catalogue.
- `templates/components/_product_card.html.twig` : suppression du lien « Découvrir » redondant ; image et titre restent cliquables.
- `templates/components/_announcement.html.twig` : texte du bandeau centralisé, prêt à recevoir un futur `announcement_message` administré.
- `src/Controller/Admin/DashboardController.php` : chargement de la palette partagée uniquement.
- `tests/BrandingPresentationTest.php` : vérification des trois produits mis en avant, de six saveurs, du catalogue complet, de la première section éditoriale et du carrousel administré.
- `docs/VISUAL_FINISH_ELIXQUIS.md` : ce compte rendu.

## Header

Desktop : logo textuel Elixquis existant à gauche, trois liens centraux (Accueil, Boutique, Nos saveurs), compte et panier à droite. Hauteur de navigation minimale de 70 px, bandeau ambre d'environ 29 px, séparation basse fine, séparateurs verticaux retirés. Les commandes restent accessibles via le compte ; le lien direct est aussi conservé dans le menu mobile.

Mobile / tablette : panneau Bootstrap sombre, catégories intégrées sans rectangle blanc, zones tactiles de 44–48 px, compteur du panier, focus visible. Échap ferme d'abord la liste de saveurs si elle est ouverte, puis le panneau ; le focus revient au burger. Le bandeau reste discret. Le header est statique sous 992 px, sticky au-dessus.

## Hero et storytelling

- Desktop : `height: clamp(620px, 75svh, 820px)`, sans 100vh. Texte légèrement remonté, largeur contenue, légendes administrées à 5 rem du bas. Image de fond et carrousel existants conservés.
- Tablette : `height: clamp(580px, 74svh, 720px)`.
- Mobile : hauteur automatique, `min-height: clamp(500px, 73svh, 660px)` ; peut grandir avec le contenu pour ne pas le couper. Réservation de place pour les légendes quand des slides existent, sans imposer 100vh. Crop `cover`, position adaptée au mobile.
- Première section après le hero : section 01 existante déplacée, sans duplication. Grande image à gauche, texte à droite, fond ivoire, titre avec italique ponctuel et lien souligné discret.
- Section 02 : contenu conservé, alternance texte / image, numéro atténué et filet doré fin.

## Produits, saveurs et boutique

Accueil et catalogue partagent une grille ouverte, sans fond de carte, bordure de carte, arrondi ou cadre dans le cadre. Images dominantes en `contain`, nom cliquable avec focus clavier, catégorie, disponibilité et prix conservés. Pas de bouton « Découvrir » répété ; hover de l'image très léger, neutralisé en mouvement réduit.

Accueil : trois produits maximum, lien « Voir toute la boutique ↗ ». Les saveurs existantes sont toutes présentées dans une grille qui retourne à la ligne : l'ajout de quatre à six catégories ne les masque pas. Le lien vers toutes les saveurs du catalogue est conservé.

Boutique : trois colonnes desktop, deux sur tablette/mobile, une sous 375 px. Catégories, recherche, tri et disponibilité restent fonctionnels ; présentation des filtres allégée.

## Achat, footer et administration

Fiche produit : grande image sans fond encadré, titre et prix hiérarchisés, sélecteur `− 1 +` inchangé, bouton panier visible et recette sans card.

Panier / checkout : bordures fines, étapes simples, récapitulatif aéré, boutons de marque, blocs de formulaire allégés. Aucun changement des formulaires, tokens, prix, adresses, points relais, transporteurs, emballages ou actions de paiement.

Footer : contenus et liens conservés, respiration accrue, séparateurs fins et zones de lien plus faciles à toucher.

EasyAdmin : branding, sidebar, titres, tableaux, badges et boutons harmonisés. L'accueil redirige toujours vers les commandes ; aucune page statistique ni aucun calcul ajouté.

## Design system

Palette et typographies existantes conservées, aucune police ni palette supplémentaire. Les variables ajoutées pendant le chantier sont des dérivés de la palette :

- `--elixquis-filet` : filet de séparation atténué.
- `--elixquis-overlay` : surface sombre translucide réutilisable.
- `--elixquis-hero-shade` / `--elixquis-hero-light` : dégradé de lisibilité.
- `--elixquis-space-section` : espacement vertical responsive.

## Vérifications

- Suite complète : **332 tests, 3 764 assertions, succès**. Base SQLite en mémoire, intégrations externes simulées dans les tests ; aucun paiement ni appel réel Stripe/Sendcloud déclenché.
- **40 templates Twig valides**, syntaxe PHP du Dashboard et JavaScript du menu valides, `git diff --check` sans erreur d'espacement.
- Les tests couvrent les routes principales, connexion, catalogue, panier, stock, commandes et checkout ; aucun changement de leur logique.
- Comparaison Chrome avant/après : desktop, portable, tablette, mobiles de 430, 390 et 320 px. Exports issus des fixtures de tests, avec CSS, scripts et images locaux ; requêtes HTTP externes bloquées pendant le contrôle navigateur.
- Après modification : **77 rendus**, aucun débordement horizontal, aucune image manquante, texte du hero contenu et aucune légende mobile superposée au CTA. Contrôles de pagination du carrousel réussis sur les sept largeurs. Menu, focus, Échap et `aria-expanded` vérifiés sur tablette et mobile.
- Hauteurs effectivement mesurées (identiques avec et sans carrousel pour les fixtures contrôlées) :

| Écran contrôlé | Hauteur du hero |
| --- | ---: |
| 1 600 × 1 200 | 820 px |
| 1 440 × 1 000 | 750 px |
| 1 280 × 800 | 620 px |
| 820 × 844 | 625 px |
| 430 × 844 | 616 px |
| 390 × 844 | 616 px |
| 320 × 844 | 616 px |

- Captures et mesures locales dans `var/ergonomie-before/` et `var/ergonomie-after/` ; ces artefacts ne sont pas livrés avec les sources.
- Avis de dépréciation Doctrine/Symfony présents dans la suite, sans échec de test.

Le projet A&A Profumo Atelier n'est pas présent dans ce dépôt. La direction est appliquée à partir des principes ergonomiques explicitement fournis, sans copier sa marque, ses textes ou ses couleurs.
