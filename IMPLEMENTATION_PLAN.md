# Content-Pipeline Ausbauplan

> Erstellt: 2026-09-01 | Modell: Claude Sonnet 4.6 (Analyse) + Qwen3 (Umsetzung)
> Vorgehen: 4 iterative Phasen, je mit eigenem Review-Punkt.

---

## Ist-Zustand (verifiziert, Stand 2026-09-01)

| Bereich | Status |
|---|---|
| Scoring | 4 LLM-Kriterien (1–3 Pkt.), `ranking_score` = Summe (max 12), kein Judge-Pattern, **keine Begründung gespeichert**, kein Auto-Approve |
| Duplikate | **Nicht vorhanden** – weder Laravel noch Python |
| Persona-Modell | `tonality{style,do,dont}`, `content_attributes{maxLength,formats,tone,keywords}`, `voice` (Freitext) – **fehlt:** Vokabular-Verbotsliste, Satzlänge, Emoji-Nutzung, Ich-Perspektive |
| Prompt-Architektur | System/User getrennt ✓, Persona/Strategie dynamisch injiziert ✓ – **fehlt:** Few-Shot, Brand-Voice in `production_agent.py` teilweise hardcoded (Zeilen 51–55) |
| Generierung | `ContentController::produzieren()` erzeugt **genau 1 ContentItem** pro Aufruf – keine Varianten, kein A/B |
| Guardrails | `enforceTone()` entfernt verbotene Phrasen stumm ✓ – `checkToneViolations()` hat **Bug** (undefinierte Variable `$strategy` statt `$unit`, Zeile ~184) und wird **nirgends aufgerufen** |
| Kosten-Tracking | `EdenAIWebService::log()` für Search/Crawl ✓ – `generateContentWithLLM()` loggt **nichts** |
| DB | PostgreSQL 17 (docker-compose.yml) → `pgvector` verfügbar |
| LLM-Provider | EdenAI (bestehender Key in `settings.llm_keys.edenai_key`) |

---

## Phase 1 — Idea Scoring: Judge-Pattern + Duplikat-Check + Auto-Approve

### Ziel
- LLM gibt Begründung zu jedem Score-Kriterium zurück (Transparenz)
- Ähnliche/doppelte Angles werden erkannt und geflaggt (kein Hard-Block)
- Angles werden ab konfigurierbarem Score-Schwellenwert automatisch auf `approved` gesetzt
- Bug in Guardrails beheben, Verletzungen im Response sichtbar machen

---

### Schritt 1.1 — Migration: pgvector + neue Angle-Felder

**Datei (neu erstellen):** `database/migrations/2026_09_01_000001_add_scoring_fields_to_angles_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pgvector-Extension aktivieren (idempotent)
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::table('angles', function (Blueprint $table) {
            // Embedding-Vektor (OpenAI text-embedding-3-small = 1536 dim)
            // Roher DDL nötig, da Laravel Blueprint kein vector-Typ kennt
        });

        // Vektor-Spalte per Raw-DDL
        DB::statement('ALTER TABLE angles ADD COLUMN IF NOT EXISTS embedding vector(1536)');

        // HNSW-Index für Cosine-Similarity
        DB::statement('CREATE INDEX IF NOT EXISTS angles_embedding_hnsw
            ON angles USING hnsw (embedding vector_cosine_ops)');

        Schema::table('angles', function (Blueprint $table) {
            $table->text('score_reasoning')->nullable()->after('ranking_rang');
            $table->string('duplicate_of_id')->nullable()->after('score_reasoning');
            $table->float('similarity_score')->nullable()->after('duplicate_of_id');

            $table->foreign('duplicate_of_id')
                ->references('id')->on('angles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('angles', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropColumn(['score_reasoning', 'duplicate_of_id', 'similarity_score']);
        });
        DB::statement('DROP INDEX IF EXISTS angles_embedding_hnsw');
        DB::statement('ALTER TABLE angles DROP COLUMN IF EXISTS embedding');
    }
};
```

**Ausführen:**
```bash
php artisan migrate
```

---

### Schritt 1.2 — Neuer Service: `EmbeddingService`

**Datei (neu erstellen):** `app/Services/EmbeddingService.php`

```php
<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Erzeugt Embeddings via EdenAI und findet ähnliche Vektoren via pgvector.
 *
 * EdenAI-Endpoint: POST https://api.edenai.run/v2/text/embeddings
 * Body: { "providers": "openai", "texts": ["..."], "response_as_dict": true }
 * Response: { "openai": { "items": [{ "embedding": [0.1, ...] }] } }
 */
class EmbeddingService
{
    private const ENDPOINT = 'https://api.edenai.run/v2/text/embeddings';
    private const PROVIDER = 'openai';
    private const SIMILARITY_THRESHOLD = 0.92; // ab hier = Duplikat-Flag

    private function apiKey(): ?string
    {
        return Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
    }

    /**
     * Erzeugt einen Embedding-Vektor für den gegebenen Text.
     *
     * @return float[]|null  Vektor-Array oder null bei Fehler/fehlendem Key
     */
    public function embed(string $text): ?array
    {
        $key = $this->apiKey();
        if (!$key) {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(30)
                ->post(self::ENDPOINT, [
                    'providers'        => self::PROVIDER,
                    'texts'            => [$text],
                    'response_as_dict' => true,
                ]);

            $vector = $response->json(self::PROVIDER . '.items.0.embedding');

            if (!is_array($vector) || count($vector) === 0) {
                Log::warning('EmbeddingService: leerer Vektor', [
                    'response' => $response->body(),
                ]);
                return null;
            }

            return $vector;
        } catch (\Throwable $e) {
            Log::error('EmbeddingService::embed() fehlgeschlagen: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sucht den ähnlichsten Angle innerhalb einer Strategie per Cosine-Similarity.
     *
     * Gibt null zurück, wenn kein Embedding verfügbar oder keine Treffer.
     *
     * @param  float[]  $vector        Embedding des neuen Angles
     * @param  int      $strategyId    Nur Angles dieser Strategie durchsuchen
     * @param  string|null $excludeId  Eigene Angle-ID ausschließen (bei Updates)
     * @return array{id: string, angle: string, similarity: float}|null
     */
    public function findMostSimilar(array $vector, int $strategyId, ?string $excludeId = null): ?array
    {
        // Vektor als pgvector-Literal formatieren: '[0.1,0.2,...]'
        $literal = '[' . implode(',', $vector) . ']';

        $query = \DB::select(
            "SELECT id, angle,
                    1 - (embedding <=> ?::vector) AS similarity
             FROM angles
             WHERE strategy_id = ?
               AND embedding IS NOT NULL
               " . ($excludeId ? "AND id != ?" : "") . "
             ORDER BY embedding <=> ?::vector
             LIMIT 1",
            $excludeId
                ? [$literal, $strategyId, $excludeId, $literal]
                : [$literal, $strategyId, $literal]
        );

        if (empty($query)) {
            return null;
        }

        $row = $query[0];

        if ($row->similarity < self::SIMILARITY_THRESHOLD) {
            return null; // nicht ähnlich genug
        }

        return [
            'id'         => $row->id,
            'angle'      => $row->angle,
            'similarity' => round((float) $row->similarity, 4),
        ];
    }

    public function getSimilarityThreshold(): float
    {
        return self::SIMILARITY_THRESHOLD;
    }
}
```

---

### Schritt 1.3 — `Angle`-Model erweitern

**Datei:** `app/Models/Angle.php`

Änderungen:
1. Neue Felder in `$fillable` + `$casts` ergänzen
2. `updateRanking()` um Auto-Approve-Logik erweitern
3. Neuer Accessor `getAutoApproveThresholdAttribute()` (liest aus Strategy-Config)

```php
// $fillable — ersetze die bestehende Array-Definition:
protected $fillable = [
    'id', 'source_id', 'strategy_id', 'batch_key', 'angle', 'icp',
    'pain_cluster', 'statement_type', 'funnel', 'viscale_phase',
    'r_zielgruppe', 'r_viscale_fit', 'r_schaerfe', 'r_timing',
    'ranking_score', 'ranking_rang', 'status',
    // NEU:
    'score_reasoning', 'duplicate_of_id', 'similarity_score',
];

// $casts — ergänze:
protected $casts = [
    'r_zielgruppe'    => 'integer',
    'r_viscale_fit'   => 'integer',
    'r_schaerfe'      => 'integer',
    'r_timing'        => 'integer',
    'ranking_score'   => 'integer',
    'ranking_rang'    => 'integer',
    'similarity_score' => 'float',  // NEU
];

// updateRanking() — ersetze die bestehende Methode:
public function updateRanking(): void
{
    $this->ranking_score = $this->calculateScore();

    // Auto-Approve: Score-Threshold aus Strategie-Config
    if ($this->ranking_score !== null && $this->status === 'neu') {
        $threshold = $this->strategy?->config['rules']['autoApproveScore'] ?? null;
        if ($threshold !== null && $this->ranking_score >= (int) $threshold) {
            $this->status = 'approved';
        } else {
            $this->status = 'bewertet';
        }
    }

    $this->save();
    static::recalculateRanks($this->strategy_id, $this->batch_key);
}
```

---

### Schritt 1.4 — `AngleController` erweitern

**Datei:** `app/Http/Controllers/Api/AngleController.php`

1. `EmbeddingService` im Constructor injizieren
2. `store()`: nach Create Embedding berechnen + Duplikat-Check
3. `update()`: nach Ranking-Update Embedding neu berechnen falls Angle-Text geändert

```php
// Imports ergänzen:
use App\Services\EmbeddingService;

// Constructor:
public function __construct(
    private ContentRulesService $rulesService,
    private EmbeddingService $embeddingService,  // NEU
) {}

// store() — nach Angle::create(...), vor return:
// Embedding + Duplikat-Check (async wäre besser, sync reicht für V1)
$vector = $this->embeddingService->embed($angle->angle);
if ($vector) {
    // Vektor als pgvector-Literal speichern
    \DB::statement(
        "UPDATE angles SET embedding = ?::vector WHERE id = ?",
        ['[' . implode(',', $vector) . ']', $angle->id]
    );

    // Duplikat-Check gegen andere Angles der Strategie
    $similar = $this->embeddingService->findMostSimilar($vector, $strategy->id, $angle->id);
    if ($similar) {
        $angle->update([
            'duplicate_of_id'  => $similar['id'],
            'similarity_score' => $similar['similarity'],
        ]);
    }
}

// update() — wenn 'angle'-Text geändert wurde, Embedding aktualisieren:
if (isset($validated['angle'])) {
    $vector = $this->embeddingService->embed($angle->angle);
    if ($vector) {
        \DB::statement(
            "UPDATE angles SET embedding = ?::vector WHERE id = ?",
            ['[' . implode(',', $vector) . ']', $angle->id]
        );
        // Duplikat-Check neu auswerten
        $similar = $this->embeddingService->findMostSimilar($vector, $angle->strategy_id, $angle->id);
        $angle->update([
            'duplicate_of_id'  => $similar['id'] ?? null,
            'similarity_score' => $similar['similarity'] ?? null,
        ]);
    }
}
```

---

### Schritt 1.5 — Bug-Fix `ContentRulesService::checkToneViolations()`

**Datei:** `app/Services/ContentRulesService.php`

Zeile ~184: `$strategy->forbidden_patterns` → `$unit->forbidden_patterns`

```php
// Vorher (buggy):
foreach ($strategy->forbidden_patterns as $pattern) {

// Nachher (fix):
foreach ($unit->forbidden_patterns as $pattern) {
```

Methode anschließend in `ContentController::produzieren()` nach `enforceTone()` aufrufen und Verletzungen in die API-Response aufnehmen:

```php
// In ContentController::produzieren(), nach enforceTone():
$violations = $this->rulesService->checkToneViolations($rawContent, $strategy, $strategyCtx);
// ... beim return:
return response()->json([
    'content_item' => $item,
    'tone_violations' => $violations,  // NEU: leeres Array wenn alles OK
]);
```

---

### Schritt 1.6 — Auto-Approve-Threshold in Strategie-UI

**Datei:** `resources/js/Pages/Strategie/Index.vue`

Im Tab `brand_voice` oder neuem eigenen Tab `scoring_rules` folgendes Feld ergänzen:

```vue
<!-- Scoring-Regeln Abschnitt in brand_voice Tab oder eigener Tab -->
<div class="bg-white border border-gray-200 rounded-xl p-6 mt-4">
  <h3 class="text-sm font-semibold text-gray-900 mb-4">Scoring & Auto-Approve</h3>
  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="block text-sm text-gray-900 mb-1 font-medium">
        Auto-Approve ab Score
      </label>
      <input
        type="number" min="1" max="12"
        v-model.number="forms.brand_voice.autoApproveScore"
        class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm"
        placeholder="z.B. 10 (max 12)"
      />
      <p class="text-xs text-gray-500 mt-1">
        Angles ab diesem Score werden automatisch auf „approved" gesetzt.
        Leer lassen = immer manuelle Review.
      </p>
    </div>
  </div>
</div>
```

> `autoApproveScore` wird als Teil von `brand_voice`-Content in `Strategy.config` gespeichert
> und von `Angle::updateRanking()` über `$this->strategy->config['rules']['autoApproveScore']` gelesen.

---

### Schritt 1.7 — angle_agent.py: Judge-Prompt erweitern

**Datei:** `content-agent/agents/angle_agent.py`

Prompt-Ergänzung: Agent gibt für jeden bewerteten Angle zusätzlich ein `score_reasoning`-Feld zurück.

```python
# Im ANGLE_SYSTEM_PROMPT, nach dem bestehenden Wichtig-Abschnitt ergänzen:

"""
## Ausgabeformat für update_angle():
Übergib bei jedem Update IMMER auch score_reasoning mit einer 1-2-sätzen Begründung, z.B.:
  score_reasoning="Trifft B2B-1 präzise (CRM-Datenqualität als Kernproblem). 
                   Schärfe hoch durch kontrarianen Take gegen Tool-Fokus."
"""
```

`update_angle()` in `api_tools.py` muss `score_reasoning` als Parameter akzeptieren:

```python
# In tools/api_tools.py, Funktion update_angle() — neuen Parameter ergänzen:
def update_angle(
    angle_id: str,
    ...
    r_timing: int | None = None,
    score_reasoning: str | None = None,  # NEU
) -> dict:
    """...
    :param score_reasoning: Kurze Begründung der Bewertung (1-2 Sätze)
    """
    payload = {}
    for k, v in [..., ("score_reasoning", score_reasoning)]:
        if v is not None: payload[k] = v
    ...
```

---

### Schritt 1.8 — UI: Duplikat-Badge + Score-Reasoning in Angles

**Datei:** `resources/js/Pages/Angles/Index.vue`

In der Tabellenspalte nach Score:

```vue
<!-- Duplikat-Warnung Badge (in der Tabellen-Row) -->
<td v-if="angle.duplicate_of_id" class="px-2">
  <span class="text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 px-2 py-0.5 rounded-full">
    ⚠ Ähnlich
  </span>
</td>
```

Auf der Detail-Seite (`resources/js/Pages/Angles/Show.vue` — falls vorhanden, sonst neu erstellen):

```vue
<!-- Score-Begründung -->
<div v-if="angle.score_reasoning" class="bg-gray-50 border border-gray-200 rounded-lg p-3 mt-4">
  <p class="text-xs font-semibold text-gray-500 mb-1">Scoring-Begründung</p>
  <p class="text-sm text-gray-700">{{ angle.score_reasoning }}</p>
</div>

<!-- Duplikat-Hinweis -->
<div v-if="angle.duplicate_of_id" class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mt-4">
  <p class="text-xs font-semibold text-yellow-700 mb-1">⚠ Mögliches Duplikat</p>
  <p class="text-sm text-yellow-700">
    Ähnlichkeit {{ Math.round(angle.similarity_score * 100) }}% zu Angle
    <a :href="`/angles/${angle.duplicate_of_id}`" class="underline">{{ angle.duplicate_of_id }}</a>
  </p>
</div>
```

---

### Phase 1 — Checkliste

- [ ] `php artisan migrate` ausgeführt
- [ ] `EmbeddingService` erstellt und getestet (`php artisan tinker` → `app(App\Services\EmbeddingService::class)->embed('Test')`)
- [ ] `Angle::updateRanking()` Auto-Approve getestet
- [ ] Bug `checkToneViolations()` gefixt, `tone_violations` im Response sichtbar
- [ ] `angle_agent.py` mit Reasoning-Prompt updated
- [ ] `update_angle()` in api_tools.py mit `score_reasoning`-Parameter updated
- [ ] UI: Duplikat-Badge + Score-Reasoning sichtbar
- [ ] `php artisan test` → alle Tests grün

---

## Phase 2 — Persona-/Strategie-Struktur + Layer-Trennung

### Voraussetzung: Phase 1 abgeschlossen

### Schritt 2.1 — Migration: neue Persona-Felder

**Datei (neu):** `database/migrations/2026_09_01_000002_add_style_fields_to_personas_table.php`

```php
Schema::table('personas', function (Blueprint $table) {
    $table->jsonb('forbidden_words')->nullable()->after('content_attributes');
    // z.B. ["revolutionär", "Game-Changer", "disruptiv"]

    $table->unsignedTinyInteger('max_sentence_length')->nullable()->after('forbidden_words');
    // Maximale Wörter pro Satz (z.B. 20)

    $table->enum('emoji_usage', ['none', 'light', 'heavy'])->default('none')->after('max_sentence_length');
    // none = kein Emoji, light = 1-2 pro Post, heavy = frei

    $table->enum('perspective', ['ich', 'wir', 'neutral'])->default('ich')->after('emoji_usage');
    // Ich-Form vs. Wir-Form vs. neutral
});
```

---

### Schritt 2.2 — Persona-Modell erweitern

**Datei:** `app/Models/Persona.php`

```php
protected $fillable = [
    ..., // bestehende Felder
    'forbidden_words', 'max_sentence_length', 'emoji_usage', 'perspective',
];

protected $casts = [
    ...,
    'forbidden_words' => 'array',
    'max_sentence_length' => 'integer',
];
```

---

### Schritt 2.3 — Persona-Formular erweitern

**Datei:** `resources/js/Pages/Personas/Index.vue`

Neue Felder im Formular:

```vue
<!-- Stil-Felder -->
<div>
  <label class="block text-sm font-medium mb-1">Perspektive</label>
  <select v-model="form.perspective" class="...">
    <option value="ich">Ich-Form (persönlich)</option>
    <option value="wir">Wir-Form (Unternehmen)</option>
    <option value="neutral">Neutral</option>
  </select>
</div>

<div>
  <label class="block text-sm font-medium mb-1">Emoji-Nutzung</label>
  <select v-model="form.emoji_usage" class="...">
    <option value="none">Keine Emojis</option>
    <option value="light">Sparsam (1–2 pro Post)</option>
    <option value="heavy">Häufig</option>
  </select>
</div>

<div>
  <label class="block text-sm font-medium mb-1">Max. Satzlänge (Wörter)</label>
  <input type="number" min="5" max="50" v-model.number="form.max_sentence_length"
    class="..." placeholder="z.B. 20 (leer = keine Beschränkung)" />
</div>

<div>
  <label class="block text-sm font-medium mb-1">Verbotene Wörter (Persona-spezifisch)</label>
  <div v-for="(w, i) in (form.forbidden_words || [])" :key="i" class="flex gap-2 mb-1">
    <input v-model="form.forbidden_words[i]" class="flex-1 ..." placeholder="z.B. disruptiv" />
    <button @click="form.forbidden_words.splice(i, 1)" class="text-red-500">✕</button>
  </div>
  <button @click="form.forbidden_words = [...(form.forbidden_words || []), '']"
    class="text-sm text-green-600">+ Wort</button>
</div>
```

---

### Schritt 2.4 — Prompt-Layer-Trennung in `buildContentPrompt()`

**Datei:** `app/Http/Controllers/Api/ContentController.php`

Methode `buildContentPrompt()` (~Zeile 299) umstrukturieren in 3 klar kommentierte Sektionen:

```php
private function buildContentPrompt(...): string
{
    $lines = [];

    // ═══ LAYER 1: CONTENT-KERN (Was soll gesagt werden?) ═══
    $lines[] = "## CONTENT-KERN";
    $lines[] = "ANGLE (Kernaussage): {$angle->angle}";
    if ($angle->icp)         $lines[] = "ICP: {$angle->icp}";
    if ($angle->pain_cluster) $lines[] = "Pain-Cluster: {$angle->pain_cluster}";

    // ═══ LAYER 2: STIL-LAYER (Wie soll es klingen?) ═══
    $lines[] = "\n## STIL-LAYER (Persona)";
    if ($personaCtx) {
        $lines[] = "Persona: {$personaCtx['name']} ({$personaCtx['role']})";
        if (!empty($personaCtx['voice']))      $lines[] = "Sprachstil: {$personaCtx['voice']}";
        if (!empty($personaCtx['perspective'])) $lines[] = "Perspektive: {$personaCtx['perspective']}";
        if (!empty($personaCtx['emoji_usage'])) $lines[] = "Emoji: {$personaCtx['emoji_usage']}";
        if (!empty($personaCtx['max_sentence_length'])) {
            $lines[] = "Max. Satzlänge: {$personaCtx['max_sentence_length']} Wörter";
        }
        if (!empty($personaCtx['forbidden_words'])) {
            $lines[] = "VERBOTENE WÖRTER (Persona): " . implode(', ', $personaCtx['forbidden_words']);
        }
        if (!empty($personaCtx['core_statements'])) {
            $lines[] = "Überzeugungen: " . implode(' | ', array_slice($personaCtx['core_statements'], 0, 3));
        }
    }

    // ═══ LAYER 3: ZIEL-LAYER (Kanal, Format, CTA) ═══
    $lines[] = "\n## ZIEL-LAYER (Strategie/Format)";
    // ... bestehende Kanal-Regeln, Brand Voice, Hashtags, CTA etc.

    return implode("\n", $lines);
}
```

---

### Schritt 2.5 — `AgentContextService::describePersona()` erweitern

**Datei:** `app/Services/AgentContextService.php`

Neue Felder in `describePersona()` (~Zeile 118) ausgeben:

```php
// Nach bestehenden Feldern ergänzen:
if (!empty($persona->perspective)) {
    $lines[] = "- Perspektive: {$persona->perspective}";
}
if (!empty($persona->emoji_usage)) {
    $lines[] = "- Emoji-Nutzung: {$persona->emoji_usage}";
}
if (!empty($persona->max_sentence_length)) {
    $lines[] = "- Max. Satzlänge: {$persona->max_sentence_length} Wörter";
}
if (!empty($persona->forbidden_words)) {
    $lines[] = "- Verbotene Wörter: " . implode(', ', $persona->forbidden_words);
}
```

---

### Schritt 2.6 — Hardcoded Brand-Voice in production_agent.py entfernen

**Datei:** `content-agent/agents/production_agent.py`

Zeilen 51–55 (hardcoded Brand-Voice-Regeln wie `"kein 'wir'"`, `"kein 'revolutionär'"`) entfernen.
Diese Daten kommen ausschließlich aus `get_strategy()` → dynamisch.

---

### Phase 2 — Checkliste

- [ ] `php artisan migrate` ausgeführt
- [ ] Persona-Formular zeigt neue Felder
- [ ] `buildContentPrompt()` hat 3 klar getrennte Layer-Sektionen
- [ ] `AgentContextService::describePersona()` gibt neue Felder aus
- [ ] Hardcoded Brand-Voice in `production_agent.py` Zeilen 51–55 entfernt
- [ ] `php artisan test` → alle Tests grün

---

## Phase 3 — Variantengenerierung (3–5 Hooks, A/B-fähig)

### Voraussetzung: Phase 2 abgeschlossen

### Schritt 3.1 — Migration: `variant_group_id` in ContentItems

**Datei (neu):** `database/migrations/2026_09_01_000003_add_variant_group_to_content_items.php`

```php
Schema::table('content_items', function (Blueprint $table) {
    $table->uuid('variant_group_id')->nullable()->after('status');
    $table->string('variant_pattern')->nullable()->after('variant_group_id');
    // variant_pattern: story | listicle | contrarian | question | data_drop

    $table->index('variant_group_id');
});
```

---

### Schritt 3.2 — `ContentController::produzieren()` für Mehrfach-Varianten

**Datei:** `app/Http/Controllers/Api/ContentController.php`

```php
// Validation ergänzen:
$validated = $request->validate([
    ...,
    'variants_count' => 'sometimes|integer|min:1|max:5', // NEU, default 1
    'variant_patterns' => 'sometimes|array',             // NEU: welche Patterns?
    'variant_patterns.*' => 'string|in:story,listicle,contrarian,question,data_drop',
]);

// Generierungslogik:
$variantCount = $validated['variants_count'] ?? 1;
$patterns = $validated['variant_patterns']
    ?? ['contrarian', 'listicle', 'story', 'question', 'data_drop'];

$groupId = $variantCount > 1 ? \Illuminate\Support\Str::uuid()->toString() : null;
$items = [];

foreach (array_slice($patterns, 0, $variantCount) as $pattern) {
    $params['pattern'] = $pattern;
    $content = $this->generateContentWithLLM($angle, $params, $strategy, $strategyCtx, $personaCtx);
    $content = $this->rulesService->enforceTone($content, $params['format'], $strategy, $strategyCtx);

    $item = ContentItem::create([
        ...existing fields...,
        'variant_group_id' => $groupId,
        'variant_pattern'  => $pattern,
    ]);
    $items[] = $item;
}

return response()->json([
    'items'            => $items,  // Array statt single item
    'variant_group_id' => $groupId,
    'tone_violations'  => $violations,
]);
```

---

### Schritt 3.3 — Output-UI: Varianten-Vergleich

**Datei:** `resources/js/Pages/Output/Index.vue` (oder neue Komponente)

```vue
<!-- Varianten-Gruppe anzeigen -->
<div v-if="item.variant_group_id" class="mb-2">
  <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full">
    Variante: {{ item.variant_pattern }}
  </span>
  <button @click="showVariants(item.variant_group_id)"
    class="text-xs text-gray-500 underline ml-2">
    Alle Varianten zeigen
  </button>
</div>

<!-- Side-by-Side Varianten-Modal -->
<div v-if="activeVariantGroup" class="fixed inset-0 z-50 ...">
  <div class="grid grid-cols-2 md:grid-cols-3 gap-4 p-6">
    <div v-for="v in variantItems" :key="v.id"
      class="bg-white border rounded-xl p-4"
      :class="v.status === 'geplant' ? 'border-green-400 ring-2 ring-green-300' : 'border-gray-200'">
      <p class="text-xs font-semibold text-gray-500 mb-2">{{ v.variant_pattern }}</p>
      <p class="text-sm text-gray-900 whitespace-pre-line">{{ v.content }}</p>
      <button @click="selectVariant(v.id)"
        class="mt-3 w-full px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg">
        ✓ Diese Variante wählen
      </button>
    </div>
  </div>
</div>
```

`selectVariant(id)` setzt diese Variante auf `status='geplant'` und alle anderen der Gruppe auf `status='verworfen'` via `PATCH /api/content/{id}`.

---

### Phase 3 — Checkliste

- [ ] `php artisan migrate` ausgeführt
- [ ] `produzieren()` akzeptiert `variants_count` + `variant_patterns`
- [ ] Mehrere ContentItems mit `variant_group_id` werden erstellt
- [ ] Output-UI zeigt Varianten-Badge + Side-by-Side-Modal
- [ ] Varianten-Auswahl setzt Status korrekt
- [ ] `php artisan test` → alle Tests grün

---

## Phase 4 — Prompt-Architektur härten (Few-Shot, Guardrails, Cost-Tracking)

### Voraussetzung: Phase 3 abgeschlossen

### Schritt 4.1 — Migration: `persona_examples`-Tabelle

**Datei (neu):** `database/migrations/2026_09_01_000004_create_persona_examples_table.php`

```php
Schema::create('persona_examples', function (Blueprint $table) {
    $table->id();
    $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
    $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
    $table->string('format');              // linkedin_post, newsletter_bk, etc.
    $table->text('content');              // Der Referenz-Post-Text
    $table->text('why_good')->nullable(); // Kurze Begründung (manuell kuratiert)
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->index(['persona_id', 'format', 'active']);
});
```

---

### Schritt 4.2 — Few-Shot in `buildContentPrompt()` injizieren

**Datei:** `app/Http/Controllers/Api/ContentController.php`

```php
// In buildContentPrompt(), im STIL-LAYER nach Persona-Beschreibung:
if ($personaCtx && !empty($personaCtx['persona_id'])) {
    $examples = \App\Models\PersonaExample::where('persona_id', $personaCtx['persona_id'])
        ->where('format', $format)
        ->where('active', true)
        ->limit(2)
        ->get();

    if ($examples->count() > 0) {
        $lines[] = "\n### Referenz-Beispiele (Few-Shot)";
        foreach ($examples as $i => $ex) {
            $lines[] = "Beispiel " . ($i + 1) . ":";
            $lines[] = "```";
            $lines[] = $ex->content;
            $lines[] = "```";
            if ($ex->why_good) {
                $lines[] = "Warum gut: {$ex->why_good}";
            }
        }
    }
}
```

---

### Schritt 4.3 — Kosten-Tracking für Content-Generierung

**Datei:** `app/Http/Controllers/Api/ContentController.php`

In `generateContentWithLLM()` nach erfolgreichem API-Call:

```php
// Nach $text = $response->json('choices.0.message.content'):
\App\Models\AgentLog::create([
    'agent'       => 'production',
    'provider'    => 'edenai/openai',
    'model'       => 'openai/gpt-4o',
    'input'       => substr($prompt, 0, 2000), // kein Overflow
    'output'      => substr($text ?? '', 0, 2000),
    'status'      => $text ? 'success' : 'error',
    'tokens_used' => $response->json('usage.total_tokens'),
    'duration_ms' => (int) ((microtime(true) - $start) * 1000),
]);
```

`$start = microtime(true)` muss vor dem HTTP-Call gesetzt werden.

---

### Schritt 4.4 — Guardrail: Pflicht-CTA-Check

**Datei:** `app/Services/ContentRulesService.php`

Neue Methode `checkMandatoryCta()`:

```php
/**
 * Prüft ob ein verpflichtender CTA vorhanden ist (aus Strategy.config.rules.mandatoryCta).
 */
public function checkMandatoryCta(string $text, Strategy $unit): bool
{
    $mandatoryCta = $unit->config['rules']['mandatoryCta'] ?? null;
    if (!$mandatoryCta) return true; // kein Pflicht-CTA definiert

    return str_contains(strtolower($text), strtolower($mandatoryCta));
}
```

In `ContentController::produzieren()` prüfen und in Response aufnehmen:

```php
'missing_cta' => !$this->rulesService->checkMandatoryCta($content, $strategy),
```

---

### Schritt 4.5 — Few-Shot-UI in Persona-Verwaltung

**Datei:** neues Komponenten-Tab in `resources/js/Pages/Personas/Index.vue` oder neue Seite

```vue
<!-- Referenz-Posts Tab -->
<div class="space-y-3">
  <div v-for="ex in persona.examples" :key="ex.id"
    class="bg-white border border-gray-200 rounded-xl p-4">
    <div class="flex justify-between mb-2">
      <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{{ ex.format }}</span>
      <button @click="deleteExample(ex.id)" class="text-red-500 text-xs">Entfernen</button>
    </div>
    <p class="text-sm text-gray-900 whitespace-pre-line">{{ ex.content }}</p>
    <input v-model="ex.why_good" class="mt-2 w-full text-xs border rounded px-2 py-1"
      placeholder="Warum ist das ein gutes Beispiel?" />
  </div>
  <button @click="addExample" class="neu-btn-primary px-4 py-2 text-sm">
    + Referenz-Post hinzufügen
  </button>
</div>
```

---

### Phase 4 — Checkliste

- [ ] `persona_examples`-Tabelle migriert + neues Model `PersonaExample` erstellt
- [ ] `buildContentPrompt()` injiziert Few-Shot-Beispiele
- [ ] `generateContentWithLLM()` loggt via `AgentLog`
- [ ] `checkMandatoryCta()` läuft + `missing_cta` im Response
- [ ] UI: Referenz-Posts in Persona-Verwaltung pflegbar
- [ ] `php artisan test` → alle Tests grün

---

## Globale Checkliste

- [ ] Phase 1 Review bestanden
- [ ] Phase 2 Review bestanden
- [ ] Phase 3 Review bestanden
- [ ] Phase 4 Review bestanden
- [ ] Alle Feature-Tests aktualisiert
- [ ] `IMPLEMENTATION_PLAN.md` mit erledigten Punkten abgehakt

---

## Referenz: relevante Dateipfade

```
app/
  Models/
    Angle.php                          # Phase 1: neue Felder + updateRanking()
    Persona.php                        # Phase 2: neue Felder
    ContentItem.php                    # Phase 3: variant_group_id
    Strategy.php                       # Phase 1: autoApproveScore-Accessor
    PersonaExample.php                 # Phase 4: new
  Services/
    EmbeddingService.php               # Phase 1: new
    ContentRulesService.php            # Phase 1: Bug-Fix + Phase 4: mandatoryCta
    AgentContextService.php            # Phase 2: describePersona() erweitern
  Http/Controllers/Api/
    AngleController.php                # Phase 1: Embedding + Duplikat-Check
    ContentController.php              # Phase 2-4: Layer-Trennung, Varianten, Logs

database/migrations/
  2026_09_01_000001_add_scoring_...    # Phase 1: pgvector, score_reasoning, duplicate
  2026_09_01_000002_add_style_...      # Phase 2: Persona-Felder
  2026_09_01_000003_add_variant_...    # Phase 3: variant_group_id
  2026_09_01_000004_create_persona_examples  # Phase 4: Few-Shot-Tabelle

content-agent/
  agents/angle_agent.py               # Phase 1: Judge-Prompt mit Reasoning
  tools/api_tools.py                  # Phase 1: update_angle() + score_reasoning

resources/js/Pages/
  Angles/Index.vue                    # Phase 1: Duplikat-Badge
  Angles/Show.vue                     # Phase 1: Score-Reasoning (ggf. neu erstellen)
  Personas/Index.vue                  # Phase 2: neue Felder + Phase 4: Few-Shot
  Output/Index.vue                    # Phase 3: Varianten-Modal
  Strategie/Index.vue                 # Phase 1: Auto-Approve-Threshold
```
