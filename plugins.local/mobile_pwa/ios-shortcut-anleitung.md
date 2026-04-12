# iOS Shortcut: „In TT-SS speichern"

Dieser Shortcut erscheint im **Share-Menü** deines iPhones und speichert die geteilte URL direkt in deinem TT-SS RSS-Reader mit dem Label „📌 Später lesen".

---

## Einrichtung (1x)

### Schritt 1: Shortcut erstellen

1. Öffne die **Kurzbefehle**-App auf deinem iPhone
2. Tippe auf **+** (neuer Kurzbefehl)
3. Tippe auf den Namen oben und benenne ihn: **„In TT-SS speichern"**
4. Tippe auf das **ℹ️** (Info-Icon oben) → aktiviere **„Im Share-Sheet anzeigen"**
5. Stelle als Share-Sheet-Typen ein: **URLs**, **Safari-Webseiten**, **Text**

### Schritt 2: Aktionen hinzufügen

Füge folgende Aktionen in dieser Reihenfolge hinzu:

```
1. [Kurzbefehleingabe empfangen]
   → wird automatisch als erste Aktion eingefügt

2. [URL aus Eingabe abrufen]
   → Gibt die geteilte URL zurück

3. [URL abrufen] (HTTP-Request)
   URL:     https://DEIN-SERVER.de/tt-rss/public.php?op=pluginhandler&plugin=browser_extension&pmethod=quick_save
   Methode: POST
   Header:  Content-Type = application/json
   Body (JSON):
   {
     "url": [Variable: URL aus Eingabe abrufen],
     "title": [Variable: Kurzbefehleingabe → Name],
     "api_key": "DEIN_API_SCHLÜSSEL"
   }

4. [Wörterbuch: Wert für Schlüssel abrufen]
   Schlüssel: status

5. [Wenn] Wert [ist gleich] ok
   → [Mitteilung anzeigen] „Gespeichert! ✓"
   [Sonst]
   → [Mitteilung anzeigen] „Fehler beim Speichern"
```

### Schritt 3: Konfigurieren

Ersetze in der URL:
- `DEIN-SERVER.de/tt-rss` → Die URL deiner TT-RSS-Instanz
- `DEIN_API_SCHLÜSSEL` → Dein API-Schlüssel aus TT-RSS (Einstellungen → Browser-Erweiterung)

---

## Verwendung

1. Öffne eine beliebige App (Safari, Twitter/X, YouTube, Reddit, Pocket, …)
2. Tippe auf das **Share-Icon** (Teilen-Pfeil)
3. Scrolle in der unteren Reihe zu **„In TT-SS speichern"**
4. Der Shortcut speichert die URL und zeigt eine Bestätigung

---

## Optionen

### Labels beim Speichern zuweisen

Du kannst dem Shortcut ein weiteres Feld `labels` im JSON-Body hinzufügen:

```json
{
  "url": "...",
  "title": "...",
  "api_key": "...",
  "labels": "Artikel,SEO"
}
```

Labels werden kommagetrennt angegeben. Nur bestehende Labels werden zugewiesen.

### Shortcut mit Menü erweitern

Du kannst einen **„Aus Menü wählen"**-Block hinzufügen, um beim Speichern ein Label auszuwählen:

```
[Aus Menü wählen]
├── Artikel
├── Recherche
├── Inspiration
└── Ohne Label

→ Ergebnis als "labels"-Feld im JSON-Body verwenden
```

---

## API-Endpunkt

```
POST /public.php?op=pluginhandler&plugin=browser_extension&pmethod=quick_save

Body (JSON):
{
  "url": "https://example.com/artikel",
  "title": "Optionaler Titel",
  "labels": "Label1,Label2",     ← optional
  "api_key": "DEIN_SCHLÜSSEL"
}

Antwort:
{
  "status": "ok",
  "ref_id": 12345,
  "title": "Artikeltitel",
  "read_later": true
}
```

---

## Tipps

- **Schnellzugriff**: Halte den Shortcut lang gedrückt in der Kurzbefehle-App → „Zum Home-Bildschirm" → eigenes App-Icon
- **Siri**: Sage „Hey Siri, in TT-SS speichern" nachdem du eine URL kopiert hast
- **Automatisierung**: Erstelle eine Automatisierung die URLs aus bestimmten Apps automatisch speichert
