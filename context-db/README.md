# context-db Skill

**Version:** v1.1.0  
**Zweck:** Postgres-Zugriff auf die viminds Context-DB — Single Source of Truth für Kunden, Projekte, SOP-Status und Kontext-Items. Ersetzt Live-Drive-Reads zwischen SOP-Schritten.

## Architektur

```
DB (Postgres)          = Kontext, Status, SOP-Fortschritt
Drive                  = Blob-Storage + Exchange (finale Docs)
AnythingLLM Workspace  = SOP-Inhalt (RAG-durchsuchbar)
```

## Verbindung

| Umgebung | URL | Automatisch |
|----------|-----|-------------|
| Intern (Docker) | `postgresql://aicontext:***@postgres-context:5432/context_db` | ✅ DNS-Detection |
| Extern (Desktop) | `postgresql://aicontext:***@ai.viminds.de:5433/context_db` | ✅ DNS-Detection |

Der Skill erkennt automatisch über DNS-Lookup ob `postgres-context` auflösbar ist — kein manuelles Umschalten.

## Einmalig: Schema initialisieren

```
action: setup_schema
```

Legt alle Tabellen an (idempotent, `CREATE TABLE IF NOT EXISTS` + `ADD COLUMN IF NOT EXISTS` Migrationen).

## 📐 KONVENTIONEN FÜR ALLE SKILLS & WORKSPACES

### 1. Jeder Skill-Output wird in der DB gespeichert
Alle erzeugenden Skills (landingpage-concept, landingpage-build, content-system, image-generation etc.) speichern ihre Ausgabe als `kontext_item` unter dem Projekt des Kunden. **NIEMALS nur im Drive ablegen.**

### 2. Pflicht-Metadaten je Kontext-Item
Jedes `kontext_item` trägt automatisch:

| Feld | Quelle | Beispiel |
|------|--------|----------|
| `source_skill` | Skill-Name (auto) | `Landingpage Concept` |
| `created_by` | Skill-Name oder User (auto/überschreibbar) | `Landingpage Concept` |
| `created_at` | DB (auto) | `2026-08-20 15:30:00` |
| `updated_at` | DB (auto, bei Update) | `2026-08-20 16:45:00` |
| `version` | DB (auto-increment bei Upsert) | `2` |

### 3. Kontext-Fluss zwischen SOP-Steps
- **Write:** Der erzeugende Step speichert via `set_kontext` (oder `save_output`).
- **Read:** Der nächste Step lädt via `get_kontext` — **KEIN Drive-Read**.
- Kontext ist damit lückenlos und nachvollziehbar (wer → wann → was).

### 4. Umsetzung in jedem Skill
Jeder Output-Skill nutzt `shared/context-client.js`:
```javascript
const { saveToContext } = require("../shared/context-client");
await saveToContext(runtime, {
  projekt_id,            // aus context-db (create_projekt)
  step_code,             // z.B. "V4", "lp-briefing", "lp-build"
  key,                   // z.B. "strategie", "briefing", "index.html"
  content,               // vollständiger Inhalt
  typ,                   // "text" | "markdown" | "json" | "html"
});
```

### 5. Auto-Save ist best-effort
Wenn `projekt_id` fehlt oder DB nicht erreichbar → still überspringen, kein Fehler im Haupt-Skill. Der Haupt-Flow bricht nie wegen DB-Save ab.

### 6. Drive-Nutzung reduziert
Drive nur noch für: finale Kunden-Dokumente (share), Kunden-Uploads lesen (einmalig). Alles andere → DB.

## Actions

### Kunden
| Action | Params |
|--------|--------|
| `list_kunden` | — |
| `find_kunde` | `name` oder `kkz` |
| `create_kunde` | `name`, `kkz`, `drive_folder_id?` |
| `update_kunde` | `id`, `drive_folder_id?`, `status?` |

### Projekte
| Action | Params |
|--------|--------|
| `list_projekte` | `kunden_id?`, `status?` |
| `create_projekt` | `kunden_id`, `unit_id`, `typ?` |
| `update_projekt` | `id`, `status?`, `unit_id?` |

### Kontext (ersetzt Drive-Reads)
| Action | Params |
|--------|--------|
| `get_kontext` | `projekt_id`, `step_code?`, `keys?` |
| `set_kontext` | `projekt_id`, `step_code`, `key`, `content`, `typ?`, `drive_file_id?` |

**Beispiel SOP-Übergabe V4 → V5:**
```
// V4 speichert:
set_kontext(projekt_id=5, step_code="V4", key="strategie", content="...")

// V5 liest:
get_kontext(projekt_id=5, keys=["strategie","kickoff-protokoll"])
```

### SOP-Status
| Action | Params |
|--------|--------|
| `get_sop_run` | `projekt_id` |
| `advance_sop_step` | `sop_run_id`, `completed_step_code`, `next_step_code` |

### Datei-Referenzen
| Action | Params |
|--------|--------|
| `list_dateien` | `projekt_id`, `step_code?` |
| `register_datei` | `projekt_id`, `step_code`, `drive_file_id`, `name`, `mime_type?`, `hash?` |

### System
| Action | Params |
|--------|--------|
| `debug` | — (zeigt Tabellen-Zählstände) |
| `setup_schema` | — (einmalig, idempotent) |
