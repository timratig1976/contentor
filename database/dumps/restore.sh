#!/usr/bin/env bash
# Stellt einen Contentor-DB-Dump auf diesem Gerät wieder her.
# Aufruf:  bash database/dumps/restore.sh database/dumps/contentor_<DATUM>.sqlite
set -euo pipefail

DUMP="${1:-}"
TARGET="database/database.sqlite"

if [[ -z "$DUMP" ]]; then
  echo "Verwendung: bash database/dumps/restore.sh <dump-datei.(sqlite|sql|sql.gz)>"
  exit 1
fi

if [[ ! -f "$DUMP" ]]; then
  echo "Datei nicht gefunden: $DUMP"
  exit 1
fi

# Backup der aktuellen DB, falls vorhanden
if [[ -f "$TARGET" ]]; then
  BACKUP="${TARGET}.bak_$(date +%Y%m%d_%H%M%S)"
  cp "$TARGET" "$BACKUP"
  echo "→ Bestehende DB gesichert: $BACKUP"
fi

case "$DUMP" in
  *.sqlite)
    cp "$DUMP" "$TARGET"
    echo "→ Roh-Datei kopiert nach $TARGET"
    ;;
  *.sql)
    rm -f "$TARGET"; touch "$TARGET"
    sqlite3 "$TARGET" < "$DUMP"
    echo "→ SQL-Dump importiert nach $TARGET"
    ;;
  *.sql.gz)
    rm -f "$TARGET"; touch "$TARGET"
    gunzip -c "$DUMP" | sqlite3 "$TARGET"
    echo "→ Komprimierter SQL-Dump importiert nach $TARGET"
    ;;
  *)
    echo "Unbekanntes Format: $DUMP (erwartet .sqlite, .sql oder .sql.gz)"
    exit 1
    ;;
esac

php artisan config:clear >/dev/null 2>&1 || true
echo "→ Fertig. Cache geleert."
