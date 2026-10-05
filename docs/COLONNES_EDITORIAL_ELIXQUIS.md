# Colonnes éditoriales Elixquis

Branche : `feature/identite-visuelle-elixquis`. Correction CSS des deux sections uniquement ; hero et métier inchangés.

## Règles appliquées

- Grille centrée : `width: calc(100% - 48px); max-width: 1180px; padding-inline: 0`.
- Dès 1024 px : colonnes `minmax(0, 43fr) minmax(0, 57fr)` pour L’esprit ; proportions inversées pour L’histoire. Ces proportions concernent la largeur restante après le gap.
- Espace desktop : `clamp(48px, 5vw, 72px)` ; alignement vertical `align-items: center`.
- Figure : `width: 100%; max-width: 520px` dans sa colonne, jamais dans toute la grille desktop.
- Photo desktop : hauteur `clamp(340px, 28vw, 420px)`, `object-fit: cover` ; cadrage verre `70% center`, fruits `35% 60%`.
- Tablette 768–1023 px : deux colonnes égales. À 768 px : grille 720 px, colonnes 348 px et gap 24 px.
- Mobile sous 768 px : une colonne texte puis image ; grille `calc(100% - 32px)` et hauteur `clamp(220px, 65vw, 260px)`. La photo remplit la largeur disponible de sa figure ; L’histoire conserve son encadrement graphique avec un retrait intérieur de 12 px.
- Repères 01/02, titres, textes et CTA conservés.

## Dimensions mesurées dans Edge

| Écran | Section | Grille | Image largeur × hauteur | Texte | Gap | Part de l’image dans la grille |
| --- | --- | --- | --- | --- | --- | --- |
| 1440 px | L’esprit | 1180 px | 476,44 × 403,19 px | 631,56 px | 72 px | 40,38 % |
| 1440 px | L’histoire | 1180 px | 464,45 × 403,19 px | 631,55 px | 72 px | 39,36 % |
| 1024 px | L’esprit | 976 px | 397,66 × 340 px | 527,16 px | 51,2 px | 40,74 % |
| 1024 px | L’histoire | 976 px | 385,67 × 340 px | 527,14 px | 51,2 px | 39,52 % |

La différence de 12 px entre les photos correspond à l’encadrement graphique conservé pour L’histoire. Les colonnes image/texte sont bien en 43/57 après retrait du gap.

Captures 1440 et 1024 px inspectées visuellement. 125 contrôles de pages aux largeurs 1440, 1024, 768, 390 et 320 px réussis, ainsi que 15 contrôles d’interaction. Le contrôle rejette désormais une image desktop dépassant la moitié de la grille, 520 px de large ou 420 px de haut. Lint Twig et `git diff --check` réussis. La suite métier n’a pas été relancée pour cette correction CSS isolée.

[Capture 1440 px](branding-review/colonnes/accueil-1440.png) · [Capture 1024 px](branding-review/colonnes/accueil-1024.png) · [Mesures navigateur](branding-review/colonnes/controles.json).
