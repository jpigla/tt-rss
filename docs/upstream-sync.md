# Upstream-Sync — tt-rss Fork

Prozess zum Synchronisieren des Forks mit dem Original-Repository (`tt-rss/tt-rss`).

## Überblick

```
upstream/main  ──→  main (sauberer Spiegel)  ──→  worktree-Inoreader (eigene Plugins)
```

- `main` enthält ausschließlich upstream-Commits (kein eigener Code)
- `worktree-Inoreader` enthält alle eigenen Plugin-Entwicklungen
- Eigene Änderungen liegen nur in `plugins.local/` — Konflikte sind selten

---

## Skript

**Pfad:** `utils/upstream-sync.sh`

### Verwendung

```bash
# Standard: aktuellen Branch synchen
./utils/upstream-sync.sh

# Bestimmten Branch als Ziel
./utils/upstream-sync.sh --target-branch worktree-Inoreader

# Nur analysieren, nichts ausführen
./utils/upstream-sync.sh --dry-run
```

### Was das Skript tut

1. **Voraussetzungen prüfen** — sauberer Working Tree, Remote vorhanden
2. **`upstream/main` fetchen** — neueste Upstream-Commits holen
3. **Änderungen analysieren** — neue Commits auflisten, kritische Dateien identifizieren
4. **`main` mergen** — Fast-forward-Merge, dann Push zu `origin`
5. **Target-Branch mergen** — `main` → `worktree-Inoreader`, dann Push
6. **Abschluss-Report** — finaler Zustand und Handlungsbedarf

### Exit-Codes

| Code | Bedeutung |
|------|-----------|
| `0`  | Erfolg |
| `1`  | Fehler (z.B. kein Git-Repo, Remote fehlt) |
| `2`  | Merge-Konflikt — manueller Eingriff nötig |
| `3`  | Uncommitted Changes — erst committen oder stashen |

---

## Kritische Dateien

Das Skript meldet explizit, wenn sich diese Dateien im Upstream geändert haben:

| Datei | Warum kritisch |
|-------|---------------|
| `classes/PluginHost.php` | Hook-Signaturen könnten sich ändern → eigene Plugins prüfen |
| `classes/Config.php` | `SCHEMA_VERSION` + Config-Keys |
| `classes/Prefs.php` | Prefs-Konstanten, auf die Plugins zugreifen |
| `classes/Db_Migrations.php` | Migrations-Mechanismus |
| `sql/pgsql/schema.sql` | DB-Struktur |

Bei Meldung `[WARN] KRITISCHE ÄNDERUNG`: Diff lesen, eigene Plugins auf Kompatibilität testen.

---

## Ablauf für KI-Agenten

Das Skript gibt strukturierte Ausgaben (`[INFO]`, `[OK]`, `[WARN]`, `[ERROR]`, `[CONFLICT]`).

### Normaler Durchlauf (kein Handlungsbedarf)
```
[OK] Working Tree sauber.
[OK] main ist bereits auf aktuellem upstream-Stand.
[OK] Upstream-Sync abgeschlossen.
```

### Neue Commits vorhanden
```
[INFO] 3 neue Upstream-Commit(s) seit letztem Sync:
  abc1234 Fix: XSS in article renderer
  def5678 Update composer dependencies
[OK] Merge erfolgreich.
```

### Handlungsbedarf nach Merge
```
[WARN] KRITISCHE ÄNDERUNG in: classes/PluginHost.php
    --- a/classes/PluginHost.php
    +++ b/classes/PluginHost.php
    @@ ... @@
[WARN] Eigene Plugins auf Kompatibilität testen!
```
→ `plugins.local/` nach Nutzung des geänderten Hooks/Patterns durchsuchen und anpassen.

### Merge-Konflikt (Exit-Code 2)
```
[CONFLICT] Merge von main in worktree-Inoreader gescheitert.
  KONFLIKT: classes/RPC.php
```
→ Datei öffnen, `<<<<`/`====`/`>>>>` Marker auflösen, dann:
```bash
git add classes/RPC.php
git commit
git push origin worktree-Inoreader
```

### Uncommitted Changes (Exit-Code 3)
```bash
git stash push -m "vor-upstream-sync"
./utils/upstream-sync.sh
git stash pop
```

---

## Wann synchen?

- Upstream-Atom-Feed in tt-rss abonnieren: `https://github.com/tt-rss/tt-rss/commits/main.atom`
- Sync auslösen bei: Security-Fixes, Hook-Änderungen, Schema-Updates
- Rhythmus: alle 2–4 Wochen oder bei relevantem Commit im Feed

---

## Update-Symbol in tt-rss deaktivieren

Das eingebaute Update-Symbol vergleicht den lokalen Git-Timestamp mit `tt-rss.org/version.json`.
Da der Fork immer eigene Commits enthält, ist das Symbol nicht sinnvoll nutzbar.

In `.env` deaktivieren:
```
TTRSS_CHECK_FOR_UPDATES=false
```
