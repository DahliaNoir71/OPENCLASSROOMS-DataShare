# Design tokens — DataShare

Source : maquette Figma « DataShare »
(`https://www.figma.com/design/XEinfkoE7mXktCCfMs3E8c/DataShare`), pages
« Composants UI », « Login », « Téléversement », « Téléchargement », « Mon
espace ».

Ce document trace l'origine de chaque variable CSS définie dans
`frontend/src/assets/styles/tokens.css` et `fonts.css`. Ce lot ne stylise
aucune vue : il matérialise uniquement les fondations (variables). L'habillage
des composants (`.app-header`, `.register-card`, `.form-field`, etc.) reste le
lot CSS final.

## Couleurs

### Dégradé de fond

| Variable | Valeur | Usage maquette | Réf. Figma |
|---|---|---|---|
| `--ds-gradient-from` | `#FFB88C` | Départ du dégradé de fond (pages Téléversement, Login, Téléchargement, Mon espace) | Global var `Background`, `rgba(255,184,140,1)` |
| `--ds-gradient-to` | `#DE6262` | Arrivée du dégradé de fond | `rgba(222,98,98,1)` |
| `--ds-gradient-angle` | `149deg` | Angle du dégradé de fond | |
| `--ds-gradient-bg` | `linear-gradient(var(--ds-gradient-angle), var(--ds-gradient-from) 0%, var(--ds-gradient-to) 100%)` | Variable composée, prête à l'emploi | |

### Bouton primaire sombre (variant Dark)

| Variable | Valeur | Usage maquette | Réf. Figma |
|---|---|---|---|
| `--ds-color-primary` | `#2C2C2C` | Fond des boutons « Se connecter », « Mon espace », « Ajouter des fichiers » | Button Component `#20:598`, variant `Dark` |
| `--ds-color-primary-text` | `#F3EEEA` | Texte sur bouton sombre | |

### Bouton upload rond (accueil)

| Variable | Valeur | Usage maquette | Réf. Figma |
|---|---|---|---|
| `--ds-color-upload-halo` | `rgba(47, 25, 13, 0.15)` | Halo autour du disque sombre du bouton upload (page d'accueil) | Frame `#55:338`, cercle extérieur |

**Ajouté au lot habillage, manqué à l'extraction initiale** : ce token n'existait pas au moment de l'extraction des fondations (le disque intérieur du bouton upload avait été rattaché à `--ds-color-primary`, mais le halo qui l'entoure n'avait pas été relevé). Valeur reprise telle quelle depuis Figma (`rgba(47, 25, 13, 0.15)`), pas une approximation.

### Accents orange (3 tokens distincts, arbitrage : pas de fusion)

| Variable | Valeur | Usage maquette | Réf. Figma |
|---|---|---|---|
| `--ds-color-accent` | `#E27F29` | Texte orange sur fond blanc — boutons Tertiary/Secondary (« Créer un compte », « J'ai déjà un compte », « Supprimer », « Accéder », « Changer ») | Button Component `#20:598`, variants `Tertiary` / `Secondary` |
| `--ds-color-accent-strong` | `#BA681F` | Texte orange sur fond teinté — boutons Primary (« Téléverser », « Télécharger », « Connexion ») | Button Component `#20:598`, variant `Primary` |
| `--ds-color-accent-soft` | `rgba(255, 129, 45, 0.13)` | Fond teinté des boutons Primary | |
| `--ds-color-accent-border` | `rgba(205, 94, 20, 0.5)` | Bordure des boutons Primary | |
| `--ds-color-accent-border-soft` | `#FFA569` | Bordure des boutons Secondary | |
| `--ds-color-link` | `#D8640B` | Texte du lien de partage (`https://datashare.fr/...`), pages Téléversement / Téléchargement | |

Ces trois oranges ont été conservés séparés sur arbitrage explicite : chacun
répond à un contexte de contraste différent (fond blanc, fond teinté, lien
hypertexte) et une fusion aurait effacé une distinction volontaire de la
maquette.

### États disabled (tous variants de bouton)

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-color-disabled-bg` | `rgba(183, 167, 156, 0.2)` | Fond bouton Primary désactivé |
| `--ds-color-disabled-bg-dark` | `#F4EEE9` | Fond bouton Dark désactivé |
| `--ds-color-disabled-text` | `#AEA49B` | Texte / bordure des boutons désactivés, tous variants |

### Callouts (Info / Warning / Error)

| Variable | Valeur | Usage maquette | Réf. Figma |
|---|---|---|---|
| `--ds-color-info-bg` / `-border` / `-text` | `#E2ECFF` / `#B1C9F5` / `#2A3F72` | Callout Info (ex. « Ce fichier expirera dans 3 jours. ») | Callout Component `#56:1078`, `Type=Info` |
| `--ds-color-warning-bg` / `-border` / `-text` | `#FFF5ED` / `#E6CBB5` / `#AA642B` | Callout Warning/Alert (ex. « Ce fichier expirera demain. ») | `Type=Alert` |
| `--ds-color-error-bg` / `-border` / `-text` | `#FFE2E2` / `#E8A6A6` / `#9C3333` | Callout Error (ex. « Ce fichier n'est plus disponible... ») | `Type=Error` |

### Texte

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-color-text` | `#000000` | Texte principal (titres H1/H2) |
| `--ds-color-text-secondary` | `#1E1E1E` | Labels de champ, valeur saisie dans un input |
| `--ds-color-text-muted` | `#B3B3B3` | Placeholder des inputs |
| `--ds-color-text-inverse` | `#FFFFFF` | Texte sur fond sombre / dégradé (footer copyright) |
| `--ds-color-text-expired` | `#C62020` | Libellé « Expiré » (Mon espace) |

### Surfaces

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-color-surface` | `#FFFFFF` | Fond de carte, d'input, de select |
| `--ds-color-border` | `#D9D9D9` | Bordure input / select |

### Switch (filtre « Tous / Actifs / Expiré »)

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-color-switch-bg` | `rgba(255, 193, 145, 0.16)` | Fond du conteneur |
| `--ds-color-switch-border` | `rgba(215, 99, 11, 0.2)` | Bordure du conteneur |
| `--ds-color-switch-selected` | `#E77A6E` | Segment sélectionné (solide) |

### Liste de fichiers (Mon espace)

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-color-file-row-bg` | `rgba(255, 193, 145, 0.05)` | Fond d'une ligne de fichier actif |
| `--ds-color-file-row-border` | `rgba(215, 99, 11, 0.2)` | Bordure d'une ligne de fichier actif |

## Typographie

`--ds-font-family-heading` et `--ds-font-family-body` sont définies dans
`fonts.css` (voir section Polices ci-dessous).

| Rôle | Variables | Famille | Graisse | Taille | Line-height | Usage maquette |
|---|---|---|---|---|---|---|
| H1 | `--ds-font-size-h1` / `--ds-line-height-h1` / `--ds-font-weight-h1` | DM Sans | Bold (700) | 32px | 40px | Logo « DataShare » (header) |
| H2 | `--ds-font-size-h2` / `--ds-line-height-h2` / `--ds-font-weight-h2` | DM Sans | Bold (700) | 28px | 40px | Titres de carte (« Connexion », « Ajouter un fichier », « Mes fichiers ») |
| XLarge | `--ds-font-size-xlarge` / `--ds-line-height-xlarge` / `--ds-font-weight-xlarge` | DM Sans | Light (300) | 30px | 40px | Accroche page d'accueil |
| Normal (corps) | `--ds-font-size-body` / `--ds-line-height-body` / `--ds-font-weight-body` | Inter | Regular (400) | 16px | 24px | Labels de champ, paragraphes, copyright |
| Input | `--ds-font-size-input` / `--ds-line-height-input` / `--ds-font-weight-input` | DM Sans | Regular (400) | 16px | 16px | Valeur/placeholder des inputs, libellés de bouton |
| Small | `--ds-font-size-small` / `--ds-line-height-small` / `--ds-font-weight-small` | DM Sans | Regular (400) | 14px | 16px | Métadonnées (taille fichier, callouts, expiration) |
| Accent | `--ds-font-size-accent` / `--ds-line-height-accent` / `--ds-font-weight-accent` | DM Sans | SemiBold (600) | 16px | 24px | Noms de fichiers et utilisateur (Mon espace) |

## Formes

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-radius-card` | 16px | Cartes login / upload / download |
| `--ds-radius-button` | 8px | Tous variants de bouton |
| `--ds-radius-input` | 8px | Input / select |
| `--ds-radius-callout` | 8px | Callouts info / warning / error |
| `--ds-radius-pill` | 24px | Switch filtre (forme pilule) |
| `--ds-shadow-card` | `0px 0px 12px 0px rgba(0, 0, 0, 0.25)` | Ombre des cartes |
| `--ds-border-width` | 1px | Épaisseur de bordure standard (inputs, boutons, callouts, switch) |

## Espacements

Échelle relevée dans la maquette (pas une échelle inventée) :

| Variable | Valeur | Usage maquette |
|---|---|---|
| `--ds-space-xs` | 8px | Label → input, boutons empilés, padding callout, icône ↔ texte bouton |
| `--ds-space-sm` | 12px | Padding bouton medium, padding horizontal d'un input |
| `--ds-space-md` | 16px | Écart entre champs de formulaire, padding header, padding ligne fichier |
| `--ds-space-lg` | 24px | Padding de carte, écart titre → champs |
| `--ds-size-control-height` | 40px | Hauteur des inputs / select |

## Breakpoint

`--ds-breakpoint-desktop: 768px` — **valeur de projet (US03), non mesurée
depuis Figma**. Les frames de la maquette sont en 393px (mobile, gabarit
iPhone 16) et 1440px (desktop) ; aucune valeur 768px n'existe dans le fichier
Figma. Une media query ne pouvant pas consommer une variable CSS, la valeur
768px doit être répétée littéralement dans chaque `@media (min-width: 768px)`
du lot CSS final — la variable ne sert que de référence documentaire.

## Polices

Aucune des deux familles utilisées (DM Sans, Inter) n'est une police système.
Arbitrage retenu : **fichiers locaux** (auto-hébergement), pas de CDN Google
Fonts, pour éviter toute requête tierce.

- Fichiers dans `frontend/src/assets/fonts/`, format woff2 uniquement (support
  navigateur suffisant pour ce projet), sous-ensemble « latin » (couvre tous
  les caractères accentués français, compris dans Latin-1 Supplement) :
  - `dm-sans-300.woff2`, `dm-sans-400.woff2`, `dm-sans-600.woff2`,
    `dm-sans-700.woff2`
  - `inter-400.woff2`
- Déclarations `@font-face` (`font-display: swap`) et variables
  `--ds-font-family-heading` (DM Sans + repli système) /
  `--ds-font-family-body` (Inter + repli système) dans
  `frontend/src/assets/styles/fonts.css`.
- Fichiers récupérés depuis Google Fonts (`fonts.gstatic.com`), même contenu
  binaire que le CDN — seul le mode de service change (auto-hébergé vs CDN).

### Polices — provenance et licence

URL source exacte de chacun des 5 fichiers woff2 :

| Fichier | URL source |
|---|---|
| `dm-sans-300.woff2` | `https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwA_JxRSW32.woff2` |
| `dm-sans-400.woff2` | `https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwAopxRSW32.woff2` |
| `dm-sans-600.woff2` | `https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwAfJtRSW32.woff2` |
| `dm-sans-700.woff2` | `https://fonts.gstatic.com/s/dmsans/v17/rP2tp2ywxg089UriI5-g4vlH9VoD8CmcqZG40F9JadbnoEwARZtRSW32.woff2` |
| `inter-400.woff2` | `https://fonts.gstatic.com/s/inter/v20/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hiA.woff2` |

DM Sans et Inter sont toutes deux distribuées sous licence **SIL Open Font
License 1.1**. Chaque fichier est un sous-ensemble « latin » uniquement (pas
la police complète), d'un poids de ~14-24 Ko.

## Anomalies constatées

Écarts relevés dans la maquette Figma, non corrigés ici (aucune valeur
inventée) : à faire trancher par le designer, pas de token créé pour ces cas.

- **Dégradé du switch sélectionné (drawer mobile "Mon espace", nœud
  `#27:553`)** : ce segment utilise le dégradé de fond
  (`linear-gradient(135deg, #FFB88C 0%, #DE6262 100%)`) alors que toutes les
  autres occurrences du même composant (Composants UI, Téléversement, Login,
  Mon espace desktop) utilisent une couleur pleine `#E77A6E`. L'angle (135deg)
  diffère en plus de celui du fond de page (149deg). Traité comme une erreur
  ponctuelle de maquette : `--ds-color-switch-selected` retient la couleur
  pleine `#E77A6E`, majoritaire.
- **Texte du footer copyright, « Mon espace » desktop (nœud `#15:393`)** :
  utilise `#F1E9E2` alors que le même footer utilise `#FFFFFF` partout ailleurs
  (Téléversement, Login, Téléchargement, Mon espace mobile). Traité comme une
  erreur ponctuelle : `--ds-color-text-inverse` retient `#FFFFFF`.
- **Contraste `--ds-color-accent` (#E27F29) sur fond blanc**, utilisé comme
  texte des boutons Tertiary/Secondary (« Créer un compte », « J'ai déjà un
  compte », etc.) : ratio approximatif ~2,7:1, sous le seuil WCAG AA texte
  normal (4,5:1) et sous le seuil UI/large text (3:1). Valeur de maquette non
  modifiée ici — à faire trancher par le designer.
- **Contraste `--ds-color-accent-strong` (#BA681F) sur `--ds-color-accent-soft`**
  (texte des boutons Primary sur leur fond teinté) : ratio approximatif
  ~3,3:1, à la limite du seuil UI/large text (3:1) et sous le seuil texte
  normal (4,5:1). Valeur de maquette non modifiée ici — à faire trancher par
  le designer.

## Dérivations hors maquette (lot CSS final)

États ou valeurs absents de la maquette, sans variante Figma échantillonnée.
Dérivations validées (arbitrage du 2026-08-14) :

- **État Error visuel des inputs/select** : la propriété de composant
  `Has Error: true` est posée sur une instance (Select « Expiration », nœud
  `#9:194`) mais aucune variante stylée distincte (bordure/texte rouges) n'a
  été observée dans les données Figma récupérées — le variant « Error » du
  component set `Input Field` (`#1:379`) n'a pas été échantillonné visuellement.
  **Retenu** : réutilisation telle quelle de `--ds-color-error-border`
  (bordure d'input en erreur) et `--ds-color-error-text` (message d'erreur),
  déjà définis pour les callouts. Implémenté en CSS pur via
  `.form-field:has(.form-error) input`, sans classe conditionnelle ajoutée au
  template.
- **États hover / focus** (boutons et inputs) : uniquement Default et Disabled
  présents dans la maquette, aucun état hover/focus trouvé.
  **Retenu** : focus visible via `:focus-visible { outline: 2px solid
  var(--ds-color-accent-border); outline-offset: 2px; }` (jamais `:focus`
  seul, pour ne pas afficher l'anneau au clic souris). Hover des boutons par
  dérivation mécanique de l'existant, sans nouvelle couleur : `filter:
  brightness(0.95)` sur Primary, `filter: brightness(1.15)` sur Dark, et
  `color-mix(in srgb, var(--ds-color-accent) 8%, transparent)` comme fond de
  survol pour Tertiary (fond transparent par défaut).
- **Halo du bouton upload rond (accueil)** : voir § Bouton upload rond
  ci-dessus — contrairement aux deux points précédents, ce cas a donné lieu à
  un nouveau token (`--ds-color-upload-halo`) plutôt qu'à une dérivation
  calculée, la valeur Figma étant disponible et fidèle prévalant sur une
  approximation.
- **Breakpoint 768px** : voir section dédiée ci-dessus — valeur de projet, pas
  une mesure Figma.
