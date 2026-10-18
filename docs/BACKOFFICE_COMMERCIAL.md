# Gestion commerciale Elixquis

Branche : `feature/backoffice-commercial`. Aucun commit créé.

## Architecture

Les entités et services existants restent la source des produits, du panier, des commandes, du stock et des devis. `Product::getPriceWt()` fournit désormais le prix TTC effectif selon les dates. `CommercialPricing` centralise les calculs en centimes : montant articles, éligibilité au code, remise par ligne et livraison offerte. Les règles sont relues côté serveur à la création de commande, avec verrouillage des produits et du code. Les prix et l'éligibilité des promotions sont évalués au même instant dans un devis commercial.

Une seule ligne métier est conservée par produit. Chaque ligne enregistre son prix unitaire TTC et sa remise commerciale en centimes. Le total et la TVA utilisent ce net. Le code, la remise totale et le coût transport sont figés sur la commande. Les traitements de décrémentation et de restitution du stock sont conservés.

Stripe utilise les montants nets historiques, sans coupon Stripe distant ni appel supplémentaire. Si une remise ne se divise pas exactement par la quantité, la requête Stripe répartit le centime résiduel sur deux prix unitaires ; la somme reste exactement égale à la commande. Cette répartition ne crée aucune ligne métier supplémentaire. Facture, récapitulatifs, espace client et administration présentent les totaux nets.

## Entités et champs

- `CommercialSettings` : configuration unique (id 1), seuil de stock faible, mode de livraison offerte et seuil TTC en centimes.
- `HomepageContent` : 35 contenus identifiés par clés fixes ; texte, destination ou image. Le type et le layout ne sont pas éditables.
- `PromoCode` : code unique normalisé en majuscules, nom interne, activation, type, valeur, dates, minimum, limite, compteur d'utilisations réservées et autorisation du cumul.
- `Product` : pourcentage facultatif (5 à 90, par pas de 5), activation, début et fin facultatifs. L'ancien prix promotionnel TTC reste conservé pour compatibilité ; le prix HT normal existant reste inchangé.
- `Order` : code historique, remise totale en centimes, coût transport TTC avant gratuité (nullable pour les anciennes commandes).
- `OrderDetail` : remise commerciale de la ligne en centimes, à zéro pour les anciennes commandes.

## Migration

`Version20261018110413` ajoute trois tables et les champs ci-dessus, sans suppression ni réécriture des données existantes. Elle initialise la configuration (seuil stock 5, livraison offerte désactivée) et les contenus d'accueil à leurs valeurs précédentes. Aucun code ni promotion n'est activé par défaut.

Migrations appliquées à la base locale après simulation. `Version20261018120000` ajoute uniquement `product.promotion_percent`, nullable, sans convertir les anciens prix fixes ni réécrire les commandes. `php bin/console doctrine:schema:validate` : Mapping OK, Database OK. Lors de la première migration commerciale, la comparaison SHA-256 des lignes de toutes les tables préexistantes, en excluant les nouveaux champs et la table de suivi des migrations, avait confirmé 15 tables inchangées. Les migrations refusent un retour destructif ; elles sont non transactionnelles pour les DDL MariaDB/MySQL.

## Alertes stock et dashboard

Stock nul : Rupture. Stock de 1 au seuil inclus : Stock faible. Au-delà : Disponible. Seuil réglable dans **Configuration commerciale**, qui ouvre directement le formulaire en sections Stocks et Livraison. Les badges du CRUD Produit et le dashboard utilisent la même règle. Le dashboard présente les huit commandes les plus récentes, le nombre en attente de paiement, les ruptures et stocks faibles avec liens directs, les promotions effectivement actives et les codes disponibles.

## Accueil du site

La rubrique **Accueil du site** permet de modifier le bandeau, les surtitres/titres/textes du hero, sa partie italique, son CTA et sa destination, son image de repli et sa légende, les blocs 01/02 (textes, images, alternatives et légendes), ainsi que les titres et liens textuels des sections sélection et saveurs. Les images et légendes du carrousel restent gérées dans **Bannières**, via l'entité `Header` existante.

Uploads limités à JPEG, PNG et WebP, 5 Mo, validés côté serveur. Noms aléatoires pour les nouvelles images d'accueil ; noms par empreinte existants pour les bannières et produits. Aperçus dans EasyAdmin. Les anciennes images sont conservées : aucune suppression automatique susceptible de casser un partage ou une commande historique. Les textes sont échappés par Twig ; les destinations acceptent uniquement les chemins locaux et HTTP(S).

## Promotions produits

La section **Promotion** propose « Aucune promotion » par défaut, puis 5 %, 10 %, … 90 %. Sans dates, la réduction est immédiate ; sinon le début est inclus et la fin exclue. Le prix réduit est arrondi au centime TTC et doit rester strictement positif et inférieur au prix normal. Aucun cron requis. `Product::getPriceWt()` reste le point central du prix applicable, utilisé par le frontend, le panier et le calcul commercial de commande.

Un composant Twig commun présente le prix normal barré, le prix applicable et un indicateur discret « −XX % » sur homepage, boutique et fiche. Le CRUD affiche prix normal TTC, promotion (active, à venir ou expirée) et prix appliqué TTC. Les promotions anciennes à prix fixe ne sont pas converties automatiquement : une option explicite permet de les conserver, de les remplacer par un pourcentage ou de les supprimer. La commande et Stripe utilisent les snapshots en centimes ; changer une promotion ne recalcule pas une ancienne commande.

## Codes promo et cumul

Saisie, application et retrait dans le panier ; récapitulatif et lien de modification au checkout. Réponse générique pour les codes inconnus, inactifs, expirés, futurs, épuisés ou inapplicables. Protection CSRF des actions. Pourcentage entier de 1 à 100 ; pour un montant fixe, saisie directement en euros dans EasyAdmin (5,00 € devient 500 centimes en base). L'aide de Valeur et son unité suivent le type choisi. Le serveur choisit le type de champ et réalise la conversion, y compris après changement de type ; le JavaScript n'est qu'une aide visuelle. Le champ **Montant minimum du panier TTC** est aussi saisi en euros et stocké en centimes. Dates facultatives, début inclus et fin exclue. Réduction plafonnée aux articles éligibles, sans total négatif.

Par défaut, un code ne s'applique pas aux lignes bénéficiant d'une promotion produit. Dans un panier mixte, les autres lignes restent éligibles. L'option **Cumulable avec les promotions produits** autorise aussi la remise sur les lignes en promotion. Aucune restriction supplémentaire par catégorie ou produit n'a été ajoutée.

La limite globale est réservée atomiquement dès la création de commande, y compris en attente. Les reprises de paiement ne consomment pas une nouvelle utilisation. Les annulations et abandons ne recréditent pas automatiquement ce compteur : règle conservatrice explicitée dans le CRUD. Les usages restent consommés même si une ancienne commande est ensuite annulée. Modifier le code ou son montant ne réécrit aucun snapshot.

## Livraison offerte

Trois modes : désactivée, toutes les commandes, seuil. Le seuil porte sur les articles TTC **après** promotions et code, hors livraison. Tous les modes réellement proposés par Sendcloud restent disponibles. L'offre réelle, le colisage et le point relais sont vérifiés comme auparavant. Seul le montant facturé sur la commande devient zéro ; le coût transport réel est enregistré séparément et le snapshot du devis reste intact. L'administration affiche ces deux montants. Aucun moteur Sendcloud ni colisage modifié.

## Vérifications

Tests commerciaux sur SQLite en mémoire, Stripe mocké et Sendcloud mocké : seuils stock, périodes de promotion, prix panier, code fixe/pourcentage, minima, dates, limites, cumul, gratuité, montants Stripe exacts, snapshot après modification, restitution du stock, TVA à plusieurs taux, formulaires admin, uploads autorisés/refusés, édition de texte, unicité et droits ROLE_ADMIN.

Dernier résultat : **392 tests réussis, 4 147 assertions**. Les 45 templates Twig et le conteneur Symfony sont valides. Les dépendances émettent toujours des dépréciations Doctrine/Symfony. La revue initiale portait sur 145 rendus ; la passe promotions ajoute 30 rendus dédiés à 1440, 1024, 768, 430 et 390 px, sans débordement horizontal ni erreur JavaScript. L'aide dynamique, la saisie avec virgule et les transitions de type de code ont été vérifiées dans Edge. Aucun appel réel Stripe, Sendcloud ou Mailjet.

Le CSS ajouté se limite à l'indicateur de réduction. Pas de modification du header, du layout hero, de l'authentification ou des données clients, des emails, des remboursements, du moteur de colisage ou de `.env.local`. Le template de détail de commande client présente les nouveaux montants nets.
