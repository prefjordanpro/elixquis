# Design system Elixquis

Référence : analyse des sept planches officielles fournie et approuvée par le client le 5 octobre 2026. Le dossier `docs/branding/` n'est pas présent dans ce dépôt. Les planches servent de référence et ne sont jamais utilisées comme images du site.

## Palette

| Variable CSS | HEX | Usage |
| --- | --- | --- |
| `--elixquis-noir` | `#1A1A1A` | Texte, CTA principal, footer |
| `--elixquis-blanc` | `#FFFFFF` | Fond principal, cartes, texte sur noir |
| `--elixquis-ivoire` | `#FAF8F4` | Surface UI complémentaire conservée du site existant |
| `--elixquis-beige` | `#F4EAD0` | Storytelling, récapitulatif, détails chaleureux |
| `--elixquis-or` | `#BA9540` | Filets, barres verticales, état actif |
| `--elixquis-orange` | `#E84D1F` | Accent décoratif des produits orange |
| `--elixquis-litchi` | `#EA5463` | Accent décoratif des produits litchi |
| `--elixquis-ambre` | `#8B451F` | Liens, focus, survol des CTA |
| `--elixquis-muted` | `#656057` | Texte secondaire |
| `--elixquis-bordure` | `#DDD7CA` | Bordures fines |
| `--elixquis-succes` | `#356047` | Disponibilité et états positifs |
| `--elixquis-erreur` | `#923C43` | Erreurs, indisponibilité, annulation |

Noir, blanc, beige, or, orange et litchi proviennent des captures. L'or `#BB9641` observé sur la planche 4 est normalisé sur l'aplat `#BA9540` de la planche 5. Ces prélèvements ne remplacent pas les références colorimétriques des fichiers sources. Ivoire, ambre, texte secondaire, bordure et couleurs fonctionnelles sont des adaptations UI, pas des couleurs certifiées de la charte. Les annotations HEX/RVB incohérentes des planches ne sont pas reprises.

L'or est réservé aux détails. Pas de fond entièrement doré, de dégradé métallique simulé ni de petit texte doré sur blanc. Les couleurs fruitées signalent une saveur, jamais un statut métier. Les statuts conservent leur libellé : la couleur ne porte pas seule l'information.

## Typographie

Corps : Segoe UI, Arial, sans-serif, police système sans téléchargement. Titres : Arial Narrow si disponible, puis Segoe UI et Arial. Ce choix provisoire rappelle les titres condensés des planches sans prétendre identifier leur police. Le logotype officiel ne doit pas être reconstitué à partir d'une police.

- H1 : 32–48 px selon l'écran ; hero 44–76 px sur desktop, environ 42 px sur mobile.
- H2 éditorial : 29–64 px selon le contexte ; H3 produit : 19 px sur desktop.
- Titres : graisse 400–600, interligne 1,12 ; casse naturelle.
- Libellés éditoriaux : 12 px, capitales espacées, barre verticale de 3 px.
- Corps : 16 px, interligne 1,7 ; longueur de lecture limitée dans le hero et les sections éditoriales.

Le nom textuel Elixquis reste un emplacement provisoire. Il ne remplace pas le logo aux lettres dessinées et aux prolongements courbes.

## Composants

- Bouton principal : classe d?di?e `btn-brand`, noir, texte blanc, fin rep?re dor? ; survol ambre ; hauteur minimale 44 px, 52 px pour les grands CTA. Rayon 4 px. Les CTA publics ont ?t? raccord?s ? la classe de marque sans changer leurs actions. Le vert est r?serv? aux disponibilit?s, succ?s et confirmations.
- Bouton secondaire : fond transparent et bordure fine ; survol noir, texte blanc. CTA secondaire du hero souligné et discret.
- Liens : ambre foncé, soulignement lisible ; focus de 3 px, décalé de 4 px. Dans le footer, focus beige.
- Cartes : bordure de 1 px, rayon 0–4 px, aucune ombre. Les utilitaires hérités de grandes ombres et de rayons sont neutralisés dans le site public.
- Produits : image au ratio 3:4 en `object-fit: contain`, nom lisible, catégorie discrète, disponibilité textuelle, prix et CTA. Filet fruité pour les noms contenant orange ou litchi.
- Formulaires : champs de 48 px minimum, bordures fines, focus visible. Labels et erreurs préservés.
- Alertes : fond clair, bord gauche marqué, texte sémantique contrasté. Succès vert assourdi, erreur rouge sombre, information neutre, avertissement beige/ambre.
- Badges : rayon 4 px, texte explicite ; paiement/livraison positifs en vert, préparation/demande en beige, annulation en rouge sombre, remboursement/expédition en beige neutre.
- Checkout : structure fonctionnelle existante, étapes numérotées, radios et labels cliquables, résumé clair ; aucune composition artistique ne masque les frais ou erreurs.

## Composition et photographie

Unité d'espacement : 8 px. Espacements habituels : 8, 16, 24, 32, 48 et 64 px. Sections de 48–96 px selon l'écran. Colonnes asymétriques sur desktop, ordre de lecture simple sur mobile. Signature : barres verticales et filets fins ; numérotation de l'histoire uniquement sur grands écrans.

Hero : grandes photos administrées, recadrage `cover`, légende sur aplat sombre et filet doré. Produits : `contain` pour préserver la silhouette, pas de détourage automatique. Les photos produit existantes sont temporaires. Les deux visuels ?ditoriaux de la seconde passe sont g?n?r?s : aucune bouteille ni ?tiquette officielle n?est repr?sent?e. Ils illustrent l?ambiance et doivent ?tre remplac?s par les photos du client.

Le carrousel conserve son JavaScript existant : intervalle 5 secondes, flèches, indicateurs, arrêt au survol et au focus clavier, suspension quand l'onglet est masqué. `prefers-reduced-motion` désactive le défilement automatique et les transitions. Aucun framework ajouté.

## Responsive

Desktop : hero asymétrique, quatre produits par ligne, galerie et informations côte à côte. Tablette : hero empilé, trois produits, checkout en une colonne. Mobile : deux produits puis une seule colonne sous 375 px, CTA larges, menu repliable, histoire et résumé empilés. Le visuel produit devient statique. Les tableaux de commande gardent leur présentation mobile existante.

## Contenu et assets à obtenir

L'histoire de Paul, l'association élixir/exquis, les ingrédients naturels et « fabriqué en France » proviennent des planches. Aucun délai de fabrication, certification ou promesse commerciale ajouté. « Rhum 100% Made in France » a été remplacé par la formulation documentée « Fabriqués en France ».

À demander : SVG officiel du logotype et de l'emblème, PNG transparent, polices et droits web, photos HD des bouteilles et scènes de dégustation, détourages, étiquettes finales (choix entre les deux propositions), éventuelles textures originales. Le rouge texturé, les grandes flèches de présentation et les mockups complets ne sont pas intégrés au site.

Le modèle produit actuel ne fournit qu'une image et une description, sans champs structurés de contenance, degré d'alcool, ingrédients, notes ou conseils. Aucune rubrique vide ni donnée inventée : une seule grande photo et description conditionnelle. Une vraie galerie et ces rubriques pourront être raccordées lorsque les données seront disponibles.

Les pages juridiques finales restent à fournir. Aucun faux texte ni lien vers une route inexistante n'est ajouté.

## Seconde passe visuelle

Header sombre de m?me hauteur, filet dor? et nom textuel espac?. Footer sombre avec signature verticale et phrase serif italique. L?esprit Elixquis associe une grande photo sombre ? un bloc ?ditorial align? sur la m?me grille ; l?histoire alterne le texte ? gauche et la photo claire ? droite. Num?ros 01/02 rattach?s aux labels, labels uppercase espac?s et cadres incomplets rappellent les compositions du brief. Sur mobile, les colonnes et chevauchements sont supprim?s au profit d?un ordre de lecture vertical.

La recette produit devient une section ?ditoriale sous le duo photo/achat : titre ? gauche, description existante ? droite, filet de s?paration. Le bloc d?achat garde ses donn?es et ses actions, avec une barre dor?e lat?rale et un fond ivoire. Le panier et le checkout conservent des cartes simples et un r?capitulatif avec filet sup?rieur discret.

## Finition UX/UI

Une seule d?finition de la grille du hero : deux zones jointives d?s 992 px, puis texte/image empil?s. Le rail vertical flottant est supprim?. ? Fruits ? ?pices ? Caract?re ? est une l?gende li?e au visuel, sans num?ro concurrent des sections. Image/carrousel : hauteur 500?580 px sur desktop, 420 px sur tablette, 280?360 px sur mobile. Les recadrages utilisent cover ; le fallback ambr? est centr? ? 68 % horizontalement.

Storytelling : colonnes ?gales d?s 992 px, empilement en dessous, aucun d?calage n?gatif. Photos au ratio 5:4 sur desktop, 16:9 sur tablette et 4:3 sur mobile ; point de cadrage ? 70 % pour le verre et 35 %/60 % pour les fruits. Les num?ros et labels forment un seul rep?re align?.

Trois traitements de CTA : principal noir/ambre (`btn-brand` et alias `btn-primary`), secondaire transparent ? bordure fine (classes outline historiques raccord?es au m?me style), lien ?ditorial soulign? (`editorial-link`, ?galement utilis? pour les cartes produit). Les variables de contexte adaptent les deux derniers traitements aux fonds sombres sans cr?er de style suppl?mentaire. La disponibilit? et les alertes conservent leurs couleurs s?mantiques.

## Raffinement des images et interactions ? r?gles en vigueur

Les sections ?ditoriales gardent deux colonnes d?s 768 px, avec alternance image/texte puis texte/image. Sous 768 px, les deux suivent texte puis image. Les hauteurs des photos sont born?es : 280?360 px desktop (`24vw`), 240?290 px tablette (`30vw`), 220?260 px mobile (`65vw`), en cover avec les m?mes points de cadrage. Ces hauteurs remplacent les ratios de storytelling d?crits dans la finition pr?c?dente ; le hero reste inchang?.

Les trois traitements de CTA r?agissent au survol et au focus-visible : principal noir vers ambre, secondaire transparent vers aplat contrast?, lien ?ditorial l?g?rement teint? avec filet renforc?. Transitions de 200 ms, aucune translation ou animation de photo. Les cat?gories deviennent beige/dor?. Les r?gles de mouvement r?duit et les outlines clavier sont conserv?s.
