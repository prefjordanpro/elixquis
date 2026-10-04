# Intégration progressive Sendcloud

## Correction du checkout réel (adresse 17 / produit 4)

La commande `app:sendcloud:quote` et le contrôleur réutilisaient déjà `SendcloudDelivery::offers` : même adresse Doctrine, mêmes lignes produit/quantité, même calcul emballage + produits, mêmes dimensions, même payload `shipping-options` et même filtre commercial. Un test vérifie désormais l’égalité exacte des payloads CLI/web, y compris les poids et dimensions.

L’erreur réelle provenait de l’étape web suivante : la recherche GET `service-points` envoyait simultanément `carrier_code` et `use_integration_carriers=true`. Ces paramètres sont mutuellement exclusifs dans l’API v3. Le second a été supprimé puisque le transporteur de l’offre est explicitement choisi. Les tests simulés vérifient désormais cette exclusivité pour chaque recherche de relais.

Les erreurs de configuration utilisent une exception distincte, avant appel API ; une absence d’offre compatible reste un résultat métier explicite. Les erreurs API sont journalisées avec opération, type d’exception et message expurgé (statut HTTP lorsque disponible). Aucune réponse brute, adresse, clé, objet exception ou contexte d’authentification n’est journalisé. Les erreurs techniques imprévues sont également masquées côté client et signalées côté serveur.

Suite complète après correction : **114 tests, 1 573 assertions**, tous réussis. Les contrôles du conteneur Symfony, de Twig et `git diff --check` passent. Les dépréciations Doctrine existantes restent présentes. Le fallback historique et le verrou de création d’étiquettes restent inchangés ; `.env.local` n’a pas été modifié dans cette correction.

Vérification réelle dans Edge via Apache, session temporaire du propriétaire, adresse 17, produit 4, quantité 1 : trois options affichées, Chrono Shop2Shop à **6,05 € TTC**, Colissimo Home à **14,41 € TTC**, Chrono 18 à **16,02 € TTC**. Recherche Shop2Shop réussie : **100 relais** avec nom et adresse. Contrôles desktop 1440 px et mobile 390 px sans débordement. Aucun formulaire final de commande soumis, aucune réservation de stock, expédition ou étiquette créée. Les captures restent locales dans le répertoire ignoré `var/sendcloud-verification`.

## Sélection des méthodes adaptées au checkout

La politique `SendcloudMethodPolicy` s’applique aux devis affichés et à leur revalidation serveur. `sendcloud.method_rules`, dans `config/packages/sendcloud.yaml`, définit les transporteurs autorisés, les motifs de codes techniques de méthodes (`methods`) et les priorités facultatives (`preferred_methods`). Les noms commerciaux ne servent pas à autoriser une méthode. Le joker initial `*` autorise les variantes du transporteur retenu qui passent les contrôles de caractéristiques ; il peut être remplacé par une liste de codes exacts validés ou de préfixes. Aucun code fictif n’est ajouté aux résultats API.

Les formats `letter`, `mailbox`, `pallet`, `long`, les livraisons en boîte aux lettres et les services de retour sont exclus. Une défense supplémentaire écarte les mentions lettre/enveloppe dans le code ou le nom lorsque les caractéristiques sont incomplètes. Le relais doit exiger un identifiant de point ; un type domicile contradictoire est refusé. Les transporteurs initiaux sont Mondial Relay, Colissimo et Chronopost pour les relais, Colissimo pour le standard, Chronopost pour l’express. Les caractéristiques premium/délai rapide empêchent une variante express d’être présentée comme standard.

Le checkout présente trois catégories françaises, uniquement lorsqu’elles ont une offre réelle tarifée : relais, standard à domicile, express. Une méthode par catégorie domicile et une méthode relais par transporteur sont retenues. Par défaut, le tarif TTC le plus bas gagne, avec départage stable par code ; une méthode correspondant à `preferred_methods` gagne en priorité si configurée. Cette politique ne modifie aucun tarif ni la TVA. Le relais est ensuite réellement choisi avec son nom et son adresse visibles, puis revalidé côté serveur avant création de la commande.

Ce filtrage commercial ne constitue pas une certification du transport de bouteilles par un contrat : la liste peut être resserrée aux codes effectivement validés avec les transporteurs. Le fallback est conservé. Aucun envoi ni étiquette réelle n’est créé pour cette modification.

## État actuel : devis activés, paramètres privés en environnement

Cette section remplace les instructions de saisie en YAML et les mentions « désactivé » du rapport initial ci-dessous. La branche reste `feature/livraison-sendcloud`.

`sendcloud.enabled: true`, `sendcloud.allow_label_creation: false`, `sendcloud.legacy_fallback_enabled: true`. Webhooks toujours désactivés. Les paramètres privés sont désormais lus depuis `.env.local` ; 16 variables absentes y ont été ajoutées vides sans remplacer les variables existantes. Aucune donnée personnelle n’est inscrite en configuration versionnée.

| Variable à compléter dans `.env.local` | Valeur attendue |
| --- | --- |
| `SENDCLOUD_PACKAGING_WEIGHT_G` | Poids de l’emballage vide avec protections, grammes, entier ≥ 0 |
| `SENDCLOUD_PARCEL_LENGTH_CM` | Longueur extérieure du colis fermé en cm, positive |
| `SENDCLOUD_PARCEL_WIDTH_CM` | Largeur extérieure en cm, positive |
| `SENDCLOUD_PARCEL_HEIGHT_CM` | Hauteur extérieure en cm, positive |
| `SENDCLOUD_PARCEL_MAX_BOTTLES` | Capacité réelle en bouteilles, entier positif |
| `SENDCLOUD_SENDER_NAME` | Nom du contact expéditeur |
| `SENDCLOUD_SENDER_COMPANY` | Société, facultative si non applicable |
| `SENDCLOUD_SENDER_ADDRESS` | Numéro et rue du profil expéditeur choisi |
| `SENDCLOUD_SENDER_ADDRESS_EXTRA` | Complément d’adresse, facultatif |
| `SENDCLOUD_SENDER_POSTAL_CODE` | Code postal, conserver les éventuels zéros initiaux |
| `SENDCLOUD_SENDER_CITY` | Ville |
| `SENDCLOUD_SENDER_COUNTRY` | Code ISO majuscule à deux lettres, par exemple FR |
| `SENDCLOUD_SENDER_PHONE` | Téléphone expéditeur, format international conseillé |
| `SENDCLOUD_SENDER_EMAIL` | E-mail expéditeur |
| `SENDCLOUD_SHIPPING_TAX_RATE` | Taux de TVA applicable, valeur numérique, à confirmer |
| `SENDCLOUD_QUOTE_INCLUDES_TAX` | `1` si tarif API TTC vérifié, `0` si HT |

Mettre entre guillemets les textes contenant des espaces. Ne pas modifier les clés API existantes. Le tarif est issu du devis sans marge : si HT, seule la TVA configurée est ajoutée. Les poids produits restent dans l’administration. Les valeurs vides provoquent une erreur claire plutôt qu’un devis fondé sur des données inventées.

Après saisie : `php bin/console cache:clear`, puis `php bin/console app:sendcloud:quote --address=ID_ADRESSE --product=ID_PRODUIT --quantity=1`. Utiliser les identifiants réels d’une adresse cliente complète et d’un produit pesé, avec une destination domestique pour ce premier test. Cette commande réservée à l’exploitation serveur ne crée ni commande, ni réservation de stock, ni expédition. Elle appelle uniquement le calcul de devis `POST shipping-options` et affiche les méthodes et tarifs sans coordonnées privées.

Vérifications de cette étape : GET réels réussis (une adresse expéditeur, 21 contrats) ; devis réel bloqué avant tout appel réseau car l’expéditeur reste à compléter. Aucun devis compatible ne peut être affirmé avant la saisie de l’adresse, de l’emballage et de la fiscalité. PHPUnit : **106 tests, 1 516 assertions**, tous réussis. Configuration YAML et conteneur Symfony validés. Aucune expédition ou étiquette réelle créée.

## Audit initial

Branche de travail : `feature/livraison-sendcloud`, répertoire initialement propre. `.env.local` est ignoré et non suivi ; ses valeurs ne sont pas lues ou reproduites dans ce rapport.

| Composant actuel | Conservation / remplacement progressif |
| --- | --- |
| `Carrier` et CRUD transporteur | Nom, description, tarif TTC et TVA configurés manuellement. Conservés pour les commandes anciennes et le checkout historique. |
| `Cart` | Quantités et prix relus côté serveur, limites de stock. Conservé sans changement. |
| `OrderType` / `OrderController` | Adresse propriétaire et transporteur Doctrine, formulaire CSRF, double soumission renvoyée vers la commande existante. Conservé ; un parcours Sendcloud distinct sera activable. |
| `OrderManager` | Création transactionnelle, réservation de stock, instantanés des lignes. Conservé, avec ajout d’un instantané structuré de livraison. |
| `Order` / `OrderDetail` | Transporteur, livraison TTC, taux TVA, adresse texte et prix historiques des articles. Conservés pour Stripe, factures, annulations et remboursements. |
| `Address` | Nom, adresse sur une ligne, code postal, ville, pays et téléphone ; propriétaire vérifié côté serveur. Utilisée comme origine de l’instantané destinataire. |
| Administration | Transitions protégées par rôle et CSRF ; aucune expédition ou étiquette existante. Ajout d’une section dédiée. |
| Espace client | Détail filtré par propriétaire ; aucun suivi actuel. Ajout d’un suivi sans identifiants internes. |
| Produits / colis | Aucun poids ni dimensions. Nécessité de poids produits et d’un emballage réellement renseignés ; aucune estimation inventée. |
| Webhooks | Stripe existant uniquement. Réception Sendcloud à préparer séparément, désactivée tant que non configurée. |

L’API v3 actuelle est retenue, à partir des [schémas publics Sendcloud](https://sendcloud.dev/llms.txt), notamment les [options de livraison](https://sendcloud.dev/api/v3/shipping-options/return-a-list-of-available-shipping-options), les [points relais](https://sendcloud.dev/api/v3/service-points/retrieve-a-service-point) et les [expéditions](https://sendcloud.dev/api/v3/shipments/create-and-announce-a-shipment-synchronously).

Les données manquantes (expéditeur, emballage, poids, politique de TVA) et l’autorisation des opérations facturables restent à configurer explicitement. Aucun envoi réel ne sera créé pendant cette intervention.

## Résultat et activation

### Préparation du premier test réel (mise à jour)

Renseigner manuellement dans `config/packages/sendcloud.yaml` :

1. `sendcloud.sender.name` : nom du contact expéditeur ; `company_name` : raison sociale si applicable.
2. `address_line_1` : numéro et rue du profil expéditeur choisi dans Sendcloud ; `address_line_2` si nécessaire ; `postal_code`, `city`, `country_code` (ISO, par exemple `FR`).
3. `phone_number` et `email` : coordonnées opérationnelles de l’expéditeur.
4. `sendcloud.parcel.packaging_weight_g` : poids réel de l’emballage vide, protections comprises, en grammes.
5. `length_cm`, `width_cm`, `height_cm` : dimensions extérieures du colis fermé, en centimètres.
6. `max_units` : nombre maximal réel de bouteilles que cet emballage peut contenir. Le premier périmètre utilise un seul colis.
7. `sendcloud.tax_rate` et `sendcloud.quote_includes_tax` : traitement fiscal du tarif API. Le prix retourné est utilisé sans marge ni grille commerciale ; s’il est HT, seule la TVA configurée est ajoutée. Ne pas indiquer qu’un tarif est TTC sans avoir vérifié son traitement dans le compte/contrat Sendcloud.

Dans **Administration → Produits → Modifier**, renseigner **Poids d’expédition (g)** pour chaque produit acheté lors du test : une unité complète, bouteille pleine et bouchon inclus, sans ajouter à nouveau l’emballage du colis. Le champ est désormais visible aussi dans la liste. Une valeur vide est autorisée pour préserver les produits historiques, mais bloque leurs devis Sendcloud.

Dans Sendcloud, vérifier les transporteurs activés ou les contrats connectés. Préparer également une adresse destinataire complète dans l’espace client, dans le même pays que l’expéditeur pour ce premier périmètre.

**Sélection du profil expéditeur :** la mise en œuvre actuelle de l’API v3 transmet un objet `from_address` complet, figé ensuite sur la commande. Elle ne choisit pas automatiquement le profil par défaut et ne reçoit pas un identifiant seul. La donnée manquante est donc **l’adresse et les coordonnées du profil expéditeur à utiliser**, à recopier dans `sendcloud.sender` après vérification dans Sendcloud. Aucun identifiant d’intégration, de marque ou de contrat n’est nécessaire à renseigner manuellement pour les devis ; les contrats disponibles sont retournés par l’API. Référence : [origine des devis v3](https://sendcloud.dev/api/v3/shipping-options/return-a-list-of-available-shipping-options).

Après saisie, mettre `sendcloud.enabled: true`, garder `sendcloud.allow_label_creation: false` et `sendcloud.webhooks_enabled: false`, puis vider le cache Symfony (`php bin/console cache:clear`). Tester le checkout : seules les offres API compatibles et tarifées y sont affichées. L’activation des devis ne crée pas d’envoi.

Pendant la transition, `sendcloud.legacy_fallback_enabled: true` affiche un lien **Utiliser la livraison classique** vers les transporteurs et tarifs historiques. Ce choix est explicite et n’est pas mélangé aux offres API. Pour le supprimer après validation, mettre **`sendcloud.legacy_fallback_enabled: false`**, en conservant `sendcloud.enabled: true`, puis vider le cache. Les anciennes commandes et les entités `Carrier` restent conservées ; l’accès direct à l’ancien formulaire ne permet plus de créer une nouvelle commande historique. Désactiver simultanément les deux parcours bloque la livraison plutôt que de contourner ce réglage.

Vérification après préparation de l’activation : **105 tests, 1 512 assertions**, tous réussis. Tests supplémentaires du tarif sans marge, du fallback explicite et du blocage de ses routes après désactivation. Les contrôles du conteneur, YAML et Twig passent. Aucun appel API réel n’a été effectué dans cette étape de préparation.

L’intégration est implémentée mais désactivée par défaut dans `config/packages/sendcloud.yaml`. Le checkout historique reste donc disponible. `.env.local` n’a pas été modifié. Les clés `SENDCLOUD_PUBLIC_KEY` et `SENDCLOUD_SECRET_KEY` sont utilisées exclusivement côté serveur ; elles ne sont ni affichées, ni intégrées au HTML, ni journalisées.

Avant activation, renseigner les poids réels de chaque bouteille dans le CRUD produit, l’adresse expéditeur complète, les dimensions de l’emballage, son poids et sa capacité maximale. Configurer explicitement le taux de TVA et préciser si le tarif API inclut déjà cette taxe. Aucune valeur commerciale, aucun poids et aucun transporteur ne sont inventés. Le prix TTC présenté au client est calculé côté serveur à partir du devis Sendcloud et de cette politique tarifaire.

Le premier périmètre couvre un colis domestique, avec contrôle de capacité. Les envois internationaux nécessitant des formalités douanières et les commandes nécessitant plusieurs colis restent hors de ce premier périmètre. Une donnée manquante bloque le parcours Sendcloud avec un message explicite.

L’activation des devis (`sendcloud.enabled`) est indépendante de celle des étiquettes (`sendcloud.allow_label_creation`). Garder la seconde désactivée jusqu’à une autorisation explicite de création d’un envoi potentiellement facturable. Aucun envoi ni étiquette réelle n’a été créé lors de cette intervention.

## Parcours client et administration

Le nouveau checkout propose uniquement les méthodes compatibles retournées par l’API, avec un devis exploitable en euros. Les points relais sont recherchés puis revalidés côté serveur : transporteur, pays, disponibilité et compatibilité avec la méthode choisie. Une variation de prix impose une nouvelle confirmation. L’adresse doit appartenir au client connecté et les formulaires utilisent CSRF ; le navigateur ne choisit ni le statut ni un montant fiable.

La commande conserve un instantané de l’adresse, du transporteur, de la méthode, du tarif, du relais, des poids et du colis. Les lignes et prix historiques restent conservés. Les données sont de nouveau contrôlées sous verrou avant réservation du stock pour éviter une modification concurrente pendant le devis.

L’administration dispose d’actions de création, vérification et téléchargement PDF, réservées à `ROLE_ADMIN`, avec CSRF sur les actions POST. La création exige une commande payée en statut payé ou préparation. Elle enregistre une référence unique avant l’appel API : un clic répété ne recrée jamais l’envoi. Après une réponse incertaine, la vérification recherche l’envoi existant ; aucun nouvel appel de création n’est lancé automatiquement. Si cette recherche ne résout pas l’incertitude, vérifier dans Sendcloud avant toute intervention manuelle.

Créer une étiquette ne passe pas automatiquement la commande au statut « Expédiée » : les transitions métier existantes sont conservées. Le client voit le suivi uniquement de ses propres commandes, sans identifiants Sendcloud internes.

Une expédition active bloque l’annulation et la restitution du stock. Annuler d’abord l’expédition dans Sendcloud puis utiliser « Vérifier » pour synchroniser son état. Un remboursement Stripe confirmé ne remet pas en stock une marchandise déjà engagée dans une expédition active. Les anciennes commandes suivent leur comportement existant. Les services Stripe n’ont pas été modifiés.

## Webhooks

La route POST `/webhooks/sendcloud` est préparée mais désactivée localement (`sendcloud.webhooks_enabled: false`). Aucune URL publique fictive n’a été déclarée. Pour une mise en ligne future, configurer une véritable URL HTTPS accessible depuis Sendcloud, puis activer la réception.

La signature HMAC SHA-256 `Sendcloud-Signature` est vérifiée sur le corps brut avec la clé secrète. Après notification, l’état est relu via l’API : le contenu du webhook ne fait pas autorité, ce qui évite les régressions dues à des notifications répétées ou désordonnées. Les [instructions officielles de signature](https://sendcloud.dev/api/v3/webhooks/index) servent de référence.

## Fichiers concernés

- Configuration : `config/packages/sendcloud.yaml`, `config/services.yaml`.
- API et orchestration : `src/Service/SendcloudService.php`, `SendcloudDelivery.php`, `SendcloudShipping.php`, `SendcloudWebhook.php`, `ShippingConfiguration.php`, DTO et exception dans `src/Shipping/`.
- Entrées HTTP : `SendcloudCheckoutController`, `SendcloudWebhookController`, `Admin/ShippingController`, redirection conditionnelle dans `OrderController`.
- Persistance : nouvelle entité `Shipment`, instantané dans `Order`, poids dans `Product`, migration additive `Version20261004140000`.
- Compatibilité stock et annulation : garde-fous dans `OrderManager`, `OrderCancellation` et `Order` ; champ poids dans `Admin/ProductCrudController`.
- Interfaces : nouveau checkout `templates/order/sendcloud.html.twig`, sections de suivi client et administration, adaptation du récapitulatif et du lien de suivi dans la liste client. Le layout reconnaît seulement la nouvelle route checkout ; la page d’accueil et ses styles n’ont pas été modifiés.
- Diagnostic : `src/Command/SendcloudCheckCommand.php` (`app:sendcloud:check`).
- Tests : `tests/SendcloudTest.php`.

## Vérifications

La connexion réelle a été vérifiée par des appels GET uniquement : une adresse expéditeur et 21 contrats sont accessibles. Ce diagnostic valide l’authentification, sans garantir les méthodes disponibles pour chaque destination : celles-ci sont obtenues dynamiquement lors du devis.

La migration additive a été appliquée localement. Doctrine confirme la validité des mappings et l’alignement du schéma. Les anciennes lignes de commande et leurs transporteurs sont conservés.

La suite complète passe : **102 tests, 1 497 assertions**, dont 25 tests Sendcloud avec API simulée. Elle couvre propriétaire, CSRF, tarifs et relais validés, données manquantes, instantanés, répétitions, erreurs API, création incertaine, PDF, signature webhook et absence de double restitution de stock. Les dépréciations Doctrine déjà présentes restent signalées par PHPUnit.

Vérification navigateur sur adresse, méthodes, relais, suivi client et administration aux largeurs 1440, 1024, 768, 390 et 320 px : **25 contrôles**, sans débordement horizontal, image manquante, champ sans label ni erreur JavaScript. Les rendus utilisent des données de test et les ressources réelles du site ; ils ne créent aucun envoi. Les contrôles Twig, YAML, conteneur Symfony et `git diff --check` passent également.
