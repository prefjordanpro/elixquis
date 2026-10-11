# Commandes et emails transactionnels

Branche : `feature/emails-statuts-commandes`. Aucun commit, aucune modification de `.env.local`.

## État initial

Les statuts sont des entiers, sans enum : 0 attente de paiement, 1 payée, 2 préparation, 3 expédiée, 4 annulée, 5 livrée, 6 remboursée, 7 demande d’annulation. Les transitions logistiques et les verrous pessimistes existaient déjà dans `OrderManager`. Stripe vérifie la session, la devise, le montant et la référence ; l’expiration, la réconciliation historique et les remboursements ont leurs services dédiés. La réservation/restitution de stock reste inchangée.

`Shipment` possède déjà numéro et URL de suivi ; `ColisCommande` possède les numéros individuels. Les dates `requestedAt`/`updatedAt` sont des dates techniques de suivi, pas des preuves de livraison. Aucun champ supplémentaire n’est nécessaire pour les emails demandés. Les reçus des nouveaux statuts expédié/livré indiquent la date de déclaration métier. L’historique préexistant concerne les demandes d’annulation ; les nouveaux reçus ne reconstruisent pas le passé.

## Matrice

La matrice par opération se trouve dans `OrderManager::TRANSITIONS`.

| Opération | Transitions |
|---|---|
| Confirmation Stripe fiable | 0 → 1 |
| Logistique | 1 → 2 → 3 → 5 |
| Annulation non payée vérifiée | 0 → 4 |
| Annulation administrateur existante | 0/1/2 → 4, sans expédition active ; remboursement distinct |
| Remboursement total confirmé Stripe | 1/2/3/4/5/7 → 6 |
| Demande client | 1/2 → 7 |
| Résolution de la demande | 7 → statut précédent enregistré (1 ou 2) ; si acceptée, annulation puis remboursement séparé |

Les remboursements en cours bloquent la logistique. Aucun retour arrière logistique n’est autorisé. Un rejeu du paiement ne modifie pas une commande déjà avancée ou remboursée. Exception Stripe préexistante conservée : un échec bancaire vérifié après un remboursement déclaré réussi restaure `stateBeforeRefund` ; ce n’est pas une action administrative libre et cela ne renvoie aucun email de paiement/préparation.

## Emails et exploitation

Six emails HTML + texte : paiement confirmé (récapitulatif complet), préparation, expédition (transporteur et suivi disponible), livraison, annulation sans promesse de remboursement, remboursement confirmé sans délai bancaire garanti. Mailjet est appelé via `Mail::sendEmail`. L’origine des liens et l’expéditeur réutilisent `AUTH_BASE_URL`/`AUTH_SENDER` et leurs valeurs de configuration de secours. Aucun domaine commercial en dur.

La transition et son instantané d’email sont persistés dans la même transaction sous verrou de commande. L’unicité `(order_id, state)` empêche les doublons, y compris après une résolution d’annulation ou une correction Stripe. Les reprises de statuts déjà notifiés ne renvoient pas de mail. Aucun envoi rétroactif des commandes historiques.

Après la requête HTTP ou la commande console, le traitement consomme les emails `pending` hors transaction métier. Le relais cron recommandé est `php bin/console app:orders:send-emails` (par exemple chaque minute). Une revendication SQL atomique et validée avant l’appel Mailjet fait passer le reçu à `attempting`. Un autre processus ne peut pas revendiquer le même email. Puis il passe à `sent` ou `failed`.

Choix explicite **au plus une tentative** : aucune relance automatique des statuts `failed` ou `attempting`. En cas de timeout, Mailjet peut avoir accepté l’email sans que la réponse soit connue ; un nouvel envoi pourrait créer un doublon. Après un arrêt entre revendication et envoi, l’email peut donc ne pas partir. Vérifier les reçus Mailjet avant toute intervention manuelle. Le statut `sent` signifie accepté par Mailjet, pas livré à la boîte du client. Le journal contient seulement les identifiants internes commande/notification, jamais le message d’exception fournisseur, les secrets ou l’adresse client.

Le back-office conserve client/date/livraison/colis, précise le paiement et affiche les nouveaux reçus datés et leur état d’envoi. Ses actions logistiques suivent la matrice ; les annulations/remboursements et l’historique existants restent disponibles selon leurs gardes. Les statuts inconnus restent affichables.

## Migration et validation

Migration additive `Version20261011160000` : nouvelle table `order_notification`, index unique, clé étrangère vers la commande. Aucun changement des anciennes lignes ; aucun ajout de champ Sendcloud. Migration préparée mais non exécutée sur la base locale. L’appliquer avant d’utiliser ces changements en exploitation. La création d’étiquettes reste désactivée dans la configuration existante.

Les tests utilisent SQLite et un transport Mailjet simulé. Les webhooks de paiement sont signés localement ; aucun appel réseau Stripe n’est requis par les nouveaux tests. La suite existante conserve ses simulations Stripe/Sendcloud.

Résultat final : **331 tests, 3 753 assertions, tous réussis**. Les 16 nouveaux cas couvrent également l’instantané immuable, l’absence d’email pour un remboursement en attente, la confidentialité des erreurs et la matrice. Twig (10 fichiers), le conteneur Symfony et le mapping Doctrine sont valides. Une dépréciation préexistante concerne `User::eraseCredentials()` ; elle ne provoque aucun échec.

## Fichiers modifiés ou ajoutés

- `src/Entity/Order.php`
- `src/Entity/OrderNotification.php` (ajout)
- `src/Service/OrderManager.php`
- `src/Service/OrderCancellation.php`
- `src/Service/OrderEmails.php` (ajout)
- `src/EventSubscriber/OrderEmailDispatch.php` (ajout)
- `src/Command/SendOrderEmailsCommand.php` (ajout)
- `templates/admin/order.html.twig`
- `templates/admin/_order_actions.html.twig`
- `templates/admin/state.html.twig`
- `templates/emails/order.html.twig` (ajout)
- `templates/emails/order.txt.twig` (ajout)
- `migrations/Version20261011160000.php` (ajout)
- `tests/OrderEmailsTest.php` (ajout)
- `tests/ExpiredPaymentsTest.php` (adaptation du constructeur du service dans un test existant)
- `docs/emails-statuts-commandes.md` (ajout)
