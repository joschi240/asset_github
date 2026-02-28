# ARCHITECTURE.md – Routing, Permissions, Inner-View Regel

Stand: 2026-02-28  
Quelle: `app.php`, `src/auth.php`, `src/helpers.php`, `src/layout.php`, `src/db.php`

---

## 1) Front-Controller `app.php` (NICHT ändern)

`app.php` ist der einzige Einstiegspunkt für alle authentifizierten Seiten.

**Ablauf:**
1. Route-Key aus `?r=<route_key>` (Fallback: `cfg['app']['default_route']`)
2. Route-Definition aus `core_route` laden (`aktiv=1` Pflicht)
3. `require_login()` wenn `core_route.require_login = 1`
4. Rechteprüfung: `user_can_see($userId, route.modul, route.objekt_typ, route.objekt_id)`
   - Wenn 0 → HTTP 403
5. Pfad-Absicherung via `realpath()` (blockt `..` Path Traversal)
6. Layout rendern:
   - `render_header(route.titel)`
   - `require route.file_path` → INNER-VIEW
   - `render_footer()`

---

## 2) INNER-VIEW Regel (bindend)

Alle Dateien, die via `app.php` als `route.file_path` geladen werden, sind **INNER-VIEWS**.

**Verboten in INNER-VIEWS:**
- `render_header()` / `render_footer()` aufrufen
- `src/layout.php` includen
- Eigenes HTML-Grundgerüst (`<html>`, `<body>`, `<head>`) ausgeben

**Pflicht in INNER-VIEWS:**
```php
require_once __DIR__ . '/../../src/helpers.php';
```
(Pfad relativ zur Dateilage anpassen.)

`helpers.php` lädt transitiv `db.php` und `auth.php` und stellt alle nötigen Funktionen bereit.

---

## 3) Routing – `core_route`

Tabelle: `core_route`

| Spalte        | Bedeutung                                                   |
|---------------|-------------------------------------------------------------|
| `route_key`   | Eindeutiger Schlüssel, URL-Parameter `?r=<key>`             |
| `titel`       | Seitentitel (für `render_header`)                           |
| `file_path`   | Pfad zur INNER-VIEW-Datei (relativ zu Repo-Root)            |
| `modul`       | Modulname für Permission-Check (z.B. `wartungstool`)        |
| `objekt_typ`  | Objekt-Typ für Permission-Check (z.B. `global`, `dashboard`)|
| `objekt_id`   | Optional: spezifische Objekt-ID (meist NULL)                |
| `require_login`| 1 = Login Pflicht, 0 = öffentlich                          |
| `aktiv`       | 1 = aktiv (wird von `app.php` geladen)                      |

**Best Practice:** `objekt_typ = 'global'` für modul-weite Rechte.  
Ausnahme: `wartung.dashboard` verwendet `objekt_typ = 'dashboard'`.

---

## 4) Permission-System

### 4.1 Tabelle `core_permission`

| Spalte         | Bedeutung                                      |
|----------------|------------------------------------------------|
| `user_id`      | User                                           |
| `modul`        | Modul (`wartungstool`, `stoerungstool`, `*`)   |
| `objekt_typ`   | Typ (`global`, `dashboard`, `*`)               |
| `objekt_id`    | Optional: spezifische ID (NULL = global)       |
| `darf_sehen`   | 0/1                                            |
| `darf_aendern` | 0/1                                            |
| `darf_loeschen`| 0/1                                            |

### 4.2 Primäre API (`src/auth.php`)

```php
user_can_see($userId, $modul, $objektTyp, $objektId)   // darf_sehen
user_can_edit($userId, $modul, $objektTyp, $objektId)  // darf_aendern
user_can_delete($userId, $modul, $objektTyp, $objektId)// darf_loeschen
require_can_edit($modul, $objektTyp, $objektId)        // 403 wenn kein darf_aendern
require_can_delete($modul, $objektTyp, $objektId)      // 403 wenn kein darf_loeschen
```

Direkte SQL-Abfragen auf `core_permission` sind zu vermeiden – immer die API nutzen.

### 4.3 5-stufige Fallback-Logik (`user_can_flag`)

Die Funktion `user_can_flag()` in `src/auth.php` prüft in dieser Prioritätsreihenfolge:

| Stufe | Bedingung                                           |
|-------|-----------------------------------------------------|
| a     | `modul = X AND objekt_typ = Y AND objekt_id = Z`   |
| b     | `modul = X AND objekt_typ = Y AND objekt_id IS NULL`|
| c     | `modul = X AND objekt_typ = 'global' AND objekt_id IS NULL` |
| d     | `modul = '*' AND objekt_typ = '*' AND objekt_id IS NULL`    |
| e     | `modul = '*' AND objekt_typ = 'global' AND objekt_id IS NULL`|

`MAX(flag)` über alle passenden Zeilen → 1 = erlaubt.

**Konsequenz:** Ein User mit `modul='*' / objekt_typ='*'` (Admin-Wildcard) hat immer Zugriff.  
Ein User mit `modul='wartungstool' / objekt_typ='global'` kommt auch an `objekt_typ='dashboard'`-Routen ran (Stufe c).

### 4.4 Sonderfälle

- `modul = NULL` oder `objekt_typ = NULL` in `core_route` → `user_can_see` gibt `true` zurück (öffentlich)
- `require_login = 0` → kein Login nötig, keine Permission-Prüfung (z.B. `stoerung.melden`)

---

## 5) Menü – `core_menu` + `core_menu_item`

- `core_menu`: Container (Name z.B. `main`)
- `core_menu_item`: Baum-Struktur (`parent_id`), Sortierung (`sort`)
  - `route_key` → Generiert Link `app.php?r=<key>` (bevorzugt)
  - `url` → direkter Link (Legacy, vermeiden)
  - `modul / objekt_typ / objekt_id` → Permission-Filter im Menü

Menü-Items werden nur angezeigt, wenn `user_can_see()` für das Item `true` zurückgibt.

---

## 6) Dateisystem-Übersicht

```
/app.php                              # Front-Controller (NICHT ändern)
/src/
  auth.php                            # session, login, csrf, permissions
  helpers.php                         # e(), audit_log(), db_introspection, upload
  db.php                              # PDO + db_one/db_all/db_exec
  layout.php                          # render_header/footer + Menü
  config.default                      # Konfigurationsvorlage

/module/
  /wartungstool/
    dashboard.php                     # INNER-VIEW: Dashboard mit Ampel
    uebersicht.php                    # INNER-VIEW: Wartungspunkte pro Anlage
    punkt.php                         # INNER-VIEW: Wartungspunkt Detail + Durchführung
    punkt_save.php                    # INNER-VIEW: POST Handler (Protokoll + Ticket)
    punkt_dokument_upload.php         # INNER-VIEW: POST Handler (Dokument Upload)
    admin_punkte.php                  # INNER-VIEW: Admin CRUD Wartungspunkte
  /stoerungstool/
    melden.php                        # INNER-VIEW: öffentlich (require_login=0)
    inbox.php                         # INNER-VIEW: Inbox mit Filter + Suche
    ticket.php                        # INNER-VIEW: Ticket Detail + Aktionen
  /admin/
    setup.php                         # INNER-VIEW: Erstuser (require_login=0)
    users.php, routes.php, menu.php, permissions.php

/tools/
  runtime_ingest.php                  # Telemetrie Ingest (single + bulk)
  runtime_rollup.php                  # Aggregator (sample → counter + agg_day)
```

---

## 7) Neue Routen anlegen (Checkliste)

1. SQL: `INSERT INTO core_route (route_key, titel, file_path, modul, objekt_typ, require_login, aktiv, sort) ...`
2. SQL: `INSERT INTO core_menu_item ...` (via `route_key`)
3. SQL: `INSERT INTO core_permission ...` (Seeds für Rollen)
4. PHP: INNER-VIEW anlegen (Start: `require_once __DIR__ . '/../../src/helpers.php';`)
5. Audit: Alle Schreibpfade via `audit_log(...)` absichern

---

Ende.
