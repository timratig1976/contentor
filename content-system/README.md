# Content System (Multi-Unit)

AnythingLLM-Skill für den vollständigen Content-Workflow von Rohidee über Angle und Produktion bis Redaktionsplan und BK-Newsletter. Unterstützt mehrere Units (viscale, vitalents, ...) mit jeweils eigenen Drive-Ordnern, Regelwerken und Tonalität.

## Zweck

Der Skill bildet diese Ebenen ab:

- `idee` — Rohidee, URL, Zitat oder Beobachtung zu einem Angle-Kandidaten verdichten
- `produzieren` — Asset-Produktion für LinkedIn, Ads, Akquise-Newsletter, Landing-Page-Headlines und BK-Newsletter
- `redaktionsplan` — aktuellen Plan lesen oder neue Einträge ergänzen
- `harvest` — Ideas Inbox verarbeiten
- `monitoring` — Briefing für Wettbewerber- und Marktbeobachtung
- `angle_speichern` — validierten Angle in der Angle-Library ablegen
- `newsletter_bk` / `bk_newsletter` — Backlog anzeigen oder BK-Newsletter-Draft erzeugen
- `foundation_index` — Foundation-Index in Drive laden

## Unit-System

Jede Unit hat eigene Drive-Ordner-IDs und ein eigenes Regelwerk (Cluster, Tonalität, Hashtags, BK-Defaults, Monitoring-Lücken). Die Unit wird über den Parameter `unit` gesteuert:

```json
{ "action": "idee", "input": "Recruiting-Prozess bricht ab.", "unit": "vitalents" }
```

- **Default:** `viscale` (wenn `unit` weggelassen wird)
- **Validierung:** Unbekannte Units liefern einen `VALIDATION_ERROR`
- **Drive-Aktionen:** Werfen `CONFIGURATION_ERROR`, wenn für die Unit keine Ordner-IDs hinterlegt sind
- **Neue Unit anlegen:** In `skills/content-system/lib/constants.js` unter `UNITS` einen neuen Block mit `contentSystemRootId` und `rules` ergänzen

## Vereinfachte Drive-Struktur (neu)

Statt 8+ einzelner Ordner-IDs pro Unit braucht es nur noch eine `contentSystemRootId`. Der Skill legt die Unterordner automatisch an:

```
VIM Products > [Unit] > Marketing & Sales/
├── 03-Personas-Positioning/   ← Foundation/ICP-Docs (besteht bereits, wird gelesen)
└── Content System/
    ├── angles/    ← Sheet: Angle Library (validierte Angles)
    ├── plan/      ← Sheet: Content System (Ideen, Plan, Output-Referenzen)
    └── output/    ← Ordner für fertige Docs (LinkedIn-Posts, Blog-Artikel, Newsletter)
```

**Sheets:**
- **Angle Library** (`angles/`): Angle-ID | Datum | Angle | ICP | Pain-Cluster | Statement-Typ | Quelle | Status | Verwendet-in-IDs
- **Content System** (`plan/`): ID | Datum | Typ | Status | Input | Angle-ID | ICP | Pain-Cluster | Statement-Typ | Format | Titel | Owner | Live-Datum | Output-Doc-ID | Output-URL | Quelle | Notizen

**Hinweis:** Der Skill nutzt den bestehenden `03-Personas-Positioning` Ordner als Foundation-Quelle. Dieser liegt als Sibling neben `Content System` unter `Marketing & Sales`.

**Setup pro Unit:**
1. In Drive unter `VIM Products > [Unit]` einen Ordner `content-system` anlegen
2. Die Ordner-ID in `plugin.json` als `CONTENT_SYSTEM_ROOT_ID` eintragen (oder in `constants.js` als `contentSystemRootId`)
3. Beim ersten Aufruf einer Drive-Aktion werden `01-inbox`, `02-angles`, `03-plan`, `04-foundation` und die Standard-Docs automatisch angelegt

**Legacy-Fallback:** Wenn `contentSystemRootId` nicht gesetzt ist, nutzt der Skill die alten Einzel-IDs (viscale ist damit vorkonfiguriert).

### Was pro Unit gesteuert wird

| Bereich | Beispiele |
|---------|-----------|
| Drive-Ordner | Root, Personas, Inbox, Angle-Library, Redaktionsplan, Foundation |
| Pain-Cluster | viscale: Forecast/CRM-Cluster · vitalents: Recruiting/Pflege-Cluster |
| ICP-Guesser | Unit-spezifische Keyword-Mapping für automatische ICP-Erkennung |
| Tonalität | Forbidden Patterns, generische BK-Phrasen, Ton-Label |
| Hashtags | LinkedIn-Hashtags je Unit |
| BK-Newsletter | Default-Thema, Preheader, CTA, Signatur, Themenvorschläge |
| Monitoring | Markt-Lücken und Ton-Hinweise je Unit |
| Redaktionsplan | Titel und Default-Owner je Unit |

## v6-nahe Regeln, die jetzt zusätzlich umgesetzt sind

- Foundation-First: `idee` lädt den Foundation-Index aus Drive, bevor ICP-nahe Arbeit passiert, sofern Drive konfiguriert ist.
- Ohne expliziten ICP bricht `idee` jetzt mit einer Klärungsfrage ab, statt still zu raten.
- URL-Inputs können über `source_url` mitgelesen und im Angle verarbeitet werden.
- `redaktionsplan` liefert eine KW-Zusammenfassung und schreibt Einträge bevorzugt über Ersetzen in den aktuellen Wochenblock.
- `harvest` trennt Kandidaten, geparkte Ideen und verworfene Inputs.
- `newsletter_bk` erzwingt den 350–500-Wörter-Rahmen und kann Themenvorschläge ausgeben, wenn kein Thema gesetzt ist.

## Wichtige Regeln aus dem Workflow

- ICP-Zuordnung ist Pflicht für jedes produzierte Asset.
- Vor echter ICP-Recherche dient der Foundation-Index der jeweiligen Unit als Einstiegspunkt.
- Foundation-Dokumente nur lesen, nie überschreiben.
- Schreiben nur in Inbox, Angle-Library und Redaktionsplan der aktiven Unit.
- Kein Output mit Ausrufezeichen, Superlativen oder generischer Inspirationssprache (unit-spezifische Forbidden Patterns).
- LinkedIn-Posts erzwingen Leerzeile nach dem Hook.
- BK-Newsletter folgen einem separaten Tonalitäts-Layer und genau einem CTA.

## Drive-Konfiguration

Für alle lese- oder schreibenden Operationen werden erwartet:

- `DRIVE_SCRIPT_URL`
- `DRIVE_TOKEN`

Optional vorbereitbar und jetzt bereits vorgefüllt:

- `MARKETING_SALES_ROOT_ID`
- `PERSONAS_FOLDER_ID`
- `IDEAS_INBOX_FOLDER_ID`
- `IDEAS_INBOX_DOC_ID`
- `ANGLE_LIBRARY_FOLDER_ID`
- `EDITORIAL_PLAN_FOLDER_ID`
- `EDITORIAL_PLAN_DOC_ID`
- `FOUNDATION_INDEX_DOC_ID`
- `BK_BACKLOG_NAME`
- `BK_FOLDER_NAME`

Ohne diese Konfiguration funktionieren reine Produktions- und Angle-Outputs ohne Drive-Schreibzugriff, lese- oder schreibende Drive-Aktionen liefern einen Konfigurationsfehler.

## Beispiele

### IDEE (viscale, Default)

```json
{
  "action": "idee",
  "input": "Ein falsch eingerichtetes CRM kostet mehr als gar keins."
}
```

### IDEE (vitalents)

```json
{
  "action": "idee",
  "input": "Recruiting-Prozess bricht im letzten Schritt ab.",
  "unit": "vitalents"
}
```

### PRODUZIEREN LinkedIn

```json
{
  "action": "produzieren",
  "format": "linkedin_post",
  "angle": "Ein CRM ohne Mechanismus simuliert nur Sicherheit.",
  "icp": "B2B-2",
  "statement_type": "Direkt",
  "metric": "Von 3% auf 7% statt Blindflug im Forecast"
}
```

### REDAKTIONSPLAN zeigen (viscale)

```json
{
  "action": "redaktionsplan",
  "mode": "show"
}
```

### REDAKTIONSPLAN zeigen (vitalents)

```json
{
  "action": "redaktionsplan",
  "mode": "show",
  "unit": "vitalents"
}
```

### Angle speichern

```json
{
  "action": "angle_speichern",
  "angle": "Ein CRM ohne Mechanismus simuliert nur Sicherheit.",
  "icp": "B2B-2",
  "pain_cluster": "Cluster 2 · E3-01 · Blindflug im Forecast",
  "statement_type": "Direkt",
  "channels": ["linkedin_organic", "newsletter"]
}
```

### BK-Newsletter-Draft

```json
{
  "action": "newsletter_bk",
  "mode": "draft",
  "topic": "Property-Hygiene · 3 Fehler die wir oft sehen",
  "sources": ["Support-Fragen", "Delivery-Best-Practice"]
}
```

### Migration (Legacy-Docs → Sheets)

```json
{ "action": "migrate", "source": "all", "unit": "viscale" }
```

- `source`: `all` | `ideas` (nur Inbox) | `plan` (nur Redaktionsplan)
- Liest `legacyIdeasInboxDocId` und `legacyEditorialPlanDocId` aus `constants.js`
- Erkennt automatisch zwei Inbox-Formate:
  - **Format A** (strukturiert): `[Status] | Typ | ICP | Text | Owner | Datum | Link`
  - **Format B** (frei): `ANGLE N - Text | ICP: B2B-X | Cluster N | Statement-Typ | Status: Eingang`
- Schreibt Ergebnisse ins `Content System`-Sheet unter `plan/`

## Content-Strategie (v3.1.0)

Zentrale Strategie-Definition pro Unit in der DB. 6 Keys bilden das Operating System:

| Strategy Key | Inhalt |
|---|---|
| `brand_voice` | Tonalität, Personality, No-Gos, Signature Elements |
| `channel_rules` | Kanäle, Frequenz, Formate, Owner |
| `icp_channel_mapping` | ICP → Kanal-Mapping |
| `media_logic` | Wann Text / Bild / Video / Karussell |
| `editorial_rhythm` | Wochentage, Owner, Review-Prozess |
| `content_strategy` | Aggregiertes Strategie-Dokument |

### Strategy-Actions

```json
// Strategie-Session starten (zeigt Status)
{ "action": "strategy_session", "unit": "viscale" }

// Brand Voice speichern
{ "action": "strategy_speichern", "strategy_key": "brand_voice", "unit": "viscale", "content": { "personality": "Direkt", "never": ["Floskeln"] } }

// Brand Voice abrufen
{ "action": "strategy_abrufen", "strategy_key": "brand_voice", "unit": "viscale" }

// Alle Strategien listen
{ "action": "strategy_list", "unit": "viscale" }

// Strategie löschen
{ "action": "strategy_loeschen", "strategy_key": "media_logic", "unit": "viscale" }
```

**DB-Tabelle:** `content_strategies` (unit_id, strategy_key, content JSONB, version)
**Produktion:** `overview` und `produzieren` laden Strategy-Context automatisch mit.

## Content Media (v3.2.0)

Medien-Elemente (Bilder, Videos, Grafiken) sind mit Content-Items verknüpft und durchlaufen einen definierten Lebenszyklus:

```
briefing → generiert → in_drive → live
```

### DB-Tabelle `content_media`

| Spalte | Typ | Beschreibung |
|---|---|---|
| `media_id` | TEXT (PK) | MED-XXXXXX |
| `item_id` | TEXT (FK) | Verknüpftes Content-Item |
| `media_type` | TEXT | image, video, graphic, carousel_slide, ad_creative |
| `status` | TEXT | briefing, generiert, in_drive, live, verworfen |
| `prompt` | TEXT | Bild-Prompt für image-generation |
| `generation_params` | JSONB | Modell, aspect_ratio, style |
| `url` | TEXT | CDN-URL nach Generierung |
| `drive_file_id` | TEXT | Drive-ID nach saveGeneratedAsset |
| `position` | INT | Reihenfolge (0=erstes) |

### Media-Actions

```json
// Briefing anlegen
{ "action": "media_briefing", "item_id": "CNT-ABC123", "media_type": "image", "prompt": "Corporate style, dark tones" }

// Alle Medien eines Items listen
{ "action": "media_list", "item_id": "CNT-ABC123" }

// Generierungs-Briefing abrufen
{ "action": "media_generieren", "media_id": "MED-XYZ789" }

// Status/URL aktualisieren
{ "action": "media_update", "media_id": "MED-XYZ789", "status": "generiert", "url": "https://..." }

// Medien löschen
{ "action": "media_delete", "media_id": "MED-XYZ789" }
```

### Automatischer Workflow

1. `produzieren` → erzeugt `media_briefings` im Output (automatisch)
2. `media_briefing` → speichert Briefing in DB
3. `media_generieren` → liefert Prompt für `image-generation` Skill
4. `image-generation` → generiert Bild → CDN-URL
5. `media_update` → speichert URL + Status "generiert"
6. `drive-automation saveGeneratedAsset` → dauerhafte Drive-Ablage
7. `media_update` → `drive_file_id` + Status "in_drive"

---

## Internes — Drive-Client

`lib/drive-client.js` ergänzt bei allen **Write-Actions** automatisch `baseFolderId = contentSystemRootId` in den Apps-Script-Parametern. Das ist notwendig, damit `isAllowed()` im Apps Script den Schreibzugriff freigibt.

**Write-Actions** (werden automatisch mit `baseFolderId` angereichert):
`createFolder`, `createDoc`, `createSheet`, `writeDoc`, `appendToDoc`, `appendRow`, `writeCell`, `uploadFile`, `importFileFromUrl`, `moveFile`, `deleteFile`, `copyFile`, `rename`, `setFileDescription`, `shareFile`, `unshareFile`, `setPublicAccess`, `batchCreateFolders`, `batchCreateDocs` und weitere.

**Ohne `baseFolderId`** würde das Apps Script alle schreibenden Operationen mit `PERMISSION_DENIED: Parent not accessible` ablehnen — auch wenn der Service Account technisch Zugriff hat.

---

## Änderungshistorie

| Version | Datum | Änderung |
|---------|-------|----------|
| 2.0.0 | 2026-06 | Multi-Unit, vereinfachte Drive-Struktur (contentSystemRootId), Sheets statt Docs |
| 2.0.1 | 2026-08-11 | Fix: `drive-client.js` sendet `baseFolderId` bei Write-Actions (PERMISSION_DENIED-Fix) |
| 2.0.1 | 2026-08-11 | Fix: `migrate` erkennt jetzt Format B (`ANGLE N - Text \| ICP: ...`) aus Ideas Inbox |
