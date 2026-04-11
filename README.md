Tiny Tiny RSS (tt-rss)
======================

Tiny Tiny RSS (tt-rss) is a free, flexible, open-source, web-based news feed (RSS/Atom/other) reader and aggregator.

## Getting started

Please refer to [the installation guide](https://tt-rss.org/docs/Installation-Guide.html).

## Some notes about this project

* The original tt-rss project, hosted at https://tt-rss.org/ and its various subdomains, was retired on 2025-11-01.
  * Massive thanks to fox for creating tt-rss, and maintaining it (and absolutely everything else that went along with it) for so many years.
* This project (https://github.com/tt-rss/tt-rss) is a fork of tt-rss as of 2025-10-03, created by one of its long-time contributors (`wn_`/`wn_name` on `tt-rss.org`, `supahgreg` on `github.com`).
  * The goal is (as you might expect) to continue tt-rss development.
  * No major breaking changes are planned.
  * Like the original project:
    * The minimum PHP version supported by tt-rss will match [what's in Debian's current `stable` release](https://packages.debian.org/stable/php).
    * What's on the `main` branch (or `latest` and the most recent `sha-*` tag for the Docker images) is intended to be stable
      and safe for use.  Like all software, however, bugs sometimes slip through; the goal is to address those bugs promptly.
    * Using the latest code/image is strongly encouraged, and may be a prerequisite to getting support in certain situations.
  * Developer note: Due to use of `invalid@email.com` on `supahgreg`'s pre-2025-10-03 commits (which were done on `tt-rss.org`) GitHub incorrectly shows `ivanivanov884`
    (the GitHub user associated with that e-mail address) as the author instead of `wn_`/`supahgreg`.  Apologies for any confusion.  `¯\_(ツ)_/¯`
* Docker images (for `linux/amd64` and `linux/arm64`; drop-in replacements for the old images;
  see [the installation guide](https://tt-rss.org/docs/Installation-Guide.html)) are being built and published
  ([via GitHub Actions](https://github.com/tt-rss/tt-rss/actions/workflows/publish.yml)) to:
  * Docker Hub (as [supahgreg/tt-rss](https://hub.docker.com/r/supahgreg/tt-rss/) and [supahgreg/tt-rss-web-nginx](https://hub.docker.com/r/supahgreg/tt-rss-web-nginx/)).
  * GitHub Container Registry (as [ghcr.io/tt-rss/tt-rss](https://github.com/orgs/tt-rss/packages/container/package/tt-rss)
    and [ghcr.io/tt-rss/tt-rss-web-nginx](https://github.com/orgs/tt-rss/packages/container/package/tt-rss-web-nginx)).
* Documentation from https://tt-rss.org has been recreated in https://github.com/tt-rss/tt-rss.github.io,
  which is the new source for https://tt-rss.org content.
  * The original project's repository that held content for https://tt-rss.org was mirrored to https://github.com/tt-rss/tt-rss-web-static .
    Some content tweaks were made after mirroring (prior to the new repository being set up), and the repository is now archived.
* Plugins that were under https://gitlab.tt-rss.org/tt-rss/plugins have been mirrored to `https://github.com/tt-rss/tt-rss-plugin-*`.
  * Plugin repository names have changed to get a consistent `tt-rss-plugin-*` prefix.

## Inoreader-Erweiterungen (Custom Plugins)

Dieses Fork erweitert TT-RSS um Funktionen nach dem Vorbild von [Inoreader](https://www.inoreader.com). Alle Erweiterungen sind als Plugins in `plugins.local/` implementiert und nutzen das bestehende Hook-System -- keine Core-Änderungen nötig.

### Verfügbare Plugins (43 Plugins in 5 Phasen)

#### Phase 1 -- Lese-Erlebnis
| Plugin | Beschreibung |
|--------|-------------|
| **reading_time** | Geschätzte Lesezeit pro Artikel (konfigurierbare WPM) |
| **boosted_feeds** | Kürzere Aktualisierungsintervalle für ausgewählte Feeds |
| **keyword_spotlight** | Keywords farblich hervorheben (5 Farbgruppen) |
| **filter_log** | Protokoll aller Filter-Aktionen mit Detailtabelle |
| **read_later** | Später-Lesen-Funktion mit Hotkey `l` |
| **af_fulltext** | Volltext-Extraktion direkt im Reader |
| **enhanced_tags** | Erweitertes Tagging: Autocomplete, Tag-Cloud, Kreuzsuche |
| **dedup_filter** | Duplikaterkennung via Titel-Ähnlichkeit und URL |

#### Phase 2 -- Quellenaufnahme
| Plugin | Beschreibung |
|--------|-------------|
| **web_feeds** | Webseiten ohne RSS per CSS-Selektoren scrapen |
| **social_feeds** | Reddit/YouTube/Mastodon/GitHub URL-Rewriting zu RSS |
| **news_search_feed** | Google-News-Suchen als Feed abonnieren |
| **track_changes** | Webseiten auf Änderungen überwachen |
| **monitoring_feeds** | Keyword-basiertes Artikel-Monitoring mit Auto-Tagging |
| **podcast_player** | HTML5-Audio-Player mit Speed-Control und Fortschritt |
| **save_page** | Beliebige Webseiten als Artikel speichern |
| **youtube_sync** | YouTube-Abos importieren + Video-Embedding |
| **feed_autodetect** | Erweiterte Feed-Erkennung (14 gängige Pfade) |

#### Phase 3 -- Automatisierung
| Plugin | Beschreibung |
|--------|-------------|
| **webhooks** | HTTP-Webhooks bei Filter-Aktionen mit HMAC-Signatur |
| **push_notify** | Push-Benachrichtigungen (ntfy.sh/Gotify/Pushover) |
| **output_feeds** | RSS-Feeds aus Ordnern/Labels/Tags generieren |
| **save_to** | Export zu Wallabag/Pocket/Linkding/Instapaper |
| **pdf_export** | Artikel als druckoptimiertes HTML exportieren |
| **enhanced_search** | Gespeicherte Suchen mit Quick-Access |
| **enhanced_filters** | Erweiterte Filter-Regeln (Länge, Regex, Score) |
| **bulk_actions** | Massenoperationen: Taggen, Label zuweisen |
| **browser_extension** | Server-API für Browser-Erweiterung + Bookmarklet |

#### Phase 4 -- KI & Intelligence
| Plugin | Beschreibung |
|--------|-------------|
| **ai_core** | LLM-Abstraktionsschicht (Ollama/OpenAI/Anthropic/Custom) |
| **ai_summary** | KI-Zusammenfassungen in 3 Modi (Kurz/Stichpunkte/Ausführlich) |
| **ai_prompts** | Benutzerdefinierte KI-Prompts auf Artikel anwenden |
| **ai_chat** | Fragen an Artikel stellen (Chat-Interface) |
| **translate** | Artikelübersetzung (LibreTranslate/DeepL/KI) |
| **tts** | Text-to-Speech via Browser Web Speech API |
| **ai_tags** | KI-gestützte Tag-Vorschläge + Auto-Tagging |
| **magic_sort** | Relevanz-Sortierung nach Feed-Engagement |
| **ai_reports** | Multi-Artikel Intelligence Reports |

#### Phase 5 -- Kollaboration & UI
| Plugin | Beschreibung |
|--------|-------------|
| **annotations** | Text-Highlighting und Annotationen in Artikeln |
| **reading_progress** | Lesefortschritt mit Fortschrittsbalken |
| **resizable_sidebar** | Seitenleiste per Drag in der Breite anpassen |
| **dashboards** | Konfigurierbare Dashboards mit Widgets |
| **edit_metadata** | Artikelmetadaten (Titel, Autor) bearbeiten |
| **newsletter_feeds** | E-Mail-Newsletter per IMAP als Feed |
| **team_spaces** | Team-Arbeitsbereiche mit geteilten Artikeln |
| **file_uploads** | PDF/TXT/HTML-Dateien als Artikel hochladen |

### Schnellstart

1. Plugins in TT-RSS unter **Einstellungen > Plugins** aktivieren
2. Konfiguration über die jeweiligen Prefs-Tabs (Einstellungen > Feeds/Einstellungen)
3. Für KI-Features: `ai_core` zuerst aktivieren, Provider konfigurieren (Ollama empfohlen)
4. Für Übersetzung: `translate` konfigurieren (LibreTranslate für Self-Hosting)
5. Für Team-Features: `team_spaces` aktivieren, Teams in den Einstellungen erstellen

### Dokumentation

Die ausführliche technische Dokumentation aller Plugins befindet sich in [`plugins.local/PLUGINS-DOKUMENTATION.md`](plugins.local/PLUGINS-DOKUMENTATION.md).

## Änderungen & Weiterentwicklungen gegenüber dem Original-Repo

Dieses Repository basiert auf dem Community-Fork von [tt-rss/tt-rss](https://github.com/tt-rss/tt-rss) und enthält folgende eigene Erweiterungen:

- **43 eigene Plugins** in `plugins.local/` nach dem Vorbild von Inoreader — von KI-Zusammenfassungen über Feed-Scraping bis hin zu Team-Workspaces
- **KI-Abstraktionsschicht** (`ai_core`) mit einheitlichem Interface für Ollama, OpenAI, Anthropic und kompatible APIs
- **Sicherheitskorrekturen** in 18 Plugins (Input-Validierung, CSRF-Schutz, sichere Shell-Aufrufe)
- **Feed-Autodetect-Bugfix**: Fehlerbehebung beim Abonnieren von HTML-Seiten und Korrektur von HTML5-Boolean-Attributen in der XML-Verarbeitung
- **Sticky Artikel-Header mit Lesefortschrittsbalken**: Der Header (Titel, Datum, Tags) bleibt beim Langen beim Scrollen sichtbar; der Fortschrittsbalken ist am unteren Rand des Headers verankert und zeigt den exakten Lesefortschritt von 0–100 %
- **Textvorschau im Tree Panel entfernt**: Die Inhaltsvorschau (`content_preview`) wird in der Headline-Liste (Tree Panel View) nicht mehr angezeigt und auch nicht mehr per JSON an den Client übertragen. Im CDM-Modus (Combined Mode) bleibt die Vorschau bei eingeklappten Artikeln erhalten.

## Development and contributing

Contributions (code, translations, reporting issues, etc.) are welcome. Please see [CONTRIBUTING.md](CONTRIBUTING.md) for more information.

## License

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

Copyright (c) 2005 Andrew Dolgov (unless explicitly stated otherwise).
