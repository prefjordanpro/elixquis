# Pages légales — compte rendu vérifié le 29 octobre 2026

## Mentions légales et confidentialité — dernière étape du 29 octobre 2026

Les mentions légales et la confidentialité sont publiées en version 1 depuis le 29/10/2026 à 08:54:38, dans leurs emplacements existants, via `Version20261029110000`. Les CGV ne changent pas. Les deux nouvelles routes et les liens du footer sont vérifiés en configurations dev et prod locales. Suite complète : **470 tests, 4 958 assertions, aucun échec** ; Doctrine **Mapping [OK], Database [OK]**. Les modifications futures restent des brouillons jusqu'à publication explicite.

La politique cookies reste non publiée : la pièce jointe s'arrête à « ## 9. Modifier son ». Les consignes reçues s'arrêtent également au milieu de la section prospection. La suite a été demandée ; elle n'a pas été inventée. L'audit technique a identifié OpenStreetMap pour les revendeurs, Géoplateforme/IGN pour le géocodage et Mapbox dans le sélecteur Sendcloud. La qualification des stockages techniques Mapbox reste à finaliser avant toute affirmation d'exemption globale ou mise en place éventuelle d'un consentement. Aucun faux dispositif de gestion des cookies n'est ajouté.

L'[audit détaillé](AUDIT_CONFIDENTIALITE_COOKIES_20261029.md) recense les données, scripts, stockages, sources, limites de vérification, adaptations des textes fournis et fichiers ajoutés. Aucun déploiement distant, commit ou push.

## Publication demandée le 29 octobre 2026 — état actuel

Les CGV existantes sont maintenant **publiées, version 1, le 29/10/2026 à 08:25:14 (heure de Paris)** dans la base locale. Il existe toujours quatre documents, dont un seul possède une version publiée ; une seule version CGV a été créée. Le contenu source de cet instantané est identique au HTML préparé lors du chantier initial. Le brouillon n'a pas été remplacé, et une nouvelle modification reste privée jusqu'à sa publication explicite.

La publication et l'affichage distinguent désormais un contenu publiable d'un document juridiquement complet. Seuls les deux marqueurs connus concernant médiateur et garanties, ainsi que les champs associés nom/adresse/site du médiateur, ne bloquent plus les CGV. Les contenus vides, titres incomplets et autres marqueurs restent refusés. Les règles des trois autres documents sont conservées. Aucun traitement dépendant du mode dev n'a été ajouté.

L'avertissement administratif est : « Avant la mise en production définitive, complétez le médiateur de la consommation et l’encadré réglementaire des garanties légales. » Les écrans d'édition et la liste affichent les informations de publication ; la liste indique aussi le numéro de version. Les réserves restent conservées dans le brouillon et l'instantané historique ; leurs paragraphes non renseignés sont omis uniquement du rendu public. Aucun organisme ni encadré juridique n'a été inventé.

`/conditions-generales-de-vente` et le lien du footer fonctionnent avec les CGV initiales publiées ; la lecture des versions historiques et le consentement au checkout utilisent la même règle. Les autres documents ne sont pas publiés automatiquement. Leurs liens restent absents du footer.

La migration additive de données `Version20261029100000` lit le brouillon CGV existant, crée son instantané numéroté et daté, puis affecte la référence de publication. Elle ne recopie pas un nouveau document à partir d'un fichier, ne remplace aucune version déjà publiée et ne crée pas de doublon si elle est réexécutée. Elle est appliquée localement. Pour le serveur de production distant, livrer les changements puis exécuter `php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction` et reconstruire le cache suivant la procédure habituelle. Aucun déploiement distant n'a été effectué ici.

Vérifications réexécutées : **25 tests juridiques, 234 assertions** ; suite complète **465 tests, 4 874 assertions, aucun échec** (49,412 s), résultats dans `var/legal-published-full.txt` et `.xml`. Doctrine : **Mapping [OK], Database [OK]** ; huit fichiers Twig juridiques valides. Une requête réelle au noyau de l'application, sur la base locale, donne HTTP 200, un lien CGV dans le footer et la version 1 pour chacune des configurations **dev** et **prod** (`debug=false`). Les caches locaux obsolètes ont été reconstruits ; le cache prod a été réchauffé avec succès. Contrôle navigateur complémentaire à 390 px : **12 contrôles réussis**, onze écrans dont les CGV initiales publiées et leur écran administrateur, aucun débordement ni erreur JavaScript. Les réserves sont absentes du texte public et le consentement reste visible sans JavaScript.

Fichiers de cette publication : `src/Service/LegalMarkup.php`, `src/Service/LegalContent.php`, `src/Twig/LegalExtension.php`, `src/Controller/LegalController.php`, `src/EventSubscriber/CheckoutTermsAcceptance.php`, `templates/admin/legal/index.html.twig`, `templates/admin/legal/edit.html.twig`, `migrations/Version20261029100000.php`, `tests/LegalPagesTest.php`, `tools/check-legal-pages.cjs` et ce compte rendu. Stripe, Sendcloud, Mailjet, facturation et `.env.local` restent inchangés. Aucun commit ni push.

Les sections ci-dessous décrivent les étapes précédentes ; leurs indications de CGV non publiées ou bloquées constituent l'état antérieur, remplacé par cette publication.

## Reprise du 29 octobre 2026

L'intégration décrite ci-dessous était déjà présente et suivie par Git au début de cette reprise, sur `feature/cms-home-revendeurs`, avec un espace de travail propre. Elle a été réutilisée sans créer de système parallèle. Aucun changement de branche, commit ou push ; aucune modification de `.env.local`, Stripe, Sendcloud, Mailjet, facturation, promotions, stock ou remboursements.

Deux corrections ciblées complètent ce travail : les documents contenant uniquement des espaces Unicode ou caractères invisibles ne sont plus considérés comme complets ; les marqueurs dans le titre déclenchent désormais l'alerte et désactivent le bouton de publication, et le titre est revérifié après verrouillage de la page. Deux tests de régression couvrent ces cas. La zone de lecture publique utilise désormais explicitement le fond ivoire de la palette existante ; le contrôle navigateur vérifie sa couleur calculée.

Fichiers modifiés pendant cette reprise : `src/Service/LegalContent.php`, `src/Service/LegalMarkup.php`, `templates/admin/legal/edit.html.twig`, `templates/admin/legal/index.html.twig`, `public/assets/css/legal.css`, `tests/LegalPagesTest.php`, `tools/check-legal-pages.cjs` et ce compte rendu. Aucune nouvelle migration : la migration additive `Version20261028100000` est déjà appliquée. Aucun document n'a été publié dans la base locale : les quatre références de publication sont nulles. Le contenu source initial a également été comparé directement à la pièce jointe de cette demande : identité du texte hors espaces et retours à la ligne.

Contrôles réexécutés : **23 tests juridiques, 176 assertions** ; suite complète **463 tests, 4 816 assertions, aucun échec**, durée 47,131 s ; `doctrine:schema:validate` donne **Mapping [OK], Database [OK]** ; huit fichiers Twig juridiques valides. Les dépréciations des dépendances restent signalées. Les résultats de la suite complète sont dans `var/legal-full.txt` et `var/legal-full.xml`. Le cache de développement obsolète a été régénéré avec `php bin/console cache:clear` ; la lecture de la base fonctionne aussi avec `--no-debug`.

Catalogue local revérifié : « Aux bonbons deux », id 10, possède zéro produit, contre des produits actifs dans les trois autres catégories. Aucune catégorie supprimée ; son caractère de test ne peut pas être établi à partir de ces données.

Vérification navigateur réexécutée sur huit écrans à 1600, 1280, 1024, 768, 430, 390 et 320 px : **57 contrôles réussis**, aucun débordement horizontal ni erreur JavaScript, colonnes du footer conformes, et consentement visible sans JavaScript. Résultat conservé dans `var/legal-review/browser/results-seven-widths.json`. Après la correction du fond ivoire, **9 contrôles supplémentaires à 390 px** passent, dont la couleur calculée des pages publiques et le consentement sans JavaScript ; résultat dans `var/legal-review/browser/results.json`. Les pages sont des exports des tests SQLite ; aucune version de démonstration n'est publiée dans la base locale. Playwright est installé uniquement dans le répertoire ignoré `var/legal-review/tooling`, sans dépendance ajoutée au projet.

Les sections suivantes détaillent l'architecture existante et ses limites. Les résultats datés du 28 octobre qui y figurent constituent les vérifications antérieures, distinctes de celles de cette reprise.

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

Organisation en quatre colonnes permanentes : marque, boutique, compte et Informations. La colonne Informations reste présente même sans document publié, avec uniquement son titre et aucun lien ni message de complétion. Quatre colonnes sur ordinateur, une grille 2 × 2 sur tablette, une colonne sur mobile. Le message public « Informations légales à compléter avant la mise en production » a été supprimé.

À la suite de la demande spécifique sur le footer, le masquage de la quatrième colonne et le passage à trois colonnes ont été retirés. La marque n'occupe plus toute la rangée sur tablette. Le filtrage et l'ordre des liens publiés sont conservés. Les 23 tests juridiques passent avec 185 assertions, dont la présence des quatre blocs sans publication et l'ordre des cinq liens avec vérification de leurs pages cibles.

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
