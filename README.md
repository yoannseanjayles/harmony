# 🎬 Harmony — AI-Powered Presentation Platform

> Plateforme web de création de présentations pilotée par IA conversationnelle.  
> L'utilisateur décrit sa présentation en langage naturel → l'IA génère du JSON structuré → le moteur Twig produit les slides HTML premium.

**Version :** 1.1 · **Statut :** En développement · **Stack :** Symfony · PHP 8.3 · Redis · S3 · Gotenberg

---

## 🧠 Contexte Copilot — Règles absolues

> Ces règles sont non négociables. Copilot doit les respecter dans chaque suggestion.

| Règle | Détail |
|---|---|
| ❌ L'IA ne génère jamais de HTML | Le LLM retourne exclusivement du JSON structuré |
| ✅ Le rendu HTML est produit par Twig uniquement | `templates/slides/*.html.twig` est la seule source de markup |
| ❌ Aucune insertion HTML libre issue du LLM | Toute zone enrichie passe par champs structurés + sanitizer |
| ✅ Les tokens CSS portent le préfixe `--hm-` | Jamais de style inline généré dynamiquement |
| ✅ Toute sortie LLM est validée via JSON Schema | Rejet immédiat si type inconnu, champ absent ou valeur hors borne |
| ✅ Les clés API ne sont jamais stockées en clair | Chiffrement libsodium/OpenSSL obligatoire |
| ✅ Chaque endpoint mutable porte une protection CSRF | Sans exception |
| ✅ Chaque accès à un projet vérifie l'autorisation | Pas de raccourci par ID seul |

---

## 📁 Architecture du projet

```text
harmony/
├── src/
│   ├── Controller/
│   │   ├── ChatController.php        # SSE streaming + orchestration chat
│   │   ├── ProjectController.php     # CRUD projets, duplication, archivage
│   │   ├── ExportController.php      # HTML single-file + PDF via Gotenberg
│   │   ├── ThemeController.php       # Gestion presets et overrides thème
│   │   └── MediaController.php       # Upload, validation MIME, URLs signées
│   │
│   ├── Service/
│   │   ├── ChatEngine.php            # Orchestration conversation + SSE events
│   │   ├── SlideBuilder.php          # JSON validé → HTML via Twig (SEUL autorisé)
│   │   ├── ThemeEngine.php           # Tokens CSS --hm-, presets JSON versionnés
│   │   ├── ExportService.php         # Assemblage slides + délégation PDF
│   │   ├── MediaService.php          # Stockage S3, variantes, base64 export
│   │   └── AI/
│   │       ├── AIProviderInterface.php   # Contrat stable multi-provider
│   │       ├── PromptBuilder.php         # Construction system prompt + contexte
│   │       ├── ProviderFactory.php       # Sélection provider par config projet
│   │       ├── ClaudeProvider.php        # Transport + streaming Anthropic
│   │       ├── OpenAIProvider.php        # Transport + streaming OpenAI
│   │       ├── ResponseValidator.php     # JSON Schema strict sur sortie LLM
│   │       └── RetryPolicy.php           # Retry unique + consigne de correction
│   │
│   └── Entity/
│       ├── User.php
│       ├── Project.php               # Persiste provider + modèle utilisé
│       ├── Slide.php                 # renderHash SHA-256 pour cache déterministe
│       ├── ChatMessage.php
│       ├── Theme.php                 # JSON versionné des tokens --hm-
│       └── MediaAsset.php
│
├── templates/
│   ├── slides/                       # Twig par type : title, content, split...
│   └── export/                       # Templates assemblage HTML single-file
│
├── assets/
│   ├── js/                           # Chat UI, SSE listener, preview live
│   └── css/                          # Tokens --hm-, animations, thème cinématique
│
├── config/
├── migrations/
├── tests/
└── docker/
    ├── gotenberg/                    # Service PDF externalisé
    └── redis/                        # Cache slides
```

---

## 🏗️ Les 6 modules fonctionnels

### 1. Chat Engine — `Service/ChatEngine.php`
- Orchestre la conversation, le contexte projet et le streaming SSE
- Appelle le provider IA actif via `AIProviderInterface`
- Valide la réponse JSON via `ResponseValidator`
- Déclenche les retries si nécessaire (`RetryPolicy`)
- Publie les événements SSE au frontend

### 2. Slide Builder — `Service/SlideBuilder.php`
- **Seule source autorisée de markup HTML**
- Transforme les structures JSON validées en slides via templates Twig
- Centralise la sanitization du rendu
- Calcule le `renderHash` SHA-256 pour le cache

### 3. Theme Engine — `Service/ThemeEngine.php`
- Applique les tokens CSS `--hm-*` par preset ou override utilisateur
- Stocke les thèmes en JSON versionné (reproductibilité garantie)
- Ne délègue jamais au LLM le choix des valeurs visuelles

### 4. Media Engine — `Service/MediaService.php`
- Upload avec validation MIME + taille maximale
- Stockage local (dev) / S3-compatible (prod)
- Génère : miniature, preview, export
- Inline base64 uniquement pour l'export HTML single-file

### 5. Export Engine — `Service/ExportService.php`
- Assemble les slides, injecte le thème, résout les assets
- Délègue le rendu PDF à **Gotenberg** (jamais Puppeteer direct en prod)
- Fallback immédiat sur HTML si le PDF échoue
- Dégradation gracieuse avec statut d'échec détaillé

### 6. Project Manager — `Controller/ProjectController.php`
- CRUD, duplication, archivage, partage, versions
- **Persiste le provider et le modèle IA par projet** (reproductibilité)
- Gère les liens publics signés avec expiration

---

## 🤖 Contrat IA — Actions JSON supportées

```json
{
  "action": "add_slide",
  "type": "title",
  "position": 1,
  "data": {
    "label": "Intro",
    "title": "Titre principal",
    "subtitle": "Sous-titre accrocheur"
  }
}
```

**Actions autorisées :**
`add_slide` · `update_slide` · `remove_slide` · `reorder_slides` · `suggest_question` · `request_confirmation`

**Types de slides :**
`title` · `content` · `split` · `image` · `quote` · `timeline` · `stats` · `comparison` · `closing`

> Tout type inconnu, champ absent ou valeur hors borne → rejet immédiat + régénération partielle demandée.

---

## 🎨 Système de design — Tokens `--hm-`

Le thème cinématique Harmony s'applique **uniquement via CSS custom properties**.

```css
/* Palette principale */
--hm-bg-primary      /* Fond sombre principal */
--hm-accent-violet   /* Accent violet signature */
--hm-accent-green    /* Accent vert secondaire */
--hm-text-primary    /* Texte principal haute luminosité */
--hm-text-muted      /* Sous-titres faible contraste */

/* Animations */
--hm-anim-speed      /* Vitesse globale des animations */
--hm-anim-intensity  /* Intensité des effets */
```

**Animations fondamentales :** `fadeIn` · `slideUp` · `phoneIn` · `float` · `glint` · `textIn` · `linePop`

---

## ⚙️ Stack technique

| Couche | Technologie | Notes |
|---|---|---|
| Backend | Symfony (PHP 8.3) | Monolithique, maintenable solo |
| IA | Claude (Anthropic) + OpenAI | Pattern Strategy — `AIProviderInterface` |
| Streaming | SSE (Server-Sent Events) | Prévisualisation temps réel |
| Cache | Redis (prod) / filesystem (dev) | Clé SHA-256 déterministe par slide |
| Stockage | S3-compatible (prod) / local (dev) | Via `MediaService` |
| Export PDF | Gotenberg / Browserless | Headless Chrome externalisé |
| Rendu slides | Twig uniquement | Jamais de HTML généré par l'IA |
| Sécurité | libsodium / OpenSSL | Chiffrement clés API, CSRF, rate limiting |

---

## 🔐 Sécurité — Conventions obligatoires

```php
// ✅ Toujours vérifier l'appartenance du projet
$this->denyAccessUnlessGranted('PROJECT_ACCESS', $project);

// ✅ Chiffrer les clés API — jamais en clair
$encrypted = $this->encryptor->encrypt($apiKey); // libsodium

// ✅ Valider tout JSON LLM avant persistance
$this->responseValidator->validate($llmOutput); // JSON Schema strict

// ✅ Rate limiting sur les endpoints IA
#[RateLimit(limit: 60, period: 60)]
```

---

## 🗄️ Stockage des assets — Configuration S3 (HRM-F27)

Harmony supporte deux backends de stockage, sélectionnés via `APP_STORAGE_DRIVER` :

| Valeur | Backend | Usage recommandé |
|--------|---------|-----------------|
| `local` (défaut) | Filesystem local (`public/uploads/media/`) | Développement / CI |
| `s3` | S3-compatible (AWS, MinIO, Cloudflare R2…) | Production / staging |

### Variables d'environnement

| Variable | Description | Défaut |
|----------|-------------|--------|
| `APP_STORAGE_DRIVER` | `local` ou `s3` | `local` |
| `HARMONY_S3_BUCKET` | Nom du bucket S3 | *(vide)* |
| `HARMONY_S3_REGION` | Région AWS (ex. `eu-west-3`) | `us-east-1` |
| `HARMONY_S3_ACCESS_KEY_ID` | Access key AWS / service account | *(vide)* |
| `HARMONY_S3_SECRET_ACCESS_KEY` | Secret access key | *(vide)* |
| `HARMONY_S3_ENDPOINT` | Endpoint custom (MinIO, R2…) — laisser vide pour AWS | *(vide)* |

### Configuration AWS S3

```bash
# .env.local (ne jamais versionner)
APP_STORAGE_DRIVER=s3
HARMONY_S3_BUCKET=my-harmony-assets
HARMONY_S3_REGION=eu-west-3
HARMONY_S3_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
HARMONY_S3_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

**Politique IAM minimale** à attacher à l'utilisateur/rôle IAM :

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::my-harmony-assets/*"
    }
  ]
}
```

**Configuration CORS du bucket** (requise pour les pré-signed URLs consultées depuis le navigateur) :

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["GET"],
    "AllowedOrigins": ["https://your-harmony-domain.com"],
    "ExposeHeaders": [],
    "MaxAgeSeconds": 3600
  }
]
```

### Configuration MinIO (développement S3-compatible)

```bash
APP_STORAGE_DRIVER=s3
HARMONY_S3_BUCKET=harmony
HARMONY_S3_REGION=us-east-1
HARMONY_S3_ACCESS_KEY_ID=minioadmin
HARMONY_S3_SECRET_ACCESS_KEY=minioadmin
HARMONY_S3_ENDPOINT=http://localhost:9000
```

---

## 💾 Cache des slides — Stratégie déterministe

```php
// Clé de cache par slide
$cacheKey = hash('sha256',
    $slide->getContentJson() .
    $theme->getThemeJson() .
    $templateVersion .
    $assetsVersion
);
```

- **Dev :** filesystem cache
- **Prod :** Redis
- **Invalidation :** à chaque modification de contenu, thème, template ou assets
- **Pré-chauffage :** pendant la génération et lors des exports

---

## 📊 KPIs de succès

| KPI | Objectif |
|---|---|
| Temps première génération (8 slides) | À définir en phase MVP |
| Taux slides acceptées sans édition | > 70% |
| Itérations chat par présentation | < 5 |
| Coût IA moyen / présentation | Suivi par projet |
| Taux succès export HTML | > 99% |
| Taux succès export PDF | > 95% |
| Taux erreurs JSON / retries | < 5% |
| Satisfaction qualitative utilisateur | Score qualitatif |

---

## 🗺️ Roadmap — 14-15 semaines

| Phase | Contenu clé | Durée |
|---|---|---|
| **Phase 1** | Auth, Project CRUD, Chat Engine basique, SSE, types `title` + `content` | ~3 sem |
| **Phase 2** | Theme Engine, tokens `--hm-`, animations, Media Engine, export HTML | ~3 sem |
| **Phase 3** | Types avancés (`timeline`, `stats`, `comparison`), cache Redis, renderHash | ~3 sem |
| **Phase 4** | Export PDF via Gotenberg, fallback HTML, observabilité, rate limiting | ~3 sem |
| **Phase 5** | Polish UX, KPIs, tests de charge, préparation production | ~2 sem |

---

## ⚠️ Risques et mitigations

| Risque | Mitigation |
|---|---|
| JSON LLM invalide | JSON Schema strict + retry unique + rejet blocs fautifs |
| Timeout provider IA | Fallback modèle rapide + reprise dernière slide validée |
| PDF Puppeteer en prod | Gotenberg externalisé obligatoire + fallback HTML immédiat |
| Fuite clés API | libsodium, jamais en clair, rotation, BYOK séparé |
| Dérive visuelle LLM | IA sans HTML + tokens `--hm-` + Twig + sanitizer |
| Re-render complet | Cache SHA-256 déterministe + invalidation ciblée |
| Scope creep types slides | Introduction progressive par phases |
| Irrepro ductibilité | Provider + modèle persistés par projet + renderHash |
| Charge solo | Roadmap phasée + externalisation PDF + périmètre contrôlé |

---

## 🚀 Démarrage rapide

```bash
# Cloner le repo
git clone https://github.com/yourorg/harmony.git && cd harmony

# Installer les dépendances
composer install && npm install

# Configurer l'environnement
cp .env .env.local
# → Renseigner DATABASE_URL, OPENAI_API_KEY, ANTHROPIC_API_KEY, REDIS_URL

# Lancer les services
docker compose up -d  # Redis + Gotenberg

# Initialiser la base
php bin/console doctrine:migrations:migrate

# Lancer le serveur
symfony server:start
```

---

## 📐 Conventions de code

- **Services** : stateless, injectés via constructor DI, responsabilité unique
- **Controllers** : minces — délèguent aux Services, valident l'autorisation, retournent JSON ou SSE
- **Entities** : pas de logique métier — uniquement mapping Doctrine
- **Templates Twig** : `templates/slides/{type}.html.twig` — un fichier par type de slide
- **Tests** : un test unitaire par Service, tests fonctionnels sur les endpoints critiques (chat, export)
- **Nommage** : `camelCase` PHP, `kebab-case` CSS, `snake_case` JSON API

---

*Harmony v1.1 — Document confidentiel — Auteur : Yoyo — Avril 2026*
