# audit_log_analyse.md – Audit-Coverage und bekannte Lücken

Stand: 2026-02-28  
Tabelle: `core_audit_log`  
Helper: `audit_log()` in `src/helpers.php`

---

## 1) Audit-Log Struktur

```sql
core_audit_log (
  id            BIGINT AUTO_INCREMENT,
  modul         VARCHAR(50)   -- z.B. 'wartungstool', 'stoerungstool'
  entity_type   VARCHAR(60)   -- z.B. 'wartungspunkt', 'protokoll', 'ticket', 'aktion', 'dokument'
  entity_id     BIGINT
  action        VARCHAR(30)   -- CREATE | UPDATE | STATUS | DELETE
  actor_user_id INT NULL      -- NULL bei anonymen Meldungen
  actor_text    VARCHAR(120)  -- Anzeigename oder 'anonym'
  ip_addr       VARCHAR(45)
  old_json      LONGTEXT NULL -- JSON-Snapshot vor Änderung
  new_json      LONGTEXT NULL -- JSON-Snapshot nach Änderung
  created_at    TIMESTAMP
)
```

---

## 2) Audit-Coverage Matrix

### 2.1 Wartung (`wartungstool`)

| Pfad / Aktion                          | entity_type    | action   | Status   |
|----------------------------------------|----------------|----------|----------|
| `admin_punkte.php` → create            | wartungspunkt  | CREATE   | ✅       |
| `admin_punkte.php` → update            | wartungspunkt  | UPDATE   | ✅       |
| `admin_punkte.php` → toggle_active     | wartungspunkt  | STATUS   | ✅       |
| `admin_punkte.php` → copy_from_asset   | wartungspunkt  | CREATE   | ✅ (je Punkt) |
| `admin_punkte.php` → csv_import        | wartungspunkt  | CREATE   | ✅ (je Punkt) |
| `punkt_save.php` → Protokoll erstellen | protokoll      | CREATE   | ✅       |
| `punkt_save.php` → WP letzte Wartung   | wartungspunkt  | UPDATE   | ✅       |
| `punkt_save.php` → Ticket erzeugen     | ticket         | CREATE   | ✅       |
| `punkt_dokument_upload.php` → Upload   | dokument       | CREATE   | ✅       |

### 2.2 Störung (`stoerungstool`)

| Pfad / Aktion                          | entity_type    | action   | Status   |
|----------------------------------------|----------------|----------|----------|
| `melden.php` → Ticket erstellen        | ticket         | CREATE   | ✅       |
| `ticket.php` → set_status              | ticket         | STATUS   | ✅       |
| `ticket.php` → assign                  | ticket         | UPDATE   | ✅       |
| `ticket.php` → add_action (mit Status) | ticket         | STATUS   | ✅       |
| `ticket.php` → add_action (kein Status)| ticket         | UPDATE   | ✅       |
| `ticket.php` → update_ticket           | ticket         | UPDATE   | ✅       |
| `ticket.php` → upload_doc              | —              | —        | ⚠ fehlt  |

### 2.3 Admin-Module (`admin`)

| Pfad / Aktion                          | entity_type    | action   | Status        |
|----------------------------------------|----------------|----------|---------------|
| `admin/users.php`                      | —              | —        | ❓ nicht geprüft |
| `admin/routes.php`                     | —              | —        | ❓ nicht geprüft |
| `admin/menu.php`                       | —              | —        | ❓ nicht geprüft |
| `admin/permissions.php`                | —              | —        | ❓ nicht geprüft |

---

## 3) Bekannte Lücken

### 3.1 ⚠ ticket.php → upload_doc (kein audit_log)

**Pfad:** `module/stoerungstool/ticket.php`, Action `upload_doc`  
**Problem:** Dokumentenupload schreibt in `core_dokument`, aber kein `audit_log`-Call.  
**Empfehlung:** Nach dem `INSERT` in `core_dokument` ein `audit_log('stoerungstool', 'dokument', $dokId, 'CREATE', ...)` ergänzen.

### 3.2 ❓ Admin-Module

Die Dateien `module/admin/users.php`, `routes.php`, `menu.php`, `permissions.php` wurden nicht auf Audit-Coverage analysiert.  
Da Admin-Aktionen (User anlegen, Routen ändern, Permissions vergeben) sicherheitsrelevant sind, sollten diese ebenfalls `audit_log`-Calls haben.

---

## 4) Audit-Log Helper (Referenz)

```php
audit_log(
  string $modul,          // 'wartungstool' | 'stoerungstool'
  string $entityType,     // 'wartungspunkt' | 'protokoll' | 'ticket' | 'aktion' | 'dokument'
  int    $entityId,       // ID der betroffenen Entität
  string $action,         // 'CREATE' | 'UPDATE' | 'STATUS' | 'DELETE'
  mixed  $old,            // Array oder null (Snapshot vor Änderung)
  mixed  $new,            // Array oder null (Snapshot nach Änderung)
  ?int   $actorUserId,    // NULL bei anonymen Aktionen
  ?string $actorText      // Anzeigename oder 'anonym'
): void
```

---

## 5) Audit-Pflicht (Projektregeln)

Folgende Schreibpfade **müssen** über `audit_log` auditierbar sein:
- Wartungspunkte (CREATE, UPDATE, STATUS/Aktiv-Toggle)
- Wartungsprotokolle (CREATE)
- Tickets (CREATE, UPDATE, STATUS)
- Aktionen an Tickets (CREATE mit und ohne Statuswechsel)
- Dokumente (CREATE)
- Statuswechsel (immer als eigener `action='STATUS'`-Eintrag)

---

Ende.
