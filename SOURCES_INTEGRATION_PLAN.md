# Source Intelligence System — Integration Plan

**Version:** 2.0 (validiert gegen tatsächliche Code-Basis)  
**Datum:** 2026-09-14  
**Scope:** Erweiterung des bestehenden `Source`-Modells und `SourceMonitorService` zu einem
vollständigen **Source Intelligence System**, das automatisch neuen Input liefert und ihn
in den bestehenden Angle → Post-Pipeline-Trichter einspeist.

---

## 1. Warum überhaupt?

Der Content-Flow heute ist:

```
Angle (manuell oder Quick-Input) → Score → Template wählen → Post generieren → Output
```

Das Problem: **der erste Schritt ist ein Flaschenhals.** Alle 34 Angles stammen aus einem
einzigen manuellen Batch (`quick-20260901`). Das System kann ausgezeichnet Angles verarbeiten
und Posts produzieren — aber es bekommt zu wenig neue Rohmasse.

**Ziel dieses Plans:** Das System soll täglich frischen Input aus verschiedenen Quellen
ziehen, ohne dass du aktiv etwas einfügen musst. Der Nutzer entscheidet nur, welche Angles
er genehmigt (Approval-Queue) — nicht mehr, wie er an die Rohdaten kommt.

---

## 2. Systemarchitektur (Ist → Soll)

### Ist: manuelle Pipeline

```
Nutzer gibt Text/URL ein
        ↓
QuickInputController
        ↓
QuickInputAgentService::extractAngles()   ← GPT-4o via EdenAI
        ↓
Draft-Angles (im UI auswählen)
        ↓
/api/angles/batch  →  angles-Tabelle
```

### Soll: automatisierte Source Intelligence Pipeline

```
┌─────────────────────────────────────────────────────────┐
│              SOURCE INTELLIGENCE SYSTEM                  │
│                                                          │
│  RSS-Feeds ──┐                                           │
│  URLs ───────┤                                           │
│  YouTube ────┤→ SourceIntelligenceService                │
│  Reddit/HN ──┤     ├── fetchContent(source)              │
│  PDF/Upload ─┘     ├── extractItems()                    │
│  OCR-Screenshot    ├── deduplicateItems()     ←  seen_items (meta-Feld)
│                    └── enqueueForApproval()               │
└─────────────────────────────────────────────────────────┘
                              ↓
                    SourceInputQueue
                    (source_id, raw_text, status: pending/approved/rejected)
                              ↓
                    QuickInputAgentService::extractAngles()
                              ↓
                    Angle-Drafts in Approval-UI
                              ↓
                    Nutzer approved (Bulk-UI auf /quellen oder Dashboard)
                              ↓
                    angles-Tabelle → bestehender Post-Flow
```

**Wichtig:** Der Output der neuen Quellen ist immer `raw_text` → vorhandener
`extractAngles()`-Pfad. **Kein neuer Angle-Erzeugungspfad.**

---

## 3. Datenmodell-Änderungen

### 3a. `sources`-Tabelle: `meta`-Feld ergänzen

```sql
ALTER TABLE sources ADD COLUMN meta JSON NULL;
```

`meta` ersetzt `seen_items`-Workarounds und ist für alle neuen Typen nutzbar:

| Quell-Typ | `meta`-Inhalt |
|---|---|
| `rss` | `{items: [{guid, title, link, hash, seen_at}], feed_title, item_count}` |
| `video` | `{transcript_job_id, duration_sec, chapters: [{start, title}], language}` |
| `screenshot` | `{ocr_confidence, page_count, extracted_at}` |
| `community` | `{subreddit, post_ids_seen: [], last_post_at}` |
| `url` | *(bereits durch `content_hash` + `last_content_preview` gedeckt)* |

### 3b. `source_input_queue`-Tabelle (neu)

Entkoppelt die Quellen-Verarbeitung von der Angle-Erstellung:

```php
Schema::create('source_input_queue', function (Blueprint $table) {
    $table->id();
    $table->string('source_id');            // Foreign: sources.id
    $table->string('strategy_id');          // Foreign: strategies.id
    $table->text('raw_content');            // Extrahierter Text des Items
    $table->string('item_title')->nullable();
    $table->string('item_url')->nullable();  // Original-URL des Items (Feed-Item, YT-Video)
    $table->string('item_guid')->nullable(); // GUID zur Deduplizierung
    $table->enum('status', ['pending', 'processing', 'done', 'rejected'])->default('pending');
    $table->json('extracted_angles')->nullable(); // Draft-Angles aus LLM
    $table->timestamps();

    $table->index(['source_id', 'status']);
    $table->index('item_guid');
    $table->foreign('source_id')->references('id')->on('sources')->cascadeOnDelete();
});
```

**Warum eine eigene Tabelle?**
- Quellen können viele Items haben (Feed: 50 Items/Tag) — keine Spalten-Inflation in `sources`
- Status-Tracking (wann processed, wann approved) ohne `sources` zu verschmutzen
- Approval-Queue ist dann ein einfaches `WHERE status = 'pending'`

### 3c. `sources.type` Enum-Erweiterung

Da SQLite `varchar` nutzt (kein harter Constraint): nur Validierung in Controllers anpassen.

Neue erlaubte Werte: `rss`, `video`, `audio`, `screenshot`, `community`

---

## 4. Neuer zentraler Service: `SourceIntelligenceService`

Ersetzt **nicht** den `SourceMonitorService` — erweitert ihn um Typ-Dispatch:

```php
class SourceIntelligenceService
{
    // Dispatcher — welcher Fetcher für welchen Typ?
    public function fetch(Source $source): FetchResult
    {
        return match($source->type) {
            'url'         => $this->fetchUrl($source),       // bestehend via EdenAIWebService
            'rss'         => $this->fetchRss($source),       // NEU
            'video'       => $this->fetchVideo($source),     // NEU
            'screenshot'  => $this->fetchScreenshot($source),// NEU
            'community'   => $this->fetchCommunity($source), // NEU
            default       => FetchResult::skip("Typ {$source->type} nicht unterstützt"),
        };
    }

    // Für jeden neuen Typ: separate private Methode
    private function fetchRss(Source $source): FetchResult { ... }
    private function fetchVideo(Source $source): FetchResult { ... }
    private function fetchScreenshot(Source $source): FetchResult { ... }
    private function fetchCommunity(Source $source): FetchResult { ... }
}
```

`FetchResult` ist ein einfaches Value-Object:
```php
class FetchResult {
    public bool $success;
    public array $items; // [{title, content, url, guid}]
    public ?string $error;
    public static function skip(string $reason): self;
    public static function error(string $msg): self;
}
```

**`SourceMonitorService`** bekommt eine 1-Zeilen-Änderung:
```php
// Statt ->where('type', 'url') :
->whereIn('type', ['url', 'rss', 'video', 'audio', 'screenshot', 'community'])
```

---

## 5. Phasen-Implementierung

### Phase 0 — Datenmodell-Foundation (0,5 Tage) [ZUERST]

Muss vor allen anderen Phasen fertig sein, da alle darauf aufbauen.

**Tasks:**
- [ ] Migration: `sources.meta` (JSON, nullable)
- [ ] Migration: `source_input_queue`-Tabelle
- [ ] `Source`-Model: `meta`-Cast ergänzen, Fillable updaten
- [ ] `SourceInputQueue`-Model erstellen
- [ ] Validierung in `SourceController` und `QuickInputController`: neue Typen erlauben
- [ ] `FetchResult`-Value-Object
- [ ] `SourceIntelligenceService`-Stub mit Dispatcher
- [ ] `SourceMonitorService`: `type`-Filter reparieren (Bug 1 aus Validierung)

**Kein UI-Aufwand in Phase 0.**

---

### Phase 1 — RSS-Feeds (1 Tag)

**Neue Fähigkeit:** Feed-URL einfügen → System zieht täglich neue Artikel → Angle-Drafts landen in der Queue.

**Backend:**
```php
// SourceIntelligenceService::fetchRss()
private function fetchRss(Source $source): FetchResult
{
    $xml = Http::timeout(30)->get($source->url)->body();
    $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

    $seenGuids = collect($source->meta['items'] ?? [])->pluck('guid')->toArray();
    $newItems = [];

    foreach ($feed->channel->item as $item) {
        $guid = (string)($item->guid ?? $item->link);
        if (in_array($guid, $seenGuids)) continue;

        $newItems[] = [
            'title'   => (string)$item->title,
            'content' => strip_tags((string)($item->description ?? $item->{'content:encoded'} ?? '')),
            'url'     => (string)$item->link,
            'guid'    => $guid,
        ];
    }

    // Meta aktualisieren (max. 200 bekannte GUIDs halten)
    $allSeen = array_merge(
        $source->meta['items'] ?? [],
        array_map(fn($i) => ['guid' => $i['guid'], 'seen_at' => now()], $newItems)
    );
    $source->update(['meta' => ['items' => array_slice($allSeen, -200)]]);

    return FetchResult::success($newItems);
}
```

**Queue-Befüllung** (in `SourceMonitorService` nach Fetch):
```php
foreach ($fetchResult->items as $item) {
    SourceInputQueue::create([
        'source_id'    => $source->id,
        'strategy_id'  => $source->strategy_id,
        'raw_content'  => $item['title'] . "\n\n" . $item['content'],
        'item_title'   => $item['title'],
        'item_url'     => $item['url'],
        'item_guid'    => $item['guid'],
        'status'       => 'pending',
    ]);
}
```

**Angle-Extraktion** (neuer Artisan-Command `process:source-queue`):
```
pending items → extractAngles() → extracted_angles JSON → status = 'done'
```

**UI: Quick Input — neuer Tab „RSS-Feed":**
- URL eingeben + Strategie + Frequenz → Source anlegen, sofortiger erster Fetch
- Feed-Preview: letzte 5 Artikel-Titel anzeigen bevor gespeichert wird

**UI: Dashboard/Quellen — Approval-Widget:**
- Neue Sektion „📥 Angle-Entwürfe aus Quellen" (Badge-Counter)
- Liste der `pending` Queue-Items mit Draft-Angles
- Bulk-Approve / Einzeln bearbeiten / Ablehnen

**Aufwand:** 1 Tag (Backend inkl. Tests 4h, UI 4h)

---

### Phase 1b — Approval-UI & Dashboard-Widget (0,5 Tage, parallel zu Phase 1)

Separater Task, da die Queue-Tabelle (Phase 0) die Basis ist und für alle folgenden Phasen
dieselbe UI wiederverwendet wird.

**Dashboard:** Neues Stat-Widget „📥 N Entwürfe warten" → Link zu `/quellen?tab=queue`

**Quellen-Seite:** Neuer Tab „Eingang" (Badge):
```
[alle Quellen] [Eingang 12]

┌─────────────────────────────────────────────────────────┐
│ 📄 HubSpot-Blog: "CRM-Trends 2026"          2026-09-14 │
│ Quelle: HubSpot Blog RSS · 3 Angle-Entwürfe           │
│                                                          │
│  ○ HubSpot-Nutzer verlieren 30% ihrer... [B2B-2] [✏️]  │
│  ● CRM ohne Datenhygiene-Prozess...       [B2B-3] [✏️]  │
│  ○ Pipeline-Reviews fehlen in 67%...      [B2B-1] [✏️]  │
│                                                          │
│  [Alle auswählen] [Auswahl übernehmen ✅] [Ablehnen ✕] │
└─────────────────────────────────────────────────────────┘
```

---

### Phase 2 — YouTube-Transkription (2 Tage)

**Neue Fähigkeit:** YouTube-URL einfügen → Auto-Transkript → Angle-Extraktion.

**Strategie (priorisiert nach Kosten):**

1. **Gratis-Pfad: YouTube-Untertitel** (kein API-Call):
   - YouTube liefert Auto-Untertitel als TimedText-XML unter einer vorhersagbaren URL
   - `https://www.youtube.com/api/timedtext?lang=de&v={VIDEO_ID}` oder Variante
   - Fallback auf `lang=en` wenn `de` fehlt
   - **Risiko:** Dieser API-Pfad ist undokumentiert und kann sich ändern

2. **Bezahl-Fallback: EdenAI Whisper**:
   - EdenAI `audio/speech-to-text` mit Whisper-Provider
   - Kosten: ~$0.006/Minute (1h Video = ~$0.36)
   - Trigger: wenn Untertitel-Pfad 404/403 liefert

**Neue Methode `EdenAIWebService::transcribe(audioUrl)`:**
```php
public function transcribe(string $fileUrl, string $language = 'de'): array
{
    $response = $this->post('https://api.edenai.run/v2/audio/speech_to_text_async/', [
        'providers' => 'openai',
        'language' => $language,
        'file_url' => $fileUrl,
        'show_original_response' => false,
    ]);
    // Async: Job-ID zurück → polling oder Webhook
    return ['job_id' => $response->json('job_id'), 'success' => $response->ok()];
}
```

**Chunking für lange Videos:**
- Transkript > 3000 Tokens → in 5-Minuten-Blöcke aufteilen
- Pro Block: `extractAngles()` (max. 3 Angles)
- Deduplication: Cosine-Similarity via bestehendem `EmbeddingService` (bereits vorhanden!)

**UI:** Quick-Input-Tab „Video/Podcast" — URL eingeben, Sprache wählen, Transkript-Vorschau

**Aufwand:** 2 Tage (YouTube-Fallbacks sind die Unsicherheitsstelle)

---

### Phase 3 — Screenshot-OCR (0,5 Tage)

**Neue Fähigkeit:** Bild-Upload (PNG/JPG) → OCR → Text → Angle-Extraktion.

**Backend:**
```php
// EdenAIWebService::ocr(imagePath): string
public function ocr(string $imagePath): array
{
    return $this->post('https://api.edenai.run/v2/ocr/ocr/', [
        'providers' => 'google',          // oder 'microsoft', 'amazon'
        'file' => base64_encode(file_get_contents($imagePath)),
        'fallback_providers' => 'amazon', // Auto-Fallback
        'show_original_response' => false,
    ]);
}
```

**QuickInput-Erweiterung:**
- `mimes` Validierung: `pdf,txt,md,doc,docx` → `pdf,txt,md,doc,docx,png,jpg,jpeg,webp`
- Wenn Bild-Upload: OCR-Pfad statt PdfParser-Pfad

**Aufwand:** 0,5 Tage — kleinste Phase, größte Wirkung pro Aufwand nach RSS

---

### Phase 4 — Community-Quellen: Reddit + Hacker News (1,5 Tage)

**Neue Fähigkeit:** Subreddit oder HN-Suche als Quelle → täglich neue Beiträge mit echten
Pain-Points → Angle-Entwürfe.

**Wichtig:** Nicht als Auto-Angle-Quelle, sondern als **Inspiration-Pool** mit Approval.

**Reddit-Fetcher:**
```php
// Kein Key nötig — JSON-API ist offen
private function fetchCommunity(Source $source): FetchResult
{
    $meta = $source->meta;
    $subreddit = $meta['subreddit'];                        // z.B. 'hubspot'
    $keywords = $meta['keywords'] ?? [];                    // z.B. ['crm', 'pipeline']

    $url = "https://www.reddit.com/r/{$subreddit}/new.json?limit=25";
    $response = Http::withHeaders(['User-Agent' => 'contentor/1.0'])->get($url);

    $seenIds = $meta['post_ids_seen'] ?? [];
    $newItems = [];

    foreach ($response->json('data.children') as $post) {
        $d = $post['data'];
        if (in_array($d['id'], $seenIds)) continue;
        if ($d['score'] < 5) continue; // Mindest-Upvotes
        if (!empty($keywords) && !str_contains_any(strtolower($d['title'] . $d['selftext']), $keywords)) continue;

        $newItems[] = [
            'title'   => $d['title'],
            'content' => $d['selftext'] ?: $d['url'],
            'url'     => 'https://reddit.com' . $d['permalink'],
            'guid'    => $d['id'],
        ];
        $seenIds[] = $d['id'];
    }

    $source->update(['meta' => array_merge($meta, ['post_ids_seen' => array_slice($seenIds, -500)])]);
    return FetchResult::success($newItems);
}
```

**HN-Fetcher** (Algolia-API, ebenfalls keyless):
```
https://hn.algolia.com/api/v1/search?query=crm+pipeline&tags=story&hitsPerPage=10
```

**UI:** Quick-Input-Tab „Community" — Subreddit eingeben, Keywords als Tags, Score-Mindest-Schwelle

**Aufwand:** 1,5 Tage

---

### Phase 5 — E-Mail-Forwarding (2,5 Tage)

**Neue Fähigkeit:** E-Mail weiterleiten → automatisch als Quelle verarbeitet.

**Aufwand-Treiber:** Inbound-Mail-Setup braucht externe Konfiguration.

**Empfohlener Ansatz für Solo-Nutzer-System:** IMAP-Polling statt Inbound-Webhook.

```php
// Neuer Artisan-Command: poll:mailbox
// Liest dediziertes Postfach (z.B. contentor@yourdomain.com)
// Filtert nach Betreff-Präfix oder Absender-Whitelist
// Leitet an QuickInput-Pfad weiter
```

**Aufwand:** 2,5 Tage (IMAP-Setup, Spam-Filter, Attachment-Handling)

**Empfehlung:** Erst wenn Phase 1–3 stabil laufen.

---

### Phase 6 — Persistent Watching: Smarter Monitoring-Scheduler (0,5 Tage)

Nicht ein neuer Quell-Typ, sondern eine Verbesserung der Frequenz-Steuerung.

**Problem heute:** `monitor:sources` läuft täglich 07:00 für alle URLs gleich — unabhängig
davon, ob eine Quelle täglich oder nur wöchentlich neue Inhalte produziert.

**Lösung:**
- Frequenz-basiertes Scheduling bleibt (bereits implementiert via `isDue()`)
- **Neu:** Adaptive Frequenz — wenn 5 aufeinanderfolgende Checks `unchanged` liefern, Frequenz
  automatisch auf „biweekly" hochsetzen und Nutzer benachrichtigen
- **Neu:** `monitor:sources --type=rss` als separater Schedule-Slot (z.B. alle 6h statt täglich)
  für Feeds mit hohem Publik-Tempo

---

## 6. Übergreifende Architekturentscheidungen

### 6a. Immer derselbe Trichter

Jede neue Quelle mündet im selben Output:

```
raw_content (text) → extractAngles() → Draft-Angles → Approval → angles-Tabelle
```

Kein neuer Angle-Erzeugungspfad. Kein neues Prompt-System. Der bestehende
`QuickInputAgentService` mit dem GPT-4o-Prompt inkl. ICP/Cluster-Extraktion
gilt für alle Quelltypen.

### 6b. Meta-Feld statt vieler Spalten

Alle typ-spezifischen Zustandsdaten landen in `sources.meta` (JSON).
Vorteile:
- Keine Migration pro neuen Quelltyp
- Schema ist selbstbeschreibend
- Einfach serialisierbar/deserialisisierbar via `$casts`

### 6c. Approval-Queue als Puffer

Angles werden **nie automatisch** in die angles-Tabelle geschrieben (außer wenn der Nutzer
das explizit konfiguriert). Der Nutzer hat immer die Kontrolle — die Queue zeigt,
was eingegangen ist und wartet auf Freigabe.

Einzige Ausnahme: `auto_approve_sources`-Setting (opt-in, pro Strategie konfigurierbar),
das nur für Sources mit `trust_level: high` gilt.

### 6d. Kosten-Transparenz

Jeder externe Call durch `EdenAIWebService::parseResponse()` → automatisch in
`monitoring_events` geloggt (bereits implementiert, Kosten-Feld vorhanden).
Neue OCR/Transkription-Calls: dasselbe Logging.

### 6e. Python-Agent-Anbindung (content-agent/)

Die Python-Agents (Haystack) rufen die Laravel-API als Tool auf. Sie benötigen
**kein eigenes RSS/OCR/Transkription**. Einzige Erweiterung: neues Tool
`get_source_queue(status='pending')` damit der Coordinator-Agent sehen kann,
welche Angle-Drafts auf Approval warten.

---

## 7. Implementierungsreihenfolge & Zeitplan

```
Woche 1:
  Phase 0   │████████░░░░░░░░░░░░░░░░│  0,5 Tage  — Datenmodell
  Phase 1   │░░░░████████████████░░░░│  1 Tag     — RSS
  Phase 1b  │░░░░░░░░░░░░████████████│  0,5 Tage  — Approval-UI

Woche 2:
  Phase 3   │████░░░░░░░░░░░░░░░░░░░░│  0,5 Tage  — OCR
  Phase 2   │░░░░████████████████████│  2 Tage    — YouTube
  Phase 4   │░░░░░░░░░░░░░░░░░░░░░░░░│  1,5 Tage  — Community

Woche 3 (wenn gewünscht):
  Phase 5   │████████████████████████│  2,5 Tage  — E-Mail
  Phase 6   │░░░░░░░░░░░░░░░░░░░░░░░░│  0,5 Tage  — Smart Scheduler
```

**Gesamt Woche 1+2:** ~4,5 Tage für den Kern (RSS, OCR, YouTube, Community)

---

## 8. Erste Feed-Empfehlungen für viscale

Konkrete Feeds zum Start:

| Feed | URL | Frequenz | Erwartete Angle-Dichte |
|---|---|---|---|
| HubSpot Blog | `https://blog.hubspot.com/marketing/rss.xml` | weekly | hoch (CRM, RevOps) |
| G2 CRM News | `https://learn.g2.com/rss.xml` | weekly | mittel |
| RevOps Co-op | `https://www.revopscoop.com/feed` | weekly | hoch |
| Pipedrive Blog | `https://www.pipedrive.com/en/blog/rss` | biweekly | mittel (Competitor-Watch) |
| Salesforce Blog | `https://www.salesforce.com/blog/feed/` | biweekly | mittel (Markt-Kontext) |
| LinkedIn Sales Blog | `https://www.linkedin.com/blog/sales/feed` | weekly | hoch |

---

## 9. Offene Entscheidungen (vor Phase-0-Start klären)

- [ ] **Approval-Schwelle:** Sollen Angle-Drafts mit Score ≥ X automatisch genehmigt werden
  (opt-in Setting `auto_approve_score_threshold` pro Strategie)?
  → *Empfehlung: Nein für den Start, später opt-in*

- [ ] **Queue-Retention:** Wie lange bleiben abgelehnte Items in der Queue (Audit-Trail)?
  → *Empfehlung: 30 Tage, dann purge via scheduled Command*

- [ ] **Mehrsprachigkeit:** Sollen englische Feed-Artikel vor der Angle-Extraktion übersetzt werden
  oder der Prompt auf Englisch antworten lassen (aktuell DE-only)?
  → *Empfehlung: EdenAI `translation` vor Extraktion (günstiger als im Prompt lösen)*

- [ ] **YouTube-Kosten-Cap:** Maximale Minuten Transkription pro Monat (als Schutz)?
  → *Empfehlung: 60 Min/Monat als Default, konfigurierbar in Settings*
