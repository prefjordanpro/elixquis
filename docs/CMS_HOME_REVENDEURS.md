# Accueil administrable et Nos revendeurs

> Mise à jour : la refonte suivante du back-office a réintégré le mapping commercial existant. La validation locale du schéma est désormais entièrement OK. Voir `docs/REFONTE_BACKOFFICE_PAUL.md` pour l’organisation et les résultats actuels ; ce document décrit l’étape CMS précédente.

Travail réalisé sur `feature/cms-home-revendeurs`, sans commit et sans modification de `main`.

## 1. Architecture de l’accueil

L’analyse a identifié une homepage éditoriale dans Twig, une entité `Header` et son CRUD Bannières, des uploads EasyAdmin, des produits sélectionnés par `isHomepage`, des catégories issues du catalogue et un header Bootstrap commun aux affichages desktop et mobile.

La base locale contenait également une table historique `homepage_content` avec des contenus éditoriaux, bien qu’aucune entité correspondante ne soit présente dans cette branche. Cette table est réutilisée : aucune seconde infrastructure de contenu n’est créée.

`HomePageContent` est un singleton à identifiant fixe `home`. Sa colonne `value` contient un document JSON organisé par sections : bandeau, hero, présentation, sélection, saveurs, histoire, réassurance et introduction revendeurs. Les accesseurs typés exposent uniquement les champs éditoriaux. Le formulaire ne donne accès ni au JSON, ni au HTML, ni au CSS, ni à Twig. Les anciennes lignes restent conservées et sont exclues de l’index EasyAdmin.

`HomeContent` lit le singleton et fournit les valeurs actuelles du site si la configuration n’a pas encore été enregistrée. Une consultation publique ne crée aucune ligne. Le raccourci Accueil du site initialise la configuration si nécessaire puis ouvre directement sa modification.

Les slides restent des entités `Header`, sans limite fixe. Les nouvelles propriétés `active`, `position` et `alt` complètent les champs existants. Le tri est effectué par position puis identifiant. La logique de sélection des produits et la provenance des catégories restent celles du catalogue.

## 2. Contenus administrables

| Bloc | Champs |
| --- | --- |
| Bandeau supérieur | Activation, texte, titre de la page |
| Hero | Surtitre, deux lignes de titre, partie en italique, paragraphe, libellé et destination du CTA, note, image de repli, texte alternatif |
| Bannières | Image, texte alternatif, titre, texte, libellé et destination du bouton, ordre, activation |
| Présentation | Numéro, surtitre, titre, partie en italique, texte secondaire, paragraphe, libellé et destination du lien, image, texte alternatif, légende |
| Sélection produits | Surtitre, titre, description, libellé et destination du lien boutique |
| Saveurs | Surtitre, titre, libellé et destination du lien |
| Histoire | Numéro, surtitre, deux lignes de titre, partie en italique, deux paragraphes, image, texte alternatif, légende |
| Réassurance de l’accueil | Les trois titres et les trois textes |
| Page revendeurs | Texte introductif |

Les onglets EasyAdmin correspondent à ces blocs. Les images et textes du catalogue restent administrés dans leurs rubriques existantes. Les éléments communs de navigation et de pied de page gardent leur structure actuelle.

## 3. Images

Les champs réutilisent `ImageField` et le traitement des fichiers EasyAdmin. `ContentImage` centralise la politique d’upload et `ContentImageType` étend le type EasyAdmin pour conserver les sous-répertoires des images éditoriales existantes.

Validation réelle du fichier avec la contrainte Symfony `Image`, types MIME JPEG / PNG / WebP et limite de 5 Mo. L’extension de destination provient du type détecté. Le nom est généré avec 20 octets aléatoires, soit 40 caractères hexadécimaux. Le chemin généré est également contrôlé avant écriture.

Les formulaires montrent un aperçu de l’image actuelle, ouvrable en taille réelle. Un remplacement conserve les autres images du formulaire ainsi que les textes alternatifs. Les anciens fichiers sont conservés : aucune suppression automatique ne risque de casser une référence partagée. Aucun déplacement des images historiques n’est nécessaire.

## 4. Revendeur et back-office

L’entité Doctrine `Revendeur` contient nom, adresse, complément facultatif, code postal, ville, pays avec France par défaut, latitude et longitude facultatives, téléphone, email, site web, activation, priorité, création et mise à jour.

Les coordonnées sont validées par plage et par paire. Les contacts et URLs sont validés. Le CRUD permet ajout, modification, désactivation et suppression ; recherche par nom, adresse, ville, code postal ou email ; filtres par activation, ville et code postal ; tri par priorité puis nom.

Le menu CONTENU contient Accueil du site, Bannières, Revendeurs, dans cet ordre. Tous ces contrôleurs exigent `ROLE_ADMIN`. Les formulaires conservent la protection CSRF de Symfony / EasyAdmin. Les contenus sont échappés dans Twig. Une validation partagée des destinations refuse notamment JavaScript, les URLs relatives à un protocole, les antislashs et les caractères de contrôle.

## 5. Géocodage

`RevendeurGeocoder` utilise `GET https://data.geopf.fr/geocodage/search`, avec `index=address`, `q` et `limit=1`, selon la [documentation officielle IGN / Géoplateforme](https://cartes.gouv.fr/aide/fr/guides-utilisateur/utiliser-les-services-de-la-geoplateforme/geocodage/).

Le CRUD appelle le service lors d’une création ou d’une modification d’adresse. Une modification manuelle de la paire latitude / longitude est prioritaire. Les coordonnées sont stockées en base. Un changement de contact sans changement d’adresse conserve les coordonnées existantes.

Le timeout d’inactivité est de 3 secondes et la durée totale maximale de 5 secondes. Les erreurs HTTP, erreurs réseau, réponses invalides, résultats absents et scores inférieurs à 0,5 produisent un échec contrôlé. Les coordonnées devenues obsolètes sont retirées ; l’enregistrement réussit et un message explique comment corriger manuellement la localisation. Un pays autre que France demande des coordonnées manuelles.

Aucun géocodage n’a lieu dans le contrôleur public ou dans le navigateur. Tous les tests du géocodage utilisent des réponses mockées.

## 6. Carte et liste publique

La route `/nos-revendeurs` affiche le surtitre Où nous trouver, le titre Nos revendeurs. et le texte introductif administrable.

Leaflet 1.9.4 est fourni localement avec sa licence et ses images ; cette version est la [version stable indiquée par Leaflet](https://leafletjs.com/download). Ses fichiers CSS / JS sont chargés uniquement sur la page revendeurs. Le fond de carte utilise les tuiles OpenStreetMap avec attribution.

La vue initiale est centrée sur la France. Chaque revendeur actif possédant des coordonnées reçoit un marqueur et une popup contenant son nom, son adresse, sa ville et ses contacts publics présents. Les popups sont construites depuis les nœuds DOM échappés par Twig, sans interpolation de contenu dans une chaîne HTML.

La liste affiche tous les revendeurs actifs, y compris ceux en attente de coordonnées. Elle reste visible sans JavaScript ou si la cartographie ne charge pas. Le bouton Voir sur la carte centre la carte et ouvre le marqueur. Une recherche progressive par ville ou code postal filtre la liste et les marqueurs. Sans JavaScript, le champ de recherche et les boutons cartographiques restent masqués.

L’habillage reprend les variables ivoire, noir, ambre et doré existantes. La hauteur de carte est bornée, avec 350 px sur mobile. La liste passe de trois à deux puis une colonne. Les popups et adresses longues restent contenues dans la largeur disponible.

## 7. Navigation

Le lien Nos revendeurs suit Nos saveurs dans la liste commune au desktop et au menu mobile. Il pointe vers `/nos-revendeurs` et indique la page courante avec `aria-current`. Le fonctionnement Bootstrap du hamburger et le langage visuel existant sont conservés. Le header simplifié du checkout garde son comportement existant.

## 8. Migration et validation du schéma

`Version20261028090000` a été appliquée à la base locale. Elle crée `revendeur`, ajoute les trois colonnes de bannière, réutilise ou crée `homepage_content`, puis initialise le singleton en important les anciennes valeurs disponibles. Les valeurs par défaut sont figées dans `migrations/data/home-20261028.json` pour rendre la migration indépendante des évolutions futures du code.

Aucune suppression de ligne, de colonne ou de table. Les images historiques et les anciennes clés de contenu restent présentes. Le retour arrière est explicitement irréversible afin de ne pas détruire les contenus administrés.

**Validation locale : Mapping [OK], Database [ERROR].** Le décalage de base était présent avant les modifications. Après cette migration, les seules différences proposées par Doctrine concernent `commercial_settings`, `promo_code` et les colonnes de promotions / commandes absentes du mapping de cette branche. Aucune différence ne concerne `homepage_content`, `header` ou `revendeur`.

Obtenir Database [OK] sur cette base demanderait de réconcilier ces fonctionnalités hors périmètre. Aucun SQL de suppression proposé par Doctrine n’a été exécuté et aucune configuration n’a été ajoutée pour masquer ces différences. Un test vérifie que le mapping complet et un schéma créé à partir de cette branche sont cohérents sur une base SQLite neuve.

## 9. Fichiers

Fichiers existants modifiés :

```text
.gitignore
src/Controller/Admin/DashboardController.php
src/Controller/Admin/HeaderCrudController.php
src/Controller/HomeController.php
src/Entity/Header.php
src/Twig/AppExtensions.php
templates/base.html.twig
templates/components/_announcement.html.twig
templates/home/index.html.twig
tests/StorefrontTest.php
```

Fichiers ajoutés :

```text
docs/CMS_HOME_REVENDEURS.md
migrations/Version20261028090000.php
migrations/data/home-20261028.json
src/Controller/Admin/ContentImage.php
src/Controller/Admin/HomeContentController.php
src/Controller/Admin/HomePageContentCrudController.php
src/Controller/Admin/RevendeurCrudController.php
src/Controller/RevendeurController.php
src/Entity/HomePageContent.php
src/Entity/Revendeur.php
src/Form/ContentImageType.php
src/Service/HomeContent.php
src/Service/RevendeurGeocoder.php
src/Validator/PublicContentUrl.php
src/Validator/PublicContentUrlValidator.php
templates/admin/content_image_theme.html.twig
templates/home/_reassurance.html.twig
templates/revendeur/index.html.twig
public/assets/css/revendeurs.css
public/assets/js/revendeurs.js
public/assets/vendor/leaflet/leaflet.js
public/assets/vendor/leaflet/leaflet.css
public/assets/vendor/leaflet/LICENSE
public/assets/vendor/leaflet/images/marker-icon.png
public/assets/vendor/leaflet/images/marker-icon-2x.png
public/assets/vendor/leaflet/images/marker-shadow.png
public/assets/vendor/leaflet/images/layers.png
public/assets/vendor/leaflet/images/layers-2x.png
tests/HomeCmsMigrationTest.php
tests/HomeCmsRevendeursTest.php
tests/RevendeurGeocoderTest.php
tools/check-cms-revendeurs.cjs
```

Les captures, instantanés HTML, rapports de tests et outils temporaires du navigateur sont placés dans `var/cms-review`, exclu de Git. Aucun changement dans Stripe, Sendcloud, le colisage, les promotions, le stock, les emails, les commandes, la facturation, l’authentification ou `.env.local`.

## 10. Tests

La suite PHPUnit complète passe : **354 tests, 3 937 assertions**. Elle inclut les tests de contenu par défaut / enregistré, l’échappement, les slides actifs et ordonnés, les uploads valides et invalides, les aperçus, les revendeurs actifs / inactifs, les listes vides / multiples, les coordonnées, le géocodage mocké et indisponible, la correction manuelle, les changements d’adresse, la conservation des données lors de la migration, le refus d’accès aux non-administrateurs et la navigation. Les 43 templates Twig et le conteneur Symfony passent également leurs contrôles de syntaxe ; `git diff --check` ne signale aucune erreur.

Les contrôles navigateur utilisent Edge via Playwright, sur les pages produites par les tests. Toutes les requêtes sont interceptées : les tuiles sont remplacées par une image neutre, aucun service externe n’est appelé. Les fonds géographiques réels ne sont donc pas évalués par ces captures.

26 contrôles navigateur : accueil, revendeurs et deux formulaires EasyAdmin à 1600, 1280, 1024, 768, 430 et 390 px ; contrôle sans JavaScript ; ouverture tactile d’une popup à 390 px. Aucun débordement horizontal ni erreur JavaScript détecté. Le hamburger, les liens, les onglets, les marqueurs, les popups et le filtrage sont vérifiés.

Commandes de vérification :

```powershell
php vendor/phpunit/phpunit/phpunit
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
git diff --check

# Export des pages pour les contrôles navigateur
$env:CMS_REVIEW_DIR = "$PWD\var\cms-review\html"
php vendor/phpunit/phpunit/phpunit --filter HomeCmsRevendeursTest
node tools/check-cms-revendeurs.cjs <chemin-vers-playwright-core>
```

Rapport PHPUnit local : `var/cms-full-results.xml`. Résultats navigateur et captures : `var/cms-review/browser/`.
