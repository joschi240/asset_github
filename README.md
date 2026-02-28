# Asset KI – Instandhaltung & Störungsmanagement

> **Repo-verifiziert. Stand: 2026-02-28. Branch: `ui_v2`.**  
> Jede technische Aussage enthält eine Fundstelle im Repository.

Eine modulare PHP-Webanwendung für **Anlagenwartung** und **Störungsmanagement** im Shopfloor-Umfeld.

---

## Inhaltsverzeichnis

- [Quick Facts](#quick-facts)
- [Architektur-Überblick](#architektur-überblick)
- [Verzeichnisstruktur](#verzeichnisstruktur)
- [Datenbank](#datenbank)
- [Installation & Setup](#installation--setup)
- [Modulübersicht](#modulübersicht)
- [Berechtigungssystem](#berechtigungssystem)
- [Telemetrie / Produktivstunden](#telemetrie--produktivstunden)
- [Statuslogik Wartung – Verifikation (soon_ratio)](#statuslogik-wartung--verifikation-soon_ratio)
- [UI v2 Design-System](#ui-v2-design-system)
- [Audit-Log](#audit-log)
- [Known Limitations / TODOs](#known-limitations--todos)
- [Dokumentation](#dokumentation)

---

## Quick Facts

| Eigenschaft | Wert | Quelle |
|---|---|---|
| Front-Controller | `app.php` – **nie ändern** | `app.php:1–73` |
| Routing | DB-getrieben via `core_route` (`aktiv=1`, `route_key`) | `app.php:15–21` |
| Navigation | DB-getrieben via `core_menu` / `core_menu_item` | `src/layout.php:14–89` |
| Permission-System | `user_can_see()` / `user_can_flag()` via `core_permission` | `src/helpers.php:56–61`, `src/auth.php:113–184` |
| Audit-Log | `audit_log()` schreibt in `core_audit_log` (ISO-Trail) | `src/helpers.php:90–103` |
| Telemetrie | Ingest → `core_runtime_sample`, Rollup → `core_runtime_counter` + `core_runtime_agg_day` | `tools/runtime_ingest.php`, `tools/runtime_rollup.php` |
| Wartung | Zeit- & produktivbasiert; Fälligkeit + `soon_ratio`/`soon_hours` Logik | `module/wartungstool/dashboard.php:41–58`, `module/wartungstool/uebersicht.php:34–50`, `module/wartungstool/punkt.php:50–124` |
| `soon_ratio` Fallback | `0.20` (wenn `soon_ratio` NULL oder ≤ 0 **und** kein `soon_hours` gesetzt) | `module/wartungstool/dashboard.php:49–51`, `module/wartungstool/uebersicht.php:37`, `module/wartungstool/punkt.php:74` |
| UI v2 | CSS-Klassen Prefix `ui-*`, Dateien unter `src/css/ui-v2/` | `src/layout.php:34–38`, `src/css/ui-v2/tokens.css` |
| UI v2 „Final“ Policy | Template + Regeln sind verbindlich; `src/css/ui-v2/` ist eingefroren | `docs/UI_V2_GUIDE.md`, `docs/STYLE_RULES.md` |
---

## Arbeitsmodus (GPT / VS Code / neue Chats)

Für neue Chats gilt: immer `README.md` + `docs/*` als Quelle verwenden.
Verbindliche Regeln stehen in `docs/PRIJECT_CONTEXT_v2.md`.

Arbeitsweise:
- Änderungen immer deterministisch: klare Schritte + copy/paste-fähige Patches.
- Wenn unklar: Rückfrage, aber **nur wenn wirklich nötig**.
- UI: nur nach Template/Rules arbeiten, keine modul-spezifischen CSS-Abzweigungen.

## Architektur-Überblick

### Front-Controller (`app.php`)

Alle Seiten laufen über `app.php`.
(Quelle: `app.php:1–73`)

**Ablauf:**

1. Route-Key aus `?r=<route_key>` lesen (Fallback: `cfg['app']['default_route']` → `'wartung.dashboard'`)  
   (Quelle: `app.php:10–13`)
2. Route aus `core_route` laden (`WHERE route_key = ? AND aktiv=1`)  
   (Quelle: `app.php:15–21`)
3. HTTP 404 wenn Route nicht gefunden oder deaktiviert  
   (Quelle: `app.php:23–29`)
4. Login-Pflicht prüfen wenn `require_login=1`  
   (Quelle: `app.php:31–33`)
5. Permission prüfen: `user_can_see($userId, modul, objekt_typ, objekt_id)`  
   (Quelle: `app.php:36–46`)
6. Pfad-Absicherung via `realpath`, blockt `..`  
   (Quelle: `app.php:49–56`)
7. Layout rendern: `render_header(titel)` → `require file_path` (INNER-VIEW) → `render_footer()`  
   (Quelle: `app.php:68–73`)

> **Regel:** `app.php` wird **nie** verändert. Neue Seiten immer über `core_route` integrieren.  
> (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Grundregeln")

### INNER-VIEWs

Views, die über `app.php` geladen werden, sind INNER-VIEWs und dürfen **kein eigenes Layout** rendern:
- ❌ Kein `render_header()` / `render_footer()`
- ❌ Kein `require src/layout.php`
- ✅ Start: `require_once __DIR__ . '/../../src/helpers.php';`

(Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „INNER-VIEW Regel")

### DB-getriebene Navigation

Navigation wird aus `core_menu` / `core_menu_item` als Baumstruktur geladen.
(Quelle: `src/layout.php:14`, Funktion `load_menu_tree('main')`)

Menu-Items nutzen `route_key` → Link wird zu `app.php?r=<route_key>`.
(Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Menü")

---

## Verzeichnisstruktur

```
/app.php                          # Front-Controller (DB-Routing) – NICHT ändern
/index.php                        # Weiterleitung zu app.php
/login.php, /logout.php           # Standalone Login/Logout

/src/
  config.php                      # DB + App + Telemetrie-Einstellungen
  db.php                          # PDO + db_one() / db_all() / db_exec()
  auth.php                        # Session, Login, CSRF, require_login, current_user,
                                  # user_can_see, user_can_flag, user_can_edit, user_can_delete,
                                  # require_can_edit, require_can_delete
  helpers.php                     # e(), audit_json(), audit_log(), user_can_see(), is_admin_user()
  layout.php                      # render_header() / render_footer() + Menüausgabe
  permission.php                  # user_permissions(), can(), require_permission()
  menu.php                        # load_menu_tree()
  css/
    main.css                      # Legacy CSS (nicht mehr erweitern)
    ui-v2/
      tokens.css                  # CSS Custom Properties (Design-Token)
      base.css                    # Basis-Styles
      components.css              # ui-btn, ui-badge, ui-input, ui-table, ui-kpi, ui-card
      layout.css                  # app, sidebar, content, ui-container, ui-grid, ui-filterbar

/tools/
  runtime_ingest.php              # Telemetrie-Ingest (Single + Bulk) – REST-Endpoint
  runtime_rollup.php              # Aggregator: core_runtime_sample → counter + agg_day (CLI only)

/module/
  /wartungstool/
    dashboard.php                 # INNER-VIEW: Wartungs-Dashboard (UI v2)
    punkt.php                     # INNER-VIEW: Wartungspunkt Detail + Durchführungsformular (UI v2 TODO)
    punkt_save.php                # INNER-VIEW: POST – Protokoll speichern
    punkt_dokument_upload.php     # INNER-VIEW: Dokument-Upload für Wartungspunkt
    uebersicht.php                # INNER-VIEW: Übersicht pro Asset (UI v2)
    admin_punkte.php              # INNER-VIEW: Admin CRUD Wartungspunkte (UI v2)

  /stoerungstool/
    melden.php                    # INNER-VIEW: Störung melden (öffentlich, require_login=0)
    inbox.php                     # INNER-VIEW: Ticket-Inbox
    ticket.php                    # INNER-VIEW: Ticket-Detail

  /admin/
    setup.php                     # Erstbenutzer anlegen
    users.php                     # Benutzerverwaltung
    routes.php                    # Routenverwaltung
    menu.php                      # Menüverwaltung
    permissions.php               # Berechtigungsverwaltung

/uploads/                         # Hochgeladene Dateien (Dokumente/Fotos)

/docs/                            # Projektdokumentation
```

---

## Datenbank

Das aktuelle Datenbankschema: [`docs/db_schema_v2.sql`](docs/db_schema_v2.sql)  
Das Haupt-Schema mit Testdaten-Seeds: [`docs/asset_github_schema.sql`](docs/asset_github_schema.sql)  
(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Zeile 1–3)

### Tabellen-Übersicht

#### Core (Prefix `core_`) – stabile Basis

(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt 2)

| Tabelle | Zweck |
|---|---|
| `core_asset` | Anlagenstamm (Maschinen, Kompressoren, …) |
| `core_asset_kategorie` | Asset-Kategorien mit `kritischkeitsstufe` (1–3) |
| `core_standort` | Standorte und Bereiche |
| `core_user` | Benutzerverwaltung |
| `core_permission` | Berechtigungen (user/modul/objekt_typ/objekt_id) |
| `core_route` | DB-Routing für `app.php` (route_key → file_path + modul/objekt_typ) |
| `core_menu` / `core_menu_item` | Navigation als Baum |
| `core_dokument` | Universelle Dateianhänge (modul + referenz_typ + referenz_id) |
| `core_audit_log` | ISO-Audit-Trail (JSON alt/neu, Actor, IP, Timestamp) |

#### Runtime / Telemetrie (Prefix `core_runtime_`)

(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt 3)

| Tabelle | Zweck |
|---|---|
| `core_runtime_sample` | Polling-Rohdaten (ts + state run/stop); UNIQUE(asset_id, ts) |
| `core_runtime_counter` | Aktueller Produktivstunden-Stand pro Asset (`productive_hours`) |
| `core_runtime_agg_day` | Tagesaggregation (run/stop Sekunden, Gaps, Intervals) |

#### Wartungstool (Prefix `wartungstool_`)

(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt 4)

| Tabelle | Zweck |
|---|---|
| `wartungstool_wartungspunkt` | Wartungsplan (`intervall_typ`: `zeit`\|`produktiv`); enthält `soon_ratio`, `soon_hours` |
| `wartungstool_protokoll` | Durchführungshistorie (Messwert, Status `ok`/`abweichung`) |

#### Störungstool v2 (Prefix `stoerungstool_`)

(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt 5)

| Tabelle | Zweck |
|---|---|
| `stoerungstool_ticket` | Störungstickets inkl. Statusworkflow + v2-Felder |
| `stoerungstool_aktion` | Aktionen, Kommentare, Statuswechsel pro Ticket |

**v2-Felder in `stoerungstool_ticket`:** `meldungstyp`, `fachkategorie`, `maschinenstillstand`, `ausfallzeitpunkt`, `assigned_user_id`  
(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt 5)

---

## Installation & Setup

### Voraussetzungen

- PHP 8.2+  
  (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Zeile 4)
- MariaDB 10.4+  
  (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Zeile 3)
- Webserver (Apache/Nginx) mit PHP-Unterstützung

### Schritte

1. **Repository klonen**
   ```bash
   git clone <repo-url> /path/to/asset_ki
   cd /path/to/asset_ki
   ```

2. **Datenbank erstellen**
   ```sql
   CREATE DATABASE asset_ki CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

3. **Schema einspielen**
   ```bash
   mysql -u <user> -p asset_ki < docs/asset_github_schema.sql
   ```
   (Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Zeile 2 – `asset_github_schema.sql` ist das aktuelle Schema)

4. **Konfiguration anlegen**
   ```bash
   cp src/config.default src/config.php
   # Datenbankzugangsdaten + ingest_token in src/config.php eintragen
   ```
   (Quelle: `src/config.default` existiert im Repository)

5. **Uploads-Verzeichnis vorbereiten**
   ```bash
   mkdir -p uploads
   chmod 775 uploads
   ```
   (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Dokumente/Uploads")

6. **Erstbenutzer anlegen**  
   Aufruf im Browser: `http://<host>/asset_ki/app.php?r=admin.setup`  
   (Quelle: `module/admin/setup.php`, Route `admin.setup` in `docs/KNOWN_ROUTE_KEYS.md`)

7. **Telemetrie-Cron einrichten** (optional)
   ```bash
   # Rollup alle 5 Minuten ausführen (nur CLI erlaubt)
   */5 * * * * php /path/to/asset_ki/tools/runtime_rollup.php
   ```
   (Quelle: `tools/runtime_rollup.php:1` – `if (php_sapi_name() !== 'cli') { ... exit('Forbidden'); }`)

8. **Upload-Limits setzen** (optional)
   ```ini
   upload_max_filesize = 20M
   post_max_size = 25M
   ```
   (Quelle: `docs/PERMISSIONS_GUIDE.md`, Abschnitt „Upload: empfohlene Server-Einstellungen")

---

## Modulübersicht

### Wartungstool (`/module/wartungstool/`)

(Quelle: `module/wartungstool/`, `docs/KNOWN_ROUTE_KEYS.md`)

| Route | Datei | Beschreibung |
|---|---|---|
| `wartung.dashboard` | `dashboard.php` | Fälligkeitsübersicht mit Ampelstatus; KPI-Karten (Überfällig/Bald/OK); Trend-Auswertung |
| `wartung.uebersicht` | `uebersicht.php` | Detailübersicht pro Anlage (alle WP, Zeit- & Produktiv-Intervall) |
| `wartung.punkt` | `punkt.php` | Wartungspunkt-Detail: Anleitung, letzte Protokolle, Durchführungsformular |
| `wartung.punkt_save` | `punkt_save.php` | POST-Handler: Protokoll speichern, Wartungspunkt aktualisieren, optional Ticket anlegen |
| `wartung.punkt_dokument_upload` | `punkt_dokument_upload.php` | Dokument-Upload für Wartungspunkt (via `core_dokument`) |
| `wartung.admin_punkte` | `admin_punkte.php` | Admin CRUD: Wartungspunkte anlegen, bearbeiten, kopieren, CSV-Import |

### Störungstool (`/module/stoerungstool/`)

(Quelle: `module/stoerungstool/`, `docs/KNOWN_ROUTE_KEYS.md`)

| Route | Datei | Beschreibung |
|---|---|---|
| `stoerung.melden` | `melden.php` | Störung melden (öffentlich, `require_login=0`) |
| `stoerung.inbox` | `inbox.php` | Ticket-Übersicht mit Ampel, Filter (Status, Kategorie, Asset) und Suche |
| `stoerung.ticket` | `ticket.php` | Ticket-Detail: Quick-Status, Zuweisung, Aktionen, Dokumente |

### Admin (`/module/admin/`)

(Quelle: `module/admin/`, `docs/KNOWN_ROUTE_KEYS.md`)

| Route | Datei | Beschreibung |
|---|---|---|
| `admin.setup` | `setup.php` | Erstbenutzer anlegen (require_login=0) |
| `admin.users` | `users.php` | Benutzerverwaltung |
| `admin.routes` | `routes.php` | Routenverwaltung |
| `admin.menu` | `menu.php` | Menüverwaltung |
| `admin.permissions` | `permissions.php` | Berechtigungsverwaltung |

### Runtime / Telemetrie (`/tools/`)

(Quelle: `tools/`)

| Datei | Beschreibung |
|---|---|
| `runtime_ingest.php` | Telemetrie-Ingest: Single & Bulk-Import via REST (Token-Auth) |
| `runtime_rollup.php` | Aggregator: `core_runtime_sample` → `core_runtime_counter` + `core_runtime_agg_day` (nur CLI) |

---

## Berechtigungssystem

### Implementierung

Zentrale Funktion: `user_can_see()` in `src/helpers.php:56–61`  
Diese delegiert an `user_can_flag()` in `src/auth.php:124–147`.

**Fallback-Priorität von `user_can_flag()`:**
(Quelle: `src/auth.php:116–120`)

1. `(user, modul, objekt_typ, objekt_id)` – exakt
2. `(user, modul, objekt_typ, NULL)` – objekt-global
3. `(user, modul, 'global', NULL)` – modul-global
4. `(user, '*', '*', NULL)` – Admin-Wildcard
5. `(user, '*', 'global', NULL)` – Admin-Wildcard (alternativ)

Öffentliche Seiten: wenn `modul` oder `objekt_typ` leer → `user_can_see()` gibt `true` zurück.  
(Quelle: `src/helpers.php:57`)

Admin-Wildcard-Erkennung: `modul='*'` und `darf_sehen=1` in `core_permission`.  
(Quelle: `src/helpers.php:70–76`)

### DB-Struktur `core_permission`

(Quelle: `src/auth.php:110–111`)

| Spalte | Bedeutung |
|---|---|
| `user_id` | Benutzer-ID |
| `modul` | z.B. `wartungstool`, `stoerungstool`, `admin`, oder `*` |
| `objekt_typ` | z.B. `global`, `dashboard`, `users`, oder `*` |
| `objekt_id` | optional (NULL = globaler Scope) |
| `darf_sehen` | Lesezugriff |
| `darf_aendern` | Schreibzugriff (geprüft via `user_can_edit()`) |
| `darf_loeschen` | Löschzugriff (geprüft via `user_can_delete()`) |

### Berechtigungsmatrix

(Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Next 1", `docs/KNOWN_ROUTE_KEYS.md`)

| Route | modul / objekt_typ | sehen | ändern | löschen |
|---|---|:---:|:---:|:---:|
| `wartung.dashboard` | wartungstool / dashboard | ✅ | — | — |
| `wartung.punkt` | wartungstool / global | ✅ | — | — |
| `wartung.uebersicht` | wartungstool / global | ✅ | — | — |
| `wartung.punkt_save` | wartungstool / global | ✅ | ✅ (erzwungen) | — |
| `wartung.admin_punkte` | wartungstool / global | ✅ | ✅ (erzwungen) | — |
| `stoerung.melden` | stoerungstool / global | öffentlich | — | — |
| `stoerung.inbox` | stoerungstool / global | ✅ | — | — |
| `stoerung.ticket` | stoerungstool / global | ✅ | ✅ (UI-Flag) | ✅ (UI-Flag) |
| `admin.*` | `*` (Wildcard) | ✅ (Admin) | ✅ (Admin) | ✅ (Admin) |

---

## Telemetrie / Produktivstunden

### Ingest (`tools/runtime_ingest.php`)

(Quelle: `tools/runtime_ingest.php`)

- Auth: Header `X-INGEST-TOKEN` gegen `cfg['telemetry']['ingest_token']`  
  (Quelle: `tools/runtime_ingest.php:9–13`)
- Input: Single `{asset_id, state, ts?, source?, quality?, payload?}` oder Bulk `{samples:[...]}`  
  (Quelle: `tools/runtime_ingest.php:46–56`)
- state-Normalisierung: `run/1/true` → `1`, `stop/0/false` → `0`  
  (Quelle: `tools/runtime_ingest.php:32–36`)
- Schreibt in: `core_runtime_sample` (UPSERT)

```bash
# Single

curl -X POST \
  -H "X-INGEST-TOKEN: <token>" \
  -H "Content-Type: application/json" \
  -d '{"asset_id":1,"state":"run","ts":"2026-02-24 08:00:00"}' \
  http://<host>/asset_ki/tools/runtime_ingest.php

# Bulk

curl -X POST \
  -H "X-INGEST-TOKEN: <token>" \
  -H "Content-Type: application/json" \
  -d '{"samples":[{"asset_id":1,"state":"run","ts":"2026-02-24 08:00:00"}]}' \
  http://<host>/asset_ki/tools/runtime_ingest.php
```

### Rollup (`tools/runtime_rollup.php`)

(Quelle: `tools/runtime_rollup.php`)

- Nur über CLI (`php_sapi_name() !== 'cli'` → HTTP 403)  
  (Quelle: `tools/runtime_rollup.php:1`)
- Liest `core_runtime_sample` ab `core_runtime_counter.last_ts`
- `run`-Intervall: addiert Sekunden zu `productive_hours`  
- `stop`-Intervall: addiert zu `stop_seconds` (ohne `productive_hours`)  
- Zu großes Delta: zählt als `gap` (audit-sicher, wird nicht gerechnet)
- Schreibt: `core_runtime_counter` (für Wartungsfälligkeit) + `core_runtime_agg_day` (für Trends)

---

## Statuslogik Wartung – Verifikation (soon_ratio)

> Spezialabschnitt: Exakte Formel, Fallbacks, Abweichungen zwischen Seiten.

### Felder in `wartungstool_wartungspunkt`

(Quelle: `docs/db_schema_v2.sql:298–300`)

| Feld | Typ | Bedeutung |
|---|---|---|
| `plan_interval` | DOUBLE | Wartungsintervall in Stunden |
| `soon_ratio` | DOUBLE DEFAULT NULL | Relativer Schwellwert 0..1 (z.B. `0.20` = 20%) |
| `soon_hours` | DOUBLE DEFAULT NULL | Absoluter Schwellwert in Stunden (hat Vorrang vor `soon_ratio`) |

### Vollständige Statuslogik (exakt, nach `module/wartungstool/punkt.php:50–124`)

```
Initialisierung:
  soonHours = wp.soon_hours falls > 0, sonst NULL           (punkt.php:52,58)
  soonRatio = wp.soon_ratio falls > 0 und <= 1, sonst NULL  (punkt.php:53–57)
  Falls BEIDE null → soonRatio = 0.20 (Fallback)            (punkt.php:74)

Status-Bestimmung:
  1) rest === null → "Nicht initialisiert"   (kein letzte_wartung / datum)
  2) rest < 0     → "Überfällig"             (punkt.php:87–88)
  3) Bald-Prüfung:
     if soonHours !== null:
       isSoon = (rest <= soonHours)           (punkt.php:91–92)
     else:
       isSoon = (rest <= planInterval * soonRatio)  (punkt.php:94)
     if isSoon → "Bald fällig"               (punkt.php:96)
  4) sonst → "OK"
```

### Abweichungen zwischen Dashboard & Übersicht

| | `dashboard.php` | `uebersicht.php` | `punkt.php` |
|---|---|---|---|
| Funktion | `ui_badge_for()` | `ampel_from_rest()` | inline |
| `soon_hours`-Unterstützung | **Nein** (nur `soon_ratio`) | **Nein** (nur `soon_ratio`) | **Ja** |
| Fallback | `0.20` | `0.20` | `0.20` (via `soonRatio`) |
| Formel Bald | `rest/interval <= ratioLimit` | `rest/interval <= ratioLimit` | `rest <= soonHours` oder `rest <= interval*soonRatio` |

(Quelle: `module/wartungstool/dashboard.php:41–58`, `module/wartungstool/uebersicht.php:34–40`, `module/wartungstool/punkt.php:50–124`)

> **TODO (dokumentiert in `docs/PRIJECT_CONTEXT_v2.md`):** Logik zwischen Dashboard, Übersicht und Detailseite vollständig zentralisieren – `soon_hours` wird derzeit nur in `punkt.php` berücksichtigt, nicht in Dashboard/Übersicht.

### Fallback-Wert (repo-verifiziert)

Fallback `0.20` (20 % des Intervalls) ist an drei Stellen im Code definiert:  
- `module/wartungstool/dashboard.php:51`  
- `module/wartungstool/uebersicht.php:37`  
- `module/wartungstool/punkt.php:74`

### Beispiele

| Intervall | soon_ratio | soon_hours | Bald fällig ab |
|---|---|---|---|
| 100 h | 0.20 | NULL | `rest ≤ 20 h` |
| 50 h | 0.10 | NULL | `rest ≤ 5 h` |
| 200 h | NULL | 15.0 | `rest ≤ 15 h` (nur in punkt.php) |
| 100 h | NULL | NULL | `rest ≤ 20 h` (Fallback 0.20) |

### Fälligkeit berechnen

**Produktiv-Intervall** (`intervall_typ='produktiv'`):
(Quelle: `module/wartungstool/punkt.php:77–97`)
```
dueAt = letzte_wartung + plan_interval        (in Produktivstunden)
rest  = dueAt - core_runtime_counter.productive_hours
```

**Zeit-Intervall** (`intervall_typ='zeit'`):
(Quelle: `module/wartungstool/punkt.php:99–120`)
```
dueTs = strtotime(datum) + round(plan_interval * 3600)
rest  = (dueTs - time()) / 3600              (in Stunden)
```

---

## UI v2 Design-System


### UI v2 – Final Policy (verbindlich)

Wir haben eine „Final“-Version des UI v2 Templates festgelegt.

**Single Source of Truth:**
- Komponenten + Patterns: `docs/UI_V2_GUIDE.md`
- Verbindliche UX-/Style-Regeln (Shopfloor/Operativ): `docs/STYLE_RULES.md`

**CSS Freeze:**
- `src/css/ui-v2/` (`tokens.css`, `base.css`, `components.css`, `layout.css`) gilt als **final**.
- Diese Dateien werden **nicht** mehr „einfach so“ geändert.

**Änderungen am Design-System nur im Ausnahmefall:**
- Wenn eine Änderung an `src/css/ui-v2/*` nötig erscheint, muss vorher:
  1) ein kurzer **Vorschlag** kommen (was/warum),
  2) ein **Impact** (welche Seiten betroffen),
  3) eine **Ja/Nein-Rückfrage** („Soll ich das wirklich ändern?“).
- Default ist: **keine Änderung** am Design-System, sondern Lösung innerhalb der bestehenden Klassen/Patterns.

Ziel: Einheitlicher, stabiler Look über alle Module ohne CSS-Drift.

### CSS-Dateien

(Quelle: `src/layout.php:28–38`, `src/css/ui-v2/`)

| Datei | Inhalt |
|---|---|
| `src/css/main.css` | **Legacy** – nicht mehr erweitern (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „UI v2") |
| `src/css/ticket.css` | Legacy CSS für Störungstool (geladen wenn Route mit `stoerung.`/`ticket`) |
| `src/css/ui-v2/tokens.css` | CSS Custom Properties (Design-Tokens) |
| `src/css/ui-v2/base.css` | Basis-Styles |
| `src/css/ui-v2/components.css` | Komponenten-Klassen |
| `src/css/ui-v2/layout.css` | Layout-Klassen |

Ladereihenfolge: `main.css` → `ticket.css` (konditionell) → `tokens.css` → `base.css` → `components.css` → `layout.css`  
(Quelle: `src/layout.php:26–38`)  
Body-Klasse: `ui-v2` (alle Seiten)  
(Quelle: `src/layout.php:44`)

### Wichtige `ui-*` Klassen

(Quelle: `docs/UI_V2_GUIDE.md`)

| Klasse | Verwendung |
|---|---|
| `ui-container` | Haupt-Container einer INNER-VIEW |
| `ui-page-header` / `ui-page-title` | Seitentitel-Bereich |
| `ui-card` | Container für zusammengehörige Inhalte |
| `ui-badge`, `ui-badge--ok`, `ui-badge--warn`, `ui-badge--danger` | Status-Badges |
| `ui-btn`, `ui-btn--primary`, `ui-btn--ghost`, `ui-btn--sm` | Buttons |
| `ui-input` | Eingabefelder (input + textarea) |
| `ui-table-wrap` / `ui-table` | Tabellen (immer mit Wrap!) |
| `ui-kpi-row` / `ui-kpi` / `ui-kpi--danger` | KPI-Boxen (Dashboard) |
| `ui-filterbar` / `ui-filterbar__form` | Filterbereich |
| `ui-link` | Inline-Links |
| `ui-muted` | Gedämpfte Textfarbe |

### UI v2 Migrationsstatus

(Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „2026 Update – Migrationsstatus")

| Seite | Status |
|---|---|
| `wartung.dashboard` | ✅ UI v2 |
| `wartung.uebersicht` | ✅ UI v2 |
| `wartung.admin_punkte` | ✅ UI v2 |
| `wartung.punkt` | ⚠ UI v2 TODO |
| Störungstool-Seiten | Nicht im Repository verifizierbar (kein expliziter Migrationsvermerk) |
| Admin-Seiten | Nicht im Repository verifizierbar |

---

## Audit-Log

### Implementierung

(Quelle: `src/helpers.php:90–103`)

```php
function audit_log(
    string $modul,
    string $entityType,
    int $entityId,
    string $action,       // CREATE | UPDATE | STATUS | DELETE
    $old = null,
    $new = null,
    ?int $actorUserId = null,
    ?string $actorText = null
): void
```

- IP-Adresse: `$_SERVER['REMOTE_ADDR']`
- JSON-Serialisierung: `audit_json()` mit `JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR`  
  (Quelle: `src/helpers.php:78–88`)

### Tabelle `core_audit_log`

(Quelle: `docs/audit_log_analyse.md`, Abschnitt 2)

| Spalte | Typ | Beschreibung |
|---|---|---|
| `modul` | VARCHAR(50) | z.B. `'wartungstool'`, `'stoerungstool'` |
| `entity_type` | VARCHAR(60) | z.B. `'ticket'`, `'wartungspunkt'`, `'protokoll'` |
| `entity_id` | BIGINT | ID des betroffenen Datensatzes |
| `action` | VARCHAR(30) | `CREATE` / `UPDATE` / `STATUS` / `DELETE` |
| `actor_user_id` | INT NULL | FK auf `core_user.id` |
| `old_json` / `new_json` | LONGTEXT | JSON-Snapshot (mit `json_valid` CHECK) |
| `created_at` | TIMESTAMP | Automatisch gesetzt |

### Bekannte Lücken

(Quelle: `docs/audit_log_analyse.md`, Abschnitt 5 „Zusammenfassung")

- **`module/admin/*`**: Vollständig ohne `audit_log` (18 Schreibpfade)
- **`stoerungstool/melden.php`**: Ticket-CREATE ohne `audit_log`
- **`stoerungstool/ticket.php`**: `update_ticket`-UPDATE ohne `audit_log`

---

## Known Limitations / TODOs

(Quellen: `docs/PRIJECT_CONTEXT_v2.md` Abschnitt „Next 2–5", `docs/audit_log_analyse.md`, `module/wartungstool/punkt.php:327`)

1. **soon_hours nur in `punkt.php`**: `dashboard.php` und `uebersicht.php` kennen `soon_hours` nicht. Zentralisierung ausstehend.  
   (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Wartungssystem – Bald-fällig Logik": „TODO: Logik zwischen Dashboard & Übersicht vollständig zentralisieren.")

2. **Admin ohne Audit-Log**: Alle 18 Schreibpfade in `module/admin/` haben kein `audit_log`.  
   (Quelle: `docs/audit_log_analyse.md`)

3. **Störung melden ohne Audit-Log**: `stoerungstool/melden.php` schreibt kein `audit_log` beim Ticket-CREATE.  
   (Quelle: `docs/audit_log_analyse.md`)

4. **SLA-Felder vorbereitet, nicht aktiviert**: `first_response_at`, `closed_at` in Schema vorhanden, Auto-Set-Logik fehlt noch.  
   (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Next 4")

5. **Störung UX Level 2**: Inbox Quick-Filter, Ticket-Timeline, Aktions-Templates geplant.  
   (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Next 2")

6. **Wartung – Dokumente an Wartungspunkten**: `core_dokument` für `wartungspunkt` Upload/Anzeige geplant.  
   (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Next 3")

---

## Dokumentation

| Datei | Inhalt | Status |
|---|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Architektur-Details (Routing, Permissions, Telemetrie) | ✅ Aktuell |
| [`docs/WARTUNG_LOGIK.md`](docs/WARTUNG_LOGIK.md) | Wartungslogik & soon_ratio Verifikation (detail) | ✅ Aktuell |
| [`docs/PRIJECT_CONTEXT_v2.md`](docs/PRIJECT_CONTEXT_v2.md) | Vollständiger Projektkontext, Arbeitsvertrag | ✅ Aktuell |
| [`docs/PRIJECT_CONTEXT.md`](docs/PRIJECT_CONTEXT.md) | Projektkontext v1 (historisch, Legacy) | Legacy |
| [`docs/db_schema_v2.sql`](docs/db_schema_v2.sql) | Datenbankschema v2 | ✅ Aktuell |
| [`docs/asset_github_schema.sql`](docs/asset_github_schema.sql) | Haupt-Schema + Seeds | ✅ Aktuell |
| [`docs/db_schema.sql`](docs/db_schema.sql) | Datenbankschema v1 (historisch) | Legacy |
| [`docs/DB_SCHEMA_DELTA_NOTES.md`](docs/DB_SCHEMA_DELTA_NOTES.md) | Core vs. Modul-Tabellen | ✅ Aktuell |
| [`docs/KNOWN_ROUTE_KEYS.md`](docs/KNOWN_ROUTE_KEYS.md) | Alle Route-Keys mit Metadaten | ✅ Aktuell |
| [`docs/PERMISSIONS_GUIDE.md`](docs/PERMISSIONS_GUIDE.md) | Rechtesystem, Best Practices, Debug-Checkliste | ✅ Aktuell |
| [`docs/UI_V2_GUIDE.md`](docs/UI_V2_GUIDE.md) | Design-System & Seiten-Patterns | ✅ Aktuell |
| [`docs/audit_log_analyse.md`](docs/audit_log_analyse.md) | Audit-Log-Analyse (Lücken, Schreibpfade) | ✅ Aktuell |
| [`docs/db_migration_permissions_v1.sql`](docs/db_migration_permissions_v1.sql) | Migration: objekt_typ-Konsistenz | ✅ Aktuell |
| [`docs/db_migration_sla_v1.sql`](docs/db_migration_sla_v1.sql) | SLA-Felder Migration | ✅ Aktuell |
| [`docs/db_migration_soon_ratio.sql`](docs/db_migration_soon_ratio.sql) | soon_ratio / soon_hours Migration | ✅ Aktuell |
| [`docs/db_migration_wartungspunkt_dokument_v1.sql`](docs/db_migration_wartungspunkt_dokument_v1.sql) | Wartungspunkt-Dokument Migration | ✅ Aktuell |

---

> **Hinweis:** Inhalt des Legacy-README-Abschnitts „update Wartungstool" und „UI v2 Guide" wurde in diese README integriert und bleibt inhaltlich erhalten.
