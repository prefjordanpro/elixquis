# Quantité sélectionnée sur la fiche produit

Branche : `feature/identite-visuelle-elixquis`.

Le CTA « Explorer cette catégorie » et sa variante sans catégorie sont supprimés. Le retour discret et le fil d’Ariane restent présents. Le bloc « 1 par ajout » et son texte d’aide sont remplacés par un champ quantité étiqueté, avec boutons − et +. Le champ et le CTA appartiennent au même formulaire POST protégé par le jeton CSRF existant.

Le minimum est 1, le maximum affiché correspond au stock du produit. Les boutons se désactivent aux limites ; le champ reste saisissable au clavier. Aucun contrôle n’est actif en rupture. La présentation conserve une bordure fine, rayon 10 px et actions de 44 px, hover ambre/ivoire, focus visible existant. Le CTA s’empile quand la largeur manque, notamment sur mobile. Aucune autre présentation de la fiche n’est modifiée.

Le contrôleur valide strictement un entier positif (refus des tableaux, décimaux, notation scientifique, valeurs vides, négatives et nombres trop grands). `Cart::add` accepte une quantité optionnelle, avec défaut 1 pour conserver les formulaires existants du panier. La quantité ajoutée, cumulée avec la quantité déjà en panier, doit rester inférieure ou égale au stock courant. Le dépassement est refusé avant toute écriture de quantité en session : aucun ajout partiel. Le stock n’est ni décrémenté ni réservé à l’ajout ; les mécanismes existants de commande/réservation ne sont pas modifiés.

Fichiers de cette passe : template produit, CSS purchase limité au nouveau contrôle, nouveau JS `product-quantity.js`, contrôleur d’ajout panier, `Cart::add`, tests UX, contrôle navigateur et ce document. Aucun changement Stripe, Sendcloud, commande, réservation, remboursement, configuration ou `.env.local`.

Validation : 29 templates Twig valides, syntaxe PHP et JS valide, 116 tests PHPUnit / 1 630 assertions réussis. Les nouveaux cas vérifient les valeurs falsifiées, l’ajout de plusieurs unités, le cumul en panier, le refus atomique d’un dépassement et la rupture après changement de stock dans une base SQLite de test. Les tests existants de panier/commande passent. Notices Doctrine préexistantes.

Navigateur : produit disponible et épuisé à 1440, 1024, 768, 390 et 320 px. 10 contrôles de pages et 14 contrôles d’interaction réussis ; aucun débordement, aucune erreur JS. Incrémentation, décrémentation, limites, saisie hors bornes, rupture, hover et focus vérifiés. Captures 1024 et 320 px inspectées visuellement. Aucun ajout au panier réel effectué.

[Desktop](branding-review/fiche-produit/produit-1440.png) · [Mobile](branding-review/fiche-produit/produit-320.png) · [Rapport navigateur](branding-review/fiche-produit/controles.json).
