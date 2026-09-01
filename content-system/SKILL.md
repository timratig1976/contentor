# Content System v3.2

Multi-Unit Content-System. DB-First: Ideen, Angles, Posts, Newsletter, Medien in Postgres. Drive nur für Exporte.

## Setup (einmalig pro Unit)

```
"Starte die Content-Strategie-Session für viscale"
→ strategy_session → 8 Strategy-Keys in DB
```

## 8 Strategy Keys

| Key | Steuert |
|---|---|
| `brand_voice` | Tonalität, Personality, No-Gos |
| `channel_rules` | Kanäle, Frequenz, Formate |
| `icp_channel_mapping` | ICP → Kanal |
| `media_logic` | Wann Bild/Video/Karussell |
| `editorial_rhythm` | Wochentage, Review |
| `content_strategy` | Aggregat |
| `post_templates` | Regeln pro Format |
| `content_personas` | Personen-Posts: Themen, Kadenz |

## Tägliche Arbeit

```
idee         → "IDEE: CRM ohne Mechanismus" → matched Persona → DB
produzieren  → format=linkedin_post, persona_id=tim → Asset
redaktionsplan → mode=show → aktueller Plan
overview     → Gesamtüberblick + Media-Stats
```

## Medien

```
media_briefing → media_generieren → image-generation → media_update
```

## Actions (25+)

`debug`, `start_content`, `idee`, `produzieren`, `redaktionsplan`, `harvest`, `monitoring`, `angle_speichern`, `angle_source_save`, `source_save`, `source_list`, `source_angles`, `rank_angle`, `rank_batch`, `newsletter_bk`, `bk_newsletter`, `foundation_index`, `migrate`, `migrate_content`, `list_content`, `update_content`, `export_to_drive`, `overview`, `strategy_session`, `strategy_speichern`, `strategy_abrufen`, `strategy_list`, `strategy_loeschen`, `media_briefing`, `media_list`, `media_update`, `media_delete`, `media_generieren`

## Datenmodell: Quelle → Angle → Post

```
QUELLE (source_save → SRC-…)
  └── type: "pdf" | "url" | "interview" | "intern" | "research"
  └── visibility: "intern" | "extern" | "confidential"
  └── file_ref: "hubspot_pricing_2026-05.pdf"
  └── batch_key: "hubspot-pricing-2026-05"

ANGLE (angle_speichern → ANG-…)
  └── source_id: "SRC-…"       ← Referenz auf Quelle
  └── batch_key: "hubspot-pricing-2026-05"
  └── funnel: "ToFu" | "MoFu" | "BoFu"
  └── viscale_phase: "Foundation" | "Execution" | "Intelligence"
  └── r_zielgruppe / r_viscale_fit / r_schaerfe / r_timing  (je 1–3)
  └── ranking_score (4–12)  · ranking_rang (im Batch)

POST / ASSET (produzieren → CNT-…)
  └── angle_id: "ANG-…"        ← Referenz auf Angle
  └── format: "linkedin_post" | "ad_copy" | "newsletter_bk" | …
```

## Ranking-Modell (4 Kriterien · je 1–3 Punkte)

| Kriterium | Frage | 1 | 2 | 3 |
|---|---|---|---|---|
| `r_zielgruppe` | Trifft KMU-Entscheider ohne Vorwissen? | Nur Tech-Affine | Mit CRM-Erfahrung | Jeder KMU-Inhaber |
| `r_viscale_fit` | Positioniert viscale als logische Lösung? | Informiert nur | Implizite Brücke | Explizite Brücke |
| `r_schaerfe` | Erzeugt Spannung / Unbehagen / Neugier? | Flach | Spürbar | Trifft sofort |
| `r_timing` | Wie dringend ist das Thema heute? | Evergreen | Aktuell | Brennend aktuell |

**Score:** Summe 4–12 · 🟢 10–12 prioritär · 🟡 7–9 solide · 🔴 4–6 nur mit Framing  
**Rang:** automatisch berechnet innerhalb `batch_key` nach Score → Schärfe → Zielgruppe

```
rank_angle  → angle_id + 4 Kriterien (je 1-3) → Score + Rang automatisch
rank_batch  → batch_key → vollständige Rangliste des Batches
```

### Typischer Workflow PDF → Angles → Posts

```
1. source_save   → {"title": "HubSpot Pricing 2025", "type": "pdf", "file_ref": "...", "visibility": "intern"}
                 → liefert source_id: "SRC-XXXXXX"

2. angle_speichern (pro Angle) → {..., "source_id": "SRC-XXXXXX", "batch_key": "hubspot-pricing-2026-05", "funnel": "MoFu"}

3. source_angles → {"source_id": "SRC-XXXXXX"}  ← alle Angles dieser Quelle

4. produzieren   → {"format": "linkedin_post", "angle_id": "ANG-XXXXXX"}
```

### Wichtig: PDF-/Quellen-Kontext speichern

Wenn ein User eine PDF-/Quellen-Analyse (z.B. HubSpot Pricing) ablegen will, **NICHT** per Datei-Skill schreiben — stattdessen:
- Quelle anlegen: `source_save`
- Angles mit `source_id` + `batch_key` speichern: `angle_speichern`
- Für ausführliche Faktbasis-Ablage: `angle_source_save`

## DB

- `content_strategies` — 8 Keys pro Unit
- `content_items` — Ideen, Posts (mit persona_id)
- `content_media` — Bilder, Videos, Grafiken
- Strategy-First: DB > constants.js