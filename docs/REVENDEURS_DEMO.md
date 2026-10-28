# Revendeurs fictifs — développement et tests

Le groupe Doctrine Fixtures `revendeurs-demo` contient huit points actifs à Paris, Lille, Nantes, Bordeaux, Marseille, Lyon, Strasbourg et Toulouse, plus un point inactif à Rennes. Les coordonnées correspondent approximativement aux centres des villes ; aucun géocodage n’est appelé.

Chaque nom porte « FICTIF (démonstration) ». L’adresse et son complément indiquent explicitement qu’il ne s’agit pas d’un partenaire réel d’Elixquis. Les téléphones sont volontairement invalides et les sites / emails utilisent le domaine réservé `.invalid`.

Le bundle est une dépendance Composer `require-dev`, activée seulement en dev/test. Le répertoire `src/DataFixtures` est exclu des services généraux et enregistré seulement en dev/test. La fixture refuse également tout autre environnement si elle est invoquée directement. Aucun chargement automatique au démarrage du site ; aucune migration n’est modifiée.

## Charger sur la base XAMPP locale

Depuis `C:\xampp\htdocs\elixquis`, dans PowerShell :

```powershell
$env:DATABASE_URL = 'mysql://root@127.0.0.1:3306/elixquis?serverVersion=10.4.32-MariaDB&charset=utf8mb4'
php bin/console doctrine:fixtures:load --env=dev --group=revendeurs-demo --append --no-interaction
```

La variable ne modifie aucun fichier `.env`. Cette commande cible explicitement la base locale `elixquis` sur `127.0.0.1`. Les identifiants correspondent à l’installation XAMPP locale actuelle. `--env=dev` seul ne choisit pas une base différente : conserver le ciblage local explicite si d’autres connexions existent dans l’environnement.

Le chargement peut être répété : les neuf adresses de démonstration sont mises à jour sans doublons. Les revendeurs existants sont conservés. Un garde interdit de lancer le chargement sans `--append`, car Doctrine supprimerait sinon les données avant de charger les fixtures.

Consulter ensuite `http://localhost/elixquis/public/nos-revendeurs` : huit points de démonstration sont présents dans la liste et sur la carte. Le neuvième est uniquement visible dans l’administration. Les éventuels revendeurs actifs déjà présents restent affichés.

## Retirer uniquement la démonstration

Avec la même connexion locale PowerShell :

```powershell
php bin/console app:demo:revendeurs:remove --env=dev
```

Cette commande réutilise les identifiants de la fixture pour ne supprimer que ses neuf enregistrements. Elle conserve les autres données. Les champs d’identification sont l’email fictif, l’adresse de démonstration et son avertissement ; les conserver si l’on souhaite supprimer ensuite ces lignes avec cette commande.

Les tuiles de fond de carte peuvent nécessiter Internet ; aucun appel IGN n’est nécessaire pour les marqueurs.

## Validation

Chargement effectué sur XAMPP local : 9 revendeurs, dont 8 actifs. Les 4 produits et 44 commandes déjà présents sont conservés. Les six tests dédiés vérifient le chargement répété sans doublons, les huit entrées et coordonnées publiques, la suppression ciblée, le refus d’une purge et l’absence des fixtures / commandes dans un conteneur de production isolé.

Suite complète : **438 tests, 4 617 assertions, aucun échec**. Mapping et schéma Doctrine OK. Contrôle navigateur hors réseau à 1440 et 390 px : huit marqueurs, huit adresses, seize ouvertures de popup portant l’avertissement fictif, aucun débordement horizontal ni erreur JavaScript. Captures dans `var/revendeurs-demo-review/`.

La [documentation officielle Doctrine FixturesBundle](https://symfony.com/bundles/DoctrineFixturesBundle) décrit le chargement par groupe et l’option `--append` utilisée ici.
