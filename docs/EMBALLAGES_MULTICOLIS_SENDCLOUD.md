# Emballages, colisage et multi-colis Sendcloud

Branche : `feature/packaging-multicolis`. Aucun changement de `main`, aucun merge. Documentation officielle consultée le 5 octobre 2026.

## 1. Architecture

`Colisage` choisit les emballages et répartit les unités avant le devis. `SendcloudDelivery` transmet tous les colis à l'API v3, retient les offres compatibles et garde le prix global retourné. Le checkout ne demande aucun choix d'emballage au client.

Le profil colis historique reste disponible uniquement tant que la base ne contient aucun emballage. Dès qu'un emballage existe, seuls les emballages actifs et complets sont utilisables : les brouillons ou formats désactivés ne sont jamais remplacés silencieusement par le profil `.env`. Le fallback de livraison classique reste accessible volontairement.

## 2. Entités

`Emballage` : nom, capacité libre en bouteilles, poids vide avec protections (grammes), dimensions extérieures (cm, décimales acceptées), poids maximal facultatif du colis complet, activation et priorité. Poids et dimensions peuvent être absents sur un brouillon inactif ; activation refusée tant qu'il est incomplet. Poids et dimensions utilisés doivent être strictement positifs. Le poids maximal éventuel doit dépasser la tare.

`ColisCommande` : lien commande, lien emballage protégé contre suppression, position, nom/capacité/dimensions figés, contenu par référence et quantité, nombre d'unités, poids produits/tare/total. Champs préparés pour identifiants Sendcloud, identifiant de colis, suivi, statut et référence de document d'étiquette. Les données d'emballage historiques n'ont pas de setters de modification. La commande conserve aussi son snapshot JSON complet.

## 3. Algorithme

Recherche avec mémorisation des quantités restantes, uniquement dans les formats réellement configurés. Toutes les répartitions utiles des références sont examinées, y compris les mélanges d'unités lourdes et légères imposés par une limite de poids. Les grammes entiers évitent les erreurs d'arrondi dans le colisage.

Vos exemples sont prioritaires : rechercher d'abord une combinaison de cartons remplis. Parmi ces solutions, minimiser le nombre de colis, puis départager de manière déterministe par capacité, priorité, identifiant et contenu. Ceci donne notamment `4 → 3+1`, `5 → 3+2`, `7 → 6+1`, `12 → 6+6`. Un carton 6 partiellement rempli aurait moins de colis pour 4 ou 5 bouteilles, mais contredirait ces exemples ; il n'est donc pas retenu lorsqu'une combinaison exacte existe.

Sans solution de cartons remplis, les places libres sont autorisées : minimisation du nombre de colis, puis du nombre de places inutilisées. Exemple : un seul format 4 configuré permet 7 unités dans deux cartons. Aucune liste de capacités n'est codée dans le service. Une limite de travail protège les recherches combinatoires exceptionnellement complexes ; dans ce cas le panier est orienté vers une vérification par la boutique, sans résultat approximatif silencieux.

## 4. Calcul du poids

Pour chaque colis : somme des `shipping_weight_grams × quantité` des références affectées + poids vide de l'emballage avec protections.

La valeur 1200 g n'est jamais une constante du service. Le mélange 1200 g + 1350 g produit 2550 g de produits, puis ajoute la tare réelle. Une absence de poids produit interdit le devis. L'échange API convertit les grammes en kilogrammes avec trois décimales et transmet les dimensions de chaque carton.

## 5. Multi-colis Sendcloud

Sources officielles :

- [Devis pour l'expédition entière — POST /api/v3/shipping-options](https://sendcloud.dev/api/v3/shipping-options/return-a-list-of-available-shipping-options)
- [Multicollo et compatibilité](https://sendcloud.dev/docs/shipments/multicollo)
- [Annonce asynchrone — POST /api/v3/shipments](https://sendcloud.dev/api/v3/shipments/create-and-announce-a-shipment-asynchronously)
- [Restrictions et facturation selon transporteur](https://support.sendcloud.com/hc/fr/articles/360038716852-Comment-cr%C3%A9er-des-envois-multi-colis)

Le devis envoie un tableau `parcels` avec poids/dimensions individuels et `calculate_quotes: true`. Si plusieurs colis sont nécessaires, le filtre `functionalities.multicollo: true` est ajouté ; une réponse ne déclarant pas cette compatibilité est exclue. Le total API de l'expédition est conservé, puis la politique TVA existante est appliquée une seule fois. Aucune multiplication automatique du prix d'un colis, aucune grille ni marge nouvelle.

Les règles commerciales existantes restent en place ; aucun transporteur de substitution n'est ajouté. Une méthode ou un contrat peut ne pas supporter le multi-colis, ou les colis de caractéristiques différentes. Le guide distingue informations de base identiques et informations détaillées ; Chronopost est mentionné dans les informations de base, pas dans la liste détaillée. La présence du multi-colis pour Shop2Shop, particulièrement avec `6+1`, n'est donc pas garantie. L'API reçoit les caractéristiques exactes et décide des offres disponibles pour le compte. Si aucune offre compatible n'existe, aucun tarif n'est inventé et le fallback volontaire reste disponible.

Le parcours point relais utilise toujours le picker officiel et la validation du point côté serveur. Le devis avec point relais transmet le même tableau de colis et le point validé.

Pour la future annonce, `SendcloudService` choisit l'endpoint asynchrone officiel lorsqu'il y a plusieurs colis. Le payload conserve tous les poids/dimensions figés et ajoute les `parcel_items` propres à chaque colis. `SendcloudShipping` vérifie la commande, la méthode, le nombre de colis, les identifiants et les poids. Les données de suivi sont conservées par colis, y compris lorsque l'annonce est encore `ANNOUNCING`. Une demande existante n'est pas recréée. Les événements du deuxième colis peuvent retrouver la même expédition. Une annulation du premier seul ne suffit pas à déclarer tout l'envoi annulé.

**`sendcloud.allow_label_creation: false` reste inchangé. Aucun appel réel d'annonce, aucune étiquette ni opération facturable n'a été exécuté.** Les scénarios d'annonce sont uniquement testés avec un client HTTP simulé.

## 6. Migration et base locale

`Version20261005140000` crée seulement `emballage` et `colis_commande`, leurs index et leurs clés étrangères. Migration additive appliquée à la base locale après vérification des migrations et dry-run. Aucune table ni donnée préexistante supprimée. Le retour arrière est explicitement irréversible pour éviter une suppression des historiques sans autorisation. Schéma local et mapping Doctrine validés et synchronisés.

La commande idempotente `php bin/console app:emballages:references` a initialement créé quatre brouillons locaux, inactifs et sans poids. Ils ont depuis été activés avec les valeurs provisoires explicitement autorisées pour les essais : voir [Emballages de développement](EMBALLAGES_DEVELOPPEMENT.md). État initial :

| Nom | Capacité | Dimensions indicatives en cm | Poids vide | Actif |
|---|---:|---|---|---|
| Carton 1 bouteille | 1 | 12 × 12 × 38,5 | Non renseigné | Non |
| Carton 2 bouteilles | 2 | 22 × 11,5 × 39,5 | Non renseigné | Non |
| Carton 3 bouteilles | 3 | 31,5 × 12,5 × 39,5 | Non renseigné | Non |
| Carton 6 bouteilles | 6 | 32 × 22 × 41 | Non renseigné | Non |

Relancer la commande ne remplace aucune mesure ni configuration existante. Les dimensions restent temporaires, modifiables et signalées comme telles dans l'aide du formulaire. Aucun poids de fixture n'a été inséré dans la base locale.

## 7. Administration

Menu **Administration → Emballages**, liste `/admin/emballage` : nom, capacité, dimensions, poids et état. Création/modification permettent aussi poids maximal et priorité. L'activation passe par le formulaire et sa validation ; pas de bascule AJAX contournant la vérification. Suppression en lot désactivée. Un emballage lié à un colis historique ne peut pas être supprimé : le désactiver reste possible. Un emballage inutilisé conserve l'action de suppression.

Fiche commande : section **Colis**, poids en kg, dimensions, produits, quantités, tare et champs de suivi/étiquette. Les anciennes commandes sans colis détaillés restent lisibles sans recalcul rétroactif.

## 8. Tests et contrôles

**166 tests, 2105 assertions : réussis**, dont 46 nouveaux cas. APIs Sendcloud/Stripe simulées, base SQLite en mémoire. Dépréciations Doctrine préexistantes toujours présentes.

- Répartitions de 1 à 12 bouteilles, incluant 4/5/7/8/9/10/11 ; poids réel et déterminisme.
- Références à 1200 et 1350 g, mélanges lourd/léger sous limite de poids, formats arbitraires et solution non gloutonne.
- Emballages inactifs/incomplets, absence de poids produit, dépassement maximal, absence de format compatible, activation refusée sans mesure.
- Panier → CTA Sendcloud → adresse → livraison → récapitulatif pour **2, 3, 6, 7 et 12 bouteilles**, en relais/standard/express.
- Prix global non multiplié, méthodes sans multicollo exclues, changement d'emballage invalidant le devis.
- Snapshot durable après une modification réellement persistée de l'emballage, suppression historique bloquée, absence de création réelle.
- Future annonce asynchrone simulée, suivi par colis, annonce en attente sans doublon, webhook du deuxième colis, annulation partielle ne libérant pas prématurément l'envoi.
- Tests existants du panier, du stock, de Stripe, des annulations et des remboursements réussis.
- 32 templates Twig valides, conteneur valide, mapping/base synchronisés, `git diff --check` vérifié.
- Contrôle Edge des HTML réellement rendus par Symfony : liste/formulaire Emballages, fiche commande et checkout à **1440, 768 et 390 px**, sans débordement. Captures et rapport dans `docs/packaging-review`; outil `tools/check-packaging-ui.cjs`.

Le contrôle navigateur utilise les pages rendues sur les données de test isolées. Aucun devis réel n'a été sollicité avec des poids inconnus ; la validation manuelle des tarifs sur le compte Sendcloud reste à faire après mesure et activation des cartons.

## 9. Variables qui pourront devenir obsolètes

Après validation complète du remplacement du profil historique, retirer ses références dans `ShippingConfiguration`/configuration avant de supprimer :

```text
SENDCLOUD_PACKAGING_WEIGHT_G
SENDCLOUD_PARCEL_LENGTH_CM
SENDCLOUD_PARCEL_WIDTH_CM
SENDCLOUD_PARCEL_HEIGHT_CM
SENDCLOUD_PARCEL_MAX_BOTTLES
```

Aucune variable supprimée dans ce chantier. `.env.local` inchangé.

## 10. Mesures et test manuel restant

Pour chacun des vrais cartons : mesurer le **poids vide avec toutes les protections en grammes**, les **trois dimensions extérieures réelles en centimètres**, et renseigner le **poids maximal total admissible** si le fabricant en indique un. Vérifier la capacité physique réelle. La priorité est un choix d'exploitation, pas une mesure.

Compléter ces champs dans Administration → Emballages, puis activer les formats nécessaires. Vérifier les poids produits déjà enregistrés : 1200 g pour les produits tests concernés, ou leur vraie valeur s'ils diffèrent. Tester ensuite 2 bouteilles, 3, 6, puis 7 via panier → Sendcloud → adresse → livraison. Pour 7, le plan attendu est `6+1`, mais les offres dépendent de la compatibilité du transporteur avec les deux colis exacts. Aucun choix d'emballage n'est demandé au client. Ne pas activer les étiquettes pour ces essais de devis.

Homepage, fiche produit, identité visuelle, comptes, Stripe, annulations et remboursements n'ont pas été refaits.
