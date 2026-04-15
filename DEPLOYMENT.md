# TT-RSS Deployment auf VPS — rss.jpigla.de

> Anleitung für die Produktiv-Installation auf einem Linux-VPS.
> Die Hauptdomain `jpigla.de` läuft über Vercel (Astro) — die Subdomain `rss.jpigla.de` zeigt per DNS-A-Record direkt auf den VPS.

---

## Voraussetzungen

| Was | Minimum |
|-----|---------|
| VPS | 1 vCPU, 2 GB RAM, 20 GB SSD (Hetzner CX22, Netcup VPS 1000 o.ä.) |
| OS | Ubuntu 24.04 LTS oder Debian 12 |
| Domain | `jpigla.de` bei Vercel DNS verwaltet |
| Lokal | Git, SSH-Key für den VPS |

---

## 1. DNS-Eintrag bei Vercel

Da `jpigla.de` über Vercel verwaltet wird, muss der A-Record für die Subdomain im **Vercel Dashboard** gesetzt werden:

1. [Vercel Dashboard](https://vercel.com) → Projekt `jpigla.de` → **Settings** → **Domains**
2. Subdomain `rss.jpigla.de` hinzufügen — Vercel zeigt eine Warnung, dass die Domain nicht auf Vercel zeigt. Das ist korrekt.
3. Alternativ: **Vercel DNS** → Domain `jpigla.de` → DNS Records:

```
Typ:   A
Name:  rss
Wert:  <VPS-IP-Adresse>
TTL:   300
```

> **Hinweis**: Vercel verwaltet die Nameserver für `jpigla.de`. Deshalb muss der A-Record dort gesetzt werden, nicht beim Domain-Registrar. Ein AAAA-Record für IPv6 ist empfehlenswert, falls der VPS IPv6 unterstützt.

DNS-Propagierung prüfen:

```bash
dig rss.jpigla.de +short
# Erwartete Ausgabe: <VPS-IP-Adresse>
```

---

## 2. VPS-Grundkonfiguration

### 2.1 SSH-Zugang & Basis-Sicherheit

```bash
# Auf dem VPS als root:
apt update && apt upgrade -y

# Neuen Benutzer anlegen (nicht als root arbeiten)
adduser deploy
usermod -aG sudo deploy

# SSH-Key kopieren (vom lokalen Rechner)
ssh-copy-id deploy@<VPS-IP>

# SSH härten: /etc/ssh/sshd_config
# PermitRootLogin no
# PasswordAuthentication no
# MaxAuthTries 3
sudo systemctl restart sshd
```

### 2.2 Firewall

```bash
sudo apt install ufw
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

### 2.3 Docker & Docker Compose installieren

```bash
# Docker (offizielle Quelle)
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker deploy
newgrp docker

# Prüfen
docker --version
docker compose version
```

### 2.4 Fail2Ban (Brute-Force-Schutz)

```bash
sudo apt install fail2ban -y
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
```

In `/etc/fail2ban/jail.local` anpassen:

```ini
[sshd]
enabled = true
maxretry = 3
bantime = 3600
```

```bash
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

---

## 3. TT-RSS deployen

### 3.1 Repository klonen

```bash
# Als deploy-User
cd /opt
sudo mkdir ttrss && sudo chown deploy:deploy ttrss
git clone <DEIN-REPO-URL> /opt/ttrss
cd /opt/ttrss
```

### 3.2 Umgebungsvariablen konfigurieren

```bash
cp .env-dist .env
```

`.env` bearbeiten — **alle Werte anpassen**:

```bash
# ── Datenbank (sichere Passwörter!) ──────────────────
TTRSS_DB_USER=ttrss
TTRSS_DB_NAME=ttrss
TTRSS_DB_PASS=<SICHERES-PASSWORT-HIER>     # openssl rand -base64 32

# ── URL (MUSS gesetzt sein) ─────────────────────────
TTRSS_SELF_URL_PATH=https://rss.jpigla.de/tt-rss

# ── Session: 30 Tage für mobilen Betrieb ────────────
TTRSS_SESSION_COOKIE_LIFETIME=2592000

# ── Admin-Passwort (beim ersten Start gesetzt) ──────
ADMIN_USER_PASS=<ADMIN-PASSWORT-HIER>       # openssl rand -base64 24

# ── Port: nur Loopback (Caddy macht den Rest) ───────
HTTP_PORT=127.0.0.1:8280

# ── Edge-TTS API-Key ────────────────────────────────
EDGE_TTS_API_KEY=$(openssl rand -hex 32)
```

> **Wichtig**: `HTTP_PORT=127.0.0.1:8280` — Der Port ist nur lokal erreichbar. Caddy leitet den Traffic von außen weiter.

### 3.3 Container starten

```bash
docker compose build
docker compose up -d

# Logs prüfen
docker compose logs -f app    # Auf Fehlermeldungen achten
docker compose logs -f db     # DB-Initialisierung prüfen
```

### 3.4 Status prüfen

```bash
# Alle Container laufen?
docker compose ps

# App erreichbar?
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8280/tt-rss/
# Erwartete Ausgabe: 200
```

---

## 4. Reverse Proxy mit Caddy (HTTPS automatisch)

Caddy wird als Reverse Proxy **außerhalb von Docker** installiert. Er kümmert sich automatisch um Let's-Encrypt-Zertifikate und HTTPS-Terminierung.

> **Warum Caddy statt nginx?** Der interne nginx-Container (web-nginx) übernimmt PHP-FPM-Routing, Gzip und Security-Header. Caddy davor macht ausschließlich TLS-Terminierung und Proxying — einfacher als ein zweites nginx mit Certbot zu konfigurieren.

### 4.1 Caddy installieren

```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install caddy -y
```

### 4.2 Caddyfile konfigurieren

```bash
sudo nano /etc/caddy/Caddyfile
```

Inhalt:

```caddyfile
rss.jpigla.de {
    # Reverse Proxy zum Docker-nginx-Container
    reverse_proxy 127.0.0.1:8280

    # Logging
    log {
        output file /var/log/caddy/rss-access.log {
            roll_size 10mb
            roll_keep 5
        }
    }

    # Zusätzliche Header (ergänzend zu den nginx-internen)
    header {
        # Caddy setzt HSTS automatisch, aber wir verstärken es
        Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    }
}
```

### 4.3 Caddy starten

```bash
sudo mkdir -p /var/log/caddy
sudo systemctl enable caddy
sudo systemctl restart caddy

# Zertifikat prüfen
sudo caddy validate --config /etc/caddy/Caddyfile
curl -I https://rss.jpigla.de/tt-rss/
```

Caddy holt automatisch ein Let's-Encrypt-Zertifikat für `rss.jpigla.de`. Voraussetzung: DNS-A-Record zeigt bereits auf den VPS und Port 80/443 sind offen.

---

## 5. Erster Login & Einrichtung

1. **Browser öffnen**: `https://rss.jpigla.de/tt-rss/`
2. **Login**: `admin` / das Passwort aus `ADMIN_USER_PASS` in `.env`
3. **Eigenen Benutzer anlegen**: Einstellungen → Benutzer → Neuen Benutzer erstellen
4. **Admin-Account sperren** (optional, empfohlen):
   ```bash
   # In .env setzen und Container neu starten:
   ADMIN_USER_ACCESS_LEVEL=-2
   docker compose up -d app
   ```
5. **Plugins aktivieren**: Einstellungen → Plugins → gewünschte Plugins aus `plugins.local/` aktivieren

---

## 6. Mobile Nutzung (iPhone/iOS)

### 6.1 PWA installieren (empfohlen)

TT-RSS enthält ein PWA-Plugin (`mobile_pwa`) mit iOS-optimierter Touch-Bedienung:

1. **Safari öffnen** → `https://rss.jpigla.de/tt-rss/`
2. **Teilen-Button** (Quadrat mit Pfeil nach oben) → **Zum Home-Bildschirm**
3. Die App läuft jetzt im Vollbild-Modus als eigenständige App

Features der PWA:
- Swipe-Navigation (Artikel vor/zurück)
- Touch-optimierte Buttons (44px nach Apple HIG)
- Offline-Cache via Service Worker
- Vollbild ohne Safari-Adressleiste
- Push-Benachrichtigungen (falls konfiguriert)

### 6.2 Native App-Alternativen

Falls du eine native App bevorzugst — TT-RSS bietet eine API, die von diesen Apps unterstützt wird:

| App | Preis | Hinweis |
|-----|-------|---------|
| **Tiny Reader RSS** | Kostenlos | Offizielle iOS-App für TT-RSS |
| **FeedMe** | Kostenlos | Android; unterstützt TT-RSS-API |
| **Fiery Feeds** | 5,99 € | Premium iOS RSS-Reader mit TT-RSS-Support |

API-Einrichtung in der App:
- **Server-URL**: `https://rss.jpigla.de/tt-rss/`
- **Benutzername/Passwort**: Dein TT-RSS-Account
- **API aktivieren**: In TT-RSS unter Einstellungen → Einstellungen → API-Zugriff aktivieren

### 6.3 Session-Dauer für Mobilgeräte

Die `.env` ist bereits auf 30 Tage Session-Lebensdauer konfiguriert (`TTRSS_SESSION_COOKIE_LIFETIME=2592000`). Damit musst du dich auf dem iPhone nicht ständig neu einloggen.

---

## 7. Wartung & Updates

### 7.1 TT-RSS aktualisieren

```bash
cd /opt/ttrss

# Änderungen vom Repository holen
git pull

# Container neu bauen und starten
docker compose build
docker compose up -d

# Logs prüfen
docker compose logs -f app
```

### 7.2 Datenbank-Backup

```bash
# Manuelles Backup
docker compose exec db pg_dump -U ttrss ttrss > backup_$(date +%Y%m%d).sql

# Automatisches wöchentliches Backup (Cron)
sudo crontab -e
```

Cron-Eintrag:

```cron
0 3 * * 0  cd /opt/ttrss && docker compose exec -T db pg_dump -U ttrss ttrss | gzip > /opt/ttrss/backups/backup_$(date +\%Y\%m\%d).sql.gz
```

```bash
mkdir -p /opt/ttrss/backups
```

### 7.3 System-Updates

```bash
# Monatlich oder bei Security-Advisories
sudo apt update && sudo apt upgrade -y
docker compose pull     # Base-Images aktualisieren
docker compose build
docker compose up -d
docker image prune -f   # Alte Images aufräumen
```

### 7.4 Caddy-Zertifikate

Caddy erneuert Let's-Encrypt-Zertifikate automatisch (30 Tage vor Ablauf). Keine manuelle Aktion nötig.

### 7.5 Logs prüfen

```bash
# Docker-Container
docker compose logs --tail=50 app
docker compose logs --tail=50 web-nginx
docker compose logs --tail=50 db

# Caddy
sudo tail -f /var/log/caddy/rss-access.log

# System
sudo journalctl -u caddy -f
```

---

## 8. Monitoring (optional)

### 8.1 Einfaches Healthcheck-Skript

Erstelle `/opt/ttrss/healthcheck.sh`:

```bash
#!/bin/bash
STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://rss.jpigla.de/tt-rss/)

if [ "$STATUS" != "200" ]; then
    echo "[$(date)] TT-RSS nicht erreichbar (HTTP $STATUS)" >> /opt/ttrss/healthcheck.log
    cd /opt/ttrss && docker compose restart
fi
```

```bash
chmod +x /opt/ttrss/healthcheck.sh

# Alle 5 Minuten prüfen
(crontab -l; echo "*/5 * * * * /opt/ttrss/healthcheck.sh") | crontab -
```

### 8.2 Uptime-Monitoring extern

Empfehlung: [Uptime Kuma](https://github.com/louislam/uptime-kuma) (Self-hosted) oder [Betterstack](https://betterstack.com) (kostenloser Tier) für externes Monitoring mit Benachrichtigungen.

---

## 9. Sicherheits-Checkliste

Nach dem Deployment einmal durchgehen:

- [ ] SSH: Root-Login deaktiviert, nur Key-Auth
- [ ] Firewall: Nur 22, 80, 443 offen
- [ ] Fail2Ban aktiv für SSH
- [ ] `.env` hat sichere Passwörter (nicht die Defaults!)
- [ ] `HTTP_PORT=127.0.0.1:8280` — nicht von außen erreichbar
- [ ] HTTPS funktioniert (`curl -I https://rss.jpigla.de/tt-rss/`)
- [ ] Security-Header vorhanden (`curl -I` → HSTS, CSP, X-Frame-Options)
- [ ] Admin-Account gesperrt oder mit starkem Passwort
- [ ] API-Zugriff nur für benötigte Benutzer aktiviert
- [ ] Backup-Cron eingerichtet
- [ ] `backup.sql` nicht im Git (`.gitignore` prüfen)

---

## 10. Fehlerbehebung

| Problem | Lösung |
|---------|--------|
| `502 Bad Gateway` | `docker compose ps` — läuft der `app`-Container? `docker compose logs app` prüfen |
| Zertifikat-Fehler | DNS prüfen: `dig rss.jpigla.de` → zeigt auf VPS-IP? Caddy-Logs: `journalctl -u caddy` |
| DB-Verbindungsfehler | `docker compose logs db` — Healthcheck: `docker compose exec db pg_isready` |
| Login-Schleife | `TTRSS_SELF_URL_PATH` in `.env` prüfen — muss exakt mit der Browser-URL übereinstimmen |
| Langsames Laden | Gzip aktiv? `curl -H "Accept-Encoding: gzip" -I https://rss.jpigla.de/tt-rss/` → `Content-Encoding: gzip` |
| PWA installiert sich nicht | HTTPS Pflicht. `manifest.webmanifest` erreichbar? Service Worker registriert? Safari-Konsole prüfen |
| Edge-TTS funktioniert nicht | `docker compose logs edge-tts` — Container läuft? `EDGE_TTS_API_KEY` in `.env` gesetzt? |

---

## Architektur-Übersicht

```
Internet
   │
   ▼
┌──────────────────────────┐
│  Vercel DNS              │
│  jpigla.de → Vercel      │
│  rss.jpigla.de → VPS-IP  │
└──────────────────────────┘
   │
   ▼ (Port 443)
┌──────────────────────────┐
│  Caddy (TLS-Terminierung)│  ← Let's Encrypt automatisch
│  :443 → 127.0.0.1:8280  │
└──────────────────────────┘
   │
   ▼ (Port 8280, nur lokal)
┌──────────────────────────────────────────────┐
│  Docker Compose                              │
│  ┌────────────┐  ┌─────┐  ┌─────────┐       │
│  │ web-nginx   │→│ app │→│   db    │       │
│  │ :80 (intern)│  │ PHP │  │ Postgres│       │
│  └────────────┘  └──┬──┘  └─────────┘       │
│                     │                         │
│              ┌──────┴──────┐                  │
│              │  updater    │  ┌──────────┐   │
│              │ (Feed-Sync) │  │ edge-tts │   │
│              └─────────────┘  └──────────┘   │
└──────────────────────────────────────────────┘
```
