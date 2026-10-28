# Pages légales — compte rendu du 28 octobre 2026

Travail repris dans le workspace existant sur `feature/cms-home-revendeurs`, sans recréer les entités ni les migrations déjà préparées. Aucun commit ni push. Les changements antérieurs du chantier back-office, CMS et revendeurs ont été conservés.

## 1. Architecture

`LegalPage` représente chacun des quatre emplacements : CGV, mentions légales, confidentialité et cookies. Il conserve le brouillon et une référence vers sa version publiée. `LegalVersion` conserve un instantané publié. `LegalContent` pilote sauvegarde et publication ; `LegalMarkup` filtre le HTML. Les contrôleurs public et administrateur sont distincts.

## 2. Menu du back-office

Une entrée **Pages légales** figure dans la gestion du site, ainsi qu'un accès depuis « Modifier le site ». Les quatre documents affichent leur état et les actions Modifier, Aperçu et Historique. Les accès sont protégés côté serveur par `ROLE_ADMIN`.

## 3. Brouillon et publication

Enregistrer ne publie pas. L'aperçu des modifications non enregistrées ne sauvegarde rien. La publication requiert un POST, un jeton CSRF, une confirmation explicite et une révision toujours à jour. Une modification concurrente impose de recharger le document. Les documents vides ou comportant les marqueurs de complétion connus restent bloqués. La date de publication est substituée dans l'instantané publié.

## 4. Historique

Chaque publication crée une nouvelle ligne numérotée ; le pointeur de la page désigne l'unique version courante. Les anciennes versions restent consultables, notamment depuis leurs commandes. Aucune action de suppression, modification ou restauration directe d'un instantané n'est proposée. Un listener Doctrine interdit leur modification ou suppression via l'ORM ; les clés étrangères protègent les références. Cela ne constitue pas un dispositif d'archivage externe inviolable face à un administrateur SQL.

## 5. Structure des versions de CGV

Une version contient son document, son numéro unique dans ce document, son titre, le HTML publié nettoyé, le contenu source du brouillon, les dates de création/modification du document et de publication, ainsi que l'auteur et son libellé conservé. Les statuts Publiée/Archivée sont déduits du pointeur courant. L'utilisateur auteur peut être supprimé sans perdre l'instantané.

## 6. Relation avec les commandes

`Order.acceptedTermsVersion` référence l'instantané accepté ; `termsAcceptedAt` conserve l'horodatage. Ces deux colonnes sont nullables : aucune acceptation rétroactive n'est inventée pour les anciennes commandes. La vue administrateur affiche la version et la date lorsqu'elles existent. Publier de nouvelles CGV ne change pas les commandes existantes.

## 7. Checkout

Les deux parcours de commande affichent une case obligatoire, jamais précochée, et un lien vers la version exacte proposée. Le serveur exige le consentement et vérifie que cette version est toujours courante lors de la création de commande. Le contrôle intervient dans la transaction existante ; un refus annule la création et les écritures de stock. Les tests couvrent le consentement absent et une publication intervenue pendant le parcours. Le formulaire fonctionne sans JavaScript.

Sans CGV complètes publiées, la validation d'une nouvelle commande web est indisponible, avec un message public neutre. C'est notamment l'état attendu après chargement des CGV initiales incomplètes. Les créations internes hors checkout et les commandes historiques restent compatibles avec des références nulles.

## 8. Footer

Organisation en colonnes marque, boutique, compte et Informations lorsque des documents sont publiés. Quatre colonnes sur ordinateur, deux sur tablette, une sur mobile ; trois colonnes sur ordinateur tant qu'aucun lien légal n'est publiable. Le message public « Informations légales à compléter avant la mise en production » a été supprimé.

La catégorie « Aux bonbons deux » (id 10 dans la base locale inspectée) ne contient aucun produit. Elle a été conservée : son statut réel n'est pas déduit de son nom. Le footer ne propose que les catégories contenant au moins un produit actif. Aucun produit ni catégorie n'a été supprimé.

## 9. Liens légaux

Le footer ne propose que les documents possédant une version complète publiée. Aucun lien vers un brouillon ou une page vide. Les routes des documents non publiés répondent 404. Quand les CGV sont publiées, le lien d'accès à la rétractation devient disponible. Le lien « Gestion des cookies » conduit au document publié ; aucun gestionnaire de consentement aux cookies n'a été ajouté.

## 10. Page publique CGV

`/conditions-generales-de-vente` présente la version courante, son numéro et sa date, avec une mise en page de lecture responsive. `/conditions-generales-de-vente/version/{id}` conserve l'accès aux instantanés historiques ; une ancienne version indique son statut et permet de rejoindre la courante. Les aperçus de brouillon restent administratifs.

## 11. Rétractation

`/retractation` prépare l'accès aux modalités et au formulaire type contenus dans les CGV publiées, avec une ancre dédiée. Aucun dépôt de demande en ligne, accusé de réception durable ou remboursement automatique n'a été créé. La procédure existante d'annulation avant expédition n'a pas été assimilée à la rétractation. Un futur parcours de dépôt nécessitera un chantier distinct incluant preuve et accusé de réception.

## 12. Éléments laissés à compléter

Le texte initial fourni est conservé dans `migrations/data/cgv-initial-20261028.txt`, avec sa mise en forme HTML dans le fichier homonyme `.html`. Un test compare tout le texte normalisé, titre compris. Le médiateur de la consommation et l'encadré réglementaire officiel des garanties restent à renseigner, ainsi que les marqueurs associés de nom/adresse/site. La publication initiale est donc bloquée. Mentions légales, confidentialité et cookies restent vides et signalés « À compléter » uniquement dans le back-office. Aucun contenu juridique n'a été inventé.

## 13. Migration

Une seule migration juridique : `Version20261028100000`. Elle ajoute les deux tables, index et contraintes, les deux colonnes nullables de commande et quatre brouillons. Elle ne crée aucune version publiée ni acceptation historique. Elle est appliquée à la base locale et synchronisée avec le mapping. Les migrations antérieures ont été conservées. Le retour arrière destructif est explicitement refusé afin de ne pas supprimer les preuves contractuelles.

Pour le déploiement, livrer également les fichiers `migrations/data/`, sauvegarder la base, puis appliquer les migrations dans l'environnement cible suivant la procédure habituelle. Cette migration de brouillons ne charge pas les fixtures de revendeurs de démonstration.

## 14. Fichiers concernés par le chantier juridique

- Entités : `src/Entity/LegalPage.php`, `LegalVersion.php`, ajouts à `Order.php`.
- Services : `src/Service/LegalContent.php`, `LegalMarkup.php`.
- Protection : `src/EventSubscriber/LegalVersionImmutability.php`, `CheckoutTermsAcceptance.php`.
- Contrôleurs : `src/Controller/Admin/LegalPagesController.php`, `src/Controller/LegalController.php`, menu dans `Admin/DashboardController.php`.
- Twig : `src/Twig/LegalExtension.php`, `templates/admin/legal/*`, `templates/legal/*`, `templates/components/_terms_acceptance.html.twig` ; adaptations de `templates/admin/site.html.twig`, `admin/order.html.twig`, `base.html.twig`, `components/_footer.html.twig`, `order/index.html.twig`, `order/sendcloud.html.twig`.
- Présentation : `public/assets/css/legal.css`.
- Dépendances : `composer.json` et `composer.lock`, ajout de Symfony HtmlSanitizer et de ses dépendances, sans mise à jour des packages existants.
- Migration et sources : `migrations/Version20261028100000.php`, `migrations/data/cgv-initial-20261028.{txt,html}`.
- Vérification : `tests/LegalPagesTest.php`, `tests/Support/LegalTerms.php`, adaptations de `tests/StorefrontTest.php` et `tests/SendcloudTest.php`, `tools/check-legal-pages.cjs`.
- Documentation : ce fichier.

Le `git status` contient aussi les nombreux fichiers du chantier précédent. Leur présence ne correspond pas à une réécriture de ces fonctionnalités pendant cette reprise. Les services de paiement, livraison, Mailjet, facture, promotions et calculs existants n'ont pas été modifiés pour intégrer les pages légales.

## 15. Tests et sécurité

21 tests juridiques et deux nouveaux tests de refus de consentement côté livraison ; les scénarios existants de checkout fournissent désormais un consentement explicite. Couverture des droits, CSRF, confirmation, versions, concurrence, fidélité du texte initial, filtrage HTML/Twig, limite de taille, invisibilité publique des brouillons, footer, commandes historiques et intégrité du stock sur refus. Les textes complets utilisés pour publier en test sont fictifs et confinés aux bases SQLite de test.

Le HTML est filtré par Symfony HtmlSanitizer avec une liste restreinte de balises et attributs. Scripts, styles, images, iframes, formulaires, attributs d'événements et URL dangereuses ne sont pas autorisés. Les fragments Twig sont retirés. Le contenu brut public est exclusivement rendu après nettoyage. La taille est vérifiée en octets avant traitement.

Le contrôle navigateur utilise des pages exportées par les tests, sans publication factice dans la base locale, et intercepte toutes les requêtes externes. Résultat : **49 contrôles réussis**, huit écrans à **1600, 1280, 1024, 768, 430 et 390 px**, puis un contrôle sans JavaScript. Colonnes du footer conformes, aucun débordement horizontal, consentement non précoché et aucune erreur JavaScript. Captures et résultats sont dans `var/legal-review/` (ignoré par Git).

## 16. Validation Doctrine

`php bin/console doctrine:schema:validate` : **Mapping [OK] ; Database [OK]** après application locale de la migration. Le lint du conteneur et celui des 59 templates Twig passent également.

## 17. Suite complète

`php -d memory_limit=1G vendor/phpunit/phpunit/phpunit` : **461 tests, 4 803 assertions, aucune erreur ni échec**. Durée mesurée : 50,888 s. Les dépréciations Symfony/Doctrine existantes restent signalées ; elles ne constituent pas des échecs. Résultat conservé dans `var/legal-full.txt` et JUnit dans `var/legal-full.xml`.

## 18. Avant mise en production

Faire compléter et relire le médiateur, l'encadré officiel des garanties et les autres documents par la personne compétente, puis publier explicitement les versions validées. Le filtre de marqueurs empêche la publication des champs connus ; il ne valide pas juridiquement le texte. Vérifier le checkout après publication sur l'environnement cible et la présence des liens publics. Prévoir séparément, si requis, la remise des CGV sur support durable, le dépôt de rétractation avec accusé de réception et la gestion effective du consentement aux cookies ; les intégrations Mailjet et le remboursement existants n'ont pas été changés. Conserver et sauvegarder les versions contractuelles et leurs références de commande.
