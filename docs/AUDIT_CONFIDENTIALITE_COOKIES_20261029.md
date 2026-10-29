# Audit technique des documents légaux — 29 octobre 2026

## Périmètre et textes reçus

Audit du code présent sur `feature/cms-home-revendeurs`, des templates et scripts réellement référencés, puis lecture des ressources publiques du sélecteur Sendcloud et de sa bibliothèque Mapbox. Aucun changement des intégrations Stripe, Sendcloud, Mailjet, des données de société partagées, de l'authentification ou de `.env.local`. Aucun commit ni push.

La pièce jointe de référence s'arrête exactement à `## 9. Modifier son`, au milieu de la politique cookies. La seconde pièce jointe s'arrête à `- campagne promotion`, au milieu de la section 10 des consignes. La suite a été demandée. Il n'est pas possible de publier fidèlement une politique cookies complète à partir de ce fragment sans inventer les passages manquants. Le document cookies n'est donc pas publié et aucun faux lien de gestion du consentement n'est créé.

## Données observées

| Domaine | Réalité du code | Références |
| --- | --- | --- |
| Compte | Identifiant, prénom, nom, e-mail, rôles, mot de passe haché, date de vérification d'e-mail. Pas de téléphone dans User. | `src/Entity/User.php` |
| Adresse | Prénom, nom, adresse, code postal, ville, pays, téléphone, référence du compte. Pas de champ complément d'adresse. | `src/Entity/Address.php` |
| Commande | Date, lignes produits/quantités/prix/TVA, livraison, réductions, code promo, états, version CGV et date d'acceptation. | `src/Entity/Order.php`, `OrderDetail.php` |
| Paiement | Identifiant de session Stripe, intention de paiement, identifiant/statut du remboursement, date d'initiation et requête de checkout JSON ; pas de numéro complet de carte stocké par les entités. | `src/Entity/Order.php`, `src/Service/StripePayment.php` |
| Livraison | Instantané JSON expéditeur/destinataire, nom, adresse, téléphone, e-mail, transporteur et méthode, colis, point relais, éventuel numéro destinataire. Identifiants Sendcloud, suivi et états. | `src/Service/SendcloudDelivery.php`, `src/Entity/Shipment.php`, `ColisCommande.php` |
| E-mails | Destinataire, prénom, contenu nécessaire à la commande et son suivi, état d'envoi et date dans les notifications persistées. Mailjet reçoit le contenu transactionnel. Suivi des ouvertures/clics désactivé dans `sendEmail()`. | `src/Entity/OrderNotification.php`, `src/Service/OrderEmails.php`, `src/Classe/Mail.php` |
| Vérification et reset | Empreintes SHA-256 de jetons, compte concerné, finalité, expiration, empreinte d'identité ; validité 1 h pour reset et 24 h pour vérification. Consommation/supersession supprime les jetons concernés. La validité n'est pas une garantie de purge à échéance. | `src/Entity/AccountToken.php`, `src/Service/AccountAccess.php` |
| Annulations | Raison libre éventuelle, date/heure, décision, statut précédent, résolution et administrateur. Aucun workflow distinct de dépôt de rétractation en ligne. | `src/Entity/CancellationRequest.php`, `src/Service/OrderCancellation.php` |
| Sécurité et logs | IP utilisée dans les limites de demandes de compte ; journaux contenant identifiants, états, routes, erreurs, identifiants d'envoi. Pas de rotation ou durée de rétention Monolog configurée : fichiers en dev, stderr en prod ; rétention d'infrastructure non déterminable ici. | `src/Controller/AccountAccessController.php`, `config/packages/monolog.yaml` |
| Marketing | Aucun formulaire newsletter, opt-in marketing ni workflow de campagne identifié dans src/templates/config/assets. Les promotions du catalogue et du panier ne constituent pas une inscription marketing. | Recherche dans le code applicatif, `src/Form/RegisterUserType.php` |
| Revendeurs | Nom, adresse professionnelle, complément, ville/pays, coordonnées, téléphone/e-mail/site professionnels, visibilité et dates. Recherche ville/code postal locale dans le navigateur. | `src/Entity/Revendeur.php`, `public/assets/js/revendeurs.js` |

L'hébergement OVH SAS/OVHcloud et les informations de société sont ceux fournis par l'utilisateur ; l'hébergement effectif distant ne peut pas être établi depuis ce checkout local. Aucune configuration distante n'a été modifiée.

## Cartographie et ressources tierces

- **Nos revendeurs** : Leaflet est servi localement. Les tuiles proviennent de `https://tile.openstreetmap.org/{z}/{x}/{y}.png`, service OpenStreetMap Foundation. La requête révèle l'IP au fournisseur ; il ne s'agit pas d'une collecte de géolocalisation GPS du visiteur dans le script du site. Sources : `templates/revendeur/index.html.twig`, `public/assets/js/revendeurs.js`.
- **Géocodage administrateur** : adresse professionnelle des revendeurs envoyée côté serveur à `https://data.geopf.fr/geocodage/search` (Géoplateforme, IGN). La recherche du visiteur n'appelle pas ce service. Source : `src/Service/RevendeurGeocoder.php`.
- **Points relais** : le clic « Choisir mon point relais » charge `https://embed.sendcloud.sc/spp/1.0.0/api.min.js`. Ce script ouvre l'iframe `https://servicepoints.sendcloud.sc/embed/v3/service-point-picker/`, avec adresse de recherche, ville, code postal et paramètres de livraison. Il n'est pas chargé automatiquement à l'ouverture du checkout. Sources : `public/assets/js/sendcloud-picker.js`, `templates/components/_service_point_picker.html.twig`.
- **Iframe Sendcloud** : sa page publique inspectée charge Mapbox GL JS **1.13.3**, le géocodeur Mapbox, des ressources Sendcloud, Font Awesome via cdnjs et Supercluster via jsDelivr. C'est une seconde cartographie, distincte d'OpenStreetMap utilisé par la page revendeurs. L'audit n'a utilisé aucune clé API privée, adresse client réelle ou création de livraison.
- **Stripe** : le site crée une session de paiement côté serveur puis redirige vers Checkout hébergé ; aucun Stripe.js global n'est chargé par les templates. Les traceurs des pages Stripe ne sont pas assimilés à ceux du domaine Elixquis.
- Aucun Google Analytics, Google Tag Manager, Meta Pixel, script publicitaire, widget social ou outil de mesure d'audience identifié dans les templates et scripts applicatifs chargés.

Les ressources tierces ont été lues le 29 octobre 2026 ; leur contenu peut changer indépendamment du dépôt. Les copies d'audit sont dans `var/legal-review/` (ignoré par Git).

## Stockages : faits établis, sans durée inventée

| Nom / ressource | Fournisseur | Finalité observée | Type | Durée établie | État du consentement dans l'audit |
| --- | --- | --- | --- | --- | --- |
| Session PHP/Symfony (`PHPSESSID` dans le PHP local) | Elixquis | Connexion, panier et code promotionnel, sécurité des formulaires, choix de livraison | Cookie de session ; données côté serveur | `session.cookie_lifetime=0` dans le PHP local ; `gc_maxlifetime=1440` est un paramètre de nettoyage serveur, pas une promesse de durée de conservation. À revérifier sur l'hôte de production. | Fonctionnement nécessaire du service demandé ; aucun consentement marketing associé |
| `ageVerified` | Elixquis | Mémorisation de la confirmation de majorité | localStorage | Aucune expiration codée ; conservé jusqu'à effacement du stockage | Aucun usage publicitaire/audience identifié |
| `cart`, `promo_code`, sélection de livraison | Elixquis | Panier, avantages appliqués, parcours de commande | Valeurs de session serveur, pas de cookies distincts | Dépendent de la session | Aucun usage publicitaire/audience identifié |
| `mapbox.eventData:*`, `mapbox.eventData.uuid:*` | Mapbox dans l'iframe Sendcloud | Événements techniques de chargement (`map.load`), identifiant généré et comptabilisation d'usage | localStorage du domaine du composant | Aucune expiration des clés constatée dans les fonctions de stockage de la version 1.13.3 lue ; une périodicité d'événement n'est pas une expiration du stockage | Exemption ou nécessité de consentement à qualifier pour ce composant ; ne pas confondre absence de publicité et exemption juridique automatique |
| Événements vers `events.mapbox.com/events/v2` | Mapbox dans l'iframe Sendcloud | Chargements de carte et usage technique | Requêtes réseau | Pas de durée de stockage côté fournisseur déduite du seul SDK | Même réserve que ci-dessus ; l'exécution authentifiée du picker n'a pas été testée avec des données client |
| Cookies internes Stripe / composants tiers | Fournisseurs concernés | Paiement sécurisé et services tiers demandés | Hors domaine / iframe | Aucune durée prétendue sans preuve | Ne pas affirmer une absence globale de traceurs sur la base du seul code Elixquis |

Les paramètres `cookie_secure: auto`, `cookie_httponly: true` et `cookie_samesite: lax` sont dans `config/packages/framework.yaml`. Le site ne code ni cookie de consentement ni sessionStorage et ne configure pas de remember-me. Ces constats ne constituent pas une qualification juridique de tous les stockages tiers.

## Conséquence pour la politique cookies

L'absence d'outil publicitaire ou de mesure d'audience dans le code Elixquis est établie. En revanche, le composant tiers Mapbox contient un stockage d'identifiants et des événements techniques. Il serait inexact de conclure que l'ensemble du parcours n'utilise que la session et le contrôle de majorité sans examiner ce composant. Aucune fausse bannière visuelle n'a été ajoutée. Aucune affirmation juridique d'exemption globale n'a été publiée ; la publication du document cookies et toute gestion éventuelle du consentement restent à terminer avec le texte complet et la qualification du composant concerné.

Documentation officielle consultée : [politique de confidentialité OpenStreetMap Foundation](https://osmfoundation.org/wiki/Privacy_Policy), [politique d'utilisation des tuiles OSM](https://operations.osmfoundation.org/policies/tiles/), [facturation des chargements Mapbox GL JS, y compris v1](https://docs.mapbox.com/mapbox-gl-js/guides/pricing/), [sécurité et points de terminaison Mapbox](https://docs.mapbox.com/mapbox-gl-js/guides/security-and-testing/), [FAQ Mapbox sur les données et identifiants de facturation](https://www.mapbox.com/legal/legal-faq). Les SDK mobiles Mapbox ne sont pas assimilés au SDK JavaScript inspecté.

## Adaptations précises des textes fournis

- **Mentions légales** : texte intégral conservé, seule la mise en forme change. La réserve sur les autorisations liées à l'activité alcool est conservée sans inventer de licence. Les données de société partagées ne sont pas modifiées.
- **Confidentialité** : téléphone retiré de la sous-rubrique compte (il reste dans la livraison) ; complément d'adresse retiré des adresses clients ; description des jetons et durées de validité ajoutée au passage sur le compte ; référence CGV/date ajoutées aux données de commande ; mentions conditionnelles de fournisseur cartographique remplacées par les fournisseurs établis et les flux observés ; passages de prospection adaptés à l'absence actuelle de formulaire ou workflow marketing. Les durées légales générales du texte fourni pour les commandes restent inchangées et ne prétendent pas décrire une purge automatique implémentée.
- Sources originales dans `migrations/data/mentions-initial-20261029.txt` et `confidentialite-initial-20261029.txt` ; version techniquement adaptée de confidentialité dans `confidentialite-auditee-20261029.txt`. HTML correspondant utilisé par la migration.
- **Cookies** : texte tronqué non importé et non publié ; aucun contenu final inventé.

## Intégration et déploiement

La migration additive de données `Version20261029110000` réutilise les emplacements `mentions` et `confidentialite`, prépare les brouillons puis leurs premiers instantanés publiés. Elle ignore un document déjà publié et refuse d'écraser un brouillon personnalisé. Elle ne touche ni les CGV, ni les commandes, ni le document cookies. L'historique et les contrôles administrateurs existants s'appliquent aux nouvelles pages. Le footer affiche automatiquement les liens publiés dans l'ordre existant.

Les nouveaux tests couvrent fidélité des mentions, publication/idempotence, conservation des CGV et des quatre emplacements, absence de publication des cookies incomplets, validité des routes et liens, confidentialité adaptée, conservation de l'instantané pendant une modification de brouillon et droits administrateurs.

## État obtenu et vérifications

Migration appliquée localement le 29/10/2026 à 08:54:38 (Paris). Mentions légales et confidentialité : **version 1 publiée** chacune. CGV : version 1 initiale du 29/10/2026 à 08:25:14 inchangée. Cookies : emplacement existant vide et non publié, en attente de la suite du texte. Aucun déploiement distant effectué ; la migration est livrable et applicable en production par le mécanisme normal des migrations.

Les routes `/mentions-legales` et `/politique-de-confidentialite` répondent HTTP 200 dans les configurations locales **dev et prod**, sans condition spéciale d'environnement. Le footer contient CGV, mentions légales, confidentialité et accès à la rétractation ; aucun lien cookies non publié n'est affiché.

- Nouveaux tests : **5 tests, 84 assertions**, réussis.
- Suite complète : **470 tests, 4 958 assertions**, aucun échec, durée 49,312 s ; sorties dans `var/legal-documents-full.txt` et `.xml`. Les dépréciations existantes des dépendances restent signalées.
- Doctrine : **Mapping [OK], Database [OK]**.
- Huit templates juridiques : syntaxe Twig valide ; `git diff --check` sans erreur.
- Navigateur : **14 contrôles réussis à 390 px** sur treize écrans exportés par les tests, dont les deux nouvelles pages ; aucun débordement horizontal ni erreur JavaScript. Les contrôles utilisent les ressources locales et ne publient aucune donnée de test dans la base réelle.

Fichiers ajoutés pour cette étape : ce compte rendu, `migrations/Version20261029110000.php`, `migrations/data/mentions-initial-20261029.{txt,html}`, `migrations/data/confidentialite-initial-20261029.txt`, `migrations/data/confidentialite-auditee-20261029.{txt,html}` et `tests/LegalDocumentsPublicationTest.php`. Le compte rendu général `docs/PAGES_LEGALES.md` est actualisé. Aucun autre code applicatif n'a été modifié pour cette étape : les routes, l'éditeur, l'historique et le footer existants sont réutilisés.
