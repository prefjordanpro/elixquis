# Footer commun Elixquis

Source unique : `templates/components/_footer.html.twig`, inclus une fois par `templates/base.html.twig`.

Le footer n’était pas dupliqué : la condition `if not checkout` masquait marque, phrase et navigation dans le checkout. Cette condition et les deux règles CSS de réduction du footer checkout sont supprimées. Tous les contenus du footer de référence sont conservés. Les liens espace client restent adaptés à la connexion, comme auparavant ; catégories et contact restent issus des données existantes.

Layout : la colonne flex et la hauteur minimale de fenêtre, auparavant limitées à Sendcloud, sont communes à `body.elixquis-site`. `main` absorbe l’espace restant et le header/footer ne rétrécissent pas. Le minimum arbitraire de 45vh est supprimé. Aucun footer fixe ou positionné absolument. Le header spécifique checkout reste inchangé.

Intégration : extraction du HTML de base vers le composant ; retrait de la classe de layout spécifique Sendcloud ; suppression des exceptions CSS checkout. Les six templates chargeant purchase.css ne changent que la version de l’URL CSS pour éviter l’ancienne apparence en cache. Aucun formulaire ou comportement métier modifié.

Validation : lint des 30 Twig réussi, PHPUnit 116 tests / 1 630 assertions réussis. Revue navigateur des pages de test couvrant accueil, catalogue, produit, panier, compte/commandes, livraison classique, Sendcloud, paiement et confirmation aux largeurs 1440, 768 et 390 px, fenêtres de 900 et 1600 px de haut. Chaque page contient exactement un footer complet, avec styles identiques à largeur égale et aucun débordement ou espace après le footer supérieur à l’arrondi pixel. Le footer Sendcloud desktop a été inspecté visuellement.

[Mesures](branding-review/footer-sendcloud/after.json) · [Checkout Sendcloud](branding-review/footer-sendcloud/after-sendcloud-adresse.html-1440.png) · [Panier](branding-review/footer-sendcloud/after-panier.html-390.png).

Aucun changement Stripe, Sendcloud, tarifs, panier métier, commandes, stock ou `.env.local`. Aucun merge.
