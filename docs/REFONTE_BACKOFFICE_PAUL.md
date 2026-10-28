# Back-office Elixquis — gestion quotidienne de Paul

Travail effectué sur `feature/cms-home-revendeurs`, en conservant le travail CMS déjà présent. Aucun commit, aucun push, aucune modification de `main` ou de `.env.local`.

## 1. Problèmes UX identifiés

Le tableau de bord redirigeait vers une liste de commandes sans expliquer les priorités. Le formulaire produit mélangeait stock, poids, image, prix et adresse technique de la page. Les contenus d’accueil et le carrousel avaient des entrées séparées. Le répertoire client était présenté comme une liste d’utilisateurs. Des libellés techniques et des identifiants de paiement étaient visibles.

La base contenait déjà les tables commerciales mais le code de cette branche ne les utilisait pas. L’inspection des branches locales a retrouvé les entités, les calculs et les tests dans `feature/backoffice-commercial`. Ils ont été réutilisés, sans reprendre son ancien CMS ni créer de nouveaux systèmes de promotions, de commandes ou d’emballages.

Les descriptions existantes contiennent parfois de la mise en forme : leur éditeur visuel est conservé pour que Paul ne voie pas de HTML brut. Une modification de stock préserve la description historique et l’adresse publique du produit.

## 2. Nouvelle arborescence

```text
Vue d’ensemble
VENTES
  Commandes
  Clients
CATALOGUE
  Produits
  Catégories
  Stock
PROMOTIONS
  Promotions produits
  Codes promo
  Livraison offerte
LIVRAISON
  Emballages
SITE
  Modifier le site
  Revendeurs
Voir la boutique
```

Pas de menu supplémentaire Utilisateurs : le répertoire Clients permet de retrouver les comptes existants sans exposer leurs droits ou mots de passe à la modification. Pas de rubrique de réglages techniques ni de transporteurs parallèles.

## 3. Écrans regroupés et entrées retirées

L’entrée Modifier le site propose Page d’accueil et Nos revendeurs. Les Images du carrousel sont accessibles depuis cette page et depuis le formulaire d’accueil. L’entité `Header` et son contrôleur sont conservés ; seule leur présentation change. Les entrées séparées Bannières et Accueil du site disparaissent du menu principal.

Les vues Stock et Promotions produits ouvrent le même formulaire produit, directement sur le bon onglet. Il n’existe donc qu’un éditeur produit et une seule logique de calcul des prix. Les réglages Stock et Livraison offerte éditent des champs différents de l’unique `CommercialSettings` existant.

## 4. Pages ajoutées

| Page | Usage |
| --- | --- |
| Vue d’ensemble (`/admin`) | Sept indicateurs, actions rapides, commandes à préparer et stocks à surveiller |
| Stock (`/admin/stock`) | Recherche, filtres Rupture / Stock faible / Stock normal, accès à la quantité disponible |
| Promotions produits (`/admin/promotions-produits`) | Choix du produit, promotions configurées et accès à la réduction |
| Modifier le site (`/admin/modifier-le-site`) | Point d’entrée vers les pages éditoriales et le carrousel |
| Seuil de stock faible | Réglage accessible depuis Stock |
| Livraison offerte | Réglage dédié accessible depuis Promotions |

Les vues de produits sont paginées par 30. Le tableau de bord limite les aperçus aux huit stocks et six commandes prioritaires. Il ne charge aucun graphique décoratif.

## 5. Formulaires simplifiés

**Produit** : Informations, Prix, Stock, Promotion, Livraison. Les liens publics sont générés à la création ; renommer un produit ne casse pas son adresse existante. Le prix hors taxes existant reste la donnée de saisie, clairement expliqué ; les prix TTC normal et appliqué sont visibles dans la liste. Le verrou de concurrence sur le stock est conservé.

**Promotion produit** : Pas de promotion, puis réductions de 5 à 90 % par pas de 5. Début et fin facultatifs. Les anciennes promotions à prix fixe peuvent être conservées. Le prix appliqué est calculé par les méthodes commerciales existantes du produit.

**Code promo** : code, nom interne, activation, type, réduction, période et conditions, minimum TTC, nombre maximum d’utilisations et cumul. Une remise fixe se saisit en euros. Le compteur n’est pas modifiable et n’augmente qu’après paiement confirmé. Les doublons de webhook, les commandes en attente et les paiements expirés sont couverts par les tests réutilisés.

**Stock** : réglage du seuil dans un écran dédié, avec l’aide demandée : « Vous serez alerté lorsqu’un produit atteint cette quantité. » Les niveaux sont calculés selon 0 / 1 au seuil inclus / au-delà du seuil.

**Livraison offerte** : Désactivée, Toujours offerte, Offerte à partir d’un montant TTC. Le seuil en euros apparaît lorsque nécessaire. À 80 €, le minimum est enregistré en centimes, puis appliqué sur les articles après réductions, hors livraison.

**Accueil** : Bandeau du haut, Grande image d’accueil, Présentation Elixquis, Sélection produits, Saveurs, Bloc éditorial, Réassurance, texte Nos revendeurs. Le bouton Images du carrousel fait partie de ce parcours. La structure de la page reste verrouillée.

**Images** : image actuelle, Ajouter / Remplacer l’image, aperçu local de la nouvelle photo, formats et taille limite. Validation serveur du MIME JPEG / PNG / WebP, limite 5 Mo, noms aléatoires et conservation des anciennes références. Une adaptation du thème corrige le gestionnaire d’upload EasyAdmin lorsqu’une suppression est interdite. Les textes alternatifs éditoriaux disposent de l’aide d’accessibilité.

**Revendeurs** : identité, adresse et contacts ; correction manuelle de localisation dans une section repliée. Les boutons Activer / Masquer sont disponibles dans la liste, avec confirmation et protection CSRF. Masquer conserve l’adresse et retire immédiatement le revendeur de la page publique. Le géocodage et la carte existants restent en place.

**Emballages** : nom, nombre maximal de bouteilles, poids à vide avec protections en grammes, dimensions extérieures en centimètres, activation et priorité réellement utilisée. Les validations et l’algorithme de colisage existants restent utilisés. Les emballages liés à des commandes ne peuvent pas être supprimés.

## 6. Tâches désormais accessibles

| Tâche de Paul | Parcours |
| --- | --- |
| Modifier une photo ou un texte d’accueil | Modifier le site → Page d’accueil |
| Ajouter une image au carrousel | Page d’accueil → Images du carrousel |
| Créer un produit | Ajouter un produit depuis la vue d’ensemble ou Produits |
| Modifier une quantité | Stock → Modifier le stock |
| Mettre un produit à −15 % | Promotions produits → Modifier la promotion → −15 % |
| Créer un code promo | Créer un code promo depuis la vue d’ensemble ou Codes promo |
| Offrir la livraison à partir de 80 € | Livraison offerte → Offerte à partir d’un montant TTC → 80 |
| Ajouter un carton | Livraison → Emballages → Ajouter un emballage |
| Ajouter un revendeur | Ajouter un revendeur depuis la vue d’ensemble ou Revendeurs |
| Trouver les commandes à préparer | Vue d’ensemble → Commandes à préparer |
| Retrouver un client | Ventes → Clients → recherche par identité ou email |

Les commandes utilisent leurs badges métier et les transitions déjà autorisées. La fiche présente référence, date, client, adresse, produits et quantités, prix, code et réductions éventuels, livraison, total, paiement, historique puis expédition et colis. Le changement de présentation ne modifie pas le circuit d’annulation ou de remboursement.

## 7. Informations et opérations protégées

Aucun accès aux secrets, variables d’environnement, chemins serveur, configurations Symfony ou Doctrine. Aucun champ de code dans le CMS. Les identifiants internes de paiement et les valeurs numériques inconnues de statut ne sont plus affichés. Les mots de passe, rôles et vérifications d’email ne deviennent pas éditables.

La suppression des produits et codes promo est désactivée : Paul peut les masquer ou les désactiver. Une catégorie contenant des produits ne peut pas être supprimée. Les protections historiques des emballages et commandes sont conservées. Les suppressions restantes utilisent la confirmation EasyAdmin. Les boutons de visibilité revendeur utilisent POST, un jeton CSRF et une confirmation.

La réintégration commerciale apporte uniquement les raccordements nécessaires pour que le prix administré soit identique dans le catalogue, le panier, le checkout et la commande. L’adaptation de `StripePayment` réutilise le calcul net existant de la branche commerciale : les centimes d’arrondi sont répartis dans les lignes de paiement. Les API Stripe / Sendcloud / Mailjet, les webhooks, l’authentification, les transitions de statut, le multi-colis et la logique de remboursement ne sont pas réécrits.

Les frais facturés au client peuvent être nuls ; le transporteur, son service et le montant logistique avant gratuité restent enregistrés dans les données de commande. Les prix réellement payés sont figés sur les lignes de commande et ne dépendent pas d’une promotion modifiée ultérieurement.

## 8. Fichiers

L’inventaire de l’arbre de travail, incluant les fichiers du CMS conservés de l’étape précédente, est disponible dans `docs/INVENTAIRE_BACKOFFICE.txt`. Les principaux changements de cette passe concernent :

```text
src/Controller/Admin/DashboardController.php
src/Controller/Admin/WorkspaceController.php
src/Controller/Admin/ProductCrudController.php
src/Controller/Admin/CategoryCrudController.php
src/Controller/Admin/OrderCrudController.php
src/Controller/Admin/UserCrudController.php
src/Controller/Admin/EmballageCrudController.php
src/Controller/Admin/HomePageContentCrudController.php
src/Controller/Admin/HomeContentController.php
src/Controller/Admin/HeaderCrudController.php
src/Controller/Admin/RevendeurCrudController.php
src/Controller/Admin/CommercialSettingsCrudController.php
src/Controller/Admin/PromoCodeCrudController.php
src/Controller/Admin/ContentImage.php
templates/admin/dashboard.html.twig
templates/admin/product_workspace.html.twig
templates/admin/site.html.twig
templates/admin/content_image_theme.html.twig
templates/admin/retailer_visibility.html.twig
templates/admin/order.html.twig
templates/admin/state.html.twig
templates/admin/user_detail.html.twig
public/assets/css/admin-workspace.css
public/assets/js/admin-workspace.js
```

Les fichiers commerciaux et leurs tests sont repris de `feature/backoffice-commercial`, puis adaptés à ce CMS et à la navigation métier. Les fichiers publics de prix et de récapitulatif restent reliés aux mêmes calculs. Les modifications de `tests/OrderEmailsTest.php` concernent uniquement le libellé visuel d’un statut historique, sans changement du traitement des emails.

## 9. Migrations

Aucune nouvelle migration nécessaire pour cette refonte. Les trois migrations commerciales existantes ont été remises à disposition depuis leur branche : `Version20261018110413`, `Version20261018120000`, `Version20261018131227`. Elles étaient déjà exécutées sur la base locale et n’ont pas été rejouées.

La migration CMS `Version20261028090000` de l’étape précédente est conservée. Le statut final annonce 25 migrations disponibles, 25 exécutées, aucune indisponible et aucune nouvelle. `doctrine:schema:validate` retourne désormais **Mapping [OK]** et **Database [OK]**. L’écart décrit dans l’ancien rapport CMS est donc résolu par la réutilisation du mapping commercial existant, sans suppression de données.

## 10. Tests ajoutés et adaptés

`BackofficeWorkspaceTest` couvre les priorités et filtres du tableau de bord, la navigation unique, le stock, les raccourcis vers le formulaire produit, une création avec image et réduction de 15 %, le maintien du lien après renommage, la conservation de la description, le CMS, les réglages séparés sur le même singleton, la livraison offerte à 80 €, les clients, la protection admin, les emballages et les actions de visibilité revendeur protégées.

Cette passe ajoute 10 tests de parcours et réintègre 68 tests commerciaux : 34 dans `CommercialTest`, 24 dans `CommercialPromotionsTest`, 10 dans `PromoCodeUsageTest`.

Les tests commerciaux réutilisés couvrent les 18 réductions, les périodes, le refus des valeurs forgées, les euros et centimes, les promotions historiques, le cumul des codes, la TVA, les prix figés, les paiements et webhooks mockés, le comptage exactement une fois après confirmation, l’absence de consommation à la création ou après expiration, et les coûts de transport conservés.

Les tests du CMS précédent restent présents pour les contenus par défaut, les uploads, le géocodage mocké et les revendeurs actifs / masqués. Les anciennes assertions de menu et de libellés ont été adaptées à l’interface demandée.

## 11. Validation finale

Les résultats finaux sont enregistrés dans `var/backoffice-final.xml` et `var/backoffice-final.txt`. Les tests utilisent une base SQLite en mémoire ; les services externes sont mockés. La limite mémoire PHP du processus de test est portée à 1 Go pour cette suite élargie, sans modifier la configuration du site.

**Suite complète : 432 tests, 4 495 assertions, aucun échec ni erreur.** Durée : 42,1 secondes ; mémoire maximale du processus de test : 600 Mo. Le mapping, le schéma, les 50 templates Twig et le conteneur Symfony sont validés. `git diff --check` ne signale aucune erreur.

Le contrôle navigateur utilise les pages exportées par les tests et intercepte toutes les requêtes. Il couvre les écrans à 1600, 1280, 1024, 768, 430 et 390 px : absence de débordement global, ouverture du menu mobile, accès aux onglets, ouverture directe de Promotion, champs conditionnels de livraison et de code promo, remplacement et aperçu de photo, édition visuelle des descriptions historiques. Les captures et mesures sont dans `var/backoffice-review/browser/`.

**Résultat navigateur : 19 écrans × 6 largeurs = 114 contrôles réussis, aucune erreur JavaScript.** La liste revendeurs a été allégée ; les tableaux larges restent dans leur zone de défilement. Les deux tests ciblés de visibilité revendeur et d’emballage ont également été rejoués après cette dernière simplification : 24 assertions réussies.

Commandes de vérification :

```powershell
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status --no-interaction
php bin/console lint:twig templates
php bin/console lint:container
git diff --check

$env:BO_REVIEW_DIR = "$PWD\var\backoffice-review\html"
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit tests/BackofficeWorkspaceTest.php
node tools/check-backoffice.cjs <chemin-vers-playwright-core>
```

## 12. Limites et améliorations possibles

Les avertissements de dépréciation des dépendances restent à traiter lors d’une mise à jour technique dédiée. Les captures n’interrogent aucun service externe : elles ne valident pas une disponibilité réelle de Stripe, du transporteur ou de la cartographie. Les tests métier utilisent des mocks pour ces services.

Le signal de stock faible est visuel, dans le tableau de bord et la vue Stock ; cette refonte n’ajoute pas de notification automatique par email. Les situations exceptionnelles d’annulation et de remboursement historique conservent les contrôles et limitations existants.

Les paramètres d’intégration et la gestion des droits administrateur restent volontairement hors de l’outil quotidien de Paul. Aucun point métier demandé n’est remplacé par une action simulée ou un second système.
