# ARCHITECTURE.md – Asset KI Instandhaltung

> Repo-verifiziert. Stand: 2026-02-28. Jede Aussage mit Fundstelle.  
> Nur Fakten aus dem Repository. Keine Annahmen.

---

## 1) Front-Controller: `app.php`

(Quelle: `app.php:1–73`)

```
HTTP-Request → app.php
  ↓
  ?r=<route_key>  (Fallback: cfg['app']['default_route'] = 'wartung.dashboard')
  ↓
  SELECT FROM core_route WHERE route_key=? AND aktiv=1
  ↓
  [404 wenn nicht gefunden]
  ↓
  [require_login() wenn require_login=1]
  ↓
  user_can_see($userId, modul, objekt_typ, objekt_id)
  ↓
  [403 wenn kein Zugriff]
  ↓
  realpath-Check (blockt '..')
  ↓
  [400 wenn Pfad ungültig | 500 wenn Datei fehlt]
  ↓
  render_header(route.titel)
  require route.file_path   ← INNER-VIEW
  render_footer()
```

---

## 2) Routing-System

### Wo implementiert

- Entscheidung: `app.php:10–21`
- Tabelle: `core_route`
- Route-Key: `?r=<route_key>` (GET-Parameter)

### Schema `core_route`

(Quelle: `docs/db_schema_v2.sql`, `docs/KNOWN_ROUTE_KEYS.md`)

| Spalte | Beschreibung |
|---|---|
| `route_key` | Eindeutiger Schlüssel (z.B. `wartung.dashboard`) |
| `titel` | Anzeige-Titel der Seite |
| `file_path` | Relativer Pfad zur INNER-VIEW-Datei |
| `modul` | Modul-Name für Permission-Check |
| `objekt_typ` | Objekt-Typ für Permission-Check |
| `objekt_id` | Optional: spezifisches Objekt |
| `require_login` | 0 = öffentlich, 1 = Login erforderlich |
| `aktiv` | 0 = deaktiviert (→ 404), 1 = aktiv |
| `sort` | Sortierreihenfolge |

### Alle bekannten Route-Keys

(Quelle: `docs/KNOWN_ROUTE_KEYS.md`)

| route_key | modul / objekt_typ | require_login |
|---|---|:---:|
| `wartung.dashboard` | wartungstool / global | 1 |
| `wartung.punkt` | wartungstool / global | 1 |
| `wartung.punkt_save` | wartungstool / global | 1 |
| `wartung.punkt_dokument_upload` | wartungstool / global | 1 |
| `wartung.uebersicht` | wartungstool / global | 1 |
| `wartung.admin_punkte` | wartungstool / global | 1 |
| `stoerung.melden` | stoerungstool / global | 0 |
| `stoerung.inbox` | stoerungstool / global | 1 |
| `stoerung.ticket` | stoerungstool / global | 1 |
| `admin.setup` | NULL / NULL | 0 |
| `admin.users` | admin / users | 1 |
| `admin.routes` | admin / routes | 1 |
| `admin.menu` | admin / menu | 1 |
| `admin.permissions` | admin / permissions | 1 |

---

## 3) Permission-System

### Funktionen und Dateien

(Quelle: `src/helpers.php:56–76`, `src/auth.php:113–184`, `src/permission.php`)

| Funktion | Datei | Beschreibung |
|---|---|---|
| `user_can_see()` | `src/helpers.php:56–61` | Delegiert an `user_can_flag(..., 'darf_sehen')` |
| `user_can_flag()` | `src/auth.php:132–155` | Zentraler Resolver mit 5-stufigem Fallback |
| `user_can_edit()` | `src/auth.php:159–161` | Delegiert an `user_can_flag(..., 'darf_aendern')` |
| `user_can_delete()` | `src/auth.php:165–167` | Delegiert an `user_can_flag(..., 'darf_loeschen')` |
| `require_can_edit()` | `src/auth.php:171–179` | Wirft HTTP 403 wenn kein Schreibrecht |
| `require_can_delete()` | `src/auth.php:183–191` | Wirft HTTP 403 wenn kein Löschrecht |
| `is_admin_user()` | `src/helpers.php:70–76` | Prüft `modul='*'` in `core_permission` |
| `can()` | `src/permission.php:48–76` | Alternative Prüfung ohne objekt_typ |
| `user_permissions()` | `src/permission.php:11–37` | Gibt alle Permissions des aktuellen Users zurück |

### `user_can_flag()` Fallback-Priorität

(Quelle: `src/auth.php:123–131`)

```
a) (user_id, modul, objekt_typ, objekt_id)  ← exakt
b) (user_id, modul, objekt_typ, NULL)         ← objekt-global
c) (user_id, modul, 'global', NULL)            ← modul-global
d) (user_id, '*', '*', NULL)                   ← Admin-Wildcard
e) (user_id, '*', 'global', NULL)              ← Admin-Wildcard (alternativ)
```

Alle in einer einzigen SQL-Query via `MAX(flag_col) ... WHERE ... OR ... OR ...`  
(Quelle: `src/auth.php:138–154`)

### Öffentliche Seiten

Wenn `core_route.modul` oder `core_route.objekt_typ` leer (NULL) → `user_can_see()` gibt `true` zurück ohne DB-Query.  
(Quelle: `src/helpers.php:57`)

---

## 4) Navigation

(Quelle: `src/layout.php:14–89`)

- Menü wird geladen via `load_menu_tree('main')` in `render_header()`
- Quelle: Tabellen `core_menu` und `core_menu_item`
- `core_menu_item` hat: `parent_id` (Baumstruktur), `sort`, `route_key` oder `url`
- Best Practice: nur `route_key` verwenden → Link: `app.php?r=<route_key>`
- Layout-Render: Sidebar mit `<nav class="sidebar__nav">`
- Aktiver Menü-Eintrag: wird via `route_key` vs. aktuellem `?r=` bestimmt
- Bedingter Layout-Titel: bei `isWartung=true` wird Seitentitel im Layout ausgeblendet (INNER-VIEW rendert eigenen Header)  
  (Quelle: `src/layout.php:16–22`)

---

## 5) Telemetrie-Pipeline

(Quelle: `tools/runtime_ingest.php`, `tools/runtime_rollup.php`)

```
Maschine/SPS
    ↓ HTTP POST (X-INGEST-TOKEN)
tools/runtime_ingest.php
    ↓ UPSERT
core_runtime_sample (ts, asset_id, state run/stop)
    ↓ (via Cron: php tools/runtime_rollup.php)
tools/runtime_rollup.php
    ↓ → core_runtime_counter (productive_hours pro Asset)
    ↓ → core_runtime_agg_day (run/stop/gaps pro Tag)
         ↑
         Verwendet von: uebersicht.php, punkt.php (Fälligkeit)
         TODO: dashboard.php (Trend – geplant, noch nicht implementiert)
```

### Rollup-Logik (Zusammenfassung)

(Quelle: `tools/runtime_rollup.php`)

- Startet bei `core_runtime_counter.last_ts` pro Asset
- Jedes Sample-Paar bildet ein Intervall
- Gap-Erkennung: Delta > `gapThresholdSec` (Default: `3 × expectedIntervalSec`) → zählt als Gap, keine Produktivstunden
- Tagesaufteilung: `split_interval_by_day()` – Intervalle werden tagesgenau aufgeteilt
- UPSERT in `core_runtime_agg_day` (addiert auf bestehende Tageswerte)

---

## 6) Audit-Log

(Quelle: `src/helpers.php:78–103`)

### Funktionssignatur

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

### JSON-Serialisierung

(Quelle: `src/helpers.php:78–88`)

```php
function audit_json($value): ?string
// Flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
//      | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
// Gibt NULL zurück wenn $value === null
// Gibt NULL zurück bei json_encode-Fehler (mit error_log)
```

**Hinweis:** `audit_log_analyse.md` dokumentiert in Abschnitt 1 eine frühere Version der `audit_json`-Logik (ohne `JSON_INVALID_UTF8_SUBSTITUTE`). Der aktuelle Stand in `src/helpers.php:78–88` verwendet zusätzliche Flags für Robustheit.

### Audit-Log Abdeckung

(Quelle: `docs/audit_log_analyse.md`)

- ✅ `wartungstool/admin_punkte.php`: alle CRUD-Pfade
- ✅ `wartungstool/punkt_save.php`: Protokoll + WP-Update + TICKET:DENIED-Marker + Ticket-Link-Marker
- ✅ `stoerungstool/ticket.php`: Status-Änderungen, Zuweisung, Aktion-INSERTs, Dokument-Upload
- ✅ `module/admin/permissions.php`: alle Schreibpfade mit audit_log abgedeckt
- ✅ `module/admin/routes.php`: alle 3 Schreibpfade (UPDATE/INSERT/DELETE) mit audit_log
- ✅ `module/admin/users.php`: CREATE + UPDATE + Deaktivieren mit audit_log
- ✅ `stoerungstool/melden.php`: Ticket-CREATE, Aktion-INSERT, Dokument-INSERT mit audit_log
- ✅ `module/admin/menu.php`: alle 3 Schreibpfade (UPDATE/INSERT/DELETE auf `core_menu_item`) mit audit_log
- ✅ `module/admin/setup.php`: beide Schreibpfade (INSERT auf `core_user` und `core_permission`) mit audit_log

---

## 7) Dokumente / Uploads

(Quelle: `docs/DB_SCHEMA_DELTA_NOTES.md`, Abschnitt „Dokumente", `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Dokumente/Uploads")

- Dateien physisch unter `/uploads/...`
- DB-Referenz universell via `core_dokument`:
  - `modul` (z.B. `'stoerungstool'`, `'wartungstool'`)
  - `referenz_typ` (z.B. `'ticket'`, `'wartungspunkt'`)
  - `referenz_id` (ID des referenzierten Datensatzes)
  - `dateiname` = relativer Pfad unter `/uploads/`
  - Optional: `sha256`, mime, size, originalname
- Download-Link: `<base>/uploads/<dateiname>`

---

Ende.
