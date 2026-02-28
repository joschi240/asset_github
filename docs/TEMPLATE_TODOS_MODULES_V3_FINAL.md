# Template TODOs pro Modul-Datei (v3_final)

Stand: 2026-02-28  
Ziel: Alle Modul-Views auf das aktuelle UI-v2-Template und STYLE_RULES angleichen.

## Bewertungsgrundlage
- `docs/UI_V2_GUIDE.md`
- `docs/STYLE_RULES.md`
- Aktuelle Referenz-Views:
  - `module/wartungstool/dashboard.php`
  - `module/wartungstool/uebersicht.php`
  - `module/wartungstool/punkt.php`
  - `module/stoerungstool/inbox.php`
  - `module/stoerungstool/ticket.php`

---

## ✅ Bereits template-konform (kein TODO)
- `module/wartungstool/dashboard.php`
- `module/wartungstool/uebersicht.php`
- `module/wartungstool/punkt.php`
- `module/wartungstool/admin_punkte.php`
- `module/stoerungstool/melden.php`
- `module/stoerungstool/inbox.php`
- `module/stoerungstool/ticket.php`
- `module/admin/setup.php`
- `module/admin/users.php`
- `module/admin/routes.php`
- `module/admin/menu.php`
- `module/admin/permissions.php`

---

## ⚠️ Offene Restpunkte

### 1) `module/wartungstool/punkt_save.php` (POST-Handler)
- [x] Method-not-allowed Verhalten auf Redirect vereinheitlichen
- [ ] Optional: Redirect-Helper-Closure für konsistente `ok`/`err` Redirects wie in Upload-Handlern

### 2) Optional / Fachlich
- [ ] `module/stoerungstool/melden.php`: `audit_log(..., 'CREATE')` beim Ticket-Anlegen ergänzen (bekannte Lücke)

---

## ℹ️ N/A (kein UI-Template nötig, nur Handler)
- `module/wartungstool/punkt_dokument_upload.php`

## ✅ Bereinigt
- `module/wartungstool/admin_punkte - Kopie.php` entfernt

---

## Abarbeitungsreihenfolge (empfohlen)
1. `module/stoerungstool/melden.php`
2. `module/admin/setup.php`
3. `module/admin/users.php`
4. `module/admin/routes.php`
5. `module/admin/menu.php`
6. `module/admin/permissions.php`
7. `module/wartungstool/punkt_save.php`
8. Cleanup `module/wartungstool/admin_punkte - Kopie.php`

---

## Definition of Done je Datei
- Nutzt ausschließlich vorhandene UI-v2 Klassen (`ui-*`) + bestehende Design-Tokens
- Keine Legacy-Klassen (`card`, `btn`, `badge`, `tablewrap`, `table`, `grid/col-*`)
- INNER-VIEW-konform (kein `render_header/render_footer` in Modul-Views)
- Formulare mit gültigem CSRF Hidden Field
- `php -l <datei>` ohne Fehler
