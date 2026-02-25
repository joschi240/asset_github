# ARCHITECTURE – Asset KI Instandhaltung & Störungsmanagement

> Stand: 2026-02-25 · Analysiert von Copilot Coding Agent (vollständige Neuanalyse)

---

## 1. Technologie-Stack

| Schicht | Technologie | Details |
|---|---|---|
| Backend | PHP 8.2+ | Prozeduraler Stil, keine Klassen, keine Frameworks |
| Datenbank | MariaDB 10.4.x | InnoDB, utf8mb4_general_ci |
| DB-Zugriff | PDO | Prepared Statements, `ERRMODE_EXCEPTION`, `FETCH_ASSOC` |
| Frontend | HTML + CSS | Kein JS-Framework; Vanilla-JS nur lokal in `module/wartungstool/punkt.php` |
| Webserver | Apache oder Nginx | Kein eingebauter Dev-Server |
| Paketmanager | **keiner** | Kein Composer, kein npm |
| Autoloading | **keines** | Alle Includes manuell per `require_once` |
| Tests | **keine** | Keine Testinfrastruktur vorhanden |

---

## 2. Verzeichnisstruktur & Modulkarte

```
/
├── app.php                          # Front-Controller aller Modul-Views
├── index.php                        # Redirect → app.php?r=<default_route>
├── login.php                        # Standalone Login (eigenes Layout)
├── logout.php                       # Session-Destroy + Redirect → login.php
├── create.bat                       # Windows-Batch (Dev-Artefakt, ohne produktiven Nutzen)
├── Erzeuge / Done                   # Textdateien (Dev-Artefakte, ohne produktiven Nutzen)
│
├── src/                             # Kern-Bibliotheken (nur Funktionen, keine Klassen)
│   ├── config.default               # Template für src/config.php (versioniert, kein Secret)
│   ├── config.php                   # NICHT versioniert – DB-Credentials + App-Config
│   ├── db.php                       # PDO-Singleton; db_one() / db_all() / db_exec()
│   ├── auth.php                     # session_boot(), login(), logout(), csrf_token(),
│   │                                # csrf_check(), require_login(), app_cfg(),
│   │                                # user_can_flag(), user_can_edit(), user_can_delete()
│   ├── helpers.php                  # e(), audit_log(), handle_upload(), db_table_exists(),
│   │                                # db_col_exists(), user_can_see(), is_admin_user(),
│   │                                # badge_for_ticket_status(), load_menu_tree(),
│   │                                # route_map_for_keys(), ensure_dir()
│   ├── layout.php                   # render_header() + render_footer() + Menü-Rendering
│   ├── permission.php               # user_permissions(), can(), require_permission()
│   ├── menu.php                     # load_menu_tree() Legacy-Version (überschrieben durch
│   │                                # helpers.php; funktioniert nur mit Legacy-Schema)
│   └── css/main.css                 # Globales Stylesheet (kein Build-Schritt)
│
├── module/
│   ├── wartungstool/                # Modul: Anlagenwartung
│   │   ├── dashboard.php            # INNER-VIEW: Fälligkeits-Dashboard (Ampel, Trend)
│   │   ├── uebersicht.php           # INNER-VIEW: Wartungspunkte pro Asset (Filter)
│   │   ├── punkt.php                # INNER-VIEW: Wartungspunkt-Detail + Durchführungsform
│   │   ├── punkt_save.php           # INNER-VIEW: POST – Protokoll speichern, optional Ticket
│   │   └── admin_punkte.php         # INNER-VIEW: Admin-CRUD Wartungspunkte + CSV-Import
│   │
│   ├── stoerungstool/               # Modul: Störungsmanagement
│   │   ├── melden.php               # INNER-VIEW: Störung melden (öffentlich, kein Login)
│   │   ├── inbox.php                # INNER-VIEW: Ticket-Inbox mit Filter + Volltext-Suche
│   │   └── ticket.php               # INNER-VIEW: Ticket-Detail + Aktionen + Dokumente
│   │
│   └── admin/                       # Modul: Administration
│       ├── setup.php                # Erstbenutzer anlegen (has_any_user()-Guard)
│       ├── users.php                # Benutzerverwaltung (CRUD)
│       ├── routes.php               # Routenverwaltung (core_route CRUD)
│       ├── menu.php                 # Menüverwaltung (core_menu + core_menu_item CRUD)
│       └── permissions.php          # Berechtigungsverwaltung (core_permission CRUD)
│
├── tools/
│   ├── runtime_ingest.php           # REST-Endpoint: Telemetrie-Ingest (Single + Bulk)
│   └── runtime_rollup.php           # CLI/Cron: core_runtime_sample → counter + agg_day
│
├── uploads/                         # Hochgeladene Dateien (gitignored / extern)
│
└── docs/
    ├── db_schema_v2.sql             # Vollständiges DB-Schema (aktuell, idempotent)
    ├── db_schema.sql                # DB-Schema v1 (historisch)
    ├── db_migration_permissions_v1.sql
    ├── DB_SCHEMA_DELTA_NOTES.md
    ├── KNOWN_ROUTE_KEYS.md
    ├── PERMISSIONS_GUIDE.md
    ├── PRIJECT_CONTEXT.md           # Tippfehler: "PRIJECT" statt "PROJECT"
    ├── PRIJECT_CONTEXT_v2.md        # Haupt-Arbeitsvertrag (aktuell; Tippfehler im Namen)
    └── analysis/                    # ← dieses Verzeichnis
        ├── ARCHITECTURE.md
        ├── INVENTORY.md
        ├── RISKS.md
        └── ROADMAP.md
```

> **Hinweis zu `src/menu.php`:** Die Datei enthält eine veraltete `load_menu_tree()` Funktion (Legacy-Schema, nur `core_menu` ohne `core_menu_item`). Die produktive Implementierung befindet sich in `src/helpers.php` (kompatibel mit neuem und altem Schema). `src/menu.php` wird von keiner Datei über `require_once` eingebunden und ist de facto toter Code.

---

## 3. Datenbankschema – Tabellen-Übersicht

Schema-Datei: [`docs/db_schema_v2.sql`](../db_schema_v2.sql)

### Core-Stammdaten (Prefix `core_`)

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `core_asset` | Anlagenstamm | `code` (UNIQUE), `name`, `asset_typ`, `kategorie_id`, `standort_id`, `prioritaet` |
| `core_asset_kategorie` | Kategorien + Kritikalität | `name` (UNIQUE), `kritischkeitsstufe` (1–3) |
| `core_standort` | Standorte / Bereiche | `name` (UNIQUE) |
| `core_user` | Benutzerverwaltung | `benutzername` (UNIQUE), `passwort_hash` (bcrypt), `aktiv`, `last_login_at` |
| `core_permission` | Rechte je User/Modul/Objekt | `user_id`, `modul`, `objekt_typ`, `objekt_id` (nullable), `darf_sehen/aendern/loeschen` |
| `core_route` | DB-Routing | `route_key` (UNIQUE), `file_path`, `modul`, `objekt_typ`, `require_login` |
| `core_menu` | Menü-Container | `name` (UNIQUE, z.B. `main`) |
| `core_menu_item` | Menü-Items (Baum) | `menu_id`, `parent_id`, `route_key`, `modul`, `sort` |
| `core_dokument` | Datei-Attachments | `modul`, `referenz_typ`, `referenz_id`, `dateiname`, `sha256` |
| `core_audit_log` | Audit-Trail | `modul`, `entity_type`, `entity_id`, `action`, `old_json`, `new_json` (JSON-validiert) |

### Runtime-Telemetrie (Prefix `core_runtime_`)

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `core_runtime_sample` | Rohdaten (run/stop) | `asset_id`, `ts`, `state` (1/0); UNIQUE(`asset_id`, `ts`) |
| `core_runtime_counter` | Produktivstunden-Stand | `asset_id` (PK), `productive_hours`, `last_ts`, `last_state` |
| `core_runtime_agg_day` | Tagesaggregation | `asset_id`, `day` (PK), `run_seconds`, `stop_seconds`, `intervals`, `gaps` |

### Wartungstool (Prefix `wartungstool_`)

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `wartungstool_wartungspunkt` | Wartungsplan | `asset_id`, `intervall_typ` (zeit/produktiv), `plan_interval`, `letzte_wartung`, `datum` |
| `wartungstool_protokoll` | Durchführungshistorie | `wartungspunkt_id`, `asset_id`, `datum`, `messwert`, `status` (ok/abweichung) |

### Störungstool v2 (Prefix `stoerungstool_`)

| Tabelle | Zweck | Wichtige Spalten |
|---|---|---|
| `stoerungstool_ticket` | Störungstickets | `asset_id`, `status` (ENUM), `meldungstyp`, `fachkategorie`, `maschinenstillstand`, `assigned_user_id` |
| `stoerungstool_aktion` | Kommentare/Statuswechsel | `ticket_id`, `user_id`, `text`, `status_neu`, `arbeitszeit_min` |

### Wichtige Beziehungen

```
core_asset ──< wartungstool_wartungspunkt ──< wartungstool_protokoll
core_asset ──< stoerungstool_ticket ──< stoerungstool_aktion
core_asset ──< core_runtime_sample
core_asset ─── core_runtime_counter
core_asset ──< core_runtime_agg_day
core_user ──< core_permission
core_menu ──< core_menu_item
core_menu_item ──< core_menu_item (parent_id Selbstreferenz)
```

---

## 4. Routing – Datenbankgetrieben

**Kernidee:** Alle Seiten laufen über `app.php`. Die URL ist `app.php?r=<route_key>`.  
Neue Seiten/Module werden durch Datenbankeinträge in `core_route` registriert – ohne Code-Änderung an `app.php`.

### Route-Lookup in `app.php`

```php
// 1. route_key aus GET-Parameter lesen (default aus config.php)
$routeKey = trim((string)($_GET['r'] ?? ''));
if ($routeKey === '') $routeKey = $cfg['app']['default_route']; // 'wartung.dashboard'

// 2. Route aus DB laden
$route = db_one(
  "SELECT route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv
   FROM core_route WHERE route_key = ? AND aktiv=1 LIMIT 1",
  [$routeKey]
);

// 3. Auth-Check
if ((int)$route['require_login'] === 1) require_login();

// 4. Permission-Check
if (!user_can_see($userId, $route['modul'], $route['objekt_typ'], $oid)) {
  http_response_code(403); // ...
}

// 5. Pfad-Absicherung (kein Path-Traversal)
$file = str_replace('\\', '/', $route['file_path']);
if (strpos($file, '..') !== false) { http_response_code(400); exit; }
$full = realpath(__DIR__ . '/' . ltrim($file, '/'));
if (!$full || strpos($full, realpath(__DIR__)) !== 0 || !is_file($full)) { exit; }

// 6. Render
render_header($route['titel']);
require $full; // ← INNER-VIEW (rendert kein eigenes Layout)
render_footer();
```

### Bekannte Route-Keys (aus `docs/KNOWN_ROUTE_KEYS.md`)

| Route-Key | Datei | Login | Modul |
|---|---|---|---|
| `wartung.dashboard` | `module/wartungstool/dashboard.php` | ja | `wartungstool` |
| `wartung.uebersicht` | `module/wartungstool/uebersicht.php` | ja | `wartungstool` |
| `wartung.punkt` | `module/wartungstool/punkt.php` | ja | `wartungstool` |
| `wartung.punkt_save` | `module/wartungstool/punkt_save.php` | ja | `wartungstool` |
| `wartung.admin_punkte` | `module/wartungstool/admin_punkte.php` | ja | `wartungstool` |
| `stoerung.melden` | `module/stoerungstool/melden.php` | **nein** | – |
| `stoerung.inbox` | `module/stoerungstool/inbox.php` | ja | `stoerungstool` |
| `stoerung.ticket` | `module/stoerungstool/ticket.php` | ja | `stoerungstool` |
| `admin.setup` | `module/admin/setup.php` | **nein** (Guard intern) | – |
| `admin.users` | `module/admin/users.php` | ja | `admin` |
| `admin.routes` | `module/admin/routes.php` | ja | `admin` |
| `admin.menu` | `module/admin/menu.php` | ja | `admin` |
| `admin.permissions` | `module/admin/permissions.php` | ja | `admin` |

---

## 5. Auth & Permission Flow

### Login-Ablauf (`login.php` → `src/auth.php`)

```
POST login.php
  └── csrf_check($_POST['csrf'])
  └── login($benutzername, $passwort)          # src/auth.php
        ├── db_one("SELECT ... FROM core_user WHERE benutzername=?")
        ├── password_verify($pass, $hash)        # bcrypt
        ├── session_regenerate_id(true)          # Session-Fixation-Schutz
        ├── $_SESSION['user'] = [id, benutzername, anzeigename]
        └── UPDATE core_user SET last_login_at = NOW()
  └── Redirect → app.php?r=<default_route>
```

### Session-Konfiguration (`src/auth.php` → `session_boot()`)

```php
session_set_cookie_params([
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => (!empty($_SERVER['HTTPS'])),
  'path' => '/',
]);
```

### Berechtigungsmodell

Tabelle: `core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)`

**Admin-Wildcard:** Eintrag mit `modul='*'` und `darf_sehen=1` → Zugriff auf alle Module.

| Funktion | Datei | Prüft |
|---|---|---|
| `user_can_see()` | `src/helpers.php` | `darf_sehen=1`; Wildcard `modul='*'`; öffentlich wenn `modul/objekt_typ` leer |
| `user_can_flag()` | `src/auth.php` | `MAX(flagCol)` mit Whitelist-Validierung der Spalte |
| `user_can_edit()` | `src/auth.php` | `darf_aendern=1` via `user_can_flag()` |
| `user_can_delete()` | `src/auth.php` | `darf_loeschen=1` via `user_can_flag()` |
| `can()` | `src/permission.php` | Modul-übergreifend; Admin-Wildcard `modul='*'` |
| `is_admin_user()` | `src/helpers.php` | Prüft `modul='*' AND darf_sehen=1` |
| `require_login()` | `src/auth.php` | Redirect → `login.php` wenn kein `$_SESSION['user']` |
| `require_can_edit()` | `src/auth.php` | HTTP 403 wenn kein `darf_aendern` |
| `require_can_delete()` | `src/auth.php` | HTTP 403 wenn kein `darf_loeschen` |

**Zentrale Permission-Prüfung in `app.php`:**  
`user_can_see($userId, $route['modul'], $route['objekt_typ'], $route['objekt_id'])`

---

## 6. Request/Response-Flows pro Modul

### Wartungstool – Dashboard-Flow

```
GET app.php?r=wartung.dashboard
  └── app.php: Route-Lookup, require_login, user_can_see
  └── module/wartungstool/dashboard.php
        ├── db_all("SELECT ... FROM core_asset ...")          ← alle aktiven Assets
        ├── foreach Asset → berechneDashboard(assetId)        ← 4 DB-Queries pro Asset (N+1)
        │   ├── db_one(core_runtime_counter)                  ← Produktivstunden
        │   ├── db_one(core_runtime_agg_day, 28 Tage)         ← Wochenschnitt
        │   ├── db_one(core_runtime_agg_day, Trend-Split)     ← Trend
        │   └── db_one(wartungstool_wartungspunkt)            ← Nächste Fälligkeit
        └── renderTable() mit Ampel-Logik (rot/gelb/grün)
```

### Wartungstool – Protokoll speichern (`punkt_save.php`)

```
POST app.php?r=wartung.punkt_save
  └── csrf_check(), require_can_edit('wartungstool', 'global')
  └── Transaktion:
        ├── INSERT wartungstool_protokoll
        ├── UPDATE wartungstool_wartungspunkt (letzte_wartung oder datum)
        ├── audit_log('wartungstool', 'protokoll', ..., 'CREATE')
        ├── audit_log('wartungstool', 'wartungspunkt', ..., 'UPDATE')
        └── optional: INSERT stoerungstool_ticket + stoerungstool_aktion
              (wenn create_ticket=1 UND user_can_edit('stoerungstool'))
              └── Ticket-Referenz in protokoll.bemerkung: "[#TICKET:123]"
```

### Störungstool – Ticket melden (`melden.php`)

```
GET app.php?r=stoerung.melden   ← require_login=0 in core_route (öffentlich)
  └── module/stoerungstool/melden.php
        └── POST → csrf_check
              ├── INSERT stoerungstool_ticket (status='neu')
              ├── INSERT stoerungstool_aktion ("Meldung erfasst")
              └── optional: upload_first_ticket_file() → core_dokument
```

### Störungstool – Ticket-Bearbeitung (`ticket.php`)

```
POST app.php?r=stoerung.ticket&id=<id>
  └── require_login, user_can_edit('stoerungstool', 'global')
  └── Transaktion (je nach action):
        ├── set_status:    UPDATE ticket + INSERT aktion + auto-assign + audit_log
        ├── assign:        UPDATE ticket.assigned_user_id + INSERT aktion + audit_log
        ├── add_action:    INSERT aktion, optional UPDATE ticket.status
        ├── update_ticket: UPDATE ticket (Metadaten)
        └── upload_doc:    upload_ticket_file() → core_dokument
```

### Admin – Erstbenutzer-Setup (`setup.php`)

```
GET/POST app.php?r=admin.setup  (oder direkt: /module/admin/setup.php)
  └── has_any_user() → wenn true: "Setup bereits erledigt" + exit
  └── POST → csrf_check
        ├── INSERT core_user (passwort_hash = password_hash($pass, PASSWORD_DEFAULT))
        └── INSERT core_permission (modul='*', objekt_typ='*', alle Flags=1) ← Admin-Wildcard
```

---

## 7. Menü-System

`render_header()` in `src/layout.php` ruft `load_menu_tree('main')` in `src/helpers.php` auf.

### Schema-Kompatibilität

`load_menu_tree()` unterstützt zwei Betriebsmodi:

| Modus | Voraussetzung | Tabellen |
|---|---|---|
| **Neu** | `core_menu_item` und `core_menu` existieren | `core_menu` (Container) + `core_menu_item` (Items) |
| **Legacy** | nur `core_menu` | `core_menu` enthält direkt die Items |

### Menü-Datenbankfluss

```
render_header()
  └── load_menu_tree('main')                  src/helpers.php
        ├── DB: core_menu + core_menu_item    (oder Legacy: core_menu direkt)
        ├── route_map_for_keys()              Bulk-Load core_route für alle Items
        ├── user_can_see() je Item            Permission-Filter (unsichtbare Knoten prunen)
        ├── Tree-Aufbau (parent_id Hierarchie)
        ├── branch_active setzen              aktive Elternknoten markieren
        └── is_group setzen                  Knoten ohne href = Gruppe (kein Link)
```

---

## 8. Telemetrie-Architektur

```
Steuerung / PLC
  │
  POST tools/runtime_ingest.php
    Header: X-INGEST-TOKEN: <token aus config.php: telemetry.ingest_token>
    Body (JSON):
      Single: {"asset_id":1,"state":"run","ts":"2026-02-25 10:00:00"}
      Bulk:   {"samples":[{...},{...}]}
    │
    └── Validierung: asset_id, state (run/stop/0/1), ts (mit max_clock_skew_sec)
    └── UPSERT core_runtime_sample (ON DUPLICATE KEY UPDATE)
        UNIQUE: (asset_id, ts) → Bulk-Ingest ist idempotent

Cron (typisch alle 5 Minuten):
  php tools/runtime_rollup.php
    └── Pro Asset:
          ├── Startet ab core_runtime_counter.last_ts
          ├── split_interval_by_day()   Tagessplitting bei Mitternacht
          ├── Lücken (> gapThresholdSec) werden als Gaps gezählt, nicht als Laufzeit
          ├── UPSERT core_runtime_agg_day (additive: run_seconds, stop_seconds)
          └── UPDATE core_runtime_counter (productive_hours kumulativ, last_ts)
```

**Hinweis:** `runtime_rollup.php` ist ein reines CLI-Skript. Webzugriff wird mit HTTP 403 abgewiesen (`php_sapi_name() !== 'cli'` Guard, Zeile 2). Parameter werden über `$_GET` gelesen (harmlos in CLI-Kontext, da `$_GET` in CLI leer ist).

---

## 9. Upload-Handling

| Aspekt | Implementierung |
|---|---|
| Upload-Verzeichnis | `uploads/` relativ zum Projektroot (konfigurierbar via `config.php: upload.base_dir`) |
| Max. Größe | 10 MB (konfigurierbar: `upload.max_bytes`) |
| MIME-Typen | `image/jpeg`, `image/png`, `image/webp`, `application/pdf` (konfigurierbar) |
| MIME-Validierung | `finfo::file()` (kein Trust auf `$_FILES['type']`) |
| Dateiname (gespeichert) | `date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . $ext` (zufällig, nicht ratbar) |
| SHA-256 | Wird berechnet und in `core_dokument.sha256` gespeichert |
| Download | Direktzugriff via `<base>/uploads/<pfad>` – **kein Auth-Check** (Risiko, siehe RISKS.md) |
| Funktion | `handle_upload()` in `src/helpers.php`; inline in `melden.php` und `ticket.php` |

Upload-Pfade in `core_dokument.dateiname` folgen dem Muster:  
`stoerungstool/tickets/<id>/<dateiname>`

---

## 10. CSRF-Schutz

```php
// Erzeugung (src/auth.php)
function csrf_token(): string {
  if (empty($_SESSION[$key])) {
    $_SESSION[$key] = bin2hex(random_bytes(16)); // 32 hex-Zeichen
  }
  return $_SESSION[$key];
}

// Prüfung (src/auth.php)
function csrf_check(?string $token): void {
  if (!$token || empty($_SESSION[$key]) || !hash_equals($_SESSION[$key], $token)) {
    http_response_code(400); exit('CSRF token invalid');
  }
}
```

Alle POST-Formulare haben ein `<input type="hidden" name="csrf" ...>`.

---

## 11. Startanleitung (lokal)

### Voraussetzungen

- PHP 8.2+
- MariaDB 10.4+
- Apache oder Nginx mit PHP-Unterstützung

### Schritt-für-Schritt

```bash
# 1. Repository klonen
git clone <repo-url> /var/www/html/asset_ki

# 2. Konfiguration anlegen
cp src/config.default src/config.php
# Bearbeiten:
#   db.host / db.name / db.user / db.pass
#   telemetry.ingest_token (langen, zufälligen String wählen)

# 3. Datenbank anlegen
mysql -u root -p -e "CREATE DATABASE asset_ki CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 4. Schema einspielen (idempotent, kein DROP)
mysql -u <user> -p asset_ki < docs/db_schema_v2.sql

# 5. Uploads-Verzeichnis vorbereiten
mkdir -p uploads
chmod 775 uploads

# 6. Webserver auf Projektverzeichnis zeigen
#    Apache: DocumentRoot /var/www/html/asset_ki
#    Nginx:  root /var/www/html/asset_ki;

# 7. Erstbenutzer anlegen (im Browser)
#    http://<host>/app.php?r=admin.setup
#    (nur möglich, solange noch kein Benutzer in core_user existiert)

# 8. Cron für Telemetrie-Rollup (optional)
(crontab -l; echo "*/5 * * * * php /var/www/html/asset_ki/tools/runtime_rollup.php >> /var/log/asset_rollup.log 2>&1") | crontab -
```

### Konfigurationsschlüssel (`src/config.php`)

| Schlüssel | Bedeutung | Beispiel |
|---|---|---|
| `db.host` | DB-Host | `localhost` |
| `db.name` | DB-Name | `asset_ki` |
| `db.user` | DB-Benutzer | `asset_user` |
| `db.pass` | DB-Passwort | `geheimesPasswort` |
| `app.session_name` | Session-Cookie-Name | `insta_stack` |
| `app.csrf_key` | CSRF-Session-Schlüssel | `csrf_token` |
| `app.base_url` | Basis-URL (leer = auto) | `''` oder `/asset_ki` |
| `app.base_url_auto` | Auto-Erkennung aus SCRIPT_NAME | `true` |
| `app.default_route` | Standard-Route nach Login | `wartung.dashboard` |
| `upload.base_dir` | Upload-Verzeichnis | `__DIR__ . '/../uploads'` |
| `upload.max_bytes` | Max. Upload-Größe | `10485760` (10 MB) |
| `upload.allowed_mimes` | Erlaubte MIME-Typen | Array jpg/png/webp/pdf |
| `telemetry.ingest_token` | Token für Ingest-API | Langer zufälliger String |
| `telemetry.max_clock_skew_sec` | Max. Uhr-Drift für Ingest | `300` |

> **Wichtig:** `src/config.php` enthält Credentials und darf **nicht** versioniert werden.  
> `src/config.default` ist das versionierte Template (keine Secrets).
