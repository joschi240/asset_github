# audit_log_analyse.md – Audit-Coverage & bekannte Gaps

Stand: 2026-02-28  
Ziel: Übersicht, welche Schreibpfade via `audit_log(...)` abgedeckt sind und wo Gaps existieren.

---

## 1) Helper-Signatur

```php
audit_log(
  string $modul,
  string $entityType,
  int    $entityId,
  string $action,         // CREATE | UPDATE | STATUS | DELETE
  mixed  $old = null,     // array|null → wird zu old_json
  mixed  $new = null,     // array|null → wird zu new_json
  ?int   $actorUserId = null,
  ?string $actorText  = null
): void
```

Tabelle: `core_audit_log`  
Pflichtfelder im Log: `modul`, `entity_type`, `entity_id`, `action`, `ip_addr`  
Optional: `actor_user_id`, `actor_text`, `old_json`, `new_json`

---

## 2) Abgedeckte Schreibpfade ✅

### Wartungstool

| Datei | Aktion | modul | entity_type | action |
|---|---|---|---|---|
| `module/wartungstool/punkt_save.php` | Protokoll anlegen | wartungstool | protokoll | CREATE |
| `module/wartungstool/punkt_save.php` | Wartungspunkt Update (letzte_wartung) | wartungstool | wartungspunkt | UPDATE |
| `module/wartungstool/punkt_save.php` | Ticket aus Wartung anlegen | stoerungstool | ticket | CREATE |
| `module/wartungstool/admin_punkte.php` | Wartungspunkt anlegen (Einzeln) | wartungstool | wartungspunkt | CREATE |
| `module/wartungstool/admin_punkte.php` | Wartungspunkt bearbeiten | wartungstool | wartungspunkt | UPDATE |
| `module/wartungstool/admin_punkte.php` | Wartungspunkt aktiv/inaktiv | wartungstool | wartungspunkt | STATUS |
| `module/wartungstool/admin_punkte.php` | Punkte kopieren (bulk CREATE) | wartungstool | wartungspunkt | CREATE |
| `module/wartungstool/admin_punkte.php` | Punkte aus Vorlage anlegen | wartungstool | wartungspunkt | CREATE |
| `module/wartungstool/admin_punkte.php` | Asset-Wartungspunkte Update | wartungstool | asset | UPDATE |
| `module/wartungstool/punkt_dokument_upload.php` | Dokument hochladen | wartungstool | dokument | CREATE |

### Störungstool

| Datei | Aktion | modul | entity_type | action |
|---|---|---|---|---|
| `module/stoerungstool/melden.php` | Ticket öffentlich erstellen | stoerungstool | ticket | CREATE |
| `module/stoerungstool/ticket.php` | Status setzen (set_status) | stoerungstool | ticket | STATUS |
| `module/stoerungstool/ticket.php` | Zuweisung ändern (assign) | stoerungstool | ticket | UPDATE |
| `module/stoerungstool/ticket.php` | Aktion mit Statuswechsel (add_action) | stoerungstool | ticket | STATUS |
| `module/stoerungstool/ticket.php` | Ticket-Stammdaten bearbeiten (update_ticket) | stoerungstool | ticket | UPDATE |
| `module/stoerungstool/ticket.php` | Dokument hochladen (upload_doc) | stoerungstool | dokument | CREATE |

---

## 3) Bekannte Gaps ⚠️

### Admin-Module (kein Audit)
Die Admin-Module schreiben aktuell **kein** Audit-Log:

| Datei | Schreiboperation | Status |
|---|---|---|
| `module/admin/users.php` | User anlegen / bearbeiten / löschen | ❌ kein Audit |
| `module/admin/routes.php` | Route anlegen / bearbeiten / löschen | ❌ kein Audit |
| `module/admin/menu.php` | Menüpunkt anlegen / bearbeiten | ❌ kein Audit |
| `module/admin/permissions.php` | Berechtigung vergeben / ändern | ❌ kein Audit |
| `module/admin/setup.php` | Erstuser anlegen | ❌ kein Audit |

**Begründung/Priorität:**  
Admin-Module sind intern (require_login=1, Wildcard-Rechte) und werden selten genutzt.
Für ISO-Compliance oder Security-Anforderungen sollte zumindest `permissions.php` (Rechteänderungen)
mit Audit ausgestattet werden. TODO: in nächster Admin-Runde ergänzen.

### soon_hours – nicht implementiert
In `dashboard.php` und `uebersicht.php` wird "Bald fällig" über eine feste `soon_ratio = 0.20`
(Restlaufzeit ≤ 20% des Intervalls) berechnet. Es gibt keine `soon_hours`-Logik (Stunden-Schwellwert).

- `punkt.php`: ebenfalls nur `ratio <= 0.20`, kein `soon_hours`
- Alle drei Dateien sind konsistent (kein Drift), aber `soon_hours` fehlt überall.

TODO: Wenn `soon_hours` eingeführt werden soll, müsste `wartungstool_wartungspunkt` um eine
Spalte `soon_hours` (nullable float) erweitert und in allen drei Dateien ausgewertet werden.

---

## 4) Empfehlung

- Admin-Module: Mindestens `permissions.php` mit `audit_log()` ausstatten (Sicherheitsrelevanz).
- `soon_hours`: Als DB-Migrationsskript + Code-Erweiterung in allen drei Wartungs-Views aufnehmen,
  wenn erwünscht. Bis dahin gilt: `soon_ratio = 0.20` ist der einzige Schwellwert (korrekt dokumentiert).

---

Ende.
