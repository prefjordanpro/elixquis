# Facturation Elixquis — 29 octobre 2026

Implémentation sur `feature/facturation`, sans changement de branche, modification de `main`, commit ou push. L'espace de travail était propre au début. Aucun changement de `.env.local`, Sendcloud, colisage multi-colis, règles commerciales, StripePayment, StripeRefund, PaymentController ou Mailjet.

## Analyse préalable

Le document existant était un récapitulatif PDF recalculé depuis les lignes figées de la commande et les coordonnées actuelles du vendeur, sans facture persistée ni numéro comptable. `OrderDetail.productPrice` contient déjà le prix unitaire TTC après promotion produit ; `commercialDiscountCents` conserve la part de code promo affectée à la ligne. Les taux de TVA et la livraison facturée sont figés. `transportCostCents` conserve le coût du devis même lorsque la livraison est offerte. Le moteur commercial répartit déjà la remise en centimes : il n'a pas été modifié.

`OrderManager.confirmPayment()` est le point commun des confirmations de paiement vérifiées, notamment des webhooks signés. Il verrouille la commande et valide session, devise, montant et référence de commande. Le remboursement existant ne prend en charge que les remboursements totaux, confirmés côté Stripe. Les statuts restent 0 attente, 1 payée, 2 préparation, 3 expédiée, 4 annulée, 5 livrée, 6 remboursée, 7 demande d'annulation.

## 1. Architecture

`InvoiceIssuer` émet les documents dans la transaction existante de confirmation du paiement ou du remboursement. `InvoiceNumbering` attribue numéros et dates sous verrou. `InvoiceAmounts` fait les calculs comptables en entiers. `InvoicePdf` rend le modèle versionné et conserve une copie privée. Aucun PDF ni appel réseau n'intervient dans l'allocation comptable.

## 2. Entités

- `Invoice` : facture ou avoir, numéro, commande, référence de facture initiale, dates d'émission/création/confirmation du paiement, date de livraison nullable, devise, références de paiement/remboursement et snapshot vendeur/client/totaux/livraison/ventilation TVA.
- `InvoiceLine` : ligne immuable, position et données structurées de désignation, quantité, prix unitaires HT/TTC/TVA, taux, promotions/remises et totaux HT/TVA/TTC. Les données sont stockées en JSON par ligne et accessibles sans catalogue ni promotion actuelle.
- `InvoiceSequence` : singleton de numérotation en base. Aucun numéro n'est saisi manuellement.

Une facture porte le statut « Payée », un avoir « Émis ». Le type détermine ce statut historique ; le remboursement ne réécrit pas le statut ou le contenu de la facture initiale. Les tableaux JSON sont rendus par copie ; les entités n'exposent pas d'opérations de modification des données comptables.

## 3. Numérotation

Séries annuelles indépendantes `FAC-AAAA-000001` et `AV-AAAA-000001`. Une ligne singleton verrouillée avec `FOR UPDATE` sur MariaDB/MySQL sérialise les allocations. Le numéro et la date sont pris après acquisition du verrou ; une régression d'horloge ne fait pas reculer la dernière date attribuée. Une nouvelle année remet chaque série à zéro lors de la première allocation. Il n'existe aucun calcul `COUNT(*) + 1`.

La mise à jour du compteur et les documents appartiennent à la même transaction ; un rollback ne consomme pas le numéro. SQLite utilise son verrou d'écriture, obtenu dès l'insertion conditionnelle du singleton. Deux processus indépendants ont été testés sur une base SQLite temporaire pour vérifier des allocations concurrentes consécutives.

## 4. Anti-doublon et immutabilité

Le verrou de commande existant sérialise deux confirmations concernant la même commande. L'émetteur retrouve le document existant, y compris une insertion non encore flushée dans une transaction imbriquée. Les contraintes uniques protègent `(order_id, type)`, le numéro et la référence de remboursement. Le moteur actuel autorise donc au maximum une facture initiale et un avoir total par commande.

Un listener Doctrine refuse modification/suppression de factures ou de lignes, ainsi que l'ajout d'une ligne à un document déjà émis. Les clés étrangères `RESTRICT` protègent les références. Cela ne constitue pas un archivage inviolable contre un administrateur disposant d'un accès SQL direct ; les droits SQL, sauvegardes et procédures d'archivage restent nécessaires.

## 5. Moment d'émission

Après toutes les vérifications existantes du paiement, `OrderManager` passe la commande à l'état payé, enregistre la première confirmation et émet la facture avant la validation de la transaction. Les webhooks répétés retrouvent le même document. Les coordonnées structurées doivent avoir été capturées sur une nouvelle commande. Une commande historique sans ce snapshot n'est pas facturée automatiquement.

La date conservée comme `paidAt` est la date de confirmation observée par le serveur, affichée explicitement « Confirmation du paiement ». La date de création d'une session Stripe n'est jamais utilisée comme date d'encaissement. La date d'émission est distincte. Aucun horodatage fiable de livraison n'existe actuellement dans le modèle de commande : `saleAt` reste donc null, même si un simple statut livré est connu. Aucune date de livraison n'est inventée.

Les horodatages comptables sont également figés en ISO 8601 avec leur décalage dans le snapshot. Leur restitution ne dépend donc pas du fuseau utilisé par Doctrine pour hydrater les colonnes SQL sans fuseau. L'affichage comptable utilise explicitement Europe/Paris ; le test vérifie la stabilité du HTML et de la date après rechargement depuis la base. La facture est accessible depuis sa commande immédiatement après émission, dans la même transaction.

## 6. Snapshots

Les nouvelles commandes capturent nom/prénom/e-mail, adresse, code postal, ville, pays et l'origine de l'adresse dans `invoiceCustomerSnapshot`. Le site n'ayant pas d'adresse de facturation séparée, l'adresse réellement saisie pour la commande est utilisée et cette limite est indiquée. Le texte de livraison figé est également conservé.

Les lignes enregistrent désormais le prix TTC régulier au moment de la création, sans changer le prix payé, afin de distinguer promotion produit et code promo. Les données historiques absentes restent nulles. L'émission ne consulte jamais `Product`, la configuration courante d'une promotion, l'adresse actuelle ou le profil actuel pour reconstruire ces montants/coordonnées.

Le vendeur est lu une seule fois à l'émission via `CompanySettings.invoiceDetails()`. La configuration centralisée `config/packages/invoicing.yaml` reprend les informations déjà fournies dans les mentions légales ; les champs société existants non vides restent prioritaires. Les données partagées du frontend ne sont pas changées. Le snapshot émis devient indépendant de toute modification ultérieure de configuration.

## 7. TVA

Conversion à la frontière des anciens champs float, puis opérations entières en centimes. Les taux sont conservés en centièmes de pourcentage. Calcul du HT par ligne après remise avec arrondi arithmétique ; TVA = TTC − HT. La livraison utilise son propre taux enregistré. La ventilation agrège les bases HT et TVA par taux, sans taux par défaut arbitraire. Avant émission, TTC et TVA sont rapprochés des totaux de la commande existante ; une incohérence provoque un rollback, pas une facture approximative.

## 8. Promotions

Le prix TTC déjà figé après promotion produit est conservé. La remise de code déjà répartie par le moteur commercial est déduite une seule fois. La remise produit est la différence figée entre prix régulier et prix commandé, multipliée par la quantité. Les règles de cumul, les pourcentages, les montants fixes et les dates commerciales ne sont jamais réévalués pendant l'émission ou le rendu PDF.

Les remises sont indiquées comme **déjà incluses** pour éviter leur double déduction. Les totaux HT par ligne sont les valeurs faisant foi ; les prix HT unitaires affichés sont arrondis.

## 9. Livraison

La facture conserve mode, montant TTC effectivement payé, HT, taux et TVA, ainsi que le coût logistique déjà connu à titre interne. Livraison offerte : TTC/HT/TVA facturés à zéro, sans altérer le devis ou les snapshots Sendcloud. Aucun changement du moteur d'expédition.

## 10. Bibliothèque PDF

Dompdf, déjà installé, est réutilisé sans changement de Composer ni ajout de dépendance applicative. A4 portrait, texte français, police DejaVu Sans embarquée, marque ELIXQUIS, noir, ivoire et filet doré discret. Un document simple tient sur une page A4. Les ressources distantes, JavaScript et PHP dans le rendu PDF sont désactivés.

Le PDF contient vendeur/client, numéros, dates distinctes, commande, nature « livraison de biens », lignes, remises incluses, livraison, ventilation TVA, HT/TVA/TTC, paiement via Stripe, montant payé et reste dû zéro. Un avoir référence explicitement sa facture et son remboursement. Aucun numéro de carte ni clé API n'est ajouté. Aucune pénalité ou indemnité B2B n'est appliquée automatiquement à un client particulier.

## 11. Stockage et régénération

Les entités immuables constituent la référence. Le modèle `document_v1.html.twig` est sélectionné par la version du snapshot ; une évolution future doit créer un nouveau modèle plutôt que changer la présentation des documents émis. Métadonnées temporelles et identifiant PDF sont déterministes ; le test compare deux PDF régénérés dans des répertoires indépendants.

L'archive privée est configurable via `invoice.archive_dir`, par défaut `var/accounting-archive`, hors du répertoire public Symfony. Elle comprend un blocage Apache `.htaccess`. Verrou de fichier et écriture temporaire puis renommage évitent les écritures concurrentes partielles. Le fichier archivé est resservi ; s'il est absent, il est régénéré depuis les données figées. Une erreur PDF n'a aucun effet sur la facture ni son numéro.

Sauvegarder conjointement la base et l'archive pendant au moins dix ans ; conserver aussi les modèles versionnés et les dépendances de rendu verrouillées. Aucun mécanisme de purge n'est ajouté. Le stockage local seul ne garantit pas une conservation de dix ans face à une perte disque ou une procédure de déploiement supprimant `var/` : monter un volume durable et exclure l'archive des nettoyages est nécessaire en production. La racine web doit rester `public/`.

## 12. Espace client

« Télécharger ma facture » apparaît dans Mes commandes et le détail seulement si une facture émise existe. Le détail donne numéro/date/montant et liens PDF, y compris les avoirs. Chaque téléchargement exige une session autorisée et contrôle le propriétaire côté serveur ; les identifiants d'URL ne constituent pas l'autorisation. Un autre client obtient 404, un visiteur est dirigé vers la connexion. Les administrateurs disposent de leurs accès protégés.

Les anciennes routes PDF sont conservées : elles servent la facture immuable lorsqu'elle existe, sinon un récapitulatif explicitement non comptable, nommé `recapitulatif-…pdf`. Le lien du parcours non payé est libellé « Récapitulatif PDF », jamais facture.

## 13. Back-office

Entrée **Factures** entre Commandes et Clients, sous VENTES. Recherche sur les données figées : numéro, nom/e-mail client ou référence de commande. Filtres de dates inclusives et type Facture/Avoir ; pagination de trente documents. Colonnes numéro/date/commande/client/TTC/type/actions. Actions Voir/Télécharger, aucune édition ni suppression.

Le détail de commande comporte **FACTURATION**, numéro/date/montant et accès au document ou PDF, avec les avoirs éventuels. Les anciens états et parcours de commande restent compatibles.

## 14. Avoirs

Un avoir copie les données de la facture initiale, ses lignes et montants, porte une nouvelle date/numérotation et une référence explicite au document initial. Le code de motif Stripe est conservé quand il existe, avec un libellé compréhensible pour les motifs connus. Les montants du document sont positifs et désignent le montant crédité ; la liste administrative affiche un signe négatif pour un avoir.

La structure des lignes et des totaux permettra un futur avoir partiel, mais l'unicité actuelle par commande/type correspond au moteur de remboursement total. Un chantier de remboursements partiels devra notamment revoir cette cardinalité et ajouter des contrôles de cumul ; aucune prise en charge partielle n'est annoncée ici.

## 15. Remboursements et annulations

Remboursement `pending`, `requires_action`, `failed` ou `canceled` : aucun avoir. `succeeded` vérifié dans le parcours existant : un avoir total si la facture existe. Les doublons de webhook ne créent pas de document supplémentaire. Les commandes jamais payées/expirées n'ont ni facture ni avoir. Une annulation après paiement conserve la facture ; l'avoir attend la confirmation du remboursement.

Si Stripe signale ultérieurement un échec bancaire après un succès déjà comptabilisé, le mécanisme existant peut réviser le statut de commande, mais l'avoir émis reste historique. Une éventuelle correction comptable nécessite une procédure distincte ; aucun document émis n'est supprimé automatiquement. Pour les anciennes commandes sans facture, un remboursement ne fabrique pas rétroactivement un document initial.

## 16. Stripe et e-mails

Aucune modification de StripePayment, StripeRefund ou PaymentController, ni des appels/signatures/clés Stripe. Deux appels de facturation sont ajoutés dans `OrderManager` après les validations existantes : émission lors de confirmation, avoir lors de remboursement total réussi. Les tests HTTP Stripe restent simulés.

Les futurs e-mails peuvent utiliser l'entité (numéro), la route protégée `app_invoice_document` et `InvoicePdf.generate()` (octets). Les e-mails actuels sont inchangés. `Mail.sendEmail()` ne sérialise actuellement pas les pièces jointes Mime : l'ajout d'une facture au message demandera donc une extension explicite de ce point au prochain chantier, pas seulement un appel à `attach()`.

## 17. Facturation électronique future

Les données restent structurées hors PDF : vendeur/client, nature des biens, référence de commande, dates, lignes, bases/taux/TVA, livraison, montants et remises. Le client est actuellement de type particulier ; le snapshot pourra accueillir des données professionnelles lorsqu'elles seront collectées. Aucun statut de transmission inutile, plateforme agréée, envoi B2C ou connecteur d'e-reporting n'est ajouté. Ce PDF n'implémente pas la réforme française de facturation électronique.

## 18. Migration

`Version20261029120000` ajoute `invoice`, `invoice_line`, `invoice_sequence`, les contraintes/index et le singleton, ainsi que `order.invoice_customer_snapshot`, `order.payment_confirmed_at` et `order_detail.regular_unit_price_cents`, tous nullables pour les anciennes commandes. Aucun remplissage historique, modification de montant ou création de document lors de la migration. Retour arrière destructif refusé pour conserver les preuves comptables. Migration appliquée localement ; compteur toujours à zéro et table invoice vide après les contrôles réels.

## 19. CLI

Aucune commande de régularisation historique n'est ajoutée ni exécutée. Les données anciennes incomplètes nécessitent une stratégie explicite et une analyse préalable avant toute émission. Les commandes de déploiement sont les migrations habituelles et la reconstruction du cache ; les caches locaux dev/test/prod ont été reconstruits pendant les contrôles.

## 20. Fichiers

- Configuration : `config/packages/invoicing.yaml`, `phpunit.xml.dist` (limite mémoire des tests à 1 Go ; production inchangée).
- Entités : nouveaux `Invoice.php`, `InvoiceLine.php`, `InvoiceSequence.php` ; ajouts à `Order.php` et `OrderDetail.php`.
- Services : nouveaux `InvoiceIssuer.php`, `InvoiceNumbering.php`, `InvoiceAmounts.php`, `InvoicePdf.php` ; adaptations de `CompanySettings.php` et `OrderManager.php`.
- Protection : `src/EventSubscriber/InvoiceImmutability.php`.
- Contrôleurs : nouveau `Admin/InvoicesController.php`, adaptation de `InvoiceController.php`, entrée du menu dans `Admin/DashboardController.php`.
- Templates : `invoice/document_v1.html.twig`, `admin/invoices/{index,show}.html.twig`, `admin/_billing.html.twig`, `account/order/_billing.html.twig` ; adaptations de `account/index.html.twig`, `account/order/index.html.twig`, `admin/order.html.twig`, `invoice/index.html.twig` et `order/_review.html.twig`.
- Migration : `migrations/Version20261029120000.php`.
- Tests : `tests/InvoicingTest.php`, `InvoiceNumberingTest.php`, `Support/InvoiceNumberingWorker.php` ; adaptations de `OrderEmailsTest.php`, `ExpiredPaymentsTest.php`, `HomeCmsRevendeursTest.php`, `StorefrontTest.php`.
- Vérification navigateur : `tools/check-invoicing.cjs`. Documentation : ce fichier.

Les autres fichiers applicatifs, `.env.local`, Composer et les intégrations interdites sont inchangés. Les fichiers de démonstration et outils de lecture PDF sont confinés à `var/`, ignoré par Git.

## 21. Tests ajoutés et adaptés

26 tests ciblés : paiements en attente/annulations/historiques, confirmations et webhooks signés répétés, facture unique, avoir total et remboursement signé répété, absence d'avoir avant succès, stabilité des données face aux modifications vendeur/client/produit/promotion, promotions produit/code fixe/pourcentage/non-cumul, livraison offerte, ventilation multi-taux, immutabilité des documents et des lignes, contrainte unique en base, PDF réel généré sur une page et régénération indépendante identique, échec PDF sans nouveau numéro, contrôle propriétaire/autre client/visiteur/admin, recherche/filtres et lecture seule.

Les tests de compteur couvrent rollback, séries distinctes, changement d'année, régression d'horloge et allocations dans deux processus concurrents SQLite. Cette dernière vérification porte sur l'allocateur transactionnel réel ; ce n'est pas un test de charge HTTP sur un serveur MariaDB de production. Le verrou de commande et les contraintes SQL complètent la protection des webhooks.

Les tests existants ont été ajustés pour l'injection du nouvel émetteur, le menu Factures et la normalisation de l'introspection du singleton SQLite. Aucun scénario commercial, Stripe, stock ou livraison existant n'a été retiré.

## 22. Doctrine et autres vérifications

`php bin/console doctrine:schema:validate` : **Mapping [OK], Database [OK]**. Conteneur : injection valide. Templates Twig : valides. `git diff --check` : aucune erreur. Cache prod reconstruit avec succès.

## 23. PHPUnit et navigateur

`php bin/phpunit` : **496 tests, 5 130 assertions, aucun échec**, 59,467 s, 776 Mo. La configuration des tests autorise désormais 1 Go pour que cette commande complète fonctionne avec le PHP local limité par défaut à 512 Mo. Les dépréciations préexistantes Symfony/Doctrine restent signalées. Résultats : `var/invoicing-full.txt` et `var/invoicing-full.xml`.

Les 26 tests ciblés comptabilisent **168 assertions**. Les écrans de test sont exportés sous `var/invoice-review/html/`, sans création de facture factice dans la base locale métier. Contrôles navigateur sur la liste et le détail administrateur et les deux écrans client à 1440, 768, 390 et 320 px : **17 contrôles réussis**, aucun débordement global ni erreur JavaScript. Le PDF réel a été extrait et rasterisé pour vérifier son numéro, son client, ses lignes, ses totaux et sa pagination ; une facture simple tient sur une page A4. Captures/résultats dans `var/invoice-review/browser/`.

## 24. Avant production

Confirmer les coordonnées vendeur centralisées et leur priorité par rapport aux champs société existants. Faire valider la présentation comptable et les dates par la personne responsable de la comptabilité. Configurer une archive privée durable, sauvegardée conjointement à la base et conservée au moins dix ans ; ne pas supprimer cette archive lors des nettoyages de cache/déploiements. Conserver les modèles de rendu versionnés et le verrouillage des dépendances. Déployer la migration et reconstruire les caches avant de rouvrir le trafic de paiement ; vérifier le parcours de confirmation sur l'environnement cible. Prévoir séparément la régularisation des anciennes commandes, les corrections comptables après émission, les futurs remboursements partiels, les pièces jointes des e-mails et la connexion à une plateforme/e-reporting lorsqu'elles seront demandées.

Références officielles consultées pour la numérotation et les informations de facture : [Bercy — mentions obligatoires](https://www.economie.gouv.fr/entreprises/gerer-son-entreprise-au-quotidien/gerer-sa-comptabilite-et-ses-demarches/mentions-obligatoires-dune-facture-tout-savoir), [Service Public — facturation](https://entreprendre.service-public.gouv.fr/vosdroits/F23208). Les mentions B2B ne sont pas appliquées automatiquement au flux B2C.
