# Authentification et espace client

La connexion et le checkout restent accessibles sans vérification email. Les comptes
existants ne sont pas déclarés vérifiés rétroactivement. L'espace client affiche
l'état de vérification et permet de renvoyer un lien avec CSRF.

La migration `Version20261011120000` ajoute `user.email_verified_at` et la table
`account_token`. Chaque token contient un hash SHA-256 d'un secret aléatoire de
256 bits, une finalité, une échéance et une empreinte de l'identité du compte.
La réinitialisation expire après une heure, la vérification après 24 heures.
Un nouveau lien invalide les anciens liens de même finalité. Les liens de récupération
deviennent invalides après changement du mot de passe ; ceux de vérification après
changement d'email. Le mot de passe est toujours hashé avec Symfony PasswordHasher.

Le lien ouvre un formulaire. Seul un POST protégé par CSRF consomme le token,
sous verrou de compte puis de token, dans la transaction qui met à jour le compte.
Les pages de token sont privées, non mises en cache et utilisent `no-referrer`.
La demande de récupération répond de façon identique pour une adresse connue ou
inconnue. Les demandes et renvois sont limités à cinq par IP et par quinze minutes.

Les quatre emails utilisent le service historique `App\Classe\Mail` et le template
`templates/emails/account.html.twig` : bienvenue, vérification, récupération et
confirmation de modification du mot de passe. Symfony BodyRenderer rend Twig en
HTML et texte, puis `Mail::sendEmail()` transmet ces contenus à Mailjet API v3.1
avec les clés `MJ_APIKEY_PUBLIC` / `MJ_APIKEY_PRIVATE` déjà configurées. Aucun secret
n'est codé en dur. L'envoi est direct : Messenger n'est plus nécessaire pour ces
quatre emails. `MAILER_DSN`, les transports et les autres routages Messenger restent
inchangés pour leurs autres usages. Les tests mockent le service Mailjet.
La réponse API et le statut de chaque message sont contrôlés : seuls les messages
acceptés sont journalisés avec leur ID Mailjet, sans token, lien ou destinataire.
Une acceptation API ne prouve pas la réception en boîte. Les erreurs restent
génériques et ne journalisent jamais le corps de la requête ou l'erreur fournisseur.

Avant mise en production, fournir `AUTH_BASE_URL` avec l'origine HTTPS publique
canonique de la boutique et `AUTH_SENDER` avec l'adresse d'expédition validée auprès
du fournisseur. Sans configuration supplémentaire, les valeurs locales reprennent
`https://127.0.0.1:8000` et l'expéditeur déjà utilisé dans le projet.
Les liens ne sont jamais construits à partir du Host fourni par le demandeur.
Ne pas modifier `.env.local` pour ces changements ; utiliser la configuration
de déploiement. Ne pas journaliser ni partager les URL de token.

Les styles de focus sont dans `public/assets/css/auth.css`, chargé seulement sur
connexion, inscription et récupération/vérification. Le lien de livraison classique
a été retiré du template client Sendcloud ; le parcours technique historique reste
en place et aucune règle de livraison ou de commande n'a changé.
