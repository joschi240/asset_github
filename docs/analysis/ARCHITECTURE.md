# ARCHITECTURE – Asset KI Instandhaltung & Störungsmanagement

> Stand: 2026-02-24 · Analysiert von Copilot Coding Agent

---

## 1. Technologie-Stack

| Schicht | Technologie | Version |
|---|---|---|
| Backend | PHP | 8.2.x |
| Datenbank | MariaDB | 10.4.x |
| DB-Zugriff | PDO | — |
| Frontend | HTML + CSS (kein JS-Framework) | — |
| JS | Vanilla (nur lokal in `module/wartungstool/punkt.php`) | — |
| Webserver | Apache oder Nginx (kein eingebauter Dev-Server) | — |
| Paketmanager | **keiner** (kein Composer, kein npm) | — |
| Autoloading | **keines** – alle Includes manuell per `require_once` | — |
| Tests | **keine** Testinfrastruktur vorhanden | — |

---

## 2. Verzeichnisstruktur & Module

```
/
├── app.php                         # Front-Controller – NICHT ändern
├── index.php                       # Redirect → app.php (default_route)
├── login.php                       # Standalone Login (eigenes Layout)
├── logout.php                      # Session destroy + Redirect
├── hash.php                        # Dev-Hilfsskript (password_hash); sollte entfernt werden
├── create.bat                      # Windows-Batch (Dev-Hilfsskript)
├── Erzeuge / Done                  # Textdateien (Dev-Artefakte)
│
├── src/                            # Kern-Bibliotheken (keine Klassen, nur Funktionen)
│   ├── config.default              # Template für src/config.php
│   ├── config.php                  # Nicht versioniert (DB-Credentials)
│   ├── db.php                      # PDO-Singleton + db_one() / db_all() / db_exec()
│   ├── auth.php                    # session_boot, login, logout, csrf, require_login,
│   │                               # user_can_flag / user_can_edit / user_can_delete
│   ├── helpers.php                 # e(), audit_log(), handle_upload(),
│   │                               # user_can_see(), load_menu_tree(), route_map_for_keys()
│   ├── layout.php                  # render_header() / render_footer() + Menü-Rendering
│   ├── permission.php              # user_permissions(), can(), require_permission()
│   └── css/main.css                # Globales Stylesheet (kein CSS-Build-Schritt)
│
├── module/
│   ├── wartungstool/               # Modul: Anlagenwartung
│   │   ├── dashboard.php           # INNER-VIEW: Fälligkeits-Dashboard mit Ampel
│   │   ├── punkt.php               # INNER-VIEW: Wartungspunkt-Detail + Durchführung
│   │   ├── punkt_save.php          # INNER-VIEW: POST – Protokoll speichern
│   │   ├── uebersicht.php          # INNER-VIEW: Wartungspunkte pro Asset
│   │   └── admin_punkte.php        # INNER-VIEW: CRUD Wartungspunkte (Admin)
│   │
│   ├── stoerungstool/              # Modul: Störungsmanagement
│   │   ├── melden.php              # INNER-VIEW: Störung melden (öffentlich, kein Login)
│   │   ├── inbox.php               # INNER-VIEW: Ticket-Inbox mit Filter + Suche
│   │   └── ticket.php              # INNER-VIEW: Ticket-Detail + Aktionen + Dokumente
│   │
│   └── admin/                      # Modul: Administration
│       ├── setup.php               # Erstbenutzer anlegen (hat_any_user Guard)
│       ├── users.php               # Benutzerverwaltung (CRUD)
│       ├── routes.php              # Routenverwaltung (core_route CRUD)
│       ├── menu.php                # Menüverwaltung (core_menu / core_menu_item)
│       └── permissions.php         # Berechtigungsverwaltung (core_permission)
│
├── tools/                          # CLI-Tools / Cron-Skripte
│   ├── runtime_ingest.php          # REST-Endpoint: Telemetrie Single + Bulk Ingest
│   └── runtime_rollup.php          # Aggregator: core_runtime_sample → counter + agg_day
│
├── uploads/                        # Hochgeladene Dateien (gitignored / extern)
│
└── docs/                           # Projektdokumentation
    ├── db_schema_v2.sql            # Vollständiges DB-Schema (aktuell, idempotent)
    ├── db_schema.sql               # DB-Schema v1 (historisch)
    ├── db_migration_permissions_v1.sql
    ├── DB_SCHEMA_DELTA_NOTES.md
    ├── KNOWN_ROUTE_KEYS.md
    ├── PERMISSIONS_GUIDE.md
    ├── PRIJECT_CONTEXT.md
    ├── PRIJECT_CONTEXT_v2.md       # Haupt-Arbeitsvertrag (aktuell)
    └── analysis/                   # ← dieses Verzeichnis
        ├── ARCHITECTURE.md
        ├── RISKS.md
        └── ROADMAP.md
```

---

## 3. Datenbankschema – Tabellen-Übersicht

Schema-Datei: [`docs/db_schema_v2.sql`](../db_schema_v2.sql)

### Core (Prefix `core_`)

| Tabelle | Zweck |
|---|---|
| `core_asset` | Anlagenstamm (Maschinen, Kompressoren, …) |
| `core_asset_kategorie` | Kategorien + Kritikalitätsstufe (1–3) |
| `core_standort` | Standorte / Bereiche |
| `core_user` | Benutzerverwaltung (bcrypt-Hash, aktiv-Flag) |
| `core_permission` | Berechtigungen je `user_id / modul / objekt_typ / objekt_id` |
| `core_route` | DB-Routing: `route_key → file_path + modul + objekt_typ` |
| `core_menu` | Menü-Container (z.B. `main`) |
| `core_menu_item` | Menü-Items als Baum (`parent_id`), `route_key`-basiert |
| `core_dokument` | Universelle Datei-Attachments (alle Module) |
| `core_audit_log` | ISO-Audit-Trail (JSON old/new, Actor, IP, Timestamp) |

### Runtime / Telemetrie (Prefix `core_runtime_`)

| Tabelle | Zweck |
|---|---|
| `core_runtime_sample` | Polling-Rohdaten (`asset_id`, `ts`, `state` run/stop) |
| `core_runtime_counter` | Aktueller Produktivstunden-Stand je Asset |
| `core_runtime_agg_day` | Tagesaggregation (run/stop Sekunden, Gaps) |

### Wartungstool (Prefix `wartungstool_`)

| Tabelle | Zweck |
|---|---|
| `wartungstool_wartungspunkt` | Wartungsplan (zeit- oder produktivstundenbasiert) |
| `wartungstool_protokoll` | Durchführungshistorie (Messwert, Status ok/Abweichung) |

### Störungstool v2 (Prefix `stoerungstool_`)

| Tabelle | Zweck |
|---|---|
| `stoerungstool_ticket` | Störungstickets; Status-Workflow: `neu → angenommen → in_arbeit → bestellt → erledigt → geschlossen` |
| `stoerungstool_aktion` | Kommentare, Statuswechsel, Zeiten je Ticket |

---

## 4. Datenfluss

```
Browser
  │
  ├─► index.php
  │     └─ Redirect → app.php?r=<default_route>
  │
  ├─► app.php?r=<route_key>          ← Front-Controller (ALLE Seiten)
  │     │
  │     ├─ 1. DB: SELECT core_route WHERE route_key=? AND aktiv=1
  │     ├─ 2. require_login() wenn require_login=1
  │     ├─ 3. user_can_see($userId, modul, objekt_typ, objekt_id)   ← core_permission
  │     ├─ 4. Pfad-Absicherung: strpos(..), realpath(..)
  │     ├─ 5. render_header(route.titel)                            ← load_menu_tree()
  │     ├─ 6. require $route['file_path']   ← INNER-VIEW
  │     └─ 7. render_footer()
  │
  ├─► login.php                       ← Standalone (eigenes Layout)
  │     └─ POST → login() → session_regenerate_id → Redirect
  │
  ├─► logout.php
  │     └─ logout() → Redirect → login.php
  │
  └─► tools/runtime_ingest.php        ← REST Endpoint (X-INGEST-TOKEN Auth)
        └─ POST (single / bulk) → INSERT core_runtime_sample

  Cron (server-seitig):
  tools/runtime_rollup.php → liest core_runtime_sample
                            → schreibt core_runtime_counter + core_runtime_agg_day
```

### Menü-Datenfluss

```
render_header()
  └─ load_menu_tree('main')           (src/helpers.php)
       ├─ DB: core_menu + core_menu_item  (oder Legacy: core_menu direkt)
       ├─ route_map_for_keys()        ← bulk-load core_route für alle Items
       ├─ user_can_see() je Item      ← Permission-Filter
       └─ Tree-Aufbau + Prune unsichtbarer Knoten
```

---

## 5. Entry Points

| Datei | Typ | Auth | Zweck |
|---|---|---|---|
| `index.php` | Web | nein | Redirect zu `app.php?r=<default_route>` |
| `app.php` | Web | DB-gesteuert | Front-Controller aller Module |
| `login.php` | Web | nein | Login-Formular mit CSRF |
| `logout.php` | Web | Session | Session-Destroy + Redirect |
| `module/stoerungstool/melden.php` | Web (via app.php) | nein (öffentlich) | Störung melden ohne Login |
| `module/admin/setup.php` | Web (via app.php oder direkt) | nein (guards per `has_any_user()`) | Erstbenutzer anlegen |
| `tools/runtime_ingest.php` | REST | `X-INGEST-TOKEN` Header | Telemetrie-Ingest (Single + Bulk) |
| `tools/runtime_rollup.php` | CLI / Cron | Serverebene | Aggregator (sollte nicht über Web erreichbar sein) |

---

## 6. Berechtigungssystem

Tabelle: `core_permission`  
Spalten: `user_id`, `modul`, `objekt_typ`, `objekt_id` (nullable), `darf_sehen`, `darf_aendern`, `darf_loeschen`

Zentrale Prüffunktionen in `src/helpers.php` und `src/auth.php`:

| Funktion | Datei | Prüft |
|---|---|---|
| `user_can_see()` | `src/helpers.php` | `darf_sehen=1`; Admin-Wildcard `modul='*'` |
| `user_can_edit()` | `src/auth.php` | `darf_aendern=1` via `user_can_flag()` |
| `user_can_delete()` | `src/auth.php` | `darf_loeschen=1` via `user_can_flag()` |
| `can()` | `src/permission.php` | Modul-übergreifend; Admin-Wildcard |
| `is_admin_user()` | `src/helpers.php` | `modul='*' AND darf_sehen=1` |

**Admin-Wildcard:** `core_permission (modul='*', objekt_typ='*')` → Zugriff auf alles.

---

## 7. CSRF-Schutz

- Token wird bei `session_boot()` erzeugt: `bin2hex(random_bytes(16))` → `$_SESSION[$csrf_key]`
- Formular-Hidden-Field: `<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">`
- Prüfung: `csrf_check($_POST['csrf'] ?? null)` → `hash_equals()` + HTTP 400 bei Fehler

---

## 8. Upload-Handling

- Upload-Verzeichnis: `uploads/` (konfigurierbar in `src/config.php` unter `upload.base_dir`)
- Max. Dateigröße: 10 MB (konfigurierbar)
- Erlaubte MIME-Types: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`
- MIME-Validierung: `finfo::file()` (kein Trust auf `$_FILES['type']`)
- SHA-256 Prüfsumme wird berechnet und in `core_dokument` gespeichert
- Gespeicherter Dateiname: zufällig (`date + bin2hex(random_bytes(8)) + extension`)

---

## 9. Telemetrie-Architektur

```
Steuerung / PLC
  │
  POST tools/runtime_ingest.php
    Header: X-INGEST-TOKEN: <token aus config.php telemetry.ingest_token>
    Body: {"asset_id":1,"state":"run","ts":"..."}        ← Single
          {"samples":[{...},{...}]}                        ← Bulk
    │
    └─ INSERT INTO core_runtime_sample (asset_id, ts, state, source, quality, payload_json)
         ON DUPLICATE KEY UPDATE ...    ← idempotent (UNIQUE asset_id+ts)

Cron (alle 5 Minuten):
  php tools/runtime_rollup.php
    └─ liest core_runtime_sample (ab letztem Stand aus core_runtime_counter)
    └─ split_interval_by_day()    ← Tagessplitting bei Mitternacht
    └─ UPDATE core_runtime_counter (productive_hours, last_ts)
    └─ UPSERT core_runtime_agg_day (run_seconds, stop_seconds, intervals, gaps)
```

---

## 10. Startanleitung (Kurzform)

### Voraussetzungen

- PHP 8.2+
- MariaDB 10.4+
- Apache oder Nginx mit PHP-Unterstützung

### Schritt-für-Schritt

```bash
# 1. Repository klonen
git clone <repo-url> /var/www/html/asset_ki
cd /var/www/html/asset_ki

# 2. Konfiguration anlegen
cp src/config.default src/config.php
# Dann src/config.php bearbeiten:
#   db.host / db.name / db.user / db.pass
#   telemetry.ingest_token (sicheres Token wählen)
#   telemetry.max_clock_skew_sec (z.B. 300)

# 3. Datenbank erstellen
mysql -u root -p -e "CREATE DATABASE asset_ki CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 4. Schema einspielen
mysql -u <user> -p asset_ki < docs/db_schema_v2.sql

# 5. Uploads-Verzeichnis vorbereiten
mkdir -p uploads
chmod 775 uploads

# 6. Webserver-Root auf Projektverzeichnis zeigen lassen
#    Apache: DocumentRoot /var/www/html/asset_ki
#    Nginx:  root /var/www/html/asset_ki;

# 7. Erstbenutzer anlegen (im Browser)
#    http://<host>/app.php?r=admin.setup
#    Oder direkt: http://<host>/module/admin/setup.php

# 8. Telemetrie-Cron einrichten (optional)
(crontab -l; echo "*/5 * * * * php /var/www/html/asset_ki/tools/runtime_rollup.php >> /var/log/asset_rollup.log 2>&1") | crontab -
```

### Konfigurationsparameter (`src/config.php`)

| Schlüssel | Bedeutung | Beispiel |
|---|---|---|
| `db.host` | DB-Host | `localhost` |
| `db.name` | DB-Name | `asset_ki` |
| `db.user` | DB-Benutzer | `asset_user` |
| `db.pass` | DB-Passwort | `geheimesPasswort` |
| `app.session_name` | Session-Cookie-Name | `insta_stack` |
| `app.csrf_key` | CSRF-Session-Key | `csrf_token` |
| `app.base_url` | Basis-URL (leer = auto) | `''` oder `/asset_ki` |
| `app.default_route` | Standard-Route nach Login | `wartung.dashboard` |
| `upload.base_dir` | Upload-Verzeichnis | `__DIR__ . '/../uploads'` |
| `upload.max_bytes` | Max. Upload-Größe | `10485760` (10 MB) |
| `upload.allowed_mimes` | Erlaubte MIME-Types | Array mit jpg/png/pdf |
| `telemetry.ingest_token` | Token für Ingest-API | Langer zufälliger String |
| `telemetry.max_clock_skew_sec` | Max. Uhr-Drift Ingest | `300` |

> **Hinweis:** `src/config.php` enthält Credentials und ist **nicht** im Repository versioniert.
> `src/config.default` ist das versionierte Template ohne Credentials.
