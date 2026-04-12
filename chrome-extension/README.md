# TT-SS Reader — Chrome Extension

Browser-Erweiterung für den TT-SS RSS-Reader. Speichere Webseiten direkt aus dem Browser, markiere Textpassagen mit farbigen Highlights, füge Notizen hinzu und synchronisiere alles bidirektional mit deinem RSS-Reader.

---

## Features

### Seiten speichern
- **Extension-Icon** klicken → Seite mit DOM-Inhalt speichern (kein serverseitiges Parsen nötig)
- **Rechtsklick** → „Seite in TT-SS speichern"
- Beim Speichern **Labels zuweisen** und in **„Später lesen"** verschieben
- Inhalt wird direkt aus dem DOM extrahiert (`<article>`, `<main>` oder `[role="main"]`)

### Text-Highlighting (Readwise-Style)
- Text auf einer beliebigen gespeicherten Webseite markieren → **Farbwahl-Popup** erscheint
- **6 Farben**: Gelb, Grün, Blau, Rot, Lila, Orange
- **Notizen** zu einzelnen Highlights hinzufügen
- Rechtsklick → „Text markieren" oder „Text markieren als…" (Farb-Submenu)
- Klick auf bestehendes Highlight → **Bearbeiten** (Farbe ändern, Notiz, Löschen)

### Status-Bar
Fixierte Leiste am oberen Rand jeder gespeicherten Webseite:

```
┌──────────────────────────────────────────────────────────────────────┐
│ 📖 In Reader öffnen ›  [3]  │ Auto highlighting │ Label × │ 📝 │ 📌 │ ∧ │
└──────────────────────────────────────────────────────────────────────┘
```

- **„In Reader öffnen"** — Deep-Link zum Artikel im RSS-Reader
- **Highlight-Zähler** — Anzahl der Annotationen auf der Seite
- **Auto-Highlighting Toggle** — Highlights automatisch anwenden ein/aus
- **Label-Pills** — aktive Labels anzeigen, entfernen, neue hinzufügen
- **Seitennotiz** — Notiz zur gesamten Seite hinzufügen/bearbeiten
- **Später lesen** — Toggle für die Leseliste
- **Einklappen** — Bar minimieren

### Bidirektionale Synchronisation
- Highlights aus der Extension → sofort im RSS-Reader sichtbar
- Highlights aus dem RSS-Reader → beim nächsten Besuch der URL im Browser sichtbar
- Labels, Seitennotizen und Später-Lesen-Status synchronisieren in beide Richtungen
- Re-Sync bei Tab-Fokus (`visibilitychange`)

### Kontextmenü
Rechtsklick auf einer Webseite:
- **Seite in TT-SS speichern** — Seite direkt speichern
- **📌 Später lesen** — Toggle
- **Text markieren** — Auswahl mit Standardfarbe (Gelb) markieren
- **Text markieren als…** → Submenu mit 6 Farben

---

## Installation

### 1. Backend-Plugin aktivieren

Das Plugin `browser_extension` muss in TT-SS aktiviert sein:
1. TT-RSS öffnen → **Einstellungen** → **Plugins**
2. `browser_extension` aktivieren (falls nicht bereits aktiv)
3. Unter **Einstellungen** → **Browser-Erweiterung**: **API-Schlüssel generieren**
4. Schlüssel und Server-URL notieren

> **Wichtig:** Das `annotations`-Plugin muss ebenfalls aktiviert sein, damit Highlights funktionieren.

### 2. Chrome Extension laden

1. Chrome öffnen → `chrome://extensions`
2. **Entwicklermodus** aktivieren (oben rechts)
3. **„Entpackte Erweiterung laden"** klicken
4. Den Ordner `chrome-extension/` auswählen
5. Die Extension erscheint in der Toolbar

### 3. Extension konfigurieren

1. Rechtsklick auf das Extension-Icon → **„Optionen"** (oder über `chrome://extensions` → Details → Erweiterungsoptionen)
2. **Server-URL** eingeben (z.B. `https://rss.example.com/tt-rss`)
3. **API-Schlüssel** eingeben
4. **„Verbindung testen"** klicken → „Verbindung erfolgreich!" bestätigt die Einrichtung
5. **„Speichern"** klicken

---

## Verwendung

### Seite speichern
1. Beliebige Webseite öffnen
2. Extension-Icon klicken → **„Seite speichern"**
3. Nach dem Speichern: Labels zuweisen, „Später lesen" aktivieren
4. Die Status-Bar erscheint am oberen Rand

### Text markieren
1. Text auf einer **gespeicherten** Seite markieren
2. Popup mit Farbwahl erscheint
3. Farbe wählen, optional Notiz eingeben
4. **„Speichern"** klicken
5. Das Highlight ist sofort sichtbar und im RSS-Reader verfügbar

### Highlight bearbeiten
1. Auf ein bestehendes Highlight klicken
2. Farbe ändern, Notiz bearbeiten oder Highlight löschen

### Seitennotiz
1. In der Status-Bar auf **„Notiz"** klicken
2. Text eingeben → **„Speichern"**
3. Die Notiz ist im RSS-Reader beim Artikel sichtbar

---

## Technische Details

### Architektur

```
Chrome Extension                          TT-SS Backend
─────────────────                         ────────────
Content Script (alle Seiten)
  ├── api-client.js      ◄── REST ──►    browser_extension Plugin
  ├── highlighter.js                      (init.php, 12 API-Endpunkte)
  ├── highlight-popup.js (Shadow DOM)         │
  ├── status-bar.js      (Shadow DOM)         ├── ttrss_entries
  └── content-script.js  (Orchestrator)       ├── ttrss_plugin_annotations
                                              ├── ttrss_labels2
Service Worker                                └── ttrss_user_labels2
  ├── Context Menus
  └── Badge Updates

Popup → Speichern, Labels, Später lesen
Options → Server-URL, API-Key
```

### API-Endpunkte

| Endpunkt | Beschreibung |
|----------|-------------|
| `check` | Health-Check |
| `save_article` | Artikel speichern (URL, Titel, HTML-Content) |
| `get_status` | Status einer URL (gespeichert, Labels, Annotationen, Notiz) |
| `get_annotations_for_url` | Alle Highlights für eine URL |
| `save_annotation_for_url` | Neues Highlight erstellen |
| `update_annotation_ext` | Highlight aktualisieren (Farbe, Notiz) |
| `delete_annotation_ext` | Highlight löschen |
| `get_labels` | Alle Labels des Benutzers |
| `set_labels_for_url` | Labels für einen Artikel setzen |
| `toggle_read_later` | Später-Lesen umschalten |
| `get_page_note` | Seitennotiz abrufen |
| `set_page_note` | Seitennotiz setzen |

### Authentifizierung
Alle API-Aufrufe verwenden einen **API-Schlüssel** (`api_key`), der in den TT-RSS-Einstellungen generiert wird. Der Schlüssel wird im JSON-Body jedes Requests mitgesendet.

### URL-Normalisierung
URLs werden vor dem Abgleich normalisiert:
- Fragment (`#...`) entfernen
- `utm_*` Tracking-Parameter entfernen
- Trailing Slash normalisieren

### Seitennotizen
Seitennotizen werden als spezielle Annotationen in der bestehenden `ttrss_plugin_annotations`-Tabelle gespeichert:
- `highlighted_text = ''` (leerer Text)
- `selector_path = '{"type":"page_note"}'` (Marker)

### Shadow DOM
Die Status-Bar und Highlight-Popups nutzen **Shadow DOM**, um CSS-Konflikte mit der Webseite zu vermeiden. Die `<mark>`-Highlight-Elemente leben im regulären DOM, da sie tatsächlichen Text umschließen müssen.

---

## Dateien

```
chrome-extension/
├── manifest.json                 # Manifest V3 Konfiguration
├── icons/
│   ├── icon16.png               # Toolbar-Icon
│   ├── icon48.png               # Extensions-Seite
│   └── icon128.png              # Chrome Web Store
├── background/
│   └── service-worker.js        # Context Menus, Badge, Messages
├── content/
│   ├── api-client.js            # Backend-Kommunikation
│   ├── highlighter.js           # TreeWalker Text-Highlighting
│   ├── highlight-popup.js       # Farbwahl/Notiz-Popup (Shadow DOM)
│   ├── status-bar.js            # Status-Bar (Shadow DOM)
│   ├── content-script.js        # Hauptorchestrierer
│   └── styles/
│       └── highlight.css        # <mark> Styles
├── popup/
│   ├── popup.html               # Popup-UI
│   ├── popup.js                 # Speichern, Labels, Später lesen
│   └── popup.css
└── options/
    ├── options.html             # Einstellungen
    ├── options.js               # Server-URL, API-Key, Verbindungstest
    └── options.css
```
