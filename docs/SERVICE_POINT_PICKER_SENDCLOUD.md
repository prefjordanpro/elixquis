# Carte officielle des points relais Sendcloud

Branche : `feature/identite-visuelle-elixquis`. Documentation consultée le 5 octobre 2026.

## Références officielles

- [Intégration du Service Point Picker hébergé](https://sendcloud.dev/docs/service-points/integrate-the-hosted-service-point-picker-into-your-checkout)
- [Expédition vers un point relais, API v3](https://sendcloud.dev/docs/service-points/create-a-shipment-with-service-point-delivery)
- [Exemple officiel](https://sendcloud-public.gitlab.io/spp-integration-example/)

Le navigateur charge à la demande `https://embed.sendcloud.sc/spp/1.0.0/api.min.js` et appelle `sendcloud.servicePoints.open(config, onSuccess, onFailure)`. Configuration : clé publique existante, pays et adresse de livraison, langue `fr-fr`, transporteur de l'offre choisie (format chaîne exigé par le SDK). La clé secrète n'est jamais exposée. La réouverture transmet `servicePointId` pour retrouver le relais courant.

## Interface

La sélection du mode relais conduit automatiquement au bouton « Choisir mon point relais ». La longue liste radio n'est plus rendue. Après le callback et la validation serveur, une carte ivoire arrondie affiche le nom et l'adresse, puis « Changer de point relais ». Le bouton de poursuite reste désactivé tant qu'aucun relais n'est validé. L'annulation du widget conserve la sélection précédente.

Sur mobile, le widget officiel adapte lui-même la présentation de sa carte et de sa liste interne. Aucun catalogue de relais n'est inséré dans la page du checkout.

Fichiers d'interface : `templates/components/_service_point_picker.html.twig`, `templates/order/sendcloud.html.twig`, `public/assets/js/sendcloud-picker.js`, styles locaux dans `public/assets/css/purchase.css`.

## Validation et conservation

Le callback envoie seulement l'identifiant et l'éventuel `post_number`, avec adresse, méthode et jeton CSRF du formulaire. Les coordonnées transmises par le picker ne sont pas utilisées comme source fiable.

Le serveur recharge le point depuis l'API Sendcloud et vérifie son identifiant, son transporteur, son pays, son absence d'expiration et sa disponibilité. Il recharge les offres pour vérifier la compatibilité entre point, méthode et colis, ainsi que l'égalité avec le prix affiché. Il contrôle la présence des coordonnées nécessaires. L'adresse de livraison doit appartenir au client connecté.

Le `post_number`, renseigné par l'utilisateur lorsqu'il est requis, est limité à 32 caractères alphanumériques, espaces ou tirets ; il est exigé pour un point de type Packstation. Ce contrôle de format ne certifie pas l'identité personnelle du destinataire. Le parcours français Shop2Shop ne change pas.

Données conservées côté serveur, puis dans le snapshot de livraison lors de la confirmation :

- identifiant Sendcloud `service_point.id` ;
- nom canonique `service_point.name` ;
- adresse canonique `service_point.address` : rue, numéro éventuel, code postal, ville et pays ;
- transporteur `service_point.carrier` : code et informations retournées par l'API ;
- identifiant transporteur `carrier_service_point_id`, lorsqu'il existe ;
- `service_point.post_number`, éventuellement vide.

Le choix est conservé en session pendant la validité du devis (15 minutes) et restauré après un retour ou un rafraîchissement si l'adresse et le panier sont inchangés. Les liens de changement d'adresse/livraison réinitialisent volontairement le choix. La confirmation revalide le point et les offres. Choisir ou changer de relais ne crée aucune commande et ne réserve aucun stock.

## Future expédition

Le mécanisme existant `SendcloudShipping` réutilise le snapshot : `to_service_point.id` contient l'identifiant validé. L'éventuel numéro destinataire est conservé aussi dans `to_address.po_box`, champ prévu par l'API v3. Nom et adresse restent disponibles pour le récapitulatif client et le suivi administratif. Aucun appel réel de création d'expédition ou d'étiquette n'a été effectué.

Tarifs, politique Shop2Shop, paiement Stripe, stock et logique de commande existante ne sont pas remplacés. Le fallback classique reste accessible volontairement. `.env.local` n'a pas été modifié.

## Vérifications

- Suite existante : **120 tests, 1668 assertions**, réussis. Test ciblé supplémentaire après ajout du retour arrière : **1 test, 21 assertions**, réussi. Les services externes sont simulés et la base est isolée dans PHPUnit.
- Validation serveur : point incompatible/indisponible, données falsifiées, numéro invalide, session expirée, refresh, retour arrière et transfert du numéro dans le snapshot. La confirmation du relais aboutit au récapitulatif ; aucune annonce Sendcloud réelle.
- Navigateur Edge : interface du checkout à **1440, 1024, 768, 390 et 320 px**, ouverture, sélection, changement, annulation et restauration au refresh, sans débordement ni erreur JavaScript. Ce test emploie des callbacks et réponses contrôlés ; voir `tools/check-sendcloud-picker.cjs` et `docs/branding-review/picker/controles.json`.
- Widget officiel réel : ouverture de la carte, sélection et changement entre relais Chronopost disponibles, callback réel reçu. Un relais indisponible a été refusé par Sendcloud. Ouverture et captures à **1440, 768 et 390 px** via l'exemple officiel avec la clé publique existante, sans expédition. Voir `tools/check-picker-official.cjs` et les captures `widget-officiel*.png`.
- 31 templates Twig valides ; syntaxe JavaScript et `git diff --check` vérifiés. Les avertissements de dépréciation Doctrine préexistants restent présents.

Les contrôles du widget réel et ceux de l'application sont séparés : aucune commande réelle de bout en bout n'a été créée sur la boutique.
