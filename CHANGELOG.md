# Changelog

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
