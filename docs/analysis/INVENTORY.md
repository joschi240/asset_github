# INVENTORY.md – Asset KI (Stand: 2026-02-25)

Strukturkarte des Repositories: Ordner, Module, wichtigste Dateien und wo was lebt.

---

## Verzeichnisstruktur

```
asset_github/
├── app.php                          # Front-Controller (DB-Routing) — NICHT ändern
├── index.php                        # Redirect → app.php?r=<default_route>
├── login.php                        # Standalone Login-Formular
├── logout.php                       # Session zerstören + Redirect
│
├── src/                             # Kern-Bibliothek (kein öffentlicher Zugriff)
│   ├── config.default               # Konfigurationsvorlage (nach config.php kopieren)
│   ├── config.php                   # ❌ nicht im Repo (gitignore) — lokal anlegen
│   ├── db.php                       # PDO-Wrapper: db(), db_one(), db_all(), db_exec()
│   ├── auth.php                     # Session, Login, Logout, CSRF, Permission-Helpers
│   ├── helpers.php                  # e(), audit_log(), user_can_*, handle_upload(), load_menu_tree()
│   ├── layout.php                   # render_header() / render_footer() + Menüausgabe
│   ├── permission.php               # can(), require_permission(), user_permissions()
│   ├── menu.php                     # ⚠ LEGACY – nicht mehr aktiv genutzt (vgl. RISKS R-10)
│   └── css/
│       └── main.css                 # Globales Stylesheet (kein JS-Framework)
│
├── module/                          # Feature-Module (INNER-VIEWs)
│   ├── wartungstool/
│   │   ├── dashboard.php            # Wartungs-Dashboard (Ampelstatus, Fälligkeiten)
│   │   ├── punkt.php                # Wartungspunkt Detail + Durchführungsformular
│   │   ├── punkt_save.php           # POST: Protokoll speichern + audit_log
│   │   ├── uebersicht.php           # Wartungspunkte pro Asset (Listenansicht)
│   │   └── admin_punkte.php         # CRUD-Verwaltung der Wartungspunkte (Admin)
│   │
│   ├── stoerungstool/
│   │   ├── melden.php               # Störung melden (öffentlich, require_login=0)
│   │   ├── inbox.php                # Ticket-Inbox (Filter, Suche, Status-Übersicht)
│   │   └── ticket.php               # Ticket-Detail (Statuswechsel, Aktionen, Dokumente)
│   │
│   └── admin/
│       ├── setup.php                # Erstbenutzer anlegen (nur wenn core_user leer)
│       ├── users.php                # Benutzerverwaltung (create/update/disable)
│       ├── routes.php               # Routenverwaltung (CRUD auf core_route)
│       ├── menu.php                 # Menüverwaltung (CRUD auf core_menu_item)
│       └── permissions.php          # Berechtigungsverwaltung (UPSERT core_permission)
│
├── tools/                           # CLI / API-Tools (nicht im Browser-Pfad)
│   ├── runtime_ingest.php           # HTTP-POST-Ingest: Telemetrie-Samples (Token-Auth)
│   └── runtime_rollup.php           # CLI: Samples → Counter + agg_day aggregieren
│
├── uploads/                         # ⚠ Hochgeladene Dateien (sollte nicht per HTTP erreichbar sein)
│   └── stoerungstool/tickets/<id>/  # Anhänge zu Tickets
│
└── docs/                            # Projektdokumentation
    ├── db_schema.sql                 # Datenbankschema v1 (historisch)
    ├── db_schema_v2.sql              # Datenbankschema v2 (aktuell, idempotent)
    ├── db_migration_permissions_v1.sql  # Migration: objekt_typ-Konsistenz
    ├── DB_SCHEMA_DELTA_NOTES.md      # Erläuterungen zu Tabellenstruktur
    ├── KNOWN_ROUTE_KEYS.md           # Alle bekannten Route-Keys (DB-validiert)
    ├── PERMISSIONS_GUIDE.md          # Berechtigungssystem-Dokumentation
    ├── PRIJECT_CONTEXT.md            # Projektkontext v1 (historisch)
    ├── PRIJECT_CONTEXT_v2.md         # Projektkontext v2 (aktuell)
    └── analysis/                    # Analyse-Dokumente (dieses Verzeichnis)
        ├── ARCHITECTURE.md
        ├── RISKS.md
        ├── ROADMAP.md
        └── INVENTORY.md             # ← diese Datei
```

---

## Wichtigste Dateien – Wo was lebt

### Einstiegspunkte

| Datei | Zweck |
|---|---|
| `app.php` | Zentraler Front-Controller; DB-Routing über `core_route`; einziger Weg um INNER-VIEWs zu rendern |
| `login.php` | Standalone Login; rendert eigenes Layout; POST → `src/auth.php login()` |
| `logout.php` | Zerstört Session; Redirect zu `login.php` |
| `index.php` | Einfacher Redirect zu `app.php?r=<default_route>` |

### Kernbibliothek (`src/`)

| Datei | Schlüsselfunktionen |
|---|---|
| `src/db.php` | `db()` – PDO-Singleton; `db_one()`, `db_all()`, `db_exec()` |
| `src/auth.php` | `session_boot()`, `current_user()`, `login()`, `logout()`, `csrf_token()`, `csrf_check()`, `user_can_edit()`, `user_can_delete()`, `user_can_flag()`, `require_can_edit()`, `require_can_delete()` |
| `src/helpers.php` | `e()` (HTML-Escape), `user_can_see()`, `is_admin_user()`, `has_any_user()`, `audit_log()`, `handle_upload()`, `load_menu_tree()`, `route_map_for_keys()`, `db_table_exists()`, `db_col_exists()`, `badge_for_ticket_status()` |
| `src/layout.php` | `render_header(string $title)`, `render_footer()` – rendern HTML-Layout mit Sidebar-Navigation |
| `src/permission.php` | `can(string $modul, string $recht)`, `require_permission()`, `user_permissions()` – caching |
| `src/config.default` | Konfigurationsvorlage: `db`, `app` (session_name, base_url, default_route), `upload` |

### Module (`module/`)

#### wartungstool
| Datei | Schlüsselfunktionen |
|---|---|
| `dashboard.php` | Fälligkeits-Ampel (rot/gelb/grün) für alle aktiven Wartungspunkte; kombiniert `wartungspunkt` + `protokoll` + `core_runtime_counter` |
| `punkt.php` | Detail-Ansicht eines Wartungspunkts; zeigt letzte Protokolle; Durchführungsformular (GET) |
| `punkt_save.php` | POST-Handler; schreibt `wartungstool_protokoll`; setzt `letzte_wartung`/`datum` zurück; ruft `audit_log()` auf |
| `uebersicht.php` | Asset-Auswahl + alle Wartungspunkte eines Assets |
| `admin_punkte.php` | CRUD für `wartungstool_wartungspunkt`; erfordert `darf_aendern` |

#### stoerungstool
| Datei | Schlüsselfunktionen |
|---|---|
| `melden.php` | Öffentliches Meldeformular (kein Login); INSERT `stoerungstool_ticket`; optional Datei-Upload → `core_dokument` |
| `inbox.php` | Ticket-Liste mit Filtern (Asset, Status, Meldungstyp, Fachkategorie, Priorität, Freitext); dynamisches WHERE-Building |
| `ticket.php` | Ticket-Detailansicht; POST: Statuswechsel, Kommentar, Datei-Upload, Zuweisung; liest `stoerungstool_aktion` für Timeline |

#### admin
| Datei | Schlüsselfunktionen |
|---|---|
| `setup.php` | Erstbenutzer + Admin-Wildcard anlegen (nur wenn `core_user` leer); erfordert **kein** Login |
| `users.php` | Benutzer anlegen/bearbeiten/deaktivieren; `password_hash` + `password_verify` |
| `routes.php` | `core_route` CRUD (route_key, file_path, modul, objekt_typ, require_login) |
| `menu.php` | `core_menu_item` CRUD; auto-fill modul/objekt_typ aus Route |
| `permissions.php` | Matrix-View: alle User × alle Routes → `core_permission` UPSERT/DELETE |

### Tools (`tools/`)

| Datei | Aufruf | Schlüsselfunktionen |
|---|---|---|
| `runtime_ingest.php` | HTTP POST, `X-INGEST-TOKEN` Header | Nimmt Single- oder Bulk-Samples entgegen; INSERT `core_runtime_sample` mit DUPLICATE-KEY-Handling |
| `runtime_rollup.php` | CLI `php tools/runtime_rollup.php` | Verarbeitet neue Samples seit `last_ts` pro Asset; akkumuliert `productive_hours`; schreibt `core_runtime_agg_day` via UPSERT |

---

## Datenbankübersicht (Tabellen-Prefix)

| Prefix | Tabellen | Zweck |
|---|---|---|
| `core_asset*` | `core_asset`, `core_asset_kategorie`, `core_standort` | Anlagenstammdaten |
| `core_user` / `core_permission` | — | Authentifizierung + Berechtigungen |
| `core_route` | — | DB-getriebenes Routing |
| `core_menu` / `core_menu_item` | — | Navigation (Baum) |
| `core_dokument` | — | Universelle Dateianhänge |
| `core_audit_log` | — | ISO-Audit-Trail |
| `core_runtime_*` | `sample`, `counter`, `agg_day` | Telemetrie / Produktivstunden |
| `wartungstool_*` | `wartungspunkt`, `protokoll` | Wartungsplanung + -durchführung |
| `stoerungstool_*` | `ticket`, `aktion` | Störungsmanagement + Workflow |

---

## Konfiguration

| Schlüssel | Wo | Bedeutung |
|---|---|---|
| `db.host/name/user/pass` | `src/config.php` | MariaDB-Zugangsdaten |
| `app.session_name` | `src/config.php` | PHP-Session-Name (`insta_stack`) |
| `app.base_url` | `src/config.php` | URL-Präfix; `null` = automatisch aus `SCRIPT_NAME` |
| `app.default_route` | `src/config.php` | Standard-Route (`wartung.dashboard`) |
| `upload.base_dir` | `src/config.php` | Absoluter Pfad für Uploads |
| `upload.max_bytes` | `src/config.php` | Max. Dateigröße (Default: 10 MB) |
| `upload.allowed_mimes` | `src/config.php` | MIME-Whitelist (jpeg, png, webp, pdf) |
| `telemetry.ingest_token` | `src/config.php` | Auth-Token für `runtime_ingest.php` (**nicht in config.default**) |

---

## Berechtigungs-Schnellreferenz

| Route-Key | modul | objekt_typ | require_login |
|---|---|---|:---:|
| `wartung.dashboard` | `wartungstool` | `dashboard` | ✅ |
| `wartung.punkt` | `wartungstool` | `global` | ✅ |
| `wartung.punkt_save` | `wartungstool` | `global` | ✅ |
| `wartung.uebersicht` | `wartungstool` | `global` | ✅ |
| `wartung.admin_punkte` | `wartungstool` | `global` | ✅ |
| `stoerung.melden` | `stoerungstool` | `global` | ❌ (öffentlich) |
| `stoerung.inbox` | `stoerungstool` | `global` | ✅ |
| `stoerung.ticket` | `stoerungstool` | `global` | ✅ |
| `admin.setup` | — | — | ❌ (Setup-Guard) |
| `admin.users` | `admin` | `users` | ✅ + Admin-Wildcard |
| `admin.routes` | `admin` | `routes` | ✅ + Admin-Wildcard |
| `admin.menu` | `admin` | `menu` | ✅ + Admin-Wildcard |
| `admin.permissions` | `admin` | `permissions` | ✅ + Admin-Wildcard |
