<img src="https://r2cdn.perplexity.ai/pplx-full-logo-primary-dark%402x.png" style="height:64px;margin-right:32px"/>

# Ich nutze aktuell Inoreader als RSS-Reader und möchte mir mit Tiny Tiny RSS (https://github.com/tt-rss/tt-rss) als Basis einen eigenen RSS-Reader bauen. Recherchiere und Liste mir alle Funktionen und Features von Inoreader auf, die ich als Grundlage für die Weiterentwicklung von Tiny Tiny RSS nutzen möchte. Erstelle für jede Funktion und Feature eine Kurzbeschreibung, die die Funktionalität und Umfang des Features gut beschreiben (als Grundlage für die Weiterentwicklung von Tiny Tiny RSS).

Ja — Inoreader hat inzwischen deutlich mehr als nur klassische RSS-Reader-Funktionen. Für den Ausbau von Tiny Tiny RSS lohnt es sich, die Plattform nicht nur als Feed-Reader, sondern als Kombination aus Reader, Monitoring-, Automatisierungs-, Wissens- und Team-Workspace zu betrachten.[^1_1][^1_2][^1_3][^1_4]

## Funktionsmodell

Als sinnvolle Grundlage für Tiny Tiny RSS würde ich Inoreader in diese Produktbereiche zerlegen: Quellenaufnahme, Lese- und Organisationsfunktionen, Suche/Analyse, Automatisierung/Integrationen sowie Team- und Wissensfunktionen. Diese Struktur folgt den öffentlich beschriebenen Hauptbereichen von Inoreader wie Websites \& RSS, Monitoring feeds, Social listening, Feed automation, Collaboration und Intelligence.[^1_5][^1_3][^1_4][^1_1]

## Feature-Liste

| Bereich | Funktion / Feature | Kurzbeschreibung |
| :-- | :-- | :-- |
| Quellenaufnahme | RSS-Feed-Abonnement | Klassische RSS- und Atom-Feeds abonnieren und zentral lesen; Basisfunktion für den laufenden Bezug von Artikeln aus Websites und Blogs. [^1_3] |
| Quellenaufnahme | Websites ohne RSS folgen (Web Feeds) | Webseiten ohne native RSS-Unterstützung als Feed beobachten; Inhalte werden als feedähnliche Quelle in den Reader integriert. [^1_1][^1_3] |
| Quellenaufnahme | Passwortgeschützte Feeds | RSS-Feeds aus geschützten Systemen per Basic- oder Digest-Authentifizierung abonnieren; relevant für Intranet-, Kunden- oder Research-Quellen. [^1_3] |
| Quellenaufnahme | Newsletter als Feed | E-Mail-Newsletter in reguläre Feed-Einträge umwandeln, damit sie nicht im Posteingang, sondern im Reader verarbeitet werden. [^1_1][^1_3] |
| Quellenaufnahme | Google-News-Suchen als Feed | Suchabfragen aus Google News als abonnierbare Quelle verfolgen; nützlich für Themen-, Markt- oder Personen-Monitoring. [^1_1][^1_3] |
| Quellenaufnahme | Monitoring Feeds | Keyword- oder Such-basierte Monitoring-Feeds für Personen, Marken, Unternehmen, Trends oder Events erzeugen; eher aktive Beobachtung als klassisches Feed-Abonnement. [^1_1][^1_3] |
| Quellenaufnahme | 360° Brand Monitoring | Mehrere Signalarten wie Artikel, Erwähnungen, Marktupdates und Rezensionen in einem Monitoring-Kontext bündeln; geeignet für Wettbewerbs- und Markenbeobachtung. [^1_1] |
| Quellenaufnahme | Social Listening | Inhalte aus sozialen Plattformen wie Facebook, Reddit, Mastodon und Telegram ohne algorithmische Timeline verfolgen; Inoreader beschreibt dies explizit als Social-Listening-Bereich. [^1_1] |
| Quellenaufnahme | Bluesky-Integration | Accounts, Hashtags, Suchergebnisse und Home-Timeline von Bluesky direkt als Quellen in Inoreader verfolgen. [^1_6][^1_3] |
| Quellenaufnahme | YouTube-Synchronisierung | Eigene YouTube-Abos in den Reader spiegeln, sodass Videoquellen gemeinsam mit RSS-Inhalten konsumiert werden können. [^1_1][^1_3] |
| Quellenaufnahme | Podcasts abonnieren | Podcasts im selben System wie News-Feeds verwalten und über den integrierten Audio-Player konsumieren. [^1_7][^1_8] |
| Quellenaufnahme | Feed-Erkennung beim Hinzufügen | Beim Hinzufügen einer URL verfügbare Feeds oder alternative beobachtbare Inhalte automatisch erkennen; 2025 wurde die Erkennung laut Inoreader verbessert. [^1_6] |
| Quellenaufnahme | Add-feed-Zentrale | Eigene Tabs bzw. Eingänge zum Suchen und Hinzufügen neuer Feeds; Teil des neu gestalteten Navigationskonzepts. [^1_1] |
| Quellenaufnahme | Kuratierte Feed-Entdeckung | Über „Discover feeds“ und kuratierte Collections neue Quellen nach Themen entdecken, statt sie nur manuell einzeln hinzuzufügen. [^1_1][^1_3] |
| Quellenaufnahme | Trending Articles | Artikel sehen, die unter Inoreader-Nutzern aktuell im Trend liegen; hilfreich für Discovery und Priorisierung. [^1_3] |
| Quellenaufnahme | Gespeicherte Webseiten | Beliebige externe Web-Seiten per Klick in die eigene Bibliothek übernehmen, auch wenn sie kein Feed-Element sind. [^1_1][^1_3] |
| Quellenaufnahme | Browser-Erweiterung | Browser-Extension zum Speichern von Artikeln und Webseiten direkt aus dem Web in Inoreader. [^1_1][^1_7] |
| Monitoring | Änderungen verfolgen (Track Changes) | Textuelle oder visuelle Änderungen auf Webseiten erkennen, etwa Preisänderungen, Verfügbarkeit oder Content-Updates. [^1_1][^1_3] |
| Monitoring | Schnellere Feed-Aktualisierung (Boosted Feeds) | Ausgewählte Feeds mit kürzerem Refresh-Intervall aktualisieren, wenn Aktualität geschäftskritisch ist. [^1_3] |
| Monitoring | Garantiertes Refresh-SLA | Für Pro-Nutzer wird eine maximale Aktualisierungszeit von mindestens einmal pro Stunde genannt; relevant für verlässliches Near-Real-Time-Monitoring. [^1_3] |
| Lesen | Mehrere Ansichten / Layouts | Inhalte in verschiedenen Layouts wie Listen- oder kartenähnlichen Ansichten lesen; dient unterschiedlichen Arbeits- und Sichtungsmodi. [^1_9][^1_8] |
| Lesen | Volltext laden | Den vollständigen Artikel innerhalb des Readers extrahieren, um Pop-ups, Cookies oder schlechte Zielseiten-UX zu vermeiden. [^1_3][^1_8] |
| Lesen | Persistenter Volltext | Einmal geladener Volltext bleibt im System erhalten und kann danach weiter übersetzt, annotiert oder archiviert werden. [^1_3] |
| Lesen | Geschätzte Lesezeit | Artikelansicht mit Reading-Time-Anzeige, um Aufwand und Priorität beim Lesen besser einschätzen zu können. [^1_1] |
| Lesen | Lesefortschritt | Fortschritt pro Artikel speichern, damit längere Inhalte an der letzten Stelle fortgesetzt werden können. [^1_1] |
| Lesen | Continue Reading | Eigener Bereich für angefangene, noch nicht abgeschlossene Inhalte; nützlich als „Resume Queue“. [^1_1] |
| Lesen | Mark as read and hide | Gelesene Artikel mit einer Aktion zugleich als gelesen markieren und aus der Ansicht ausblenden. [^1_1] |
| Lesen | Nur aktualisierte / ungelesene Inhalte anzeigen | Sidebar-Umschalter, um alle oder nur ungelesene Sektionen zu sehen; getrennt für Feeds und Tags beschrieben. [^1_1] |
| Lesen | Magic Sorting | Artikel nach einer Relevanz-/Popularitätslogik sortieren, sodass wichtige Inhalte oben erscheinen. [^1_3] |
| Lesen | Keyword-Spotlights | Definierte Begriffe oder Phrasen automatisch farblich hervorheben, damit relevante Stellen beim Scannen schneller auffallen. [^1_3][^1_8] |
| Lesen | Artikelübersetzung | Artikel direkt im Reader in über 50 Sprachen übersetzen, ohne externe Tools zu benötigen. [^1_3][^1_8] |
| Lesen | Text-to-Speech | Artikel in Audio umwandeln, Abspielgeschwindigkeit anpassen und geräteübergreifend weiterhören. [^1_3][^1_7] |
| Lesen | Hintergrund-Audio-Player | Artikel und Podcasts im Hintergrund abspielen, während parallel weiter im Reader navigiert wird. [^1_1][^1_3] |
| Lesen | Offline-Lesen | Inhalte in mobilen Apps offline verfügbar machen, um Artikel ohne Netzwerkverbindung lesen zu können. [^1_3][^1_7] |
| Lesen | Audio-Warteschlange / Playlist | Audioinhalte aus Feeds, Ordnern oder Artikeln in eine Queue legen und die Reihenfolge unterwegs anpassen. [^1_1] |
| Lesen | YouTube-Verbesserungen | Shorts unter 60 Sekunden ausfiltern, Videolängen sehen und Live-Videos erkennen; erhöht die Steuerbarkeit von Videoquellen. [^1_1][^1_3] |
| Lesen | Podcast- und Video-Transkripte | Für Podcasts und YouTube-Videos Transkripte bereitstellen, damit Audio-/Videoquellen textuell durchsuchbar und auswertbar werden. [^1_6][^1_3][^1_4] |
| Lesen | Themenextraktion aus Audio/Video | Podcasts und YouTube-Videos um Themen-/Topic-Informationen anreichern, um Inhalte schneller einordnen zu können. [^1_6][^1_3] |
| Lesen | Zusammenfassungen für Audio/Video | Podcasts und YouTube-Videos automatisch zusammenfassen, um den Konsum zeitökonomischer zu machen. [^1_6][^1_3][^1_4] |
| Organisation | Ordner | Feeds thematisch in Ordnern strukturieren, um große Feed-Sammlungen navigierbar zu machen. [^1_3] |
| Organisation | Tags | Artikel mit frei definierbaren Schlagwörtern versehen, um eine zweite, querliegende Organisationsdimension über Ordner hinaus zu erhalten. [^1_1][^1_8] |
| Organisation | Tag-Kreuzselektion | Mehrere Tags kombinieren, um Schnittmengen von Themen gezielt zu durchsuchen oder zu sichten. [^1_1] |
| Organisation | Read-later-Liste | Artikel gezielt für später speichern und in einer separaten Leseliste verwalten. [^1_3] |
| Organisation | Archiv | Gelesene, aber weiterhin relevante Inhalte in ein Archiv verschieben, um die aktive Leseliste sauber zu halten. [^1_1] |
| Organisation | Bearbeitbare Metadaten | Metadaten an gespeicherten Artikeln anpassen, um Inhalte präziser zu verwalten und wiederzufinden. [^1_1] |
| Organisation | Bulk Actions | Mehrere Artikel gleichzeitig speichern, taggen oder teilen; wichtig für effiziente Triage großer Mengen. [^1_3] |
| Organisation | Ungelesen-Dauer pro Ordner steuern | Definieren, wie lange Ordner ungelesene Artikel behalten und die 30-Tage-Standardlogik überschreiben. [^1_3] |
| Organisation | Duplikatfilter | Wiederholte Inhalte aus Feeds unterdrücken, damit gleiche Meldungen nicht mehrfach in der Timeline auftauchen. [^1_3] |
| Organisation | Eigene CSS-Anpassungen | Oberfläche mit eigenem CSS personalisieren; technisch interessant für White-Labeling oder Power-User-UX. [^1_3][^1_10] |
| Suche | Suche in eigenen Feeds | Bereits gesammelte Artikel innerhalb der eigenen Feed-Bestände durchsuchen. [^1_3] |
| Suche | Globale Suche | Nicht nur eigene Feeds, sondern alle öffentlich verfügbaren Quellen im Inoreader-Index durchsuchen. [^1_1][^1_3] |
| Suche | Kontextuelle Suche | Innerhalb eines konkreten Kontexts wie Feed, Ordner oder Kontobereich suchen, statt immer global zu suchen. [^1_1] |
| Suche | Sprachfilter in der Suche | Artikel und Feeds nach Sprache durchsuchen; Inoreader nennt Unterstützung für 30 Sprachen. [^1_3] |
| Analyse | Dashboards | Individuell konfigurierbare Dashboards mit Widgets, Artikeln, Trends und Statistiken für Überblick und Monitoring. [^1_1][^1_3] |
| Analyse | Content-Widgets | Widgets, die relevante Artikel oder Content-Segmente direkt im Dashboard hervorheben. [^1_1] |
| Analyse | Data-Widgets | Widgets zur Beobachtung von Leseverhalten und Feed-Performance; erweitert den Reader in Richtung Analytics. [^1_1] |
| Analyse | Onboarding-Widgets | Dashboard-Elemente, die neue Nutzer durch Einrichtung und erste Schritte führen. [^1_1] |
| Analyse | Feed-Performance-Transparenz | Datenorientierte Sicht auf Nutzung und Quellenleistung, ausdrücklich über Dashboard-Datenwidgets erwähnt. [^1_1] |
| Automatisierung | Regeln (Rules) | Ereignis- oder eigenschaftsbasierte Automatisierungen auf Artikel anwenden, etwa Tagging, Benachrichtigungen oder Weiterleitungen. [^1_3][^1_8] |
| Automatisierung | Content Filters | Artikel anhand definierter Bedingungen zulassen oder entfernen; geeignet für Vorfilterung, Noise-Reduktion und Qualitätskontrolle. [^1_3][^1_1] |
| Automatisierung | Ordnerweite Filter | Filter nicht nur auf einzelne Feeds, sondern auf gesamte Ordner anwenden; erhöht Skalierbarkeit der Feed-Organisation. [^1_1] |
| Automatisierung | Filter-Log / Removed today | Sichtbarkeit auf von Filtern entfernte Artikel über ein Log im Automate-Bereich; wichtig für Debugging und Governance. [^1_1] |
| Automatisierung | Push-Benachrichtigungen | Mobile Alerts auf Basis eigener Präferenzen oder Regeln verschicken, damit kritische Treffer sofort sichtbar werden. [^1_3][^1_8] |
| Automatisierung | IFTTT-Integration | Inoreader-Ereignisse mit IFTTT verknüpfen, um externe Dienste und Workflows anzustoßen. [^1_3][^1_10] |
| Automatisierung | Zapier-Integration | Anbindung an mehr als 1.500 Apps via Zapier für Business-Workflows und Datentransfers. [^1_3] |
| Automatisierung | n8n-Integration | No-/Low-Code-Automatisierung mit n8n für technisch flexiblere und selbst hostbare Workflows. [^1_3] |
| Automatisierung | E-Mail-Digests | Wiederkehrende Digests planen und versenden, etwa für Reporting oder Stakeholder-Updates. [^1_3] |
| Automatisierung | Send to Email | Artikel als Link oder PDF an definierte Empfänger verschicken. [^1_3] |
| Automatisierung | Output Feeds / HTML Clips | Eigene RSS-Feeds oder HTML-Ausgaben aus Ordnern, Tags oder Team-Channels erzeugen und teilen. [^1_3] |
| Automatisierung | Save to other platforms | Artikel an Pocket, Evernote, OneNote, Google Drive, Dropbox oder Instapaper exportieren. [^1_3][^1_8] |
| Automatisierung | PDF-Export | Artikel in PDF umwandeln und lokal speichern; nützlich für Archivierung, Review oder Versand. [^1_3] |
| Automatisierung | Neue Rule-Trigger für Uploads/Reports | Regeln können seit 2025 auch auf neue Uploads und neue Intelligence Reports reagieren. [^1_6] |
| Automatisierung | Neue Rule-Actions für AI/Notizen/Export | Regeln können unter anderem Zusammenfassungen erzeugen, Übersetzungen auslösen, Notizen hinzufügen oder an Raindrop.io senden. [^1_6] |
| Wissen | Annotationen | Textstellen oder ganze Artikel hervorheben, mit Notizen versehen, durchsuchen und später wiederfinden. [^1_3][^1_8] |
| Wissen | In-Browser-Annotation | Inhalte bereits beim Browsen markieren und diese Notizen in Inoreader wiederverwenden; wird in Community-/Store-Beschreibungen genannt. [^1_10] |
| Wissen | Notizen an Artikeln | Eigene Notizen direkt an Inhalte hängen, um aus dem Reader ein Research- und Knowledge-Tool zu machen. [^1_6][^1_3] |
| Wissen | Export nach Readwise | Annotationen exportieren, um Highlights in externe Wissenssysteme zu übernehmen. [^1_3] |
| Wissen | Datei-Uploads | PDFs, Dokumente und Tabellen hochladen und als durchsuchbare Artikel im Reader behandeln. [^1_3] |
| Wissen | Suggested Tags | KI-gestützte Vorschläge für passende Tags auf Basis des Artikelinhalts; beschleunigt semantische Organisation. [^1_3][^1_4][^1_6] |
| KI | Article Summaries | Einzelne Artikel per KI zusammenfassen, vordefinierte oder eigene Prompts ausführen und Fragen zum Inhalt stellen. [^1_5][^1_3][^1_4][^1_6] |
| KI | Benutzerdefinierte Prompts auf Artikeln | Prompts nicht nur fest vorgegeben, sondern frei definierbar auf Artikel anwenden, um je nach Use Case Extraktion oder Analyse zu steuern. [^1_5][^1_6][^1_4] |
| KI | Fragen an Artikel stellen | Artikel als dialogfähige Wissensquelle nutzen, statt ihn nur linear zu lesen. [^1_5][^1_6][^1_4] |
| KI | Intelligence Reports | Mehrere Artikel gesammelt analysieren und aus ihnen strukturierte Berichte, Vergleiche, Sentiment- oder Pattern-Auswertungen erzeugen. [^1_11][^1_6][^1_4] |
| KI | Berichte als Artikel speichern | Generierte Intelligence Reports werden als neue Artikel im System abgelegt und können dadurch annotiert, exportiert oder geteilt werden. [^1_11][^1_6] |
| KI | Automatisierte Reports | Reports auf Basis definierter Quellen automatisch erstellen und ausliefern, statt sie manuell zu starten. [^1_4] |
| Team | Teams / Team Spaces | Gemeinsame Arbeitsbereiche für Organisationen mit getrennten Bereichen für Aktivität, Mitglieder, Ordner, Kanäle und Administration. [^1_1] |
| Team | Team-Ordner | Feed-Sammlungen nach Themen für ausgewählte Teammitglieder freigeben, damit alle denselben Quellenpool nutzen. [^1_4] |
| Team | Team-Filter | Geteilte Filter einsetzen, um Duplikate zu entfernen und relevante Inhalte teamweit hervorzuheben. [^1_4] |
| Team | Team-Channels | Geschützte, einladungsbasierte Räume für thematische Zusammenarbeit und Insight-Sammlung. [^1_4] |
| Team | Team-Spotlights | Gemeinsame Highlight-Sets für Begriffe und Phrasen, damit Teams Inhalte entlang derselben Signale lesen. [^1_4] |
| Team | Team-Annotationen | Hervorhebungen und Notizen gemeinsam nutzen, um Kontext, Interpretation und wichtige Stellen sichtbar zu machen. [^1_1][^1_4] |
| Team | Team-Kommentare | Artikel direkt im System diskutieren, Teammitglieder erwähnen und Antworten innerhalb des Readers führen. [^1_4] |
| Team | Digest-Management | Eigene Tabs für Digests sowie Logs mit Metadaten vergangener Ausgaben; wichtig für redaktionelle Nachvollziehbarkeit. [^1_1] |
| Team | Aktivitätsansicht | Zentraler Überblick über Team-Aktivitäten innerhalb des kollaborativen Bereichs. [^1_1] |
| Team | Organisation \& Billing | Administrative Bereiche für Organisationsstruktur und Abrechnung im Team-Kontext. [^1_1] |
| API / Plattform | Programmatic API Access | Inoreader als Informations-Backend per API in eigene Anwendungen, Pipelines oder Automationsplattformen integrieren. [^1_3] |
| API / Plattform | Persönlicher API-Key für Intelligence | Eigene API-Schlüssel für KI-Funktionen im Teamkontext hinterlegen und zentral verwenden. [^1_4] |
| UI / UX | Responsive Web-Oberfläche | Voll responsive Oberfläche, die laut Inoreader komplette Workflows auch auf dem Smartphone ermöglichen soll. [^1_1] |
| UI / UX | Neue Tab-Bar | Primäre Navigation über eine Tab-Leiste mit schnellem Zugriff auf Kernbereiche. [^1_1] |
| UI / UX | Dynamische Sidebar | Kontextabhängige Seitenleiste für Bereiche wie Dashboards, Feeds, Saved, Teams, Automations und Suche. [^1_1] |
| UI / UX | Resizable Sidebar | Seitenleistenbreite an persönliche Präferenzen anpassen. [^1_1] |
| UI / UX | Preferences-Suche | Einstellungen per Suchfeld schneller finden und anpassen. [^1_1] |
| UI / UX | Werbefreie Free-Version | Seit dem Redesign enthält der Free-Plan laut Inoreader keine Werbung mehr, was die Lesefläche sauberer macht. [^1_1] |

## Architekturhinweise für Tiny Tiny RSS

Für Tiny Tiny RSS wäre es fachlich sinnvoll, die Inoreader-Funktionen nicht 1:1 als monolithische Feature-Liste zu übernehmen, sondern als modulare Domänen umzusetzen: Ingestion, Normalisierung, Reader UX, Rule Engine, Search/Index, Monitoring, Knowledge Layer, Collaboration und AI Layer. Genau diese Entkopplung braucht man, damit klassische RSS-Funktionen, Web-Monitoring, Social-Quellen, Annotationen und KI-Workflows separat erweiterbar bleiben.[^1_6][^1_3][^1_4][^1_1]

Besonders wertvoll als Weiterentwicklungsbasis für tt-rss wären aus meiner Sicht fünf Kernblöcke: Web Feeds/Track Changes, Rules + Filters, Saved/Annotations, Search + Dashboards sowie Intelligence Reports. Diese Blöcke verschieben das Produkt von „Feed lesen“ zu „Informationssystem betreiben“ und bilden damit den größten funktionalen Abstand zu einem klassischen Self-Hosted-RSS-Reader.[^1_3][^1_4][^1_1][^1_6]

## Priorisierung für tt-rss

Wenn du daraus eine umsetzbare Roadmap ableiten willst, würde ich die Features in drei Stufen schneiden. Die Reihenfolge orientiert sich daran, was den größten Produktgewinn bei vertretbarer technischer Komplexität bringt.[^1_4][^1_1][^1_3]

- Phase 1: Web Feeds, Track Changes, Volltext-Laden, Read-later/Archiv, Tags/Ordner, Regeln, Content-Filter, globale Suche, Browser-Extension, Offline-/Audio-Basis.[^1_7][^1_1][^1_3]
- Phase 2: Dashboards, Monitoring Feeds, Google-News-Feeds, Social-Quellen, Annotationen, Bulk Actions, Exporte, API-Verbesserungen, Team-Grundfunktionen.[^1_1][^1_3][^1_4]
- Phase 3: KI-Zusammenfassungen, Prompting, Intelligence Reports, Suggested Tags, Audio-/Video-Transkripte, automatisierte Reports und tiefe Workflow-Automation.[^1_11][^1_5][^1_6][^1_4]

Ich kann dir als Nächstes daraus eine **strukturierte Feature-Matrix für Tiny Tiny RSS** bauen — z. B. mit Spalten für „Inoreader-Feature“, „Nutzen“, „technische Komplexität“, „tt-rss-Modul“, „MVP/Später“ und „Umsetzungsansatz“.
<span style="display:none">[^1_12][^1_13][^1_14][^1_15][^1_16][^1_17][^1_18]</span>

<div align="center">⁂</div>

[^1_1]: https://www.inoreader.com/ca/features

[^1_2]: https://www.inoreader.com/de/features

[^1_3]: https://www.inoreader.com/pricing

[^1_4]: https://www.inoreader.com/pricing/enterprise

[^1_5]: https://www.inoreader.com

[^1_6]: https://www.inoreader.com/blog/2025/12/inoreader-2025-intelligence-and-automation-in-one-content-hub.html

[^1_7]: https://chromewebstore.google.com/detail/inoreader-read-later-and/kfimphpokifbjgmjflanmfeppcjimgah

[^1_8]: https://www.inoreader.com/kr/features

[^1_9]: https://www.reddit.com/r/InoReader/comments/erxgo4/is_paid_inoreader_more_dashboardlike_or_should_i/

[^1_10]: https://www.reddit.com/r/macapps/comments/1i6p4cl/inoreader_gets_new_features/

[^1_11]: https://www.macstories.net/sponsored/inoreader-boost-productivity-and-gain-insights-with-ai-powered-intelligence-tools-sponsor/

[^1_12]: https://www.reddit.com/r/InoReader/comments/1d5pd0k/how_are_you_using_inoreader_what_makes_inoreader/

[^1_13]: https://sourceforge.net/software/compare/Content-Tracker-vs-Inoreader/

[^1_14]: https://mwm.ai/apps/inoreader-news-rss-reader/892355414

[^1_15]: https://apps.apple.com/nz/app/inoreader-news-rss-reader/id892355414

[^1_16]: https://www.inoreader.com/blog/2024/10/the-new-inoreader-experience-is-here.html

[^1_17]: https://www.appvizer.com/marketing/blog/inoreader

[^1_18]: https://play.google.com/store/apps/details?id=com.innologica.inoreader\&hl=en_IN

