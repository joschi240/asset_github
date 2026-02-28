# Audit-Log Analyse (Branch: ui_v2)

> Nur Fakten. Keine Fixes. Jede Aussage mit Datei + Zeilen belegt.

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
| `old_json`      | `json_encode($old, JSON_UNESCAPED_UNICODE)` (Zeile 80) — NULL wenn `$old === null`; bei Encoding-Fehler liefert `json_encode()` `false` (wird dann als Nicht-NULL-Wert übergeben) |
| `new_json`      | `json_encode($new, JSON_UNESCAPED_UNICODE)` (Zeile 81) — NULL wenn `$new === null`; bei Encoding-Fehler liefert `json_encode()` `false` (wird dann als Nicht-NULL-Wert übergeben) |

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
| `module/stoerungstool/ticket.php` | 107 | `'stoerungstool'` | `'ticket'` | `'STATUS'` |
| `module/stoerungstool/ticket.php` | 122 | `'stoerungstool'` | `'ticket'` | `'UPDATE'` |
| `module/stoerungstool/ticket.php` | 157 | `'stoerungstool'` | `'ticket'` | `'STATUS'` |
| `module/wartungstool/punkt_dokument_upload.php` | 70 | `'wartungstool'` | `'dokument'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 144 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 207 | `'wartungstool'` | `'wartungspunkt'` | `'UPDATE'` |
| `module/wartungstool/admin_punkte.php` | 235 | `'wartungstool'` | `'wartungspunkt'` | `'STATUS'` |
| `module/wartungstool/admin_punkte.php` | 298 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 311 | `'wartungstool'` | `'asset'` | `'UPDATE'` |
| `module/wartungstool/admin_punkte.php` | 413 | `'wartungstool'` | `'wartungspunkt'` | `'CREATE'` |
| `module/wartungstool/admin_punkte.php` | 431 | `'wartungstool'` | `'asset'` | `'UPDATE'` |
| `module/wartungstool/punkt_save.php` | 115 | `'wartungstool'` | `'protokoll'` | `'CREATE'` |
| `module/wartungstool/punkt_save.php` | 125 | `'wartungstool'` | `'wartungspunkt'` | `'UPDATE'` |
| `module/wartungstool/punkt_save.php` | 170 | `'stoerungstool'` | `'ticket'` | `'CREATE'` |

---

## 4) Schreibpfade (INSERT / UPDATE / DELETE)

### module/admin/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/admin/permissions.php` | 59 | UPDATE | `core_permission` | **nein** |
| `module/admin/permissions.php` | 62–65 | INSERT | `core_permission` | **nein** |
| `module/admin/permissions.php` | 68 | DELETE | `core_permission` | **nein** |
| `module/admin/permissions.php` | 87 | DELETE | `core_permission` | **nein** |
| `module/admin/permissions.php` | 93–95 | UPDATE | `core_permission` | **nein** |
| `module/admin/permissions.php` | 98–101 | INSERT | `core_permission` | **nein** |
| `module/admin/menu.php` | 78–82 | UPDATE | `core_menu_item` | **nein** |
| `module/admin/menu.php` | 86–89 | INSERT | `core_menu_item` | **nein** |
| `module/admin/menu.php` | 95 | DELETE | `core_menu_item` | **nein** |
| `module/admin/routes.php` | 57–59 | UPDATE | `core_route` | **nein** |
| `module/admin/routes.php` | 63–66 | INSERT | `core_route` | **nein** |
| `module/admin/routes.php` | 72 | DELETE | `core_route` | **nein** |
| `module/admin/users.php` | 50–52 | INSERT | `core_user` | **nein** |
| `module/admin/users.php` | 61 | UPDATE | `core_user` | **nein** |
| `module/admin/users.php` | 66 | UPDATE | `core_user` (Passwort-Hash) | **nein** |
| `module/admin/users.php` | 73 | UPDATE | `core_user` (aktiv=0) | **nein** |
| `module/admin/setup.php` | 43–45 | INSERT | `core_user` | **nein** |
| `module/admin/setup.php` | 50–53 | INSERT | `core_permission` | **nein** |

### module/wartungstool/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/wartungstool/admin_punkte.php` | 121–142 | INSERT | `wartungstool_wartungspunkt` (action=create) | **ja** (Zeile 144) |
| `module/wartungstool/admin_punkte.php` | 192–205 | UPDATE | `wartungstool_wartungspunkt` (action=update) | **ja** (Zeile 207) |
| `module/wartungstool/admin_punkte.php` | 233 | UPDATE | `wartungstool_wartungspunkt` (action=toggle_active) | **ja** (Zeile 235) |
| `module/wartungstool/admin_punkte.php` | 276–296 | INSERT | `wartungstool_wartungspunkt` (action=copy_from_asset, loop) | **ja** (Zeile 298) |
| `module/wartungstool/admin_punkte.php` | 391–411 | INSERT | `wartungstool_wartungspunkt` (action=csv_import, loop) | **ja** (Zeile 413) |
| `module/wartungstool/punkt_save.php` | 87–99 | INSERT | `wartungstool_protokoll` | **ja** (Zeile 115) |
| `module/wartungstool/punkt_save.php` | 110 | UPDATE | `wartungstool_wartungspunkt` (intervall_typ=produktiv) | **ja** (Zeile 125, gemeinsam mit Zeile 112) |
| `module/wartungstool/punkt_save.php` | 112 | UPDATE | `wartungstool_wartungspunkt` (intervall_typ=zeit) | **ja** (Zeile 125) |
| `module/wartungstool/punkt_save.php` | 132–136 | UPDATE | `wartungstool_protokoll` (TICKET:DENIED-Marker) | **nein** |
| `module/wartungstool/punkt_save.php` | 156–161 | INSERT | `stoerungstool_ticket` (aus Wartungsprotokoll) | **ja** (Zeile 170) |
| `module/wartungstool/punkt_save.php` | 164–168 | INSERT | `stoerungstool_aktion` (Ticket-Erstellung) | **nein** |
| `module/wartungstool/punkt_save.php` | 177–186 | UPDATE | `wartungstool_protokoll` (Ticket-Link-Marker) | **nein** |
| `module/wartungstool/punkt_dokument_upload.php` | 62–67 | INSERT | `core_dokument` | **ja** (Zeile 70) |

### module/stoerungstool/

| Datei | Zeile | SQL-Typ | Tabelle | audit_log vorhanden |
|-------|-------|---------|---------|---------------------|
| `module/stoerungstool/melden.php` | 49–54 | INSERT | `core_dokument` (optional, via upload_first_ticket_file) | **nein** |
| `module/stoerungstool/melden.php` | 92–109 | INSERT | `stoerungstool_ticket` | **nein** |
| `module/stoerungstool/melden.php` | 112–116 | INSERT | `stoerungstool_aktion` | **nein** |
| `module/stoerungstool/ticket.php` | 59–64 | INSERT | `core_dokument` (via upload_ticket_file) | **nein** |
| `module/stoerungstool/ticket.php` | 88–89 | UPDATE | `stoerungstool_ticket` (action=set_status) | **ja** (Zeile 107) |
| `module/stoerungstool/ticket.php` | 100 | UPDATE | `stoerungstool_ticket` (SLA-Felder, Teilpfad von set_status) | **ja (teilweise)** (Zeile 107 vorhanden, aber SLA-Spalten `first_response_at`/`closed_at` werden in old/new JSON nicht erfasst) |
| `module/stoerungstool/ticket.php` | 103–105 | INSERT | `stoerungstool_aktion` | **nein** |
| `module/stoerungstool/ticket.php` | 117 | UPDATE | `stoerungstool_ticket` (action=assign) | **ja** (Zeile 122) |
| `module/stoerungstool/ticket.php` | 118–120 | INSERT | `stoerungstool_aktion` | **nein** |
| `module/stoerungstool/ticket.php` | 138–140 | INSERT | `stoerungstool_aktion` (action=add_action) | **nein** |
| `module/stoerungstool/ticket.php` | 143 | UPDATE | `stoerungstool_ticket` (Statuswechsel via add_action) | **ja** (Zeile 157) |
| `module/stoerungstool/ticket.php` | 154 | UPDATE | `stoerungstool_ticket` (SLA-Felder via add_action) | **ja (teilweise)** (Zeile 157 vorhanden, aber SLA-Spalten `first_response_at`/`closed_at` werden in old/new JSON nicht erfasst) |
| `module/stoerungstool/ticket.php` | 163 | UPDATE | `stoerungstool_ticket` (updated_at, kein Status-Wechsel) | **nein** |
| `module/stoerungstool/ticket.php` | 188–192 | UPDATE | `stoerungstool_ticket` (action=update_ticket, Felder-Edit) | **nein** |

---

## Zusammenfassung: Lücken ohne audit_log

### module/admin/* — vollständig ohne audit_log

Alle 18 Schreibpfade in `admin/` (permissions, menu, routes, users, setup) haben **kein** audit_log.

### module/stoerungstool/* — teilweise ohne audit_log

Folgende Pfade ohne audit_log:
- `melden.php`: Ticket-CREATE, Aktion-INSERT, Dokument-INSERT (3 Pfade)
- `ticket.php`: `update_ticket`-UPDATE, `updated_at`-UPDATE ohne Statuswechsel, alle `stoerungstool_aktion`-INSERTs, `core_dokument`-INSERT

### module/wartungstool/* — gut abgedeckt, wenige Lücken

Ohne audit_log:
- `punkt_save.php`: `stoerungstool_aktion`-INSERT (Ticket-Erstellung), zwei UPDATE auf `wartungstool_protokoll` (DENIED-Marker, Ticket-Link-Marker)
