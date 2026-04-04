# TT-RSS Inoreader-Erweiterungen -- Plugin-Dokumentation

Dieses Dokument beschreibt die selbst entwickelten Plugins, die Tiny Tiny RSS um Funktionen nach dem Vorbild von Inoreader erweitern. Alle Plugins befinden sich in `plugins.local/` und nutzen das TT-RSS-Plugin-System (Hook-Architektur, Plugin-Storage, DB-Migrationen).

---

## Inhaltsverzeichnis

1. [Architektur und Konventionen](#architektur-und-konventionen)
2. [Plugin 1: reading_time](#1-reading_time--lesezeit-schätzung)
3. [Plugin 2: boosted_feeds](#2-boosted_feeds--priority-feeds)
4. [Plugin 3: keyword_spotlight](#3-keyword_spotlight--keyword-highlighting)
5. [Plugin 4: filter_log](#4-filter_log--filter-protokoll)
6. [Plugin 5: read_later](#5-read_later--später-lesen)
7. [Plugin 6: af_fulltext](#6-af_fulltext--volltext-extraktion)
8. [Plugin 7: enhanced_tags](#7-enhanced_tags--erweitertes-tagging)
9. [Plugin 8: ai_core](#8-ai_core--llm-abstraktionsschicht)
10. [Plugin 9: ai_summary](#9-ai_summary--ki-zusammenfassungen)
11. [Installation und Aktivierung](#installation-und-aktivierung)
12. [Roadmap: Weitere Phasen](#roadmap-weitere-phasen)

---

## Architektur und Konventionen

### Plugin-Struktur

Jedes Plugin folgt der gleichen Struktur:

```
plugins.local/<plugin_name>/
  init.php              # Hauptklasse (extends Plugin)
  <plugin_name>.css     # Optionales Stylesheet
  <plugin_name>.js      # Optionales JavaScript
  sql/pgsql/
    schema.sql          # Initiales DB-Schema (falls benötigt)
    migrations/
      1.sql, 2.sql ...  # Inkrementelle Migrationen
```

### Hook-System

TT-RSS stellt über 45 Hooks bereit. Jedes Plugin registriert sich in `init($host)` für die benötigten Hooks:

| Hook-Kategorie | Typische Hooks | Verwendung |
|----------------|---------------|------------|
| **Rendering** | `HOOK_RENDER_ARTICLE_CDM`, `HOOK_RENDER_ARTICLE` | Artikel-Content modifizieren (Lesezeit, Spotlights, Summaries) |
| **Artikel-Buttons** | `HOOK_ARTICLE_BUTTON` | Icons neben Artikeln hinzufügen (Volltext, Tags, Read-Later) |
| **Filter-Pipeline** | `HOOK_ARTICLE_FILTER`, `HOOK_FILTER_TRIGGERED` | Artikel bei Import verarbeiten, Filter-Events loggen |
| **Feed-Editor** | `HOOK_PREFS_EDIT_FEED`, `HOOK_PREFS_SAVE_FEED` | Per-Feed-Einstellungen (Boosted Feeds) |
| **Einstellungen** | `HOOK_PREFS_TAB`, `HOOK_PREFS_TABS` | Plugin-Konfigurationsseiten |
| **Sanitize** | `HOOK_SANITIZE` | DOM-Manipulation bei der HTML-Bereinigung |
| **Hotkeys** | `HOOK_HOTKEY_MAP`, `HOOK_HOTKEY_INFO` | Tastenkürzel registrieren |
| **Hintergrund** | `HOOK_HOUSE_KEEPING` | Periodische Aufräumarbeiten |

### Daten-Persistenz

- **Plugin-Storage** (`PluginHost::set/get`): Für einfache Key-Value-Konfiguration pro Benutzer. Gespeichert in `ttrss_plugin_storage`.
- **Eigene DB-Tabellen**: Für strukturierte Daten (Filter-Log, AI-Summaries). Erstellt via `Db_Migrations::initialize_for_plugin()`.
- **Bestehende Tabellen**: Wo möglich nutzen Plugins bestehende TT-RSS-Strukturen (`ttrss_tags`, `ttrss_labels2`, `ttrss_entries.cached_content`).

### Frontend-Integration

- **CSS**: Via `get_css()` -- wird automatisch in die Seite eingebunden
- **JavaScript**: Via `get_js()` -- wird im Hauptfenster geladen
- **Prefs-JS/CSS**: Via `get_prefs_js()` / `get_prefs_css()` -- nur in den Einstellungen
- **Dojo Toolkit**: TT-RSS nutzt Dijit-Widgets (Formulare, Dialoge, Buttons). Plugins integrieren sich in dieses System.
- **Material Icons**: Icon-Font ist global verfügbar (`<i class="material-icons">icon_name</i>`)

---

## 1. reading_time -- Lesezeit-Schätzung

**Verzeichnis:** `plugins.local/reading_time/`
**Dateien:** `init.php`, `reading_time.css`
**Inoreader-Pendant:** "Geschätzte Lesezeit"

### Funktionsweise

Das Plugin berechnet die geschätzte Lesezeit aus dem Artikel-Content und zeigt sie als Badge oberhalb des Textes an.

**Algorithmus:**
1. HTML-Tags aus `$article["content"]` entfernen
2. Wörter zählen via `str_word_count()`
3. CJK-Zeichen (Chinesisch, Japanisch, Koreanisch) separat zählen und als halbe Wörter werten
4. Division durch konfigurierbare WPM (Standard: 200)
5. Minimum: 1 Minute

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_RENDER_ARTICLE` | Badge in Drei-Panel-Ansicht injizieren |
| `HOOK_RENDER_ARTICLE_CDM` | Badge in Combined-/CDM-Ansicht injizieren |
| `HOOK_RENDER_ARTICLE_API` | Badge auch über API bereitstellen |
| `HOOK_PREFS_TAB` | Konfigurationsseite (WPM-Einstellung) |

### Konfiguration

| Einstellung | Standard | Beschreibung |
|-------------|----------|-------------|
| WPM (Wörter pro Minute) | 200 | Lesegeschwindigkeit, anpassbar 50--1000 |

### Darstellung

```
[ schedule ] 3 Min. Lesezeit
```

Das Badge nutzt CSS-Variablen (`--fg-secondary`, `--bg-secondary`) für Theme-Kompatibilität.

---

## 2. boosted_feeds -- Priority-Feeds

**Verzeichnis:** `plugins.local/boosted_feeds/`
**Dateien:** `init.php`, `boosted_feeds.css`
**Inoreader-Pendant:** "Boosted Feeds / Schnellere Feed-Aktualisierung"

### Funktionsweise

Erlaubt es, ausgewählte Feeds mit einem kürzeren Aktualisierungsintervall zu versehen. Nutzt die bestehende `update_interval`-Spalte auf `ttrss_feeds` -- kein neues DB-Schema nötig.

**Ablauf:**
1. Benutzer aktiviert den Boost für einen Feed im Feed-Editor
2. Plugin setzt `update_interval` auf den konfigurierten Boost-Wert (Standard: 5 Min.)
3. Beim Entboosten wird `update_interval` auf 0 zurückgesetzt (= Systemstandard)
4. Bei Änderung des globalen Boost-Intervalls werden alle geboosteten Feeds aktualisiert

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_PREFS_EDIT_FEED` | Checkbox "Feed boosten" im Feed-Editor |
| `HOOK_PREFS_SAVE_FEED` | Boost-Status beim Speichern anwenden |
| `HOOK_PREFS_TAB` | Globales Boost-Intervall + Liste geboosteter Feeds |

### Konfiguration

| Einstellung | Standard | Beschreibung |
|-------------|----------|-------------|
| Boost-Intervall | 5 Min. | Aktualisierungsintervall für geboostete Feeds (1--60 Min.) |

### Architektur-Entscheidung

**Warum bestehende Spalte statt eigene Tabelle?**
`ttrss_feeds.update_interval` wird von `RSSUtils::_update_rss_feed()` direkt ausgewertet. Durch Nutzung dieser Spalte braucht das Plugin keinen eigenen Scheduler -- der bestehende Update-Daemon respektiert das kürzere Intervall automatisch.

---

## 3. keyword_spotlight -- Keyword-Highlighting

**Verzeichnis:** `plugins.local/keyword_spotlight/`
**Dateien:** `init.php`, `keyword_spotlight.css`
**Inoreader-Pendant:** "Keyword-Spotlights"

### Funktionsweise

Definierte Keywords werden in Artikeln farblich hervorgehoben, damit relevante Stellen beim Scannen schneller auffallen.

**Algorithmus:**
1. Keywords aus Plugin-Storage laden (bis zu 5 Farbgruppen)
2. Für jeden Render-Hook: HTML mit `DOMDocument` parsen
3. Per XPath alle Textknoten finden (nicht in `<script>`, `<style>`, `<mark>`)
4. Regex-Pattern aus allen Keywords bauen (case-insensitive)
5. Textknoten splitten und Treffer in `<mark class="ks-highlight">` wrappen
6. Rückgabe des modifizierten HTML

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_RENDER_ARTICLE` | Highlighting in Drei-Panel-Ansicht |
| `HOOK_RENDER_ARTICLE_CDM` | Highlighting in Combined-Ansicht |
| `HOOK_PREFS_TAB` | Keyword-Gruppen mit Farbwähler verwalten |

### Konfiguration

| Einstellung | Standard | Beschreibung |
|-------------|----------|-------------|
| Keyword-Gruppen | 5 Gruppen | Kommagetrennte Keywords pro Gruppe |
| Farbe pro Gruppe | Gelb, Blau, Grün, Rot, Lila | Frei wählbar per Color-Picker |

### Architektur-Entscheidung

**Warum `HOOK_RENDER_ARTICLE_CDM` statt `HOOK_SANITIZE`?**
`HOOK_SANITIZE` wird beim **Feed-Import** aufgerufen (einmalig). Wenn der Benutzer Keywords ändert, müssten alle bestehenden Artikel re-importiert werden. `HOOK_RENDER_ARTICLE_CDM` wird bei **jedem Rendern** aufgerufen -- neue Keywords wirken sofort auf alle Artikel, alte wie neue.

### Beispiel

Konfiguration: Gruppe 1 = "KI, Machine Learning" (gelb), Gruppe 2 = "Datenschutz, DSGVO" (blau)

Ergebnis: `<mark class="ks-highlight" style="background-color: #fff3cd">KI</mark>` im Artikeltext.

---

## 4. filter_log -- Filter-Protokoll

**Verzeichnis:** `plugins.local/filter_log/`
**Dateien:** `init.php`, `filter_log.css`, `sql/pgsql/schema.sql`
**Inoreader-Pendant:** "Filter-Log / Removed today"

### Funktionsweise

Protokolliert jede Filter-Aktion in einer eigenen Datenbanktabelle. Bietet eine Übersicht in den Einstellungen mit Statistiken und Detailtabelle.

### Datenbank-Schema

```sql
ttrss_plugin_filter_log (
  id           SERIAL PRIMARY KEY,
  owner_uid    INTEGER NOT NULL REFERENCES ttrss_users(id),
  feed_id      INTEGER,
  article_title TEXT,
  article_link  TEXT,
  filter_id    INTEGER,
  filter_title TEXT,
  matched_rules TEXT,
  actions      TEXT,
  triggered_at TIMESTAMP DEFAULT NOW()
)
```

Indizes auf `owner_uid` und `triggered_at` für schnelle Abfragen.

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_FILTER_TRIGGERED` | Jeden Filter-Treffer loggen (feed_id, article, matched_filters, rules, actions) |
| `HOOK_PREFS_TAB` | Log-Ansicht mit Statistiken und Tabelle |
| `HOOK_HOUSE_KEEPING` | Automatische Bereinigung (>30 Tage, >1000 Einträge pro User) |

### Prefs-UI

- **Statistiken**: Treffer heute / Einträge gesamt
- **Log-Tabelle**: Zeitpunkt, Artikel (verlinkt), Filter-Name, Matched Rules, Aktionen
- **"Log leeren"-Button**: Löscht alle Einträge des Benutzers
- **Automatische Retention**: 30 Tage, danach automatisch gelöscht via `HOOK_HOUSE_KEEPING`

---

## 5. read_later -- Später Lesen

**Verzeichnis:** `plugins.local/read_later/`
**Dateien:** `init.php`, `read_later.js`, `read_later.css`
**Inoreader-Pendant:** "Read-later-Liste"

### Funktionsweise

Stellt eine "Später lesen"-Funktion bereit, die über ein dediziertes Label implementiert ist. Artikel können per Button oder Hotkey zur Leseliste hinzugefügt/entfernt werden.

### Architektur-Entscheidung

**Warum Label statt IVirtualFeed?**

`IVirtualFeed::get_headlines()` muss ein Ergebnis zurückgeben, das kompatibel mit `Feeds::_get_headlines()` ist -- eine komplexe Funktion mit ~500 Zeilen SQL-Aufbau. Statt diese Logik zu replizieren, nutzt das Plugin ein dediziertes Label (`📌 Später lesen`), das sich automatisch in die bestehende Infrastruktur einfügt:

- **Zähler**: Labels haben eigene Unread-Counts in der Sidebar
- **Feed-Ansicht**: Labels sind als virtuelle Feeds aufrufbar
- **API**: Labels werden von der API korrekt exponiert
- **Suche**: Labels sind in Filtern und Suche nutzbar

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_ARTICLE_BUTTON` | Bookmark-Icon am Artikel (gefüllt/leer je nach Status) |
| `HOOK_HOTKEY_MAP` | Taste `l` für Toggle |
| `HOOK_HOTKEY_INFO` | Hotkey in der Hilfe anzeigen |

### AJAX-Endpunkt

`toggle(id)` -- Wechselt den Read-Later-Status eines Artikels:
- Prüft ob Label zugewiesen via `ttrss_user_labels2`
- Ruft `Labels::add_article()` oder `Labels::remove_article()` auf
- Gibt JSON mit `{id, saved: bool}` zurück

### JavaScript-Integration

```javascript
// Hotkey-Registrierung über TT-RSS App-System
App.hotkey_actions["read_later_toggle"] = () => {
    const id = Headlines.getActive();
    if (id) Plugins.Read_Later.toggle(id);
};
```

---

## 6. af_fulltext -- Volltext-Extraktion

**Verzeichnis:** `plugins.local/af_fulltext/`
**Dateien:** `init.php`, `af_fulltext.js`, `af_fulltext.css`
**Inoreader-Pendant:** "Volltext laden / Persistenter Volltext"

### Funktionsweise

Extrahiert den vollständigen Artikeltext von der Quell-Website und ersetzt den Feed-Ausschnitt. Der Volltext wird in `ttrss_entries.cached_content` gecacht.

**Extraktions-Algorithmus (3-stufige Heuristik):**

1. **Störelemente entfernen**: `<script>`, `<nav>`, `<header>`, `<footer>`, `<aside>`, `<form>`, sowie Elemente mit Klassen wie `sidebar`, `widget`, `comment`, `advertisement`, `newsletter`, `cookie`
2. **Versuch 1 -- Semantische HTML5-Tags**: Suche nach `<article>`, `<main>`, `[role="main"]`, `[itemprop="articleBody"]`, `.article-content`, `.post-content`, `.entry-content` etc. Der längste Treffer gewinnt.
3. **Versuch 2 -- Textdichte**: Alle `<div>` und `<section>` nach Score bewerten: `Textlänge * max(1, Anzahl_p_Tags)`. Container mit >5 Kind-Divs werden abgewertet.
4. **Versuch 3 -- Fallback**: Gesamter `<body>`-Inhalt.

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_ARTICLE_BUTTON` | "Volltext laden"-Button (Material Icon `article`) |
| `HOOK_GET_FULL_TEXT` | Standard-Hook für Volltext-Extraktion (von anderen Plugins aufrufbar) |
| `HOOK_PREFS_TAB` | Informationsseite zur Funktionsweise |

### AJAX-Endpunkt

`fetch(id)`:
1. Prüft `cached_content` -- liefert Cache-Treffer sofort
2. Holt Artikel-URL aus `ttrss_entries.link`
3. Extrahiert Volltext via `extract_article_content()`
4. Sanitized via `Sanitizer::sanitize()` (gleicher Codepath wie normaler Feed-Content)
5. Speichert in `cached_content`
6. Gibt JSON mit `{id, content, cached}` zurück

### Sicherheit

- Extrahierter HTML-Content wird durch `Sanitizer::sanitize()` bereinigt (identisch mit der normalen Feed-Verarbeitung)
- URL-Fetch via `UrlHelper::fetch()` mit 15s Timeout und Redirect-Following
- `libxml_use_internal_errors(true)` unterdrückt HTML5-Parse-Warnungen

---

## 7. enhanced_tags -- Erweitertes Tagging

**Verzeichnis:** `plugins.local/enhanced_tags/`
**Dateien:** `init.php`, `enhanced_tags.js`, `enhanced_tags.css`
**Inoreader-Pendant:** "Tags / Tag-Kreuzselektion"

### Funktionsweise

Baut auf dem bestehenden `ttrss_tags`-System auf und erweitert es um eine benutzerfreundliche UI: Autocomplete-Dialog, Tag-Chips in Artikeln und eine Tag-Cloud in den Einstellungen.

### Bestehende TT-RSS-Infrastruktur

```sql
ttrss_tags (
  id           SERIAL PRIMARY KEY,
  tag_name     VARCHAR(250),
  owner_uid    INTEGER REFERENCES ttrss_users(id),
  post_int_id  INTEGER REFERENCES ttrss_user_entries(int_id)
)
```

Das Plugin nutzt diese Tabelle direkt -- keine eigene Tabelle nötig.

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_ARTICLE_BUTTON` | Tag-Icon am Artikel (orange wenn Tags vorhanden) |
| `HOOK_RENDER_ARTICLE_CDM` | Tag-Chips oberhalb des Contents |
| `HOOK_RENDER_ARTICLE` | Tag-Chips in Drei-Panel-Ansicht |
| `HOOK_PREFS_TAB` | Tag-Cloud mit Größen nach Häufigkeit + Purge-Button |

### AJAX-Endpunkte

| Endpunkt | Beschreibung |
|----------|-------------|
| `get_all_tags()` | Alle Tags mit Häufigkeit (Top 200) |
| `get_article_tags()` | Tags eines einzelnen Artikels |
| `save_tags()` | Tags setzen (löscht bestehende, fügt neue ein, aktualisiert `tag_cache`) |

### JavaScript-Dialog

Der Tag-Edit-Dialog wird dynamisch per DOM-API gebaut (kein `innerHTML` für Benutzerdaten):
- Input-Feld mit kommaseparierten Tags
- Vorschlag-Chips aus den Top-30 häufigsten Tags
- Klick auf Chip fügt Tag zum Input hinzu
- Speichern aktualisiert Button-Status und invalidiert Tag-Cache

---

## 8. ai_core -- LLM-Abstraktionsschicht

**Verzeichnis:** `plugins.local/ai_core/`
**Dateien:** `init.php`
**Inoreader-Pendant:** Basis für alle KI-Features

### Funktionsweise

Zentrale Konfiguration und API-Abstraktion für alle KI-Plugins. Unterstützt vier Provider:

| Provider | Endpoint | Auth | Selbst gehostet |
|----------|----------|------|-----------------|
| **Ollama** (Standard) | `http://localhost:11434/api/chat` | Keine | Ja |
| **OpenAI** | `https://api.openai.com/v1/chat/completions` | Bearer Token | Nein |
| **Anthropic** | `https://api.anthropic.com/v1/messages` | x-api-key Header | Nein |
| **Custom** | Benutzerdefiniert + `/v1/chat/completions` | Bearer Token | Ja/Nein |

### Statische API

```php
// Konfiguration lesen
$config = Ai_Core::get_config();
// → ['provider' => 'ollama', 'api_key' => '', 'endpoint' => '...', 'model' => 'llama3.2']

// LLM-Anfrage senden
$result = Ai_Core::complete(
    "Du bist ein Zusammenfassungs-Assistent.",  // System-Prompt
    "Fasse diesen Text zusammen: ...",           // User-Message
    500                                          // Max Tokens
);
// → "Der Text handelt von..." oder false bei Fehler
```

### Provider-Implementierungen

Alle Provider nutzen `UrlHelper::fetch()` (TT-RSS-eigener HTTP-Client) mit JSON-Payloads:

- **OpenAI/Custom**: OpenAI Chat Completions API Format
- **Anthropic**: Messages API mit `anthropic-version: 2023-06-01` Header
- **Ollama**: `/api/chat` mit `stream: false` für synchrone Antwort

### Prefs-UI

- Provider-Dropdown (Ollama, OpenAI, Anthropic, Custom)
- API-Key (Passwort-Feld, nicht nötig für Ollama)
- Endpoint-URL (vorkonfiguriert je nach Provider)
- Modell-Name (frei wählbar)
- **Verbindungstest-Button**: Sendet Test-Prompt und zeigt Antwort an

---

## 9. ai_summary -- KI-Zusammenfassungen

**Verzeichnis:** `plugins.local/ai_summary/`
**Dateien:** `init.php`, `ai_summary.js`, `ai_summary.css`, `sql/pgsql/schema.sql`
**Inoreader-Pendant:** "Article Summaries / Benutzerdefinierte Prompts"
**Abhängigkeit:** Benötigt `ai_core`

### Funktionsweise

Generiert on-demand KI-Zusammenfassungen für Artikel in drei verschiedenen Modi. Ergebnisse werden in einer eigenen Datenbanktabelle gecacht.

### Zusammenfassungs-Modi

| Modus | Prompt-Strategie | Ausgabe |
|-------|-----------------|---------|
| **short** (Standard) | "Fasse in 1-2 Sätzen zusammen" | Kompakter Überblick |
| **bullets** | "Fasse in 3-5 Stichpunkten zusammen" | Strukturierte Kernaussagen |
| **detailed** | "Fasse in 3-5 Sätzen zusammen, erfasse Kontext" | Ausführliche Einordnung |

### Datenbank-Schema

```sql
ttrss_plugin_ai_summaries (
  id           SERIAL PRIMARY KEY,
  ref_id       INTEGER NOT NULL,
  owner_uid    INTEGER REFERENCES ttrss_users(id),
  summary_type VARCHAR(20) DEFAULT 'short',
  summary_text TEXT,
  model_used   VARCHAR(100),
  created_at   TIMESTAMP DEFAULT NOW()
)
```

Index auf `(ref_id, owner_uid, summary_type)` für schnelle Cache-Lookups.

### Hooks

| Hook | Zweck |
|------|-------|
| `HOOK_ARTICLE_BUTTON` | KI-Button (Material Icon `auto_awesome`) |
| `HOOK_RENDER_ARTICLE_CDM` | Gecachte Zusammenfassung im CDM-Modus anzeigen |
| `HOOK_RENDER_ARTICLE` | Gecachte Zusammenfassung in Drei-Panel-Ansicht |

### AJAX-Endpunkt

`generate(id, type)`:
1. Cache-Prüfung in `ttrss_plugin_ai_summaries`
2. Artikelinhalt laden und auf ~8000 Zeichen kürzen (Token-Limit)
3. `Ai_Core::complete()` mit typ-spezifischem System-Prompt aufrufen
4. Ergebnis in DB cachen
5. JSON-Response mit `{id, summary, type, model, cached}`

### UI-Darstellung

Zusammenfassungen erscheinen als farbige Box oberhalb des Artikels:

```
┌─ auto_awesome KI-Zusammenfassung    llama3.2 ─────┐
│  [Kurz] [Stichpunkte] [Ausführlich]               │
│                                                     │
│  Der Artikel behandelt die neuesten Entwicklungen   │
│  im Bereich der erneuerbaren Energien...            │
└─────────────────────────────────────────────────────┘
```

Die drei Modus-Buttons erlauben den Wechsel ohne die Seite neu zu laden.

---

## Installation und Aktivierung

### Voraussetzungen

- TT-RSS (aktueller `main`-Branch)
- PostgreSQL mit `pg_trgm`-Extension (für `keyword_spotlight`)
- Für KI-Features: Ollama lokal installiert ODER OpenAI/Anthropic API-Key

### Schritt 1: Plugins kopieren

```bash
# Alle Plugins liegen bereits in plugins.local/
ls plugins.local/
# reading_time  boosted_feeds  keyword_spotlight  filter_log
# read_later    af_fulltext    enhanced_tags
# ai_core       ai_summary
```

### Schritt 2: Plugins aktivieren

1. TT-RSS im Browser öffnen
2. **Einstellungen** (Zahnrad-Icon oben rechts)
3. **Plugins** Tab
4. Gewünschte Plugins aktivieren (Checkbox setzen)
5. **Speichern**

**Empfohlene Aktivierungsreihenfolge:**

| Reihenfolge | Plugin | Abhängigkeiten |
|-------------|--------|---------------|
| 1 | `reading_time` | Keine |
| 2 | `boosted_feeds` | Keine |
| 3 | `keyword_spotlight` | Keine |
| 4 | `filter_log` | Keine (erstellt DB-Tabelle automatisch) |
| 5 | `read_later` | Keine (erstellt Label automatisch) |
| 6 | `af_fulltext` | Keine |
| 7 | `enhanced_tags` | Keine |
| 8 | `ai_core` | Keine -- **vor** ai_summary aktivieren |
| 9 | `ai_summary` | `ai_core` muss aktiv sein |

### Schritt 3: Konfiguration

Nach Aktivierung erscheinen die Plugin-Einstellungen in den jeweiligen Prefs-Tabs:

- **reading_time**: Einstellungen > Einstellungen > "Lesezeit (Reading Time)"
- **boosted_feeds**: Einstellungen > Feeds > "Boosted Feeds"
- **keyword_spotlight**: Einstellungen > Einstellungen > "Keyword-Spotlights"
- **filter_log**: Einstellungen > Feeds > "Filter-Log"
- **enhanced_tags**: Einstellungen > Einstellungen > "Tags verwalten"
- **ai_core**: Einstellungen > Einstellungen > "KI-Konfiguration (AI Core)"

### Schritt 4: KI-Setup (optional)

**Option A: Ollama (empfohlen für Self-Hosting)**

```bash
# Ollama installieren (macOS/Linux)
curl -fsSL https://ollama.com/install.sh | sh

# Modell herunterladen
ollama pull llama3.2

# Läuft automatisch auf http://localhost:11434
```

In TT-RSS unter "KI-Konfiguration":
- Provider: Ollama (lokal)
- Endpoint: `http://localhost:11434`
- Modell: `llama3.2`

**Option B: OpenAI API**

- Provider: OpenAI
- API-Key: `sk-...`
- Modell: `gpt-4o-mini` (kosteneffizient) oder `gpt-4o`

**Option C: Anthropic API**

- Provider: Anthropic Claude
- API-Key: `sk-ant-...`
- Modell: `claude-sonnet-4-20250514`

---

## Roadmap: Weitere Phasen

Der vollständige Implementierungsplan umfasst 5 Phasen mit 44 Features. Die hier implementierten Plugins decken die empfohlene Startreihenfolge ab (Phase 1 Kern-Features + AI-Grundlage).

### Phase 2: Quellenaufnahme (geplant)

| Plugin | Beschreibung | Komplexität |
|--------|-------------|-------------|
| `web_feeds` | Webseiten ohne RSS scrapen | L |
| `track_changes` | Seitenänderungen erkennen | L |
| `social_feeds` | Reddit/Mastodon URL-Rewriting | M |
| `news_search_feed` | Google-News-Suchen als Feed | S |
| `monitoring_feeds` | Keyword-basierte Virtual Feeds | M |
| `podcast_player` | HTML5-Audio-Player mit Speed-Control | M |
| `save_page` | Webseiten per Button speichern | S |
| `youtube_sync` | YouTube-Abos importieren | M |

### Phase 3: Automatisierung (geplant)

| Plugin | Beschreibung | Komplexität |
|--------|-------------|-------------|
| `webhooks` | HTTP-Webhooks als Filter-Action | M |
| `push_notify` | Web Push / Gotify / ntfy.sh | L |
| `output_feeds` | RSS aus Ordnern/Tags generieren | M |
| `save_to` | Export zu Pocket/Wallabag/Instapaper | M |
| `pdf_export` | Artikel als PDF exportieren | M |
| `enhanced_search` | Kontextuelle Suche + gespeicherte Suchen | M |

### Phase 4: KI-Erweiterungen (geplant)

| Plugin | Beschreibung | Komplexität |
|--------|-------------|-------------|
| `ai_prompts` | Benutzerdefinierte Prompts auf Artikel | M |
| `ai_chat` | Fragen an Artikel stellen | M |
| `translate` | Artikelübersetzung (LibreTranslate/DeepL) | L |
| `tts` | Text-to-Speech (Web Speech API / Piper) | M |
| `ai_tags` | KI-gestützte Tag-Vorschläge | M |
| `magic_sort` | Relevanz-Sortierung | M |
| `ai_reports` | Multi-Artikel Intelligence Reports | XL |

### Phase 5: Kollaboration & UI (geplant)

| Plugin | Beschreibung | Komplexität |
|--------|-------------|-------------|
| `annotations` | Text-Highlighting und Annotationen | L |
| `reading_progress` | Lesefortschritt pro Artikel | M |
| `dashboards` | Konfigurierbare Widget-Dashboards | XL |
| `team_spaces` | Geteilte Arbeitsbereiche | XL |
| `newsletter_feeds` | E-Mail-Newsletter als Feed | XL |

---

## Technische Referenz

### Wichtige TT-RSS-Klassen

| Klasse | Datei | Verwendung |
|--------|-------|------------|
| `PluginHost` | `classes/PluginHost.php` | Hook-System, Plugin-Storage, Virtual Feeds |
| `Plugin` | `classes/Plugin.php` | Basis-Klasse mit Hook-Signaturen |
| `Db_Migrations` | `classes/Db_Migrations.php` | Plugin-DB-Migrationen |
| `Feeds` | `classes/Feeds.php` | Feed-Abo, Headlines, Virtual-Feed-Routing |
| `RSSUtils` | `classes/RSSUtils.php` | Feed-Update, Filter-Pipeline |
| `Labels` | `classes/Labels.php` | Label-CRUD (create, add_article, remove_article) |
| `Sanitizer` | `classes/Sanitizer.php` | HTML-Bereinigung |
| `UrlHelper` | `classes/UrlHelper.php` | HTTP-Client |
| `TimeHelper` | `classes/TimeHelper.php` | Datums-Formatierung |

### Referenz-Plugins im Core

| Plugin | Pfad | Gutes Beispiel für |
|--------|------|-------------------|
| `af_psql_trgm` | `plugins/af_psql_trgm/` | Prefs-Tab, Feed-Editor, Filter-Hook, Plugin-Storage |
| `note` | `plugins/note/` | Artikel-Button + Dialog + Plugin-Handler |
| `nsfw` | `plugins/nsfw/` | Render-Hooks (CDM + API), Prefs-Tab |
| `bookmarklets` | `plugins/bookmarklets/` | Öffentliche Methoden / API-Endpunkte |

### CSS-Variablen für Theme-Kompatibilität

```css
--fg-secondary    /* Sekundäre Textfarbe */
--bg-secondary    /* Sekundärer Hintergrund */
--border-color    /* Standard-Rahmenfarbe */
--color-accent    /* Akzentfarbe */
```
