# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Tiny Tiny RSS (tt-rss) -- ein PHP/JavaScript-basierter RSS/Atom-Reader. Dieses Fork erweitert tt-rss um 54 Custom-Plugins (Inoreader-inspiriert) in `plugins.local/`.

## Development Setup

```bash
cp .env-dist .env              # TTRSS_DB_*, HTTP_PORT, EDGE_TTS_API_KEY konfigurieren
docker-compose up              # PostgreSQL, PHP-FPM, nginx, Updater, Edge-TTS
composer install               # PHP-Abhängigkeiten
npm install                    # Frontend-Dev-Tools
```

## Commands

```bash
# Tests
./phpunit                                        # PHPUnit (tests/, ohne tests/mocked/)
./phpunit --no-configuration --bootstrap tests/MockedDepsBootstrap.php tests/SpecificTest.php  # Einzeltest mit eigenem Bootstrap

# Statische Analyse
phpstan analyze --no-progress                    # Level 6, Config: phpstan.neon

# Linting
npm run lint:js                                  # ESLint (js/, plugins/)
npm run lint:css                                 # Stylelint (flat-ttrss, themes, plugins)
npx stylelint --fix                              # Auto-Fix für CSS/LESS

# Build
npx gulp                                         # LESS → CSS kompilieren (Watch-Mode)

# Übersetzungen
utils/rebase-translations.sh                     # messages.pot aus Quellen extrahieren
```

## Architecture

### Backend (PHP 8.2+, PostgreSQL)

- **Request-Routing**: `backend.php?op=ClassName&method=methodName` → Handler-Klassen in `classes/`
- **Handler-Hierarchie**: `Handler` → `Handler_Protected` (Auth) → `Handler_Administrative` (Admin)
  - Methoden mit `_`-Prefix sind extern blockiert; Methoden mit Pflichtparametern ebenfalls
- **ORM**: Idiorm (`ORM::for_table('ttrss_feeds')->where(...)->find_many()`)
- **Transaktionen**: `Db::pdo()->beginTransaction()` / `commit()` (Idiorm hat keine eigenen)
- **Config**: `classes/Config.php` mit `TTRSS_`-Env-Variablen oder `config.php`-Overrides
- **User-Prefs**: `Prefs::get()` / `Prefs::set()` (nicht das veraltete `get_pref()`)
- **Autoloading**: PSR-4 aus `classes/`, kein Namespace-System (globaler Namespace)

### Frontend (JavaScript + Dojo Toolkit)

- **AMD-Module**: `define(["dojo/_base/declare", ...])` -- kein ES-Module-System
- **Kontextabhängig**: `index.php` → `tt-rss.js` (Feeds, Headlines, Article); `prefs.php` → `prefs.js` (PrefUsers, PrefHelpers)
- **Globale Objekte**: `App` (Utilities, Translations), `xhr.json()` (XHR-Wrapper)
- **Widgets**: Dijit-Bibliothek (`dijit.Dialog`, `dijit.form.TextBox`, etc.)
- **HTML-Helper**: `App.FormFields.*` (JS), `\Controls\*` (PHP) -- bevorzugt gegenüber rohem HTML

### Plugin-System

Plugins in `plugins/` (Core) und `plugins.local/` (Custom) erweitern `Plugin`-Basisklasse:

```
plugins.local/myplugin/
  init.php                # Klasse mit init($host), about(), Hook-Handler
  myplugin.css            # Optional: Styles
  myplugin.js             # Optional: Client-Code
  sql/pgsql/schema.sql    # Optional: DB-Schema
  sql/pgsql/migrations/   # Optional: Migrationen
```

- **Hooks registrieren**: `$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this)` in `init()`
- **Daten speichern**: `PluginHost::set()/get()` (Key-Value) oder eigene DB-Tabellen via `Db_Migrations::initialize_for_plugin()`
- **Assets**: `get_js()` (Main-App), `get_prefs_js()` (Einstellungen), `get_css()` (Styles)
- **PluginHost** nutzt separates `$pdo_data` um Transaktionskonflikte mit Core-Code zu vermeiden

### DB-Schema

- Tabellen mit `ttrss_`-Prefix; Schema-Version in `Config::SCHEMA_VERSION`
- Schema-Änderungen: `sql/pgsql/schema.sql` aktualisieren + `migrations/{version}.sql` erstellen + `SCHEMA_VERSION` hochzählen
- Spezial-Feed-IDs: -1 (Starred), -2 (Published), -3 (Fresh), -4 (All), -6 (Recently Read)

### MCP-Server (`mcp-server/`)

TypeScript-basierter MCP-Server der tt-rss-Instanz. Eigener Build-Prozess: `cd mcp-server && npm install && npm run build`.

### XHR-Kommunikation

Backend gibt JSON via `print json_encode($data)` zurück. Kein festes Schema -- Struktur ist methodenspezifisch. Frontend verarbeitet Standardfelder (`error`, `counters`, `runtime-info`, `message`) automatisch via `App.handleRpcJson()`.

## Coding Conventions

- **Strings**: Single Quotes bevorzugt (alle Sprachen); Double Quotes bei enthaltenen Apostrophen oder Interpolation
- **Einrückung**: Tabs für PHP und JS; 2 Spaces für CSS/LESS (`.editorconfig`)
- **CSS**: Moderne Syntax (`::before`, `rgb(0 0 0 / 30%)`, Einheiten bei 0 weglassen, Hex-Kurzform)
- **Deprecation**: Beim Ändern von Code veraltete Muster ersetzen (z.B. `get_pref()` → `Prefs::get()`)
- **Input**: `clean()` für HTTP-Parameter, `Sanitizer::sanitize()` für HTML-Inhalte, explizite Type-Casts
- **Typ-Hinweise**: Pflicht für PHP-Methodensignaturen (PHPStan Level 6)

## Response Style

Default: knapper technischer Stil. Keine Floskeln, kein Fülltext, kein Hedging (außer bei echter Unsicherheit).
Fragmente statt ganzer Sätze. Technische Begriffe exakt. Fehlermeldungen wörtlich zitieren.
Stil gilt für Erklärungen, nicht für Code.

Struktur: 1) Ursache → 2) Beleg → 3) Fix → 4) Nächster Schritt

Ausnahme: Bei Dokumentation, Stakeholder-Kommunikation oder sicherheitskritischen Erklärungen → klare Prosa.

## Critical Rules

- **Immer Quellcode lesen** bevor Annahmen über Verhalten, Abhängigkeiten oder Struktur getroffen werden. Nie raten.
- **Grep-Workflow**: Abhängigkeiten mit `grep -E '(Config::|Prefs::|PluginHost::|Db::)'` verifizieren
- **Keine Ad-hoc-Dokumentationsdateien** erstellen (kein `DEBUG-SUMMARY.md` etc.) -- Infos gehören in Chat-Antworten oder Code-Kommentare
- **Terminologie prüfen**: MDN für CSS/HTML/JS, php.net für PHP -- keine veralteten Begriffe wie "CSS3" oder "HTML5"
