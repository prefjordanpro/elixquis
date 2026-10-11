# Expiration des paiements

Les nouvelles Checkout Sessions expirent 24 heures après la création de la commande.
Stripe impose au moins 30 minutes entre la création d'une session et son expiration :
une nouvelle session ne peut donc plus être créée durant la dernière demi-heure du délai.
Les sessions existantes conservent leur échéance Stripe initiale.

## Webhook prioritaire

Configurer dans Stripe l'endpoint HTTPS `/paiement/webhook` pour recevoir
`checkout.session.completed` et `checkout.session.expired`. Conserver les événements
de remboursement déjà utilisés. Le secret de signature `STRIPE_WEBHOOK_SECRET`
doit être fourni par la configuration de production.

Après validation de la signature, une expiration relit la session Stripe et le
PaymentIntent éventuel. Seule une session réellement expirée et impayée autorise
l'annulation. Une session complète impayée ou un PaymentIntent en traitement,
authentification, confirmation, capture ou déjà réussi reste protégé. Un paiement
confirmé est synchronisé avec les contrôles de montant, devise et commande existants.
Une erreur API retourne HTTP 503 pour permettre une nouvelle livraison du webhook.

## Filet de sécurité

```sh
php bin/console app:commandes:annuler-paiements-expires --env=prod --no-debug --no-interaction
```

L'alias historique `app:orders:expire` utilise désormais la même règle de 24 heures.
Ajouter `--dry-run` pour simuler : la sélection et les lectures Stripe restent les
mêmes, mais aucun statut, stock ou identifiant n'est modifié, aucun flush n'est
effectué et aucune expiration n'est demandée à Stripe. Pour une session ouverte,
l'annulation annoncée reste conditionnelle à la confirmation future de son expiration.
La commande ne sélectionne que les commandes en attente créées depuis plus de
24 heures. Une session ouverte et impayée est expirée via Stripe avant toute
restitution de stock. Pour les anciennes commandes sans suivi explicite, sans session Stripe identifiable, la vérification est impossible :
la commande et son stock réservé sont conservés, même si un PaymentIntent est enregistré.
Le traitement réel et le `--dry-run` signalent « Aucune session Stripe identifiable →
aucune annulation automatique ; vérification manuelle nécessaire ».
Les commandes payées, annulées ou ayant un autre statut ne sont pas annulées.
En cas d'erreur Stripe ou de données incohérentes, le traitement s'arrête avec un
code de sortie 1 et conserve le stock de la commande concernée. Les commandes déjà
traitées restent traitées ; la prochaine exécution reprend les commandes en attente.

Planifier toutes les heures avec cron, avec le PHP et le chemin réels du serveur.
Le délai métier reste de 24 heures : une commande éligible sera traitée lors du prochain passage horaire.

```cron
0 * * * * cd /var/www/elixquis && /usr/bin/php bin/console app:commandes:annuler-paiements-expires --env=prod --no-debug --no-interaction >> /var/log/elixquis-expiration-paiements.log 2>&1
```

Sur Windows, créer une tâche du Planificateur toutes les heures : programme
`C:\xampp\php\php.exe`, arguments
`bin/console app:commandes:annuler-paiements-expires --env=prod --no-debug --no-interaction`,
répertoire de démarrage `C:\xampp\htdocs\elixquis`. Éviter les exécutions simultanées
et surveiller le code de retour et les journaux. Cette tâche n'est pas installée par le code.

## Idempotence et stock

Le traitement verrouille la commande en base avec le même verrou que la création
du paiement. L'annulation réutilise `OrderManager::cancelUnpaid()` : le stock n'est
restitué que si `stockReserved` est vrai, puis ce drapeau passe à faux dans la même
transaction que le statut « Annulée ». Une relance ou un webhook doublonné ne
recrédite pas le stock. Aucun remboursement n'est demandé.

Le motif automatique est
affiché par la commande ; les demandes d'annulation client ne sont pas détournées
pour enregistrer une expiration automatique.

## Suivi explicite des nouvelles commandes

Appliquer la migration `Version20261011100000` avant de déployer le code.
`payment_tracking_enabled` vaut faux pour toutes les commandes préexistantes, sans
requalification des commandes #87, #99, #102 et #104. `OrderManager::create()` active
le suivi uniquement pour les commandes qu'il crée désormais.

`payment_initiated_at` reste null tant que le paiement n'a jamais été lancé.
Au lancement effectif du paiement, une première transaction verrouille la commande,
enregistre cette date et les paramètres exacts dans `stripe_checkout_request`, puis
valide la transaction AVANT tout appel Stripe. Une seconde transaction verrouille
à nouveau la commande, appelle Stripe et sauvegarde l'identifiant de session.
Une erreur réseau ou un échec de sauvegarde laisse donc une tentative identifiable.

La clé `checkout-order-ID` est stable. Une relance utilise les mêmes paramètres
enregistrés, même si le profil client a changé. La référence client et les metadata
`order_id` sont présentes sur la session et sur le PaymentIntent.
Stripe peut supprimer les clés d'idempotence après 24 heures : aucune requête de
création incertaine n'est rejouée après le délai de paiement. Le cron ne rejoue pas
la création et le dry-run reste strictement en lecture seule. Une tentative sans
session connue après 24 heures reste signalée pour vérification manuelle.

Après 24 heures, une commande suivie sans tentative, sans session et sans
PaymentIntent peut être annulée sous verrou, avec libération unique du stock.
Une session connue reste soumise aux contrôles Stripe existants. Une tentative
incertaine ne peut pas être annulée par les autres actions d'annulation non plus.

## Intervention manuelle sur une commande historique

La fiche administrateur propose « Annuler manuellement cette commande historique »
uniquement pour une commande en attente depuis plus de 24 heures, sans remboursement
ni expédition active, dont la référence Stripe manque ou dont la session est expirée,
impayée et incohérente avec les données locales. Une session normalement vérifiable
reste dans le parcours habituel. Une référence absente chez Stripe (`resource_missing`)
est admissible uniquement si aucun PaymentIntent n'est enregistré localement.

Un paiement confirmé, un PaymentIntent en traitement, une session encore ouverte ou
une panne Stripe interdisent cette action. Sans session, un PaymentIntent enregistré
bloque aussi cette intervention. La confirmation explicite, le rôle administrateur
et un jeton CSRF sont obligatoires. Les vérifications sont renouvelées au clic sous
verrou de commande. Aucun remboursement ou expiration Stripe n'est demandé.

Le statut devient « Annulée » et le stock réservé est restitué une seule fois via
le mécanisme existant. L'historique enregistre une annulation acceptée, l'administrateur,
la date et le motif « Annulation manuelle d’une commande historique non vérifiable ».
Cette intervention manuelle n'autorise jamais le traitement automatique à annuler
une commande sans session identifiable.

## Réconciliation des paiements historiques

Le bouton « Vérifier le paiement Stripe » peut synchroniser vers « Payée » une
commande créée avant le 4 octobre 2026, lorsque `client_reference_id` est absent.
Cette date fixe correspond à l'introduction du checkout sécurisé ; être simplement
en attente depuis plus de 24 heures ne rend pas une commande moderne admissible.

La session Stripe relue doit porter exactement l'identifiant stocké en base et ne
doit être associée qu'à une seule commande locale. Elle doit être complète et payée,
en EUR, avec le total attendu. Son PaymentIntent est relu séparément : identifiant
exact, état `succeeded`, devise EUR et `amount_received` égal au total de commande.
Un PaymentIntent déjà enregistré doit correspondre. Les metadata `order_id` et
`order_reference`, présentes sur la session ou le PaymentIntent, doivent aussi
correspondre. Une référence présente mais incorrecte n'est jamais ignorée.

La synchronisation conserve le stock historique en l'état, y compris lorsque
`stockReserved` est faux (anciennes commandes migrées). Elle enregistre l'identifiant
du PaymentIntent et utilise le verrou et les contrôles de statut existants. Aucun
paiement, remboursement ou annulation n'est demandé. Les règles modernes restent
strictes ; le montant et le nom du client ne suffisent jamais à rapprocher un paiement.
