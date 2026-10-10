# Hytale Hosting – Modul für Pterodactyl 1.x und 2.0

Mit diesem Modul stellen Hoster Hytale-Server auf Pterodactyl bereit, **ohne dass Kunden ihre Server von Hand per `/auth login device` anmelden müssen**. Es setzt den offiziellen [Server Provider Authentication Guide](https://support.hytale.com/hc/en-us/articles/45328341414043-Server-Provider-Authentication-Guide) von Hytale um:

1. Ihr meldet einen (oder mehrere) Hytale-Provider-Accounts **einmal** per OAuth-Device-Flow im Panel an.
2. Das Panel bewahrt den Refresh-Token **verschlüsselt** auf, erneuert ihn automatisch und gibt ihn nie heraus.
3. Bei jedem Start holt sich der Gameserver über einen **Session-Broker** im Panel kurzlebige `sessionToken`/`identityToken` (1 h gültig, der Server erneuert sie danach selbst).

| Teil | Pfad | Wofür |
| --- | --- | --- |
| Panel-Modul (gemeinsamer Kern) | `module/` | Session-Broker, Account-Verwaltung, Admin-Seiten, Befehle |
| Adapter Pterodactyl 1.x | `module/src/V1` | Composer-Paket mit Laravel-Auto-Discovery |
| Adapter Pterodactyl 2.0 | `module/src/V2`, `module/extension.json` | Native Extension (`.pteroext`) |
| Egg | `egg/egg-hytale.json` | Für 1.x und 2.0 importierbar (PTDL_v2) |
| Launcher | `egg/start.sh` | Holt die Session und startet Java |
| Node-Sync (optional, empfohlen) | `node/` | Eine gemeinsame, schreibgeschützte Kopie der Spieldateien pro Node |

---

## Wie es funktioniert

```
 Panel (1.x oder 2.0)                                    Hytale
 ┌──────────────────────────────────┐                   ┌───────────────────────────────┐
 │ Admin: Device-Login (einmalig) ──┼── device code ───▶│ oauth.accounts.hytale.com     │
 │ Refresh-Token (verschlüsselt)    │◀─ tokens ─────────│                               │
 │                                  │                   │                               │
 │ POST /hytale-broker/v1/session   │── game-session ──▶│ sessions.hytale.com           │
 │  ▲  Bearer <Server-Broker-Token> │◀─ session/identity│                               │
 └──┼───────────────────────────────┘                   └───────────────────────────────┘
    │
 Wings-Node: Container (Egg „Hytale (Provider Auth)“)
    hytale-start.sh → holt Tokens → exportiert HYTALE_SERVER_SESSION_TOKEN / _IDENTITY_TOKEN
                    → java -jar HytaleServer.jar --auth-mode authenticated
```

* Jeder Server bekommt ein eigenes, zufälliges **Broker-Token** (256 bit) als versteckte Egg-Variable `HYTALE_BROKER_TOKEN`. Das Modul speichert davon nur einen SHA-256-Hash.
* Der Broker prüft Token, Serverstatus (nicht gesperrt, installiert, kein Transfer), optional die Quell-IP, und Rate-Limits.
* Accounts werden reihum (am längsten unbenutzt zuerst) verwendet. Antwortet Hytale mit 403 (Limit erreicht/fehlende Berechtigung), wird der Account 5 Minuten pausiert und der nächste probiert.
* Beim Neustart eines Servers wird dessen vorherige Session beendet (`DELETE /game-session`), damit Neustarts das Session-Limit nicht auffressen.

---

## Voraussetzungen

* Pterodactyl Panel **1.x** (getestet gegen den Stand von 1.12, Laravel 12, PHP ≥ 8.2) **oder** Pterodactyl **2.0** (Extension-System, PHP ≥ 8.3).
* Wings mit dem Image `ghcr.io/pterodactyl/yolks:java_25` (Hytale braucht Java 25).
* Der Panel-Scheduler läuft (`* * * * * php /var/www/pterodactyl/artisan schedule:run` bei 1.x; beim 2.0-Docker-Image eingebaut).
* Ein Hytale-Account mit Spiellizenz. Für mehr als **100 gleichzeitige Server** pro Lizenz braucht ihr die Provider-Berechtigung `sessions.unlimited_servers`, die ihr laut Guide beim Hytale-Support beantragt (Firmen-/Domain-Nachweis, Abuse-Kontakt mit 24-h-SLA). Ohne sie könnt ihr mehrere Accounts im Modul hinterlegen.

---

## Installation – Pterodactyl 1.x

```bash
cd /var/www/pterodactyl

# 1. Paket ablegen (aus diesem Repository gebaut, siehe „Bauen“)
mkdir -p packages
cp -r /pfad/zu/dist/hytale-hosting-v1 packages/hytale-hosting

# 2. Als Composer-Pfad-Paket einbinden
composer config repositories.hytale-hosting path packages/hytale-hosting
composer require scriptvortexde/pterodactyl-hytale-hosting:^1.0 --no-dev --optimize-autoloader

# 3. Tabellen anlegen, Caches erneuern
php artisan migrate --force
php artisan view:clear && php artisan config:clear && php artisan route:clear
chown -R www-data:www-data /var/www/pterodactyl
```

Die Admin-Seite liegt unter **`https://<panel>/extensions/hytale-hosting/admin`** (nur Root-Admins, 2FA-Pflicht des Panels gilt).

> Ein Panel-Update (`p:upgrade` oder die manuellen Update-Schritte) überschreibt `composer.json`. Führt nach jedem Panel-Update Schritt 2 und 3 erneut aus; `packages/`, die Datenbank-Tabellen und die angemeldeten Accounts bleiben erhalten.

## Installation – Pterodactyl 2.0

```bash
cd /var/www/pterodactyl
php artisan p:extension:install /pfad/zu/dist/hytale-hosting-1.0.0.pteroext --enable
chown -R www-data:www-data extensions public/assets/extensions
```

Oder im Admin-Bereich: **Admin → Extensions → Install** und die `.pteroext`-Datei hochladen. Beim Aktivieren laufen die Migrationen automatisch. Die Admin-Seite liegt unter **`https://<panel>/extensions/hytale-hosting/admin`**.

---

## Einrichtung (beide Versionen)

### 1. Egg importieren

* **1.x:** Admin → Nests → (Nest wählen oder anlegen) → *Import Egg* → `egg/egg-hytale.json`.
* **2.0:** Admin → Eggs → *Import Egg* → `egg/egg-hytale.json`, danach im Tab *Tags* z. B. `hytale` vergeben.

Das Modul erkennt Hytale-Server daran, dass ihr Egg die Variable `HYTALE_BROKER_TOKEN` hat. Ihr könnt also auch Kopien des Eggs anlegen.

### 2. Provider-Account anmelden

Auf der Admin-Seite einen Namen eingeben → **Add account (device login)** → Link öffnen, Code eingeben, mit dem Provider-Account bestätigen. Alternativ auf der Konsole:

```bash
php artisan hytale-hosting:login provider-1
php artisan hytale-hosting:status
```

### 3. Spieldateien bereitstellen

**Empfohlen – gemeinsamer Mount pro Node** (spart pro Server mehrere GB, Updates zentral):

```bash
# Auf jedem Wings-Node, im geklonten Repository:
sudo ./node/install-node.sh
sudo hytale-files-sync.sh --login     # einmalig: Device-Login für den Hytale-Downloader
sudo hytale-files-sync.sh             # erster Download
sudo systemctl enable --now hytale-files-sync.timer
```

`/var/lib/pterodactyl/mounts/hytale` in `/etc/pterodactyl/config.yml` unter `allowed_mounts` eintragen, `systemctl restart wings`. Dann im Panel unter **Mounts** einen Mount anlegen:

| Feld | Wert |
| --- | --- |
| Source | `/var/lib/pterodactyl/mounts/hytale` |
| Target | `/mnt/hytale` |
| Read Only | **Ja** |

Mount mit dem Hytale-Egg und den Nodes verknüpfen und bei jedem Server im Tab *Mounts* einhängen. Neue Hytale-Versionen werden stündlich geladen und atomar umgeschaltet; laufende Server behalten ihre Version bis zum nächsten Neustart (die letzten 3 Versionen bleiben liegen). Der Launcher (`/mnt/hytale/bin/hytale-start.sh`) wird dabei ebenfalls zentral aktualisiert.

> Jeder Node meldet den Downloader **selbst** an. Kopiert `/var/lib/hytale-hosting` nie auf andere Nodes: Hytale rotiert Refresh-Tokens, Kopien werden ungültig.

**Alternative – lokale Kopie pro Server:** Egg-Variable *Game Files Source* auf `local`. Dann lädt das Installationsskript die Dateien mit dem offiziellen Hytale-Downloader in den Server; beim ersten Install muss sich der Kunde im Installations-Log per Device-Code anmelden.

### 4. Server anlegen

Server mit dem Hytale-Egg anlegen (Port-Allocation = UDP-Port des Servers). Nach der Installation bekommt der Server automatisch sein Broker-Token (Event-Hook, zusätzlich alle 5 Minuten per `hytale-hosting:sync`). Beim Start erscheint in der Konsole:

```
[hytale] Using shared game files 2026.x from the node.
[hytale] Game session received (valid until …, refreshed automatically by the server).
[hytale] Starting Hytale server on port 5520/udp.
```

---

## Einstellungen (Admin-Seite)

| Einstellung | Standard | Bedeutung |
| --- | --- | --- |
| Broker URL | leer = `APP_URL/hytale-broker/v1/session` | Setzen, wenn Nodes das Panel intern unter anderer Adresse erreichen. |
| Sessions per server per 10 minutes | 6 | Darüber HTTP 429. |
| Sessions per server per 24 hours | 48 | Darüber wird der Server **gesperrt**, bis ein Admin ihn entsperrt (Schutz gegen Token-Abgriff). |
| Allowed source networks | leer | Kommagetrennte IPs/CIDRs, z. B. Node-Netze. |
| Require the server's node IP | aus | Nur Anfragen von den IPs des Server-Nodes (aus dessen FQDN aufgelöst). |
| End the previous session on restart | an | Alte Session beim Neustart beenden. |
| Audit log retention | 30 Tage | |

Wenn das Panel hinter einem Reverse-Proxy/Cloudflare steht, müssen die Trusted Proxies des Panels korrekt gesetzt sein, sonst sieht der Broker die Proxy-IP statt der Node-IP.

## Befehle

| Befehl | Zweck |
| --- | --- |
| `hytale-hosting:login {name}` | Provider-Account per Device-Code anmelden (auch zum erneuten Anmelden) |
| `hytale-hosting:status` | Accounts, Ablauf der Refresh-Tokens, Fehler |
| `hytale-hosting:sync` | Broker-Tokens für alle Hytale-Server bereitstellen (läuft alle 5 min) |
| `hytale-hosting:maintain` | Refresh-Tokens > 7 Tage erneuern, Audit-Log aufräumen (läuft täglich) |

Hytales Refresh-Tokens laufen nach 30 Tagen ab. `maintain` erneuert sie wöchentlich, auch wenn kein Server startet; ein Account muss also nur neu angemeldet werden, wenn Hytale ihn widerruft (Admin-Seite zeigt dann „Sign in again“).

---

## Sicherheit

* **Provider-Credentials verlassen das Panel nie.** Access- und Refresh-Token liegen mit dem `APP_KEY` des Panels verschlüsselt in der Datenbank (`encrypted`-Cast). Sichert den `APP_KEY` – ohne ihn sind die Tokens verloren und die Accounts müssen neu angemeldet werden.
* **Token-Rotation ist race-frei:** Erneuerungen laufen unter einer Datenbank-Zeilensperre, das neue Refresh-Token wird in derselben Transaktion gespeichert.
* **Pro Server ein eigenes Geheimnis**, im Modul nur als SHA-256-Hash; konstante Vergleichszeit; Brute-Force-Sperre (20 Fehlversuche/10 min pro IP); Token per Klick rotierbar.
* **Begrenzter Schaden bei Missbrauch:** Ein Kunde kann mit dem Broker-Token seines Servers nur Sessions für *diesen* Server holen, gedrosselt und mit Tageslimit; jede neue Session beendet die vorige. Damit kann ein Server-Slot nie mehr als eine aktive Session belegen.
* **Launcher:** Broker-Token nur per 0600-Datei an `curl` (nicht in der Prozessliste), wird vor dem Java-Start aus der Umgebung entfernt; Antwort wird zeilenweise gegen eine Positivliste geparst (kein `eval`/`source`); `--auth-mode`, `--session-token`, `--identity-token`, `--bind`, `--assets` können Kunden nicht überschreiben.
* **Spieldateien** im Mount gehören root und sind schreibgeschützt eingehängt; der Downloader-Login liegt nur root-lesbar auf dem Node.
* **Audit-Log** für jede Ausgabe, Ablehnung, Rotation und Account-Aktion – ohne Tokens.
* Broker-Antworten senden `Cache-Control: no-store`. Betreibt das Panel nur mit HTTPS.

Grenzen, die sich nicht wegdesignen lassen: Der Hytale-Server braucht die Session-Tokens im Klartext, und Kunden haben vollen Zugriff auf ihren Container (Mods/Plugins). Wer seinen Server kontrolliert, kann also dessen aktuelle Session auslesen – die Limits oben sorgen dafür, dass er damit nicht mehr erhält als den einen Server, für den er bezahlt.

---

## Bauen und testen

```bash
python3 scripts/build.py          # erzeugt egg/egg-hytale.json, dist/hytale-hosting-v1/, dist/hytale-hosting-<version>.pteroext

cd tests && composer install && vendor/bin/phpunit   # Feature-Tests gegen Laravel (Testbench) mit gemocktem Hytale-API
```

`egg/egg-hytale.json` wird aus `egg/install.sh` und `egg/start.sh` erzeugt – Änderungen dort vornehmen und neu bauen.

## Fehlersuche

| Symptom | Ursache / Lösung |
| --- | --- |
| `The session broker is not configured for this server yet.` | Server noch nicht provisioniert: Admin-Seite → *Provision all* oder `php artisan hytale-hosting:sync`, dann neu starten. |
| `Session broker: Invalid credentials.` | Token wurde rotiert oder Server-Variable manuell geändert → *Rotate token*, Server neu starten. |
| `No game session is available right now.` | Kein nutzbarer Account (abgemeldet, Limit, Hytale nicht erreichbar) → Admin-Seite/Audit-Log prüfen. |
| `Source address not allowed.` | Node-Netz in *Allowed source networks* eintragen bzw. Trusted Proxies prüfen. |
| `Session issuing is locked for this server.` | Tageslimit überschritten → im Audit-Log prüfen, dann *Unlock*. |
| HTTP 403 von Hytale beim Session-Erstellen | 100-Server-Limit der Lizenz erreicht oder fehlende Provider-Berechtigung → weiteren Account hinzufügen bzw. Berechtigung beantragen. |
| `The shared Hytale files are not mounted at /mnt/hytale.` | Mount dem Server zuweisen (Admin → Server → Mounts) und `allowed_mounts` in Wings prüfen. |

## Quellen

* Hytale: [Server Provider Authentication Guide](https://support.hytale.com/hc/en-us/articles/45328341414043-Server-Provider-Authentication-Guide), [Hytale Server Manual](https://support.hytale.com/hc/en-us/articles/45326769420827-Hytale-Server-Manual)
* Pterodactyl: [Docs 1.x](https://docs.pterodactyl.io/v1), [Docs 2.0 / Extensions](https://docs.pterodactyl.io/v2)
