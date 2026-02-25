# INVENTORY – Datei- und Modulkarte

> Stand: 2026-02-25 · Analysiert von Copilot Coding Agent (vollständige Neuanalyse)

Diese Datei gibt einen schnellen Überblick: welche Datei tut was, welche Funktionen sind wo definiert, welche Tabellen werden genutzt.

---

## Einstiegspunkte (Web-Requests)

| Datei | Typ | Auth | Beschreibung |
|---|---|---|---|
| `index.php` | Web | nein | Redirect zu `app.php?r=<default_route>` |
| `app.php` | Web | DB-gesteuert | Front-Controller aller INNER-VIEW-Module |
| `login.php` | Web | nein | Login-Formular (standalone mit eigenem Layout) |
| `logout.php` | Web | Session | Session-Destroy + Redirect → `login.php` |
| `tools/runtime_ingest.php` | REST | `X-INGEST-TOKEN` | Telemetrie-Ingest (Single + Bulk, JSON) |
| `tools/runtime_rollup.php` | CLI/Cron | nur CLI | Rollup-Aggregator für Produktivstunden |

---

## `src/` – Kern-Bibliotheken

### `src/config.php` (nicht versioniert) / `src/config.default` (Template)

Kein PHP-Code, nur Konfigurationsarray. Enthält: `db.*`, `app.*`, `upload.*`, `telemetry.*`.

---

### `src/db.php`

| Funktion | Signatur | Beschreibung |
|---|---|---|
| `db()` | `(): PDO` | PDO-Singleton; stellt Verbindung via `config.php` her |
| `db_one()` | `(string $sql, array $params): array\|false` | Führt Query aus, gibt erste Zeile zurück |
| `db_all()` | `(string $sql, array $params): array` | Führt Query aus, gibt alle Zeilen zurück |
| `db_exec()` | `(string $sql, array $params): int` | Führt Query aus, gibt `rowCount()` zurück |

**DB-Konfiguration:** `PDO::ERRMODE_EXCEPTION`, `PDO::FETCH_ASSOC`, `PDO::ATTR_EMULATE_PREPARES = false`

---

### `src/auth.php`

Lädt: `src/db.php`

| Funktion | Beschreibung |
|---|---|
| `app_cfg()` | Config-Singleton aus `src/config.php`; auto-erkennt `base_url` aus `SCRIPT_NAME` |
| `session_boot()` | Startet Session mit `httponly`, `samesite=Lax`, `secure` (bei HTTPS) |
| `current_user()` | Gibt `$_SESSION['user']` zurück oder `null` |
| `require_login()` | Redirect → `login.php` wenn nicht eingeloggt |
| `login(string $benutzername, string $passwort): bool` | DB-Lookup, bcrypt-Verify, `session_regenerate_id(true)`, `last_login_at` updaten |
| `logout()` | Session leeren + Cookie entfernen + `session_destroy()` |
| `csrf_token(): string` | Erstellt/gibt `$_SESSION[$csrf_key]` zurück (32 hex-Zeichen) |
| `csrf_check(?string $token): void` | `hash_equals()`-Vergleich; HTTP 400 bei Fehler |
| `user_can_flag(?int $userId, ?string $modul, ?string $objektTyp, $objektId, string $flagCol): bool` | Generische Permission-Prüfung; `$flagCol` per Whitelist validiert |
| `user_can_edit(...): bool` | Wrapper: `user_can_flag(..., 'darf_aendern')` |
| `user_can_delete(...): bool` | Wrapper: `user_can_flag(..., 'darf_loeschen')` |
| `require_can_edit(...)` | HTTP 403 wenn kein `darf_aendern` |
| `require_can_delete(...)` | HTTP 403 wenn kein `darf_loeschen` |

**Tabellen:** `core_user`, `core_permission`

---

### `src/helpers.php`

Lädt: `src/db.php`, `src/auth.php`

| Funktion | Beschreibung |
|---|---|
| `e(?string $s): string` | `htmlspecialchars(ENT_QUOTES\|ENT_SUBSTITUTE, 'UTF-8')` |
| `db_table_exists(string $table): bool` | `information_schema.tables` Lookup |
| `db_col_exists(string $table, string $col): bool` | `information_schema.columns` Lookup |
| `user_can_see(?int $userId, ?string $modul, ?string $objektTyp, ?int $objektId): bool` | Sichtbarkeits-Check; Wildcard `modul='*'`; öffentlich wenn `modul/objekt_typ` leer |
| `has_any_user(): bool` | Prüft ob mindestens ein Benutzer existiert |
| `is_admin_user(?int $userId): bool` | Prüft `core_permission (modul='*', darf_sehen=1)` |
| `audit_log(string $modul, string $entityType, int $entityId, string $action, $old, $new, ?int $actorUserId, ?string $actorText): void` | INSERT in `core_audit_log` |
| `badge_for_ticket_status(string $status): array` | Gibt `['cls'=>..., 'label'=>...]` für CSS-Klasse + Text zurück |
| `ensure_dir(string $dir): void` | Erstellt Verzeichnis rekursiv (0775) |
| `handle_upload(array $file, string $targetDir): ?array` | MIME-Check, SHA-256, zufälliger Dateiname, `move_uploaded_file()` |
| `route_map_for_keys(array $keys): array` | Bulk-Load `core_route` für mehrere route_keys |
| `load_menu_tree(string $menuName = 'main'): array` | Lädt Menübaum (neu: `core_menu_item`; legacy: `core_menu`); Permission-Filter; Branch-Active; is_group |

**Tabellen:** `core_permission`, `core_user`, `core_audit_log`, `core_route`, `core_menu`, `core_menu_item`

---

### `src/permission.php`

Lädt: `src/db.php`, `src/helpers.php`

| Funktion | Beschreibung |
|---|---|
| `user_permissions(): array` | Gibt alle Permissions des aktuellen Users gruppiert nach Modul zurück (gecacht) |
| `can(string $modul, string $recht): bool` | Prüft `darf_sehen/aendern/loeschen` für aktuellen User; Admin-Wildcard |
| `require_permission(string $modul, string $recht): void` | HTTP 403 + exit wenn `can()` false |

**Tabellen:** `core_permission`

---

### `src/layout.php`

Lädt: `src/helpers.php`

| Funktion | Beschreibung |
|---|---|
| `render_header(string $title): void` | HTML-Head, Sidebar mit Menü + User-Info, `<main id="main-content">` öffnen |
| `render_footer(): void` | `</main></div></body></html>` |

**Enthält:** Skip-Link, `aria-label="Hauptnavigation"` auf `<nav>`.

---

### `src/menu.php` ⚠️ Toter Code

Enthält eine veraltete `load_menu_tree()` – nur Legacy-Schema. Wird von keiner Produktionsdatei eingebunden. Siehe `ROADMAP.md → TASK-14`.

---

### `src/css/main.css`

CSS ohne Build-Schritt. Enthält:
- Skip-Link-Styles (`.skip-link:focus`)
- App-Shell (Sidebar + Content-Bereich, Flexbox)
- Responsive Breakpoint bei 900px
- Card, Grid, Badge, Table, Button, Navitem-Klassen

---

## `module/wartungstool/` – Anlagenwartung

### `module/wartungstool/dashboard.php`

**Typ:** INNER-VIEW (via `app.php`)  
**Auth:** Login + `user_can_see('wartungstool', 'global')`

| Funktion (lokal definiert) | Beschreibung |
|---|---|
| `berechneDashboard(int $assetId): array` | 4 DB-Queries: Produktivstunden, Wochenschnitt, Trend, nächste Fälligkeit |
| `ampel_for(?float $rest, float $interval): array` | Gibt Ampel-Badge (`badge--r/y/g`) zurück |
| `renderTable(array $rows, string $title, string $base, bool $canSeePunkt): void` | Rendert Dashboard-Tabelle mit Ampel |

**Tabellen:** `core_asset`, `core_asset_kategorie`, `core_runtime_counter`, `core_runtime_agg_day`, `wartungstool_wartungspunkt`

---

### `module/wartungstool/uebersicht.php`

**Typ:** INNER-VIEW  
**Auth:** Login  
**Funktion:** Wartungspunkte pro Asset mit Fälligkeit und letztem Protokoll-Eintrag (ein komplexer LEFT JOIN mit Subquery für letzten Protokolleintrag je Wartungspunkt).

| Funktion (lokal definiert) | Beschreibung |
|---|---|
| `ampel_from_rest()` | Ampel-Badge aus Restlaufzeit |
| `is_open_item()` | `true` wenn überfällig, bald fällig, oder noch keine Wartung |
| `extract_ticket_marker()` | Extrahiert `[#TICKET:123]` aus Bemerkungstext |
| `short_text()` | Kürzt String auf max. Länge (⚠️ Duplikat, siehe `ROADMAP.md → TASK-09`) |

**Tabellen:** `core_asset`, `core_asset_kategorie`, `core_runtime_counter`, `wartungstool_wartungspunkt`, `wartungstool_protokoll`

---

### `module/wartungstool/punkt.php`

**Typ:** INNER-VIEW  
**Auth:** Login  
**Funktion:** Detailansicht eines Wartungspunkts + Durchführungsformular + letzte 5 Protokolle.  
**Enthält:** Vanilla-JS für Messwert-Grenzwert-Feedback (inline `<script>`).

**GET-Parameter:** `?wp=<wartungspunkt_id>`  
**Tabellen:** `wartungstool_wartungspunkt`, `core_asset`, `core_asset_kategorie`, `core_runtime_counter`, `wartungstool_protokoll`, `core_user`

⚠️ Zeilen 13–14: `function_exists()`-Guard mit Fallback `true` (siehe `RISKS.md → P2-Q-3`, `ROADMAP.md → TASK-05`).

---

### `module/wartungstool/punkt_save.php`

**Typ:** INNER-VIEW (POST-Handler)  
**Auth:** Login + `require_can_edit('wartungstool', 'global')`

**Aktionen (in Transaktion):**
1. INSERT `wartungstool_protokoll`
2. UPDATE `wartungstool_wartungspunkt` (letzte_wartung oder datum)
3. `audit_log()` für Protokoll + Wartungspunkt
4. Optional: INSERT `stoerungstool_ticket` + `stoerungstool_aktion` + Ticket-Referenz in Bemerkung

**Tabellen:** `wartungstool_wartungspunkt`, `core_asset`, `core_runtime_counter`, `wartungstool_protokoll`, `stoerungstool_ticket`, `stoerungstool_aktion`, `core_audit_log`

---

### `module/wartungstool/admin_punkte.php`

**Typ:** INNER-VIEW (GET + POST)  
**Auth:** Login + `require_can_edit('wartungstool', 'global')`

**Aktionen:**
- `create`: Neuen Wartungspunkt anlegen
- `update`: Wartungspunkt bearbeiten
- `toggle_active`: Aktivieren/Deaktivieren (kein Löschen)
- `copy_from_asset`: Wartungspunkte von einer anderen Anlage kopieren
- `csv_import`: CSV/Paste-Import (Delimiter: `;`, `,` oder TAB; Header optional)

**Tabellen:** `core_asset`, `core_asset_kategorie`, `core_runtime_counter`, `wartungstool_wartungspunkt`, `core_audit_log`

---

## `module/stoerungstool/` – Störungsmanagement

### `module/stoerungstool/melden.php`

**Typ:** INNER-VIEW  
**Auth:** **kein Login** (öffentlich via `require_login=0` in `core_route`)

**Felder:** `asset_id` (optional), `meldungstyp` (Störmeldung/Mängelkarte/Logeintrag), `fachkategorie`, `maschinenstillstand`, `name`, `kontakt`, `ausfallzeitpunkt`, `prioritaet`, `titel`, `beschreibung`, `file` (optional Upload)

**Tabellen:** `core_asset`, `stoerungstool_ticket`, `stoerungstool_aktion`, `core_dokument`

⚠️ Kein Rate-Limiting, keine Asset-ID-Validierung (siehe `RISKS.md → P0-S-2, P1-D-1`).

---

### `module/stoerungstool/inbox.php`

**Typ:** INNER-VIEW  
**Auth:** Login + `user_can_edit('stoerungstool', 'global')`

**Filter:** `asset_id`, `status`, `meldungstyp`, `fachkategorie`, `prio`, `q` (Volltext inkl. Aktionen), `show_done`, `show_older`, `only_stop`, `only_unassigned`

**SQL:** Ein großes Query mit LEFT JOINs für letzten Aktions-Eintrag + Arbeitszeitensumme; `FIELD()` für Status-Sortierung; LIMIT 300.

| Funktion (lokal definiert) | Beschreibung |
|---|---|
| `short_text()` | ⚠️ Duplikat – auch in `uebersicht.php` |
| `fmt_minutes(int $min): string` | Formatiert Minuten als `HH:MM` |

**Tabellen:** `stoerungstool_ticket`, `core_asset`, `core_user` (assigned + action-user), `stoerungstool_aktion`

---

### `module/stoerungstool/ticket.php`

**Typ:** INNER-VIEW (GET + POST)  
**Auth:** Login

**Aktionen:**
- `set_status`: Status setzen + automatisch `assigned_user_id` bei `angenommen`
- `assign`: Zuweisung ändern
- `add_action`: Aktion/Kommentar + optionaler Status-Wechsel
- `update_ticket`: Ticket-Metadaten bearbeiten
- `upload_doc`: Datei hochladen

**Tabellen:** `stoerungstool_ticket`, `core_asset`, `core_user`, `stoerungstool_aktion`, `core_dokument`, `core_audit_log`

---

## `module/admin/` – Administration

| Datei | Beschreibung | Tabellen |
|---|---|---|
| `setup.php` | Erstbenutzer anlegen (Guard: `has_any_user()`); legt Admin-Wildcard in `core_permission` an | `core_user`, `core_permission` |
| `users.php` | Benutzerverwaltung (CRUD: anlegen, bearbeiten, aktiv/inaktiv) | `core_user` |
| `routes.php` | Routen-Verwaltung (CRUD: `core_route`) | `core_route` |
| `menu.php` | Menü-Verwaltung (CRUD: `core_menu`, `core_menu_item`) | `core_menu`, `core_menu_item` |
| `permissions.php` | Berechtigungs-Verwaltung (CRUD: `core_permission`) | `core_permission`, `core_user` |

---

## `tools/` – CLI-Skripte

### `tools/runtime_ingest.php`

**Typ:** REST-Endpoint (POST, JSON oder form-data)  
**Auth:** `X-INGEST-TOKEN` Header (statischer Token aus `config.php: telemetry.ingest_token`)

| Funktion | Beschreibung |
|---|---|
| `normalize_state($stateRaw): ?int` | Normalisiert `run/stop/1/0/true/false` → `1/0/null` |
| `validate_ts(string $ts, int $maxSkewSec): bool` | Prüft DateTime-Format + max. Clock-Skew |

**Tabellen:** `core_asset` (Existenz-Check), `core_runtime_sample` (UPSERT)

---

### `tools/runtime_rollup.php`

**Typ:** CLI/Cron  
**Guard:** `php_sapi_name() !== 'cli'` → HTTP 403 (Zeile 2)

| Funktion | Beschreibung |
|---|---|
| `split_interval_by_day(int $startTs, int $endTs): array` | Teilt Zeitintervall in Tages-Stücke auf (Mitternacht-Splitting) |
| `ensure_counter_row(int $assetId): array` | Erstellt `core_runtime_counter`-Zeile falls nicht vorhanden |
| `upsert_agg(int $assetId, string $day, int $runSec, int $stopSec, int $intervals, int $gaps): void` | Additive UPSERT in `core_runtime_agg_day` |

**Tabellen:** `core_runtime_sample`, `core_runtime_counter`, `core_runtime_agg_day`

---

## Tabellen-zu-Modul-Matrix

| Tabelle | Gelesen von | Geschrieben von |
|---|---|---|
| `core_asset` | dashboard.php, uebersicht.php, punkt.php, inbox.php, ticket.php, ingest.php, admin_punkte.php, melden.php | (admin/setup oder externe Migration) |
| `core_asset_kategorie` | dashboard.php, uebersicht.php, admin_punkte.php | (extern/Migration) |
| `core_user` | auth.php, ticket.php, inbox.php, punkt.php, punkt_save.php | setup.php, admin/users.php, auth.php (last_login_at) |
| `core_permission` | auth.php, helpers.php, permission.php | setup.php, admin/permissions.php |
| `core_route` | app.php, helpers.php | admin/routes.php |
| `core_menu` | helpers.php | admin/menu.php |
| `core_menu_item` | helpers.php | admin/menu.php |
| `core_audit_log` | admin_punkte.php (Anzeige) | helpers.php: `audit_log()` (alle Module) |
| `core_dokument` | ticket.php | melden.php, ticket.php |
| `core_runtime_sample` | rollup.php | ingest.php |
| `core_runtime_counter` | dashboard.php, uebersicht.php, punkt.php, punkt_save.php, admin_punkte.php | rollup.php |
| `core_runtime_agg_day` | dashboard.php, uebersicht.php | rollup.php |
| `wartungstool_wartungspunkt` | dashboard.php, uebersicht.php, punkt.php, punkt_save.php | admin_punkte.php, punkt_save.php |
| `wartungstool_protokoll` | punkt.php, uebersicht.php | punkt_save.php |
| `stoerungstool_ticket` | inbox.php, ticket.php | melden.php, ticket.php, punkt_save.php |
| `stoerungstool_aktion` | ticket.php | melden.php, ticket.php, punkt_save.php |
