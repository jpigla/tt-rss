# Changelog

## 2026-04-22 — Core: NULL-Semantik für Purge & Max-Artikel (Schema-Version 154)

### Refactor: Explizite Vererbungs-Semantik (Option B)

**Problem:** `0` war gleichzeitig „Use default" und „unlimited" — man konnte einen Feed nicht bewusst auf den globalen Standard setzen, wenn die Kategorie etwas anderes vorgab.

**Lösung:** `NULL` als „Erben"-Sentinel in `ttrss_feeds`.

| Wert | `purge_interval` | `max_articles` |
|---|---|---|
| `NULL` | Erbt (Kategorie → Global) | Erbt (Kategorie → unlimited) |
| `0` | Explizit: Global-Standard verwenden | Explizit: Unbegrenzt |
| `-1` | Explizit: Nie löschen | — |
| `> 0` | Explizit: N Tage | Explizit: N Artikel |

**Migration:** `154.sql` — `DROP NOT NULL`, `DEFAULT NULL`, `UPDATE … SET … = NULL WHERE … = 0` (bestehende Feeds erben ab sofort).

**UI-Änderungen:**
- Feed-Dialog Purge-Select: neues Eintrag „Inherit (category or global default)" oben, „Use default" umbenannt zu „Use global default"
- Max-articles-Feld: leer = NULL (erbt), `0` = explizit unbegrenzt
- OPML: NULL-Spalten werden nicht exportiert; fehlendes Attribut beim Import → NULL

**Technisch:** `Feeds::_get_purge_interval()` und `_get_max_articles()` prüfen jetzt `is_null()` statt `!= 0`; `archive_articles`-Plugin aktualisiert.

---

## 2026-04-22 — Core: Kategorie-Einstellungen für Purge & Max-Artikel (Schema-Version 153)

### Neue Funktion: Purge-Interval und Max-Artikel für Ordner

**Wo:** Einstellungen → Feeds → Ordner doppelklicken → Dialog „Edit category"

Setzt `purge_interval` und `max_articles` auf Ordner-Ebene. Alle Feeds im Ordner erben diese Werte, sofern sie keinen eigenen Wert gesetzt haben.

**Fallback-Kette:** Feed-Einstellung → Kategorie-Einstellung → Globale Einstellung (`PURGE_OLD_DAYS`)

**Verhalten:**
- Wert `0` auf Kategorie = „No override" (Feed-Einstellung oder globaler Default gilt)
- Feed-spezifische Werte haben immer Vorrang vor der Kategorie
- Kategorie-Dialog ersetzt den bisherigen simplen Rename-`prompt()` — Umbenennen weiterhin möglich

**Technisch:** Migration `153.sql` (2× `ALTER TABLE ttrss_feed_categories ADD COLUMN`); `Feeds::_get_purge_interval()` / `_get_max_articles()` lösen Kategorie-Fallback via separatem ORM-Query auf.

---

## 2026-04-22 — Core: Max-Artikel-Limit pro Feed (Schema-Version 152)

### Neue Funktion: Maximale Artikelanzahl pro Feed

**Wo:** Einstellungen → Feeds → Feed bearbeiten → „Max articles" (Zahlenfeld, `0` = unbegrenzt)

Begrenzt die Anzahl gespeicherter Artikel pro Feed auf einen konfigurierbaren Maximalwert. Älteste Artikel werden bei jedem Feed-Update automatisch gelöscht, sobald der Grenzwert überschritten wird.

**Verhalten:**
- Läuft bei **jedem Feed-Abruf** (nicht periodisch), unmittelbar nach dem zeitbasierten Purge
- Gesternnte Artikel (`marked = true`) werden nie gelöscht
- Wert `0` (Default) = kein Limit, bisheriges Verhalten unverändert
- Kombinierbar mit `purge_interval` (Zeit-Purge läuft zuerst, Zähl-Purge danach)
- Auch im Batch-Edit (mehrere Feeds gleichzeitig) konfigurierbar
- Wird per OPML exportiert/importiert (`ttrssMaxArticles`-Attribut)

**Technisch:** Migration `152.sql` (`ALTER TABLE ttrss_feeds ADD COLUMN max_articles integer NOT NULL DEFAULT 0`); Löschlogik in `Feeds::_purge()` via `NOT IN (SELECT ... ORDER BY int_id DESC LIMIT N)`.

---

## 2026-04-12 — Neue Plugins: Phase 6 — Wissensarbeit, Analytics & Discovery

### Neue Plugins

#### `save_to_pkm` — PKM-Export (Readwise, Notion, Obsidian)
Artikel und Highlights an Personal-Knowledge-Management-Tools senden.

- **Readwise**: Artikel + Highlights synchronisieren via Readwise Reader API
- **Notion**: Seite in konfigurierter Datenbank erstellen (mit Inhalt, Metadaten, Highlights als Callout-Blöcke)
- **Obsidian**: Markdown-Note erstellen — per URI-Link (öffnet Obsidian) oder als Datei direkt im Vault
- Integriert automatisch Highlights aus dem Annotations-Plugin (falls aktiv)
- Konfigurierbares YAML-Frontmatter für Obsidian-Notes (Titel, URL, Autor, Quelle, Tags)

**Konfiguration:** Einstellungen → PKM-Export → Pro Dienst API-Token/Vault-Daten eingeben und aktivieren.

---

#### `reading_stats` — Lese-Statistiken & Analytics-Dashboard
Lesegewohnheiten tracken und visualisieren.

- Automatisches Tracking: Artikel werden als gelesen erfasst, wenn sie zu >50% sichtbar sind
- Lesezeit-Tracking: Akkumulierte Lesezeit pro Artikel (30-Sekunden-Intervalle)
- **Dashboard** mit:
  - Übersichtskarten: Heute, 7 Tage, 30 Tage, Gesamt
  - Lese-Streak (aktuell + Rekord)
  - Tages-Balkendiagramm (30 Tage)
  - GitHub-Style Contribution-Heatmap (52 Wochen)
  - Tageszeit-Verteilung (welche Stunden wird gelesen)
  - Top 10 meistgelesene Feeds
- Konfigurierbare Aufbewahrungsdauer (Default: 365 Tage)

**Nutzung:** Toolbar-Button „Insights" klicken oder Einstellungen → Lese-Statistiken → Dashboard öffnen.

---

#### `feed_health` — Feed-Gesundheitsüberwachung
Fehler, Staleness und Qualität aller Feeds im Blick behalten.

- Automatische Überwachung bei jedem Feed-Abruf (Erfolg/Fehler, Response-Zeit)
- **Health-Dashboard** mit:
  - Ampel-Übersicht: Gesund / Inaktiv / Fehler / Gesamt
  - Fortschrittsbalken (visueller Gesundheitsstatus)
  - Problem-Feed-Liste (sortiert nach Schwere)
  - Fehler-Log (letzte 20 Einträge)
- Per-Feed Health-Score (0-100%) im Feed-Editor
- Konfigurierbarer Staleness-Schwellwert (Default: 14 Tage ohne neue Artikel)

**Nutzung:** Toolbar-Button „Monitor Heart" klicken. Im Feed-Editor erscheint der Health-Score automatisch.

---

#### `digest_view` — Konfigurierbarer Daily/Weekly Digest
Verdichtete Zusammenfassungen aus ausgewählten Feeds und Kategorien.

- Mehrere Digest-Konfigurationen pro Nutzer möglich
- Häufigkeit: Täglich oder Wöchentlich (mit Wochentag-Auswahl)
- Uhrzeitgesteuerte Generierung
- Filter: Feeds, Kategorien, Mindest-Score, Max-Artikel
- **In-App Digest-Viewer**: Gruppiert nach Kategorie, mit Artikelauszügen
- **Digest-Archiv**: Vergangene Ausgaben durchblättern
- **Manuelles Generieren**: „Jetzt generieren"-Button
- Optionaler E-Mail-Versand

**Konfiguration:** Einstellungen → Digest-Konfiguration → Neuen Digest erstellen.

---

#### `feed_discovery` — Trending Topics & Feed-Empfehlungen
Trends erkennen und neue Quellen entdecken.

- **Trending Topics**: Automatische Keyword-Analyse über alle abonnierten Feeds
  - Nutzt PostgreSQLs `tsvector_combined` für effiziente Volltextanalyse
  - Vergleicht aktuelle vs. vorherige Periode (Rising Topics)
  - Tag-Cloud-Visualisierung mit Beispielartikeln
- **Feed-Vorschläge**: Empfehlungen basierend auf dem Feed-Browser
  - Beliebte Feeds, die andere Nutzer abonniert haben
  - Ein-Klick-Abonnieren oder Ausblenden
- Konfigurierbar: Trending-Zeitraum, Mindest-Artikelzahl

**Nutzung:** Toolbar-Button „Explore" klicken → Tab „Trending Topics" oder „Feed-Vorschläge".

---

### Security

- Alle Plugins nutzen Prepared Statements (keine SQL-Injection)
- HTML-Output escaped mit `htmlspecialchars()`
- JS nutzt DOM-basiertes Escaping via `textContent` / `_esc()`-Helper
- Session-basierte Autorisierung (`$_SESSION['uid']`) in allen Datenzugriffen
- `owner_uid` in allen WHERE-Klauseln (Multi-Tenant-Datenisolation)
- API-Credentials in verschlüsseltem Plugin-Storage
- Path-Traversal-Schutz (Obsidian Datei-Modus: `realpath()`-Validierung)
- Rate-Limiting: Lese-Events 1-Minute-Dedup, Feed-Health 1-Stunde-Dedup
- Lesezeit-Cap: Max. 120 Sekunden pro Update
- Batch-Limits für Housekeeping-Tasks
