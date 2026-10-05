# Sendcloud comme parcours principal

Branche : `feature/identite-visuelle-elixquis`.

Cause : le CTA « Poursuivre ma commande » du panier utilisait `path('app_order')`, soit `/commande/livraison`. Le retour après sauvegarde d’une adresse utilisait également `app_order`. Si une session portait déjà `shipping_legacy_fallback`, cette entrée restait dans le parcours historique au lieu de rediriger vers Sendcloud.

Correction : le CTA pointe directement vers `app_sendcloud_checkout`. La sauvegarde d’adresse avec panier revient aussi vers Sendcloud, sauf lorsqu’un fallback a été explicitement choisi ; dans ce cas le retour conserve `app_order` avec `fallback=1`. L’entrée Sendcloud efface déjà le marqueur legacy : aucune duplication de cette logique.

Audit : le formulaire Sendcloud, les liens « Changer d’adresse » et « Choisir une autre livraison » pointaient déjà vers Sendcloud. Les étapes partagées ne contiennent pas de lien vers l’ancienne route ; elles renvoient au panier ou à la section livraison. Le formulaire et les redirections internes du fallback classique restent inchangés. Le lien volontaire « Utiliser la livraison classique » est conservé.

Contrôle navigateur réel sur `127.0.0.1:8000` : accueil → boutique → fiche produit → ajout au panier → « Poursuivre ma commande » déclenche `/commande/sendcloud`, jamais `/commande/livraison`. La session de navigateur de test étant anonyme, le contrôle réel se termine sur `/connexion`, conformément à la protection du checkout. Aucun achat ou commande réelle créé.

Contrôle connecté isolé : les clics du navigateur Symfony BrowserKit suivent accueil → catalogue → produit → panier → CTA Sendcloud, puis soumission d’adresse, choix Colissimo et vérification de commande dans l’espace client. Réalisé aussi avec un ancien marqueur fallback présent au départ : il est supprimé par l’entrée Sendcloud. L’API est simulée uniquement dans les tests existants, base SQLite en mémoire. Aucun paiement déclenché. Ajout de tests pour le retour après sauvegarde d’adresse, avec et sans fallback volontaire.

Fichiers : `templates/cart/index.html.twig`, `src/Controller/Account/AddressController.php`, `tests/SendcloudTest.php` et ce compte rendu. Aucun changement API Sendcloud, tarifs, relais, Stripe, stock ou `.env.local`.
