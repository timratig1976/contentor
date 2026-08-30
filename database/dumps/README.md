# Datenbank-Transfer auf ein anderes Gerät

Dieser Ordner enthält Dumps der lokalen SQLite-Datenbank, um den aktuellen
Stand (Strategien, Personas, Angles, Content, Regelwerk, Settings) auf ein
anderes Gerät zu übertragen.

## Was es gibt

| Datei | Inhalt | Zweck |
|-------|--------|-------|
| `contentor_<DATUM>.sqlite` | Roh-Kopie der DB-Datei | **Schnellster Weg** — 1:1 übernehmen |
| `contentor_<DATUM>.sql` | SQL-Dump (Schema + Daten, lesbar) | Diffbar, selektiv importierbar |
| `contentor_<DATUM>.sql.gz` | Komprimierter SQL-Dump | Zum Teilen/Archivieren |

## Was ist enthalten

- `strategies` + `content_strategies` → Regelwerk (brand_voice, channel_rules, post_templates, icp_channel_mapping)
- `personas` → Ziel-Personas
- `sources`, `angles`, `content_items`, `content_media` → Produktionsdaten
- `settings` → Agent-Modelle, Prompts, API-Keys (⚠️ siehe unten)
- `agent_logs` → Test-Historie

## Auf dem Zielgerät wiederherstellen

### Variante A — Roh-Datei (empfohlen, am einfachsten)

```sh
# 1. Repo klonen & Abhängigkeiten installieren
git clone <repo> contentor && cd contentor
composer install

# 2. Dump-Datei an die richtige Stelle kopieren
cp database/dumps/contentor_<DATUM>.sqlite database/database.sqlite

# 3. Rechte & Cache
php artisan config:clear
```

Fertig — keine Migration nötig, da das Schema im Dump enthalten ist.

### Variante B — SQL-Dump importieren

```sh
# 1. Frische leere DB anlegen
touch database/database.sqlite

# 2. Dump einspielen
sqlite3 database/database.sqlite < database/dumps/contentor_<DATUM>.sql

# 3. Cache leeren
php artisan config:clear
```

### Variante C — Nur Migrationen + Regelwerk (sauberer Neustart)

Wenn du nur das Regelwerk/Personas, aber keine Produktionsdaten willst:

```sh
php artisan migrate:fresh
# dann nur die gewünschten Tabellen aus dem .sql-Dump importieren, z. B.:
sqlite3 database/database.sqlite \
  "DELETE FROM content_strategies;" \
  ".read database/dumps/contentor_<DATUM>.sql"   # oder selektiv per INSERT
```

## ⚠️ Wichtig: Secrets

Die `settings`-Tabelle enthält API-Keys (`llm_keys`: EdenAI, SerperDev).
- Der Dump enthält diese Keys → **nicht öffentlich committen/teilen**.
- Auf dem Zielgerät ggf. unter **Einstellungen** neu setzen.

Zusätzlich braucht der Python-Agent (`content-agent/`) seine eigene `.env`
(`EDENAI_API_KEY`, `SERPERDEV_API_KEY`) — diese Datei ist **nicht** im Dump.

## Python-Seite mitnehmen

```sh
cd content-agent
pip install -r requirements.txt
# .env vom Quellgerät kopieren (enthält die API-Keys)
```

## Schnell-Check nach dem Restore

```sh
php artisan tinker --execute='echo "Strategien: ".App\Models\Strategy::count()." | Personas: ".App\Models\Persona::count()." | Regelwerk: ".App\Models\ContentStrategy::count()." | Angles: ".App\Models\Angle::count();'
```
