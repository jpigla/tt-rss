# Reader Mode — Design-Spezifikation

## Kontext

Der tt-rss-Fork wird neben einer Nachrichtenzentrale auch als Lese- und Annotationswerkzeug genutzt. Aktuell fehlt ein dedizierter Lesemodus, der Inhalte ablenkungsfrei mit Metadaten-Kontext und Annotations-Werkzeugen darstellt. Ziel: ein Readwise-Reader-inspiriertes Interface zum fokussierten Lesen, Konsumieren und Herausarbeiten von Erkenntnissen.

## Architektur

### Ansatz

Neues Plugin `plugins.local/reader_mode/` (Ansatz A) — eigenständige Seite mit tt-rss-Session-Auth. Das bestehende `annotations`-Plugin wird separat um Marker erweitert.

### Dateistruktur

```
plugins.local/reader_mode/
  init.php              # Plugin-Klasse, Hooks, Backend-API
  reader.php            # Eigenständige Seite (wie prefs.php)
  reader_mode.js        # Client-Logik: TOC, Tabs, Layout
  reader_mode.css       # Drei-Spalten-Layout, Reader-Typographie
  locale/               # Übersetzungsstrings
```

### URL & Auth

- URL: `reader.php?id={article_id}`
- Session-Check: `UserHelper::authenticate()` + Redirect bei fehlender Session
- Artikel-Berechtigung: `owner_uid`-Check gegen aktive Session

### Einstiegspunkt

- `HOOK_ARTICLE_BUTTON` registriert „Reader öffnen"-Button
- Klick öffnet `reader.php?id=X` in `target="_blank"`
- Optional: Tastenkürzel `r` im Hauptinterface

### Datenfluss

```
reader.php
  → Session validieren
  → Article aus DB laden (ttrss_entries + ttrss_user_entries + ttrss_feeds)
  → Annotations aus ttrss_plugin_annotations laden
  → HTML-Template rendern: 3-Spalten-Layout
  → HOOK_RENDER_ARTICLE aufrufen (Plugins können Content modifizieren)
  → JSON-Metadaten ins DOM einbetten (readwise_theme-Muster)
  → Volltext automatisch via HOOK_GET_FULL_TEXT laden
```

## Layout

### Drei-Spalten-Struktur

```
┌──────────────────────────────────────────────────────────┐
│  Toolbar (sticky top)                                     │
│  [← Zurück] [↑↓ Prev/Next]  [Aa] [Breite]  [☆] [↗] [⋯]│
├────────────┬─────────────────────────┬───────────────────┤
│  TOC       │  Content                │  Sidebar          │
│  (240px)   │  (flex, 3 Breiten)      │  (300px)          │
│            │                         │                    │
│  auto-hide │  Optimierte Typographie │  [Info] [Notizen] │
│  wenn keine│  + Annotations          │                    │
│  Headings  │                         │                    │
├────────────┴─────────────────────────┴───────────────────┤
│  Lesefortschritt-Balken (sticky bottom)                   │
└──────────────────────────────────────────────────────────┘
```

### Content-Breiten

| Stufe | max-width | Einsatz |
|-------|-----------|---------|
| Schmal | 560px | Konzentriertes Lesen |
| Mittel | 720px | Default |
| Breit | 920px | Tabellen, Code-Blöcke |

### Responsive Verhalten (Default)

| Breite | Verhalten |
|--------|-----------|
| >=1200px | Alle drei Spalten sichtbar |
| 768-1199px | TOC ausgeblendet, Content + Sidebar |
| <768px | Nur Content, Sidebar als Overlay |

Alle Spalten sind zusätzlich manuell per Button toggle-bar, unabhängig vom Responsive-Default.

## TOC-Panel (linke Spalte)

### Heading-Extraktion

- Client-seitig: `querySelectorAll('h1, h2, h3, h4')` im Content
- Verschachtelte Liste nach Heading-Level
- Lange Überschriften auf ~60 Zeichen gekürzt (Ellipsis)

### Scroll-Verhalten

- Klick auf TOC-Eintrag: Smooth-Scroll zum Heading, Flash-Effekt
- Scroll im Content: Aktiver TOC-Eintrag markiert (Intersection Observer)
- TOC scrollt mit, aktiver Eintrag bleibt im Viewport

### Auto-Hide

- Kein Heading im Artikel: TOC-Spalte entfällt, Content bekommt volle Breite
- Manuell über Toolbar-Button ein-/ausblendbar
- State per `localStorage`

## Sidebar — Info-Tab (rechte Spalte)

### Metadaten

```
Artikeltitel (h2)
domain.com

○ Autorname
  @handle

METADATEN
Typ        Artikel
Domain     example.com  → (Filter: öffnet tt-rss mit site:domain)
Feed       Feedname     → (öffnet Feed in tt-rss)
Publiziert 14. Apr 2026
Lesezeit   10 Min
Wörter     2.593
Sprache    Deutsch
Gespeichert vor 2 Std
Fortschritt 10%
Labels     [Label1] [Label2]

ORIGINAL-LINK
https://example.com/full-url
```

### Datenquellen

| Feld | Quelle |
|------|--------|
| Titel, Autor, Link | `ttrss_entries` |
| Domain | `parse_url($link, PHP_URL_HOST)` |
| Feed | `ttrss_feeds.title` |
| Publiziert | `ttrss_entries.updated` |
| Lesezeit | `reading_time`-Plugin (Wörter / 200) |
| Wörter | `str_word_count()` auf sanitized Content |
| Sprache | `ttrss_entries.lang` |
| Gespeichert | `ttrss_user_entries.date_entered` |
| Fortschritt | `reading_progress`-Plugin |
| Labels | `ttrss_user_labels2` |

### Domain-Filter

Klick auf Domain öffnet `index.php` mit Suchfilter `site:example.com` (nutzt `enhanced_search` oder tt-rss-Standard-Suche).

## Sidebar — Notizen-Tab

### Annotations-Liste

Alle Highlights des Artikels, chronologisch sortiert. Jede Karte zeigt:

- Farbiger Linker Rand (Highlight-Farbe)
- Markierter Text (gekürzt)
- Notiz (falls vorhanden)
- Marker als Chips (z.B. `▸privacy` `▸fingerprinting`)
- Zeitstempel

### Interaktion

- Klick auf Karte: Scrollt zur Stelle im Content, Flash-Effekt
- Hover: Markierung im Content wird stärker hervorgehoben
- Inline-Bearbeitung: Notiz und Marker direkt editierbar
- Löschen: `x`-Button mit Bestätigung

### Highlight-Erstellung im Content

- Text markieren → Floating-Toolbar
- Toolbar: 5 Farben + Notiz-Icon + Marker-Icon
- Nach Erstellung: Karte erscheint sofort im Notizen-Tab
- Marker-Input: Freitext mit Autocomplete aus bestehenden Markern des Nutzers

## Annotations-Plugin-Erweiterung

### DB-Schema-Änderung

```sql
-- Migration v2: plugins.local/annotations/sql/pgsql/migrations/2.sql
ALTER TABLE ttrss_plugin_annotations ADD COLUMN markers TEXT DEFAULT '';
```

Marker als komma-separierter String. Schema-Version von 1 auf 2.

### API-Erweiterung

| Endpoint | Änderung |
|----------|----------|
| `save_annotation` | Neuer Parameter `markers` (String) |
| `update_annotation` | Marker editierbar |
| `get_annotations` | Marker im Response |
| `get_all_markers` | Neuer Endpoint: distinct Marker des Nutzers (Autocomplete) |

### Terminologie

| Konzept | Herkunft | Bezeichnung |
|---------|----------|-------------|
| Feed/Artikel-Tags | Automatisch vom Feed | **Labels** |
| Nutzer-Tags auf Highlights | Manuell gesetzt | **Marker** |

### Rückwärtskompatibilität

- Bestehende Highlights ohne Marker funktionieren weiter (`DEFAULT ''`)
- `markers`-Parameter ist optional in allen API-Calls
- Kein Breaking Change

## Toolbar

```
┌──────────────────────────────────────────────────────────────┐
│ [←] [▲] [▼]  │  [Aa ▾]  [≡ Breite ▾]  │  [☆] [⤓] [↗] [⋯] │
│  Navigation   │  Darstellung            │  Aktionen           │
└──────────────────────────────────────────────────────────────┘
```

### Navigation (links)

- **←** Zurück (`history.back()`)
- **▲ / ▼** Vorheriger / Nächster Artikel im selben Feed

### Darstellung (Mitte)

- **Aa** Dropdown:
  - Schriftgröße (Slider: 14-22px)
  - Zeilenhöhe (Slider: 1.4-2.2)
  - Absatzabstand (Slider: 0.8-2.0em)
  - Schriftart: Sans / Serif Toggle
- **≡ Breite** Drei Buttons: Schmal / Mittel / Breit

### Aktionen (rechts)

- **☆** Stern/Bookmark togglen
- **⤓** Fulltext neu laden
- **↗** Original-URL in neuem Tab
- **⋯** Menü: PDF-Export, Teilen, Read-Later, AI-Zusammenfassung

### Persistenz

Alle Darstellungseinstellungen global pro Nutzer in `PluginHost::set()`.

## Content-Bereich

### Volltext-Autoload

- Reader ruft automatisch `HOOK_GET_FULL_TEXT` auf
- Ladeindikator während Fetch
- Fallback: Originaler Feed-Content

### Typographie

- Sans-Serif Default (System-UI), Serif umschaltbar
- Zeilenhöhe: 1.7 Default (konfigurierbar)
- Absatzabstand: 1.2em Default (konfigurierbar)
- Bilder: `max-width: 100%`, zentriert, Lightbox bei Klick

### Lesefortschritt

- Fortschrittsbalken am unteren Rand (Scroll-Position)
- Speichert Position via `reading_progress`-Plugin
- Beim erneuten Öffnen: Scrollt zur gespeicherten Position

### Tastaturkürzel

| Taste | Aktion |
|-------|--------|
| `Esc` | Reader schließen |
| `j` / `k` | Nächster / Vorheriger Artikel |
| `t` | TOC-Panel togglen |
| `i` | Sidebar togglen |
| `1` / `2` / `3` | Content-Breite |
| `s` | Stern togglen |
| `h` | Highlight-Modus |

## CSS-Architektur

- CSS Custom Properties für alle konfigurierbaren Werte
- Übernimmt `readwise_theme`-Variablen wenn vorhanden, eigenes Fallback-Set sonst
- Dunkles Theme als Default

## Bestehende Plugin-Integration

| Plugin | Integration |
|--------|-------------|
| `annotations` | Erweitert um Marker, Reader nutzt dessen API |
| `af_fulltext` | Volltext-Autoload via Hook |
| `reading_progress` | Fortschrittsbalken + Scroll-Position |
| `reading_time` | Lesezeit im Info-Tab |
| `readwise_theme` | CSS-Variablen übernommen wenn aktiv |
