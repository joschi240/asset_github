# Audit-Log Analyse (Branch: ui_v2)

> Nur Fakten. Keine Fixes. Jede Aussage mit Datei + Zeilen belegt.
> Stand: 2026-02-28 (aktualisiert: Abschnitt 1 Hinweis auf `audit_json()` Änderung ergänzt).

---

## 1) Implementierung von `audit_log()`

**Datei:** `src/helpers.php`, Zeilen 78–88

**Funktionssignatur:**
```php
function audit_log(
    string $modul,
    string $entityType,
    int $entityId,
    string $action,
    $old = null,
    $new = null,
    ?int $actorUserId = null,
    ?string $actorText = null
): void
```

**Was wird gespeichert (INSERT-Statement, Zeilen 83–87):**

| Spalte          | Quelle                                            |
|-----------------|---------------------------------------------------|
| `modul`         | Parameter `$modul`                                |
| `entity_type`   | Parameter `$entityType`                           |
| `entity_id`     | Parameter `$entityId`                             |
| `action`        | Parameter `$action`                               |
| `actor_user_id` | Parameter `$actorUserId`                          |
| `actor_text`    | Parameter `$actorText`                            |
| `ip_addr`       | `$_SERVER['REMOTE_ADDR']` (Zeile 79)              |
| `old_json`      | `json_encode($old, JSON_UNESCAPED_UNICODE)` — **Legacy-Hinweis:** Diese Datei dokumentiert eine frühere Version. Aktuelle Implementierung (`src/helpers.php:78–88`) nutzt `audit_json()` mit zusätzlichen Flags `JSON_UNESCAPED_SLASHES \| JSON_INVALID_UTF8_SUBSTITUTE \| JSON_PARTIAL_OUTPUT_ON_ERROR` und gibt `NULL` bei Fehler zurück (mit `error_log`). |
| `new_json`      | `json_encode($new, JSON_UNESCAPED_UNICODE)` — Gleiche Anmerkung wie `old_json`. |

---

## 2) Schema `core_audit_log`

**Datei:** `docs/db_schema.sql`, Zeilen 145–162 
(Identisch auch in `docs/db_schema_v2.sql` Zeilen 122–143 und `docs/asset_github_schema.sql` Zeilen 46–59)

```sql
CREATE TABLE IF NOT EXISTS core_audit_log (
  id              BIGINT(20)   NOT NULL AUTO_INCREMENT,
  modul           VARCHAR(50)  NOT NULL,
  entity_type     VARCHAR(60)  NOT NULL,
  entity_id       BIGINT(20)   NOT NULL,
  action          VARCHAR(30)  NOT NULL,
  actor_user_id   INT(11)      DEFAULT NULL,
  actor_text      VARCHAR(120) DEFAULT NULL,
  ip_addr         VARCHAR(45)  DEFAULT NULL,
  old_json        LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                  DEFAULT NULL CHECK (json_valid(old_json)),
  new_json        LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                  DEFAULT NULL CHECK (json_valid(new_json)),
  created_at      TIMESTAMP    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_entity  (modul, entity_type, entity_id),
  KEY idx_actor   (actor_user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Spaltenübersicht:**

| Spalte          | Typ              | NULL? | Beschreibung                          |
|-----------------|------------------|-------|---------------------------------------|
| id              | BIGINT(20)       | NEIN  | PK, AUTO_INCREMENT                    |
| modul           | VARCHAR(50)      | NEIN  | z.B. 'stoerungstool', 'wartungstool'  |
| entity_type     | VARCHAR(60)      | NEIN  | z.B. 'ticket', 'wartungspunkt'        |
| entity_id       | BIGINT(20)       | NEIN  | ID des betroffenen Datensatzes        |
| action          | VARCHAR(30)      | NEIN  | z.B. 'CREATE', 'UPDATE', 'STATUS'     |
| actor_user_id   | INT(11)          | JA    | FK auf core_user.id                   |
| actor_text      | VARCHAR(120)     | JA    | Anzeigename des Akteurs               |
| ip_addr         | VARCHAR(45)      | JA    | IPv4/IPv6 des Aufrufers               |
| old_json        | LONGTEXT         | JA    | Vorher-Zustand (json_valid CHECK)     |
| new_json        | LONGTEXT         | JA    | Nachher-Zustand (json_valid CHECK)    |
| created_at      | TIMESTAMP        | NEIN  | Zeitpunkt, DEFAULT current_timestamp()|

---

## 3) Alle `audit_log`-Aufrufe im Projekt

| Datei | Zeile | Modul | entity_type | action |
|-------|-------|-------|-------------|--------|
| `module/stoerungstool/melden.php` | 58 | `'stoerungstool'` | `'dokument'` | `'CREATE'` |
| `module/stoerungstool/melden.php` | 127 | `'stoerungstool'` | `'ticket'` | `'CREATE'` |
| `module/stoerungstool/melden.php` | 153 | `'stoerungstool'` | `'aktion'` | `'CREATE'` |
| `module/stoerungstool/ticket.php` | 47 | `'stoerungstool'` | `'ticket'` | `'STATUS'` |
| `module/stoerungstool/ticket.php` | 48 | `'stoerungstool'` | `'aktion'` | `'CREATE'` |
| `module/stoerungstool/ticket.php` | 66 | `'stoerungstool'` | `'ticket'` | `'UPDATE'` |
| `module/stoerungstool/ticket.php` | 67 | `'stoerungstool'` | `'aktion'` | `'CREATE'` |
| `module/stoerungstool/ticket.php` | 85 | `'stoerungstool'` | `'aktion'` | `'CREATE'` |
| `module/stoerungstool/ticket.php` | 114 | `'stoerungstool'` | `'dokument'` | `'CREATE'` |
| `module/wartungstool/punkt_dokument_upload.php` | 82 | `'wartungstool'` | `'dokument'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 145 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 210 | `'wartungstool'` | `'wartungspunkt'` | `'UPDATE'` |
| `module/wartungstool/admin_punkte.php` | 238 | `'wartungstool'` | `'wartungspunkt'` | `'STATUS'` |
| `module/wartungstool/admin_punkte.php` | 303 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 317 | `'wartungstool'` | `'asset'` | `'UPDATE'` |
| `module/wartungstool/admin_punkte.php` | 448 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 467 | `'wartungstool'` | `'asset'` | `'UPDATE'` |
| `module/wartungstool/punkt_save.php` | 114 | `'wartungstool'` | `'protokoll'` | `'CREATE'` |
| `module/wartungstool/punkt_save.php` | 124 | `'wartungstool'` | `'wartungspunkt'` | `'UPDATE'` |
| `module/wartungstool/punkt_save.php` | 138 | `'wartungstool'` | `'protokoll'` | `'UPDATE'` |
| `module/wartungstool/punkt_save.php` | 173 | `'stoerungstool'` | `'ticket'` | `'CREATE'` |
| `module/wartungstool/punkt_save.php` | 179 | `'stoerungstool'` | `'aktion'` | `'CREATE'` |
| `module/wartungstool/punkt_save.php` | 197 | `'wartungstool'` | `'protokoll'` | `'UPDATE'` |
| `module/admin/permissions.php` | 66 | `'admin'` | `'permission'` | `'UPDATE'` |
| `module/admin/permissions.php` | 76 | `'admin'` | `'permission'` | `'CREATE'` |
| `module/admin/permissions.php` | 82 | `'admin'` | `'permission'` | `'DELETE'` |
| `module/admin/permissions.php` | 130 | `'admin'` | `'permission'` | `'DELETE'` |
| `module/admin/permissions.php` | 148 | `'admin'` | `'permission'` | `'UPDATE'` |
| `module/admin/permissions.php` | 157 | `'admin'` | `'permission'` | `'CREATE'` |
| `module/admin/routes.php` | 60 | `'admin'` | `'route'` | `'UPDATE'` |
| `module/admin/routes.php` | 70 | `'admin'` | `'route'` | `'CREATE'` |
| `module/admin/routes.php` | 78 | `'admin'` | `'route'` | `'DELETE'` |
| `module/admin/users.php` | 50 | `'admin'` | `'user'` | `'CREATE'` |
| `module/admin/users.php` | 82 | `'admin'` | `'user'` | `'UPDATE'` |
| `module/admin/users.php` | 100 | `'admin'` | `'user'` | `'STATUS'` |
| `module/admin/menu.php` | 82 | `'admin'` | `'menu_item'` | `'UPDATE'` |
| `module/admin/menu.php` | 92 | `'admin'` | `'menu_item'` | `'CREATE'` |
| `module/admin/menu.php` | 100 | `'admin'` | `'menu_item'` | `'DELETE'` |
| `module/admin/setup.php` | 57 | `'admin'` | `'user'` | `'CREATE'` |
| `module/admin/setup.php` | 75 | `'admin'` | `'permission'` | `'CREATE'` |

---

## 4) Schreibpfade (INSERT / UPDATE / DELETE)

### module/admin/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/admin/permissions.php` | 64 | UPDATE | `core_permission` | **ja** (Zeile 66) |
| `module/admin/permissions.php` | 70–74 | INSERT | `core_permission` | **ja** (Zeile 76) |
| `module/admin/permissions.php` | 81 | DELETE | `core_permission` | **ja** (Zeile 82) |
| `module/admin/permissions.php` | 129 | DELETE | `core_permission` | **ja** (Zeile 130) |
| `module/admin/permissions.php` | 144–147 | UPDATE | `core_permission` | **ja** (Zeile 148) |
| `module/admin/permissions.php` | 151–155 | INSERT | `core_permission` | **ja** (Zeile 157) |
| `module/admin/menu.php` | 75–82 | UPDATE | `core_menu_item` | **ja** (Zeile 82) |
| `module/admin/menu.php` | 85–92 | INSERT | `core_menu_item` | **ja** (Zeile 92) |
| `module/admin/menu.php` | 99–100 | DELETE | `core_menu_item` | **ja** (Zeile 100) |
| `module/admin/routes.php` | 57–59 | UPDATE | `core_route` | **ja** (Zeile 60) |
| `module/admin/routes.php` | 63–66 | INSERT | `core_route` | **ja** (Zeile 70) |
| `module/admin/routes.php` | 72 | DELETE | `core_route` | **ja** (Zeile 78) |
| `module/admin/users.php` | 50–52 | INSERT | `core_user` | **ja** (Zeile 50) |
| `module/admin/users.php` | 61 | UPDATE | `core_user` | **ja** (Zeile 82) |
| `module/admin/users.php` | 66 | UPDATE | `core_user` (Passwort-Hash) | **ja** (Zeile 82) |
| `module/admin/users.php` | 73 | UPDATE | `core_user` (aktiv=0) | **ja** (Zeile 100) |
| `module/admin/setup.php` | 52–57 | INSERT | `core_user` | **ja** (Zeile 57) |
| `module/admin/setup.php` | 69–75 | INSERT | `core_permission` | **ja** (Zeile 75) |

### module/wartungstool/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/wartungstool/admin_punkte.php` | 123–143 | INSERT | `wartungstool_wartungspunkt` (action=create) | **ja** (Zeile 145) |
| `module/wartungstool/admin_punkte.php` | 195–208 | UPDATE | `wartungstool_wartungspunkt` (action=update) | **ja** (Zeile 210) |
| `module/wartungstool/admin_punkte.php` | 236 | UPDATE | `wartungstool_wartungspunkt` (action=toggle_active) | **ja** (Zeile 238) |
| `module/wartungstool/admin_punkte.php` | 280–301 | INSERT | `wartungstool_wartungspunkt` (action=copy_from_asset, loop) | **ja** (Zeile 303) |
| `module/wartungstool/admin_punkte.php` | 426–446 | INSERT | `wartungstool_wartungspunkt` (action=csv_import, loop) | **ja** (Zeile 448) |
| `module/wartungstool/punkt_save.php` | 86–99 | INSERT | `wartungstool_protokoll` | **ja** (Zeile 114) |
| `module/wartungstool/punkt_save.php` | 109 | UPDATE | `wartungstool_wartungspunkt` (intervall_typ=produktiv) | **ja** (Zeile 124, gemeinsam mit Zeile 111) |
| `module/wartungstool/punkt_save.php` | 111 | UPDATE | `wartungstool_wartungspunkt` (intervall_typ=zeit) | **ja** (Zeile 124) |
| `module/wartungstool/punkt_save.php` | 131–136 | UPDATE | `wartungstool_protokoll` (TICKET:DENIED-Marker) | **ja** (Zeile 138) |
| `module/wartungstool/punkt_save.php` | 158–165 | INSERT | `stoerungstool_ticket` (aus Wartungsprotokoll) | **ja** (Zeile 173) |
| `module/wartungstool/punkt_save.php` | 166–172 | INSERT | `stoerungstool_aktion` (Ticket-Erstellung) | **ja** (Zeile 179) |
| `module/wartungstool/punkt_save.php` | 186–195 | UPDATE | `wartungstool_protokoll` (Ticket-Link-Marker) | **ja** (Zeile 197) |
| `module/wartungstool/punkt_dokument_upload.php` | 75–79 | INSERT | `core_dokument` | **ja** (Zeile 82) |

### module/stoerungstool/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/stoerungstool/melden.php` | 49–54 | INSERT | `core_dokument` (optional, via upload_first_ticket_file) | **ja** (Zeile 58) |
| `module/stoerungstool/melden.php` | 92–109 | INSERT | `stoerungstool_ticket` | **ja** (Zeile 127) |
| `module/stoerungstool/melden.php` | 112–116 | INSERT | `stoerungstool_aktion` | **ja** (Zeile 153) |
| `module/stoerungstool/ticket.php` | 40 | UPDATE | `stoerungstool_ticket` (action=set_status) | **ja** (Zeile 47) |
| `module/stoerungstool/ticket.php` | 41–46 | INSERT | `stoerungstool_aktion` (set_status) | **ja** (Zeile 48) |
| `module/stoerungstool/ticket.php` | 58 | UPDATE | `stoerungstool_ticket` (action=assign) | **ja** (Zeile 66) |
| `module/stoerungstool/ticket.php` | 60–65 | INSERT | `stoerungstool_aktion` (assign) | **ja** (Zeile 67) |
| `module/stoerungstool/ticket.php` | 79–84 | INSERT | `stoerungstool_aktion` (action=add_action) | **ja** (Zeile 85) |
| `module/stoerungstool/ticket.php` | 107–113 | INSERT | `core_dokument` (action=upload_doc) | **ja** (Zeile 114) |

---

## Zusammenfassung: Lücken ohne audit_log

### module/admin/* — vollständig abgedeckt

`module/admin/permissions.php` hat für alle 6 Schreibpfade audit_log.
`module/admin/routes.php` hat für alle 3 Schreibpfade audit_log.
`module/admin/users.php` hat für alle 4 Schreibpfade audit_log.
`module/admin/menu.php` hat für alle 3 Schreibpfade audit_log (UPDATE/INSERT/DELETE auf `core_menu_item`).
`module/admin/setup.php` hat für beide Schreibpfade audit_log (INSERT auf `core_user` und `core_permission`).

### module/stoerungstool/* — vollständig abgedeckt

Alle Schreibpfade in `ticket.php` und `melden.php` haben audit_log:
- `ticket.php`: alle `stoerungstool_aktion`-INSERTs (set_status, assign, add_action) und Dokument-Upload
- `melden.php`: Ticket-CREATE, Aktion-INSERT, Dokument-INSERT

### module/wartungstool/* — vollständig abgedeckt

Alle Schreibpfade abgedeckt:
- `punkt_save.php`: alle Protokoll-, Wartungspunkt-, Ticket- und Aktion-Schreibpfade
  (inkl. TICKET:DENIED-Marker-UPDATE, stoerungstool_aktion-INSERT, Ticket-Link-Marker-UPDATE)
- `admin_punkte.php`: alle CRUD-Pfade
- `punkt_dokument_upload.php`: Dokument-INSERT
