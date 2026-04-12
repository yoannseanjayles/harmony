# Harmony — Copilot Instructions

> Ce fichier définit les règles métier critiques, les conventions d'architecture et les patterns obligatoires du projet Harmony. GitHub Copilot doit les respecter dans **toutes** ses suggestions.

---

## 🧠 Contexte projet

Harmony est une plateforme web de création de présentations pilotée par IA conversationnelle.
- L'utilisateur décrit sa présentation en **langage naturel** via un chat
- L'IA génère du **JSON structuré** (jamais du HTML)
- Le backend Symfony transforme ce JSON en slides HTML via **templates Twig exclusivement**
- Le rendu applique un **style cinématique premium** via tokens CSS `--hm-`

---

## 🚨 Règles absolues — NE JAMAIS violer

### IA & Génération
- ❌ **JAMAIS** faire générer du HTML, CSS ou JavaScript par le LLM
- ❌ **JAMAIS** insérer directement une réponse LLM dans le DOM ou en base sans validation
- ❌ **JAMAIS** persister une slide dont le JSON n'a pas été validé par `ResponseValidator`
- ✅ Le LLM retourne **uniquement** des objets JSON conformes au contrat de sortie
- ✅ Toute réponse LLM passe par `ResponseValidator::validate()` avant tout usage
- ✅ En cas de JSON invalide : tentative de réparation locale → retry unique → rejet + log

### Rendu & Templates
- ❌ **JAMAIS** construire du markup HTML par concaténation de strings
- ❌ **JAMAIS** utiliser `|raw` dans Twig sur une donnée issue du LLM sans sanitization préalable
- ✅ Toute slide est rendue via `SlideBuilder` → template Twig dans `templates/slides/`
- ✅ Les zones texte enrichi autorisées passent obligatoirement par le sanitizer dédié
- ✅ Le thème s'applique **uniquement** via les tokens CSS `--hm-` — jamais via style inline généré par l'IA

### Sécurité
- ❌ **JAMAIS** stocker une clé API en clair en base de données
- ❌ **JAMAIS** exposer une clé API dans les logs, les réponses JSON ou les templates
- ❌ **JAMAIS** faire confiance à un `project_id` ou `slide_id` sans vérification d'autorisation
- ✅ Toutes les clés API sont chiffrées via libsodium (`sodium_crypto_secretbox`) avant persistance
- ✅ Chaque endpoint mutable possède une protection CSRF
- ✅ Chaque accès à un projet vérifie que `project.userId === currentUser.id`
- ✅ Les liens publics sont signés avec expiration

### Export PDF
- ❌ **JAMAIS** utiliser Puppeteer/headless Chrome directement dans l'application en production
- ✅ L'export PDF délègue obligatoirement à **Gotenberg** ou **Browserless** (service externe)
- ✅ Si le PDF échoue : proposer l'export HTML immédiatement + statut d'échec détaillé + bouton de relance
- ✅ Puppeteer est autorisé **uniquement** en environnement de développement local

---

## 🏗️ Architecture & Patterns

### Structure des services
```text
src/
  Service/
    ChatEngine.php          # Orchestration conversation + SSE
    SlideBuilder.php        # JSON validé → HTML Twig (source unique de markup)
    ThemeEngine.php         # Tokens visuels, presets, overrides utilisateur
    ExportService.php       # Assemblage + délégation PDF externe
    MediaService.php        # Upload, stockage, variantes, URLs signées
    AI/
      AIProviderInterface.php   # Contrat stable — toujours implémenter cette interface
      ProviderFactory.php       # Sélection provider selon config projet/user
      ClaudeProvider.php        # Transport + streaming + parsing Claude
      OpenAIProvider.php        # Transport + streaming + parsing OpenAI
      ResponseValidator.php     # Validation JSON Schema stricte
      RetryPolicy.php           # Logique retry unique avec consigne de correction
      PromptBuilder.php         # Construction des prompts système et utilisateur
```

### Pattern multi-provider IA
- Toujours passer par `ProviderFactory` pour instancier un provider — jamais `new ClaudeProvider()` directement
- Tout nouveau provider doit implémenter `AIProviderInterface`
- Le provider et le modèle utilisés sont **toujours persistés sur l'entité `Project`** pour garantir la reproductibilité

### Contrat de sortie JSON — Actions valides
```json
{
  "action": "add_slide | update_slide | remove_slide | reorder_slides | suggest_question | request_confirmation",
  "type": "title | content | split | image | quote | timeline | stats | comparison | closing",
  "position": 1,
  "data": {}
}
```
- Tout `type` non présent dans la liste ci-dessus → **rejet immédiat + log**
- Tout champ absent ou valeur hors borne → **rejet immédiat + demande de régénération partielle**

---

## 💾 Cache & Performance

### Clé de cache slide (déterministe)
```php
// Toujours calculer la clé de cache ainsi :
$cacheKey = hash('sha256',
    $contentJsonNormalized .
    $themeJsonNormalized .
    $templateVersion .
    $assetsVersion
);
```
- ✅ Stocker le `renderHash` sur l'entité `Slide`
- ✅ Invalider le cache uniquement si `contentJson`, `themeJson`, le template ou les médias référencés changent
- ✅ Redis en production, filesystem en développement
- ❌ Ne jamais invalider l'intégralité du cache sur une micro-édition

### Médias
- URLs signées pour l'édition courante
- Inline base64 **uniquement** pour l'export HTML single-file
- Toujours stocker les références médias dans `mediaRefsJson` sur l'entité `Slide`

---

## 🎨 Règles visuelles & Thème

### Tokens CSS
- Tous les tokens CSS du thème utilisent le préfixe `--hm-` (ex: `--hm-accent`, `--hm-bg`, `--hm-font-title`)
- ❌ Ne jamais créer de variable CSS sans le préfixe `--hm-`
- ❌ Ne jamais appliquer de style visuel via attribut `style` généré dynamiquement par l'IA

### Animations disponibles
```text
fadeIn | slideUp | phoneIn | float | glint | textIn | linePop
```
- L'activation et la vitesse des animations sont des paramètres utilisateur
- Les orbes, gradients texte, glint et navigation dots sont des composants du thème — pas des décisions du LLM

### Densité slide
- 1 idée principale par slide
- 1 hiérarchie claire
- 1 zone focale dominante
- ❌ Jamais de mur de texte généré

---

## 🔐 Sécurité — Patterns obligatoires

### Chiffrement clés API
```php
// Chiffrement avant persistance
$encrypted = sodium_crypto_secretbox($apiKey, $nonce, $masterKey);
$entity->setApiKeyEncrypted(base64_encode($nonce . $encrypted));

// Déchiffrement à l'usage
$decoded = base64_decode($entity->getApiKeyEncrypted());
$nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$apiKey = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $masterKey);
```

### Vérification d'autorisation (pattern obligatoire)
```php
// Dans chaque controller accédant à un projet
$project = $this->projectRepository->find($projectId);
if (!$project || $project->getUser() !== $this->getUser()) {
    throw $this->createAccessDeniedException();
}
```

### Rate limiting
- Appliquer sur tous les endpoints IA : par utilisateur ET par IP
- Journaliser les dépassements de quota, erreurs d'auth, retries IA et accès aux projets partagés

---

## 📡 Streaming SSE

```php
// Pattern SSE dans ChatController
return new StreamedResponse(function () use ($chatEngine, $message) {
    $chatEngine->streamResponse($message, function (string $event, mixed $data) {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    });
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
    'X-Accel-Buffering' => 'no',
]);
```

---

## 🧪 Conventions de test

- Tester `ResponseValidator` avec des fixtures JSON valides ET invalides
- Mocker `AIProviderInterface` — ne jamais appeler un vrai provider en test
- Tester le fallback HTML quand le service PDF est indisponible
- Tester la vérification d'autorisation sur chaque endpoint projet
- Nommer les tests : `test[Comportement]_[Condition]_[RésultatAttendu]()`

---

## 📋 Entités principales

| Entité | Champs clés |
|---|---|
| `Project` | `id`, `userId`, `provider`, `model`, `apiKeyEncrypted`, `themeJson` |
| `Slide` | `id`, `projectId`, `contentJson`, `htmlCache`, `renderHash`, `mediaRefsJson`, `position` |
| `ChatMessage` | `id`, `projectId`, `role`, `content`, `tokensUsed`, `createdAt` |
| `Theme` | `id`, `name`, `tokensJson`, `version` |
| `MediaAsset` | `id`, `projectId`, `storageKey`, `mimeType`, `variants` |

---

## ⚠️ Erreurs courantes à éviter

| ❌ À ne pas faire | ✅ À faire à la place |
|---|---|
| `echo $llmResponse;` | Valider avec `ResponseValidator` puis passer par `SlideBuilder` |
| `new ClaudeProvider()` | `ProviderFactory::create($project->getProvider())` |
| `style="color: {{ llm_color }}"` | Token CSS `--hm-accent` via ThemeEngine |
| `$slide->setHtml($llmOutput)` | `$slide->setContentJson($validated)` + `SlideBuilder::render()` |
| Puppeteer en production | Déléguer à Gotenberg via `ExportService` |
| Clé API en clair en log | Masquer avec `substr($key, 0, 8) . '****'` |
| Accès projet sans vérif | Toujours vérifier `project->getUser() === currentUser` |

---

## 🗺️ Roadmap & Priorités

- **Phase 1** : Chat Engine + Slide Builder (types simples) + SSE streaming
- **Phase 2** : Theme Engine + Media Engine + export HTML single-file
- **Phase 3** : Types complexes (timeline, stats, comparison) + export PDF Gotenberg
- **Phase 4** : Project Manager complet + partage + versioning

> Les types de slides complexes (`timeline`, `stats`, `comparison`) ne doivent pas être implémentés avant la stabilisation du moteur de rendu en Phase 2.

---

*Harmony v1.1 — Auteur : Yoyo — Cadrage consolidé avril 2026*
*Ce fichier fait autorité sur toute suggestion Copilot en cas de conflit avec un pattern générique.*
