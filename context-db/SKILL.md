# Context DB

PostgreSQL-Datenbank für alle Skills. Kunden, Projekte, SOP-Runs, Kontext, Content.

## Actions

| Group | Actions |
|---|---|
| Schema | `debug`, `setup_schema` |
| Kunden | `list_kunden`, `find_kunde`, `create_kunde`, `update_kunde` |
| Projekte | `list_projekte`, `create_projekt`, `update_projekt` |
| Kontext | `get_kontext`, `set_kontext`, `save_output`, `get_file` |
| SOP | `get_sop_run`, `advance_sop_step` |
| Dateien | `list_dateien`, `register_datei` |
| Content | `create_angle`, `list_angles`, `get_angle`, `update_angle`, `create_content_item`, `list_content_items`, `get_content_item`, `update_content_item` |
| Strategy | `set_strategy`, `get_strategy`, `list_strategies`, `delete_strategy` |
| Media | `create_media`, `list_media`, `get_media`, `update_media`, `delete_media` |

## get_file — Download ohne LLM-Transfer

```
context-db get_file(projekt_id=2, key="index.html")
→ data:-URI Download-Button
→ LLM sieht nie den Inhalt
```

## DB-URL

```
postgresql://aicontext:***@ai.viminds.de:5433/context_db
```

## Versionierung

`kontext_items`: ON CONFLICT DO UPDATE → version + 1
`content_items`: kein automatischer Version-Counter