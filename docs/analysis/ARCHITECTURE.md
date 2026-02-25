# ARCHITECTURE.md - Asset KI (Stand: 2026-02-25)

## Tech-Stack

| Schicht | Technologie |
|---|---|
| Backend | PHP 8.2.x |
| Datenbank | MariaDB 10.4.x |
| DB-Zugriff | PDO (prepared statements, FETCH_ASSOC) |
| Frontend | HTML/CSS (kein JS-Framework); `src/css/main.css` |
| Routing | Datenbankgetrieben (`core_route`) |
| Navigation | Datenbankgetrieben (`core_menu` / `core_menu_item`) |
| Auth/Session | PHP native Sessions (`session_name`, HTTPOnly, SameSite=Lax) |
| CSRF | Session-basiertes Token (`bin2hex(random_bytes(16))`) |
| Upload | `move_uploaded_file` + MIME-Whitelist + SHA-256-Fingerprint |
| Telemetrie | HTTP-POST-Ingest (`tools/runtime_ingest.php`) + CLI-Rollup |

---

## Entry Points

| Datei | Zweck |
|---|---|
| `index.php` | Weiterleitung zu `app.php?r=<default_route>` |
| `app.php` | **Front-Controller** - DB-Routing, Auth, Permission, View-Include |
| `login.php` | Login-Formular (standalone, rendert eigenes Layout) |
| `logout.php` | Session löschen, Redirect zu `login.php` |
| `tools/runtime_ingest.php` | Telemetrie-Ingest via HTTP-POST (Token-Auth) |
| `tools/runtime_rollup.php` | Produktivstunden-Aggregation (CLI only; non-CLI requests blocked with HTTP 403 via `php_sapi_name()` guard) |

---

## Routing (DB-getrieben)

`app.php` realisiert einen **Front-Controller** ohne URL-Rewriting:

```
GET app.php?r=<route_key>
```

Ablauf:
1. `$routeKey` aus `$_GET['r']` lesen; Fallback auf `cfg.app.default_route` (`wartung.dashboard`)
2. `SELECT ... FROM core_route WHERE route_key = ? AND aktiv=1` - liefert Titel, `file_path`, `modul`, `objekt_typ`, `objekt_id`, `require_login`
3. `require_login=1` -> `require_login()` (redirect zu `login.php` falls nicht eingeloggt)
4. `user_can_see($userId, modul, objekt_typ, objekt_id)` -> 403 falls falsch
5. `file_path` via `realpath()` gegen Projektroot absichern (blockt `..`)
6. `render_header(route.titel)` -> `require $full` (**INNER-VIEW**) -> `render_footer()`

**Wichtig:** INNER-VIEWs rufen niemals selbst `render_header()`/`render_footer()` auf.  
Nur wenn ein Modul als Standalone direkt aufgerufen wird (direktes `!defined('APP_INNER')`-Guard), rendern sie ein eigenes Layout.

---

## Auth / Session

Implementierung: `src/auth.php`

| Funktion | Zweck |
|---|---|
| `session_boot()` | Session starten (einmalig); HTTPOnly, SameSite=Lax, Secure (HTTPS-aware) |
| `current_user()` | `$_SESSION['user']` - gibt `['id','benutzername','anzeigename']` oder `null` |
| `require_login()` | Redirect zu `login.php` falls kein Session-User |
| `login($user, $pass)` | DB-Lookup `core_user`, `password_verify`, `session_regenerate_id(true)`, `UPDATE last_login_at` |
| `logout()` | `$_SESSION=[]`, Cookie löschen, `session_destroy()` |
| `csrf_token()` | Token in Session erzeugen/lesen |
| `csrf_check($token)` | `hash_equals` - 400 bei Mismatch |

---

## Permission-Flow

```
app.php
  └─ user_can_see($userId, modul, objekt_typ, objekt_id)   [src/helpers.php]
       ├─ Admin-Wildcard: SELECT 1 FROM core_permission WHERE modul='*' AND darf_sehen=1
       └─ Objekt-Permission: modul + objekt_typ + (objekt_id IS NULL OR objekt_id = ?)
```

Feinberechtigungen (innerhalb von Views):

| Funktion | Flag | Quelle |
|---|---|---|
| `user_can_see()` | `darf_sehen` | `src/helpers.php` |
| `user_can_edit()` | `darf_aendern` | `src/auth.php` (via `user_can_flag`) |
| `user_can_delete()` | `darf_loeschen` | `src/auth.php` (via `user_can_flag`) |
| `can($modul, $recht)` | alle | `src/permission.php` (cached) |
| `is_admin_user($uid)` | Wildcard `modul='*'` | `src/helpers.php` |

Admin-Wildcard (`modul='*'`, `objekt_typ='*'`, `objekt_id=NULL`) gewährt uneingeschränkten Zugriff.

---

## Datenmodell-Überblick

### Core-Tabellen (stabile Basis)

| Tabelle | Zweck | Schlüsselbeziehungen |
|---|---|---|
| `core_asset` | Anlagenstamm (code UNIQUE) | -> `core_asset_kategorie`, -> `core_standort` |
| `core_asset_kategorie` | Kategorien + Kritikalitätsstufe (1-3) | - |
| `core_standort` | Standorte/Bereiche | - |
| `core_user` | Benutzer (benutzername UNIQUE) | - |
| `core_permission` | Rechte (user_id, modul, objekt_typ, objekt_id) | -> `core_user` (CASCADE DELETE) |
| `core_route` | DB-Routing (route_key UNIQUE) | - |
| `core_menu` | Menü-Container (name UNIQUE) | - |
| `core_menu_item` | Menübaum (parent_id FK auf sich selbst) | -> `core_menu`, -> `core_menu_item` |
| `core_dokument` | Universelle Attachments (modul + referenz_typ + referenz_id) | -> `core_user` (SET NULL) |
| `core_audit_log` | ISO-Audit-Trail (old_json/new_json, actor, ip) | - |

### Runtime / Telemetrie

| Tabelle | Zweck |
|---|---|
| `core_runtime_sample` | Polling-Rohdaten (ts + state 0/1); UNIQUE(asset_id, ts) |
| `core_runtime_counter` | Kumulierter Produktivstunden-Stand pro Asset |
| `core_runtime_agg_day` | Tagesaggregation (run_seconds, stop_seconds, gaps, intervals) |

### Wartungstool

| Tabelle | Zweck |
|---|---|
| `wartungstool_wartungspunkt` | Wartungsplan (intervall_typ: 'zeit' oder 'produktiv', plan_interval) |
| `wartungstool_protokoll` | Durchführungsprotokoll (messwert, status ok/abweichung, bemerkung) |

`wartungspunkt.letzte_wartung` = Produktivstunden-Stand bei letzter Wartung (für `intervall_typ='produktiv'`).  
`wartungspunkt.datum` = Zeitpunkt letzter Wartung (für `intervall_typ='zeit'`).

### Störungstool (v2)

| Tabelle | Zweck |
|---|---|
| `stoerungstool_ticket` | Störungstickets; Status-Workflow: `neu -> angenommen -> in_arbeit -> bestellt -> erledigt -> geschlossen` |
| `stoerungstool_aktion` | Aktionen/Kommentare/Statuswechsel pro Ticket; `arbeitszeit_min` für Zeiterfassung |

v2-Erweiterungen in `stoerungstool_ticket`:
- `meldungstyp` (Störmeldung, Mängelkarte, Logeintrag, ...)
- `fachkategorie` (Mechanik, Elektrik, Sicherheit, Qualität, ...)
- `maschinenstillstand` (Anlage steht: 0/1)
- `ausfallzeitpunkt` (DATETIME)
- `assigned_user_id` (FK -> `core_user`, SET NULL)

---

## Modulkarte

```
/
├── app.php                  # Front-Controller
├── index.php / login.php / logout.php
├── src/
│   ├── config.php           # DB + App + Telemetrie-Einstellungen
│   ├── db.php               # PDO-Wrapper: db_one, db_all, db_exec
│   ├── auth.php             # Session, CSRF, Login, Permissions
│   ├── helpers.php          # e(), audit_log(), user_can_*, file-upload
│   ├── layout.php           # render_header/footer + Menürendering
│   ├── permission.php       # can(), require_permission(), user_permissions()
│   └── css/main.css         # Styles (kein Framework)
├── module/
│   ├── wartungstool/        # Wartungsplanung
│   ├── stoerungstool/       # Störungsmanagement
│   └── admin/               # Systemverwaltung (nur Admin-Wildcard)
├── tools/
│   ├── runtime_ingest.php   # REST-Ingest (HTTP-POST, Token-Auth)
│   └── runtime_rollup.php   # CLI-Rollup (Samples -> Counter + agg_day)
└── docs/                    # Schemas, Migrations, Analysen
```

---

## Typische Flows

### Flow: wartungstool - Dashboard

```
Browser GET app.php?r=wartung.dashboard
  -> core_route: file_path=module/wartungstool/dashboard.php
  -> require_login=1 -> session prüfen
  -> user_can_see(userId, 'wartungstool', 'dashboard', NULL)
  -> render_header('Wartung - Dashboard')
  -> dashboard.php (INNER-VIEW):
      SELECT wartungspunkt JOIN core_asset
        + LEFT JOIN protokoll (letzter Eintrag)
        + LEFT JOIN core_runtime_counter
      -> Ampelstatus berechnen (Fälligkeitsdelta)
  -> render_footer()
```

### Flow: stoerungstool - Störung melden (öffentlich)

```
Browser GET app.php?r=stoerung.melden
  -> core_route: require_login=0 (öffentlich zugänglich)
  -> user_can_see(NULL, 'stoerungstool', 'global', NULL) -> true (modul+objekt_typ leer = öffentlich)
  -> render_header('Störung melden')
  -> melden.php (INNER-VIEW):
      POST: csrf_check -> INSERT stoerungstool_ticket -> INSERT core_dokument (optional)
  -> render_footer()
```

### Flow: admin - Berechtigungen setzen

```
Browser GET app.php?r=admin.permissions
  -> is_admin_user($uid) -> 403 falls nicht Admin
  -> permissions.php: SELECT alle aktiven User + alle Routes
  -> POST: csrf_check -> UPSERT core_permission pro Route/User/Flag
```

### Flow: Telemetrie-Ingest + Rollup

```
PLC / Cron:
  POST tools/runtime_ingest.php
    Header: X-INGEST-TOKEN: <token>
    Body: {"asset_id":1,"state":"run","ts":"2026-02-25 07:00:00"}
    -> Token-Check (hash_equals)
    -> INSERT INTO core_runtime_sample ... ON DUPLICATE KEY UPDATE (upsert)

  php tools/runtime_rollup.php (CLI, z.B. alle 5 min via cron)
    -> Pro Asset: Samples seit last_ts auslesen
    -> run-Intervalle akkumulieren -> UPDATE core_runtime_counter
    -> Tageswerte -> UPSERT core_runtime_agg_day
```

---

## How to Run Locally

```bash
# 1. Repository klonen
git clone <repo-url> /var/www/html/asset_ki
cd /var/www/html/asset_ki

# 2. Datenbank anlegen (MariaDB 10.4+)
mysql -u root -p -e "CREATE DATABASE asset_ki CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p asset_ki < docs/db_schema_v2.sql

# 3. Konfiguration anlegen
cp src/config.default src/config.php
# src/config.php bearbeiten: db.host / db.name / db.user / db.pass eintragen

# 4. Uploads-Verzeichnis
mkdir -p uploads && chmod 775 uploads

# 5. Dev-Server starten (PHP built-in, Projektroot = Document Root)
php -S localhost:8080 -t /var/www/html/asset_ki

# 6. Erstbenutzer (Admin) anlegen
# Browser: http://localhost:8080/app.php?r=admin.setup
# -> Benutzernamen + Passwort (min. 8 Zeichen) eingeben
# -> Automatisch Admin-Wildcard-Permission wird angelegt

# 7. Einloggen
# Browser: http://localhost:8080/login.php

# 8. Telemetrie-Test (optional)
# Ingest-Token in src/config.php setzen: cfg['telemetry']['ingest_token']
curl -X POST -H "X-INGEST-TOKEN: <token>" \
  -H "Content-Type: application/json" \
  -d '{"asset_id":1,"state":"run","ts":"2026-02-25 08:00:00"}' \
  http://localhost:8080/tools/runtime_ingest.php
php tools/runtime_rollup.php
```
