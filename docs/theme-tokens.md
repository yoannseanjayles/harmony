# Harmony — Référence des tokens CSS `--hm-`

> HRM-T176 — Document de référence du thème cinématique par défaut (HRM-F21).
>
> Tous les tokens CSS du thème utilisent le préfixe `--hm-`.
> Ils sont définis dans `public/theme/harmony.css` et constituent
> le contrat visuel stable de la plateforme.
>
> **Règle absolue** : ne jamais créer de variable CSS sans le préfixe `--hm-`.
> Ne jamais appliquer de style via un attribut `style` généré dynamiquement par l'IA.

---

## Couleurs

### Fonds

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-bg` | `#0c0c14` | Fond de page principal (très sombre, bleu-noir cinématique) |
| `--hm-surface` | `rgba(240, 238, 255, 0.05)` | Surface de composant semi-transparente (cards, panneaux) |
| `--hm-surface-strong` | `rgba(240, 238, 255, 0.08)` | Surface de composant plus opaque (cards mises en avant) |
| `--hm-surface-muted` | `rgba(240, 238, 255, 0.03)` | Surface très atténuée (zones secondaires) |

### Texte (Ink)

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-ink` | `#e8e4ff` | Couleur de texte principale (blanc légèrement teinté violet) |
| `--hm-ink-soft` | `rgba(232, 228, 255, 0.58)` | Texte secondaire, légendes, descriptions |

### Accents

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-accent-primary` | `#7c3aed` | Accent principal — violet — CTA, éléments actifs, halos |
| `--hm-accent-primary-deep` | `#5b21b6` | Variante sombre du violet — survol, ombres colorées |
| `--hm-accent-secondary` | `#10b981` | Accent secondaire — émeraude — labels, dots timeline, stats values |
| `--hm-accent-secondary-deep` | `#059669` | Variante sombre du vert |
| `--hm-accent` | `var(--hm-accent-primary)` | Alias canonique de l'accent principal |
| `--hm-accent-deep` | `var(--hm-accent-primary-deep)` | Alias canonique de la variante sombre |
| `--hm-accent-soft` | `rgba(124, 58, 237, 0.12)` | Fond atténué violet (badges, focus ring, surfaces légères) |

### Statuts

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-danger` | `#ef4444` | Erreur, alerte danger |
| `--hm-success` | `#10b981` | Succès, confirmation |

### Bordures et ombres

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-border` | `rgba(124, 58, 237, 0.16)` | Bordure standard des composants (teintée violet) |
| `--hm-shadow` | `0 24px 70px rgba(0,0,0,0.55)` | Ombre portée des cards et panneaux flottants |

---

## Typographie

### Familles de polices

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-font-body` | `"Inter", "Segoe UI", system-ui, sans-serif` | Corps de texte, UI, labels |
| `--hm-font-title` | `Georgia, "Times New Roman", serif` | Titres dramatiques, citations, headlines de slides |
| `--hm-font-mono` | `"JetBrains Mono", "Fira Code", monospace` | Code, valeurs techniques |

### Graisses

| Token | Valeur | Usage |
|---|---|---|
| `--hm-font-weight-normal` | `400` | Texte courant |
| `--hm-font-weight-medium` | `500` | Accentuation légère |
| `--hm-font-weight-bold` | `700` | Titres, labels, CTA |

### Espacement des lettres

| Token | Valeur | Usage |
|---|---|---|
| `--hm-letter-spacing-label` | `0.12em` | Labels uppercase, kickers |
| `--hm-letter-spacing-tight` | `-0.02em` | Grands titres (resserrement cinématique) |

### Échelle de taille

| Token | Valeur | Usage |
|---|---|---|
| `--hm-font-size-label` | `0.82rem` | Petits labels, captions, kickers |
| `--hm-font-size-body` | `1rem` | Corps de texte standard |
| `--hm-font-size-subheading` | `clamp(1.1rem, 1.8vw, 1.4rem)` | Sous-titres de sections |
| `--hm-font-size-heading` | `clamp(1.8rem, 3.5vw, 2.8rem)` | Titre principal d'une slide content/stats/timeline |
| `--hm-font-size-title` | `clamp(2.4rem, 5vw, 4.2rem)` | Grand titre de slide title |
| `--hm-font-size-stat` | `clamp(2rem, 4vw, 3.2rem)` | Valeur numérique d'une stat |
| `--hm-font-size-quote` | `clamp(1.6rem, 3.5vw, 2.4rem)` | Texte d'une citation |
| `--hm-font-size-subtitle` | `1.2rem` | Sous-titre de la slide title |
| `--hm-font-size-closing` | `clamp(1.6rem, 3.5vw, 2.6rem)` | Message de clôture |

---

## Espacement

| Token | Valeur | Usage |
|---|---|---|
| `--hm-space-xs` | `4px` | Espacement minimal, icônes |
| `--hm-space-sm` | `8px` | Petits gaps, padding interne |
| `--hm-space-md` | `16px` | Espacement standard entre éléments |
| `--hm-space-lg` | `24px` | Sections, groupes de composants |
| `--hm-space-xl` | `40px` | Grandes sections, espacement de page |
| `--hm-space-2xl` | `64px` | Espacement macro (haut de page, zones hero) |

---

## Rayons de bordure

| Token | Valeur | Usage |
|---|---|---|
| `--hm-radius-sm` | `8px` | Petits éléments (tags, badges) |
| `--hm-radius-md` | `16px` | Inputs, boutons, champs de formulaire |
| `--hm-radius-lg` | `24px` | Cards moyennes, panneaux |
| `--hm-radius-xl` | `32px` | Cards larges, conteneurs principaux |
| `--hm-radius-full` | `9999px` | Éléments circulaires / pills |

---

## Tokens slides

Ces tokens contrôlent le rendu visuel de chaque slide.
Ils peuvent être surchargés par le `themeJson` du projet (Feature 22+).

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-slide-bg` | `var(--hm-bg)` | Fond de la slide |
| `--hm-slide-fg` | `var(--hm-ink)` | Couleur de texte principale de la slide |
| `--hm-slide-fg-soft` | `var(--hm-ink-soft)` | Couleur de texte secondaire de la slide |
| `--hm-slide-padding` | `3rem 4rem` | Padding interne de la slide (shorthand) |
| `--hm-slide-padding-x` | `4rem` | Padding horizontal (pour captions, lignes) |
| `--hm-surface-invert-muted` | `rgba(232, 228, 255, 0.06)` | Surface atténuée sur fond sombre (cellules stats) |
| `--hm-surface-invert-border` | `rgba(232, 228, 255, 0.10)` | Bordure sur fond sombre (séparateurs timeline) |

---

## Animations

Les animations sont définies comme `@keyframes hm-*` et exposées via des classes utilitaires.

### Classes d'animation

| Classe | `@keyframes` | Description |
|---|---|---|
| `.hm-anim-fade-in` | `hm-fadeIn` | Entrée en fondu (opacité 0→1) |
| `.hm-anim-slide-up` | `hm-slideUp` | Montée depuis le bas avec fondu |
| `.hm-anim-phone-in` | `hm-phoneIn` | Entrée ressort depuis le bas (élastique) |
| `.hm-anim-float` | `hm-float` | Lévitation continue douce (infinie) |
| `.hm-anim-glint` | `hm-glint` | Balayage scintillant (reflet de lumière, infini) |
| `.hm-anim-text-in` | `hm-textIn` | Effondrement de l'espacement lettres avec fondu |
| `.hm-anim-line-pop` | `hm-linePop` | Expansion horizontale d'une ligne depuis la gauche |

### Variables de contrôle des animations

| Token | Valeur par défaut | Usage |
|---|---|---|
| `--hm-anim-duration` | `0.55s` | Durée des animations d'entrée |
| `--hm-anim-delay` | `0s` | Délai avant déclenchement |
| `--hm-anim-easing` | `cubic-bezier(0.22, 1, 0.36, 1)` | Courbe d'accélération (ease-out express) |

> **Accessibilité** : toutes les animations décoratives sont désactivées si
> `prefers-reduced-motion: reduce` est actif.

---

## Composant Navigation Dots

Utilisé pour la navigation entre slides dans la vue de présentation.

```html
<nav class="hm-nav-dots" aria-label="Navigation entre slides">
  <button class="hm-nav-dot hm-nav-dot--visited" aria-label="Slide 1"></button>
  <button class="hm-nav-dot hm-nav-dot--active"  aria-label="Slide 2 (active)"></button>
  <button class="hm-nav-dot"                      aria-label="Slide 3"></button>
</nav>
```

| Classe | Description |
|---|---|
| `.hm-nav-dots` | Conteneur flex centré de la barre de points |
| `.hm-nav-dot` | Dot individuel (état par défaut : inactif) |
| `.hm-nav-dot--active` | Dot de la slide courante (largeur étendue, couleur accent) |
| `.hm-nav-dot--visited` | Dot d'une slide déjà visitée (accent atténué) |

---

## Halos radiaux (fond cinématique)

Les éléments `.hm-slide` reçoivent automatiquement deux halos via `::before` et `::after` :

- **Violet (haut-gauche)** — `rgba(124, 58, 237, 0.18)`, flou 40px
- **Émeraude (bas-droite)** — `rgba(16, 185, 129, 0.12)`, flou 40px

Le fond de page reprend le même principe via `radial-gradient` sur `<body>`.

---

*Harmony Theme Tokens v1.0 — HRM-F21 — Auteur : Yoyo — Avril 2026*
