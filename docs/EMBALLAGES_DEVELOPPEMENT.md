# Emballages provisoires de développement

Configuration appliquée le 5 octobre 2026 sur `feature/packaging-multicolis` :

```sh
php bin/console app:emballages:references --development --env=dev
```

Cette option est réservée à dev/test. Elle crée ou met à jour les quatre références par leur nom, sans doublons. Une nouvelle exécution réapplique les valeurs provisoires : ne pas la relancer après saisie des mesures définitives. Sans cette option, la commande conserve son comportement de création de brouillons et ne modifie pas les cartons existants.

| ID local | Capacité | Poids vide avec protections | Dimensions extérieures cm |
|---|---:|---:|---|
| 1 | 1 | 300 g | 12 × 12 × 38,5 |
| 2 | 2 | 500 g | 22 × 11,5 × 39,5 |
| 3 | 3 | 700 g | 31,5 × 12,5 × 39,5 |
| 4 | 6 | 1 200 g | 32 × 22 × 41 |

Tous sont actifs, avec poids maximal `null`. **Poids provisoires de développement, non certifiés fabricant.** Ils sont modifiables dans Administration → Emballages. Aucun poids de produit n'est modifié : le moteur utilise toujours le poids enregistré sur chaque produit.

Pour les bouteilles tests de 1 200 g :

| Bouteilles | Capacités retenues | Poids des colis | Total |
|---:|---|---|---:|
| 1 | 1 | 1 500 g | 1 500 g |
| 2 | 2 | 2 900 g | 2 900 g |
| 3 | 3 | 4 300 g | 4 300 g |
| 4 | 3 + 1 | 4 300 + 1 500 g | 5 800 g |
| 5 | 3 + 2 | 4 300 + 2 900 g | 7 200 g |
| 6 | 6 | 8 400 g | 8 400 g |
| 7 | 6 + 1 | 8 400 + 1 500 g | 9 900 g |

Le test fonctionnel `testDevelopmentPackagingSeedAndTwoBottleCheckout` vérifie le seed répété, le refus en production, la conservation des mesures lors d'une exécution sans option, les sept répartitions et le parcours produit → panier → Sendcloud → adresse. Il contrôle le poids API `2.900 kg`, les dimensions, les offres affichées et l'absence du message d'emballage incomplet. L'API est simulée dans ce test isolé.

Une demande réelle de devis seule (`app:sendcloud:quote --address=19 --product=11 --quantity=2`) a retourné Chrono Shop2Shop (6,67 € TTC), Colissimo Home (15,79 € TTC) et Chrono 18 (17,50 € TTC). Ces tarifs sont ceux de ce devis, pas des tarifs garantis. Aucune commande réelle, réservation de stock, expédition ou étiquette n'a été créée pour cette vérification.

La création d'étiquettes reste désactivée ; `.env.local` n'a pas été modifié. Les essais fonctionnels ne remplacent pas une vérification manuelle dans la session connectée du navigateur du client.
