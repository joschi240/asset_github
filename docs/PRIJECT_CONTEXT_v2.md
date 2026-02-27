
# Asset KI / Instandhaltung – Projekt-Vertrag (READY-FOR-NEW-CHAT)


Stand: 2026-02-24 (Europa/Berlin)  
DB: MariaDB 10.4.32, Projekt-DB: `asset_ki`  
Datenbankschema (aktuell): **[asset_github_schema.sql](asset_github_schema.sql)**  
Testdaten/Seeds: **[asset_github_seed_testdaten.sql](asset_github_seed_testdaten.sql)**  
PHP: 8.2.x  
Architektur: PHP (PDO), Front-Controller: `app.php` (DB-Routing), Navigation & Seiten DB-getrieben  
Ziel: **modular, auditfähig, shopfloor-tauglich** – Erweiterungen **schrittweise**, ohne Chaos.

> Diese Datei ist der „Arbeitsvertrag“ für GPT in VS Code / neuen Chats.  
> Sie beschreibt: Was existiert, wie es zusammenspielt, welche Regeln gelten, und wie wir weiterbauen.

---

## 0) Grundregeln (nicht verhandelbar)

1) **`app.php` nicht verändern.**  
   Wenn GPT glaubt, dass `app.php` geändert werden muss:  
   - Muss es **vorher** klar ansagen  
   - Muss es **triftige Gründe** liefern (Sicherheit/Architektur)  
   - Muss es einen **minimalen Patch** liefern

2) **INNER-VIEW Regel** (alles, was via `app.php` geladen wird):  
   - **Nie** `render_header()` / `render_footer()` in INNER-VIEWS  
   - **Nie** `src/layout.php` in INNER-VIEWS includen  
   - Stattdessen typischer Start:  
     `require_once __DIR__ . '/../../src/helpers.php';`

3) **DB-Änderungen**:  
   - Default: **nur additiv** (keine Umbauten an Core)  
   - **Erlaubt**: strukturelle Änderungen innerhalb eines Moduls über dessen Prefix (`stoerungstool_*`, `wartungstool_*`, später `ersatzteile_*`, …)  
   - Jede DB-Änderung muss als **SQL Script** geliefert werden (Migration + ggf. Backfill).

4) **Audit-Pflicht**:  
   - Änderungen an: Wartungspunkte, Wartungsprotokolle, Tickets, Aktionen, Statuswechsel, Dokumente  
   - müssen über `core_audit_log` nachvollziehbar sein (Helper `audit_log(...)`).

5) **„Ich bin faul“-Regel** 😄  
   - Bei jeder Änderung: **SQL Script** liefern  
   - Wenn Patch-Workflow unsicher: **komplette Datei(en)** liefern, nicht nur Diff.

---

## 1) Architektur – Routing, Menü, Rechte

### 1.1 Front-Controller `app.php` (stabil)
- Route-Key kommt aus `?r=<route_key>` (Fallback: `cfg['app']['default_route']`)
- Route-Definition in DB: `core_route` (`aktiv=1`)
- `require_login()` wenn `require_login=1`
- Rechteprüfung zentral: `user_can_see($userId, modul, objekt_typ, objekt_id)`
- Pfad-Absicherung (`realpath`, blockt `..`)
- Rendert Layout:
  1) `render_header(route.titel)`
  2) `require route.file_path` (INNER-VIEW)
  3) `render_footer()`

Konsequenz: Neue Seiten IMMER via `core_route` integrieren.

### 1.2 Menü
- `core_menu` (im aktuellen System: `main`)
- `core_menu_item`: Baumstruktur (`parent_id`), Sortierung (`sort`)
- Items können `route_key` oder `url` nutzen  
  **Best Practice:** nur `route_key` verwenden und `url` leer lassen (sonst kann es doppelte Links geben, abhängig von layout.php).

### 1.3 Rechtesystem (minimal & zentral)
DB: `core_permission`
- `user_id`
- `modul` (z.B. `wartungstool`, `stoerungstool`, `admin`, oder `*`)
- `objekt_typ` (z.B. `global`, `dashboard`, `inbox`, oder `*`)
- `objekt_id` (optional; NULL = global)
- `darf_sehen`, `darf_aendern`, `darf_loeschen`

Code: `user_can_see(...)` entscheidet zentral.
- Wildcard `*` existiert (Admin user 3 im Dump).
- **Wichtig:** `core_route.objekt_typ` muss zur Permission passen.
  - Beispiel: `stoerung.inbox` hat im Dump `objekt_typ='global'` → Permission sollte `stoerungstool/global` erlauben (oder `user_can_see` mapped intern um).

**Regel beim Erstellen neuer Routen:**
- `modul` + `objekt_typ` konsistent wählen
- bei Bedarf Permission-Seed liefern

---

## 2) Dateisystem (IST)



/app.php # Router – NICHT ändern
/src/
config.php # Settings (db/app/telemetry/upload)
db.php # PDO + db_one/db_all/db_exec
auth.php # session/login/csrf/require_login/current_user
helpers.php # e(), csrf_token/check, audit_log(), etc.
layout.php # render_header/footer + Menü
css/main.css

/tools/
runtime_ingest.php # Telemetrie Ingest (single + bulk)
runtime_rollup.php # Aggregator sample -> counter + agg_day

/module/
/wartungstool/
dashboard.php # INNER: Wartung Dashboard
punkt.php # INNER: Wartungspunkt Detail + Durchführung
punkt_save.php # INNER: POST Save (Protokoll + optional Ticket)
uebersicht.php # INNER: Übersicht pro Asset
admin_punkte.php # INNER: Admin CRUD Wartungspunkte

/stoerungstool/
melden.php # public (require_login=0)
inbox.php # INNER: Inbox (Filter + Suche)
ticket.php # INNER: Ticket Detail

/admin/
setup.php, users.php, routes.php, menu.php, permissions.php

/login.php, /logout.php
/uploads/

---

## 3) Datenbank – was existiert (DB-validiert)

### 3.1 CORE (Prefix `core_`)
- Assets: `core_asset`, Kategorien: `core_asset_kategorie`, Standorte: `core_standort`
- Users: `core_user`
- Permissions: `core_permission`
- Routing: `core_route`
- Menü: `core_menu`, `core_menu_item`
- Dokumente: `core_dokument`
- Auditlog: `core_audit_log`

### 3.2 Runtime / Produktivstunden
- `core_runtime_sample` (polling raw; uniq asset_id+ts)
- `core_runtime_counter` (productive_hours; dashboard source)
- `core_runtime_agg_day` (run/stop seconds; trends)

### 3.3 Wartung (Prefix `wartungstool_`)
- `wartungstool_wartungspunkt` (zeit/produktiv; interval; letzte wartung; limits; aktiv)
- `wartungstool_protokoll` (durchführung; status ok/abweichung)

Ticket-Linking aus Wartung (Option A):
- Ticket-ID wird im Protokoll als Marker gespeichert: `[#TICKET:<id>]`

### 3.4 Störung (Prefix `stoerungstool_`, v2 aktiv)
- `stoerungstool_ticket`
  - status enum: `neu, angenommen, in_arbeit, bestellt, erledigt, geschlossen`
  - v2 Felder: `meldungstyp, fachkategorie, maschinenstillstand, ausfallzeitpunkt, assigned_user_id`
  - legacy: `kategorie` weiterhin vorhanden
- `stoerungstool_aktion`
  - status_neu enum inkl. `bestellt`
  - arbeitszeit_min optional
- FK: assigned_user_id -> core_user, asset_id -> core_asset

---

## 4) Dokumente/Uploads – wie es funktioniert

### 4.1 Speicherprinzip
- Dateien liegen physisch unter `/uploads/...`
- DB-Referenz läuft universal über `core_dokument`:
  - `modul` z.B. `stoerungstool`
  - `referenz_typ` z.B. `ticket`
  - `referenz_id` z.B. `stoerungstool_ticket.id`
  - `dateiname` = relativer Pfad unter `/uploads/` (z.B. `stoerungstool/tickets/22/file.pdf`)
  - optional: `sha256`, mime, size, originalname

### 4.2 Sicherheits-/UX-Regeln
- Uploads nur erlaubte Mimes (typisch: jpg/png/webp/pdf)
- Filesystem: Verzeichnisse pro Entity (z.B. `/uploads/stoerungstool/tickets/<id>/`)
- Download-Link: `<base>/uploads/<dateiname>`

---

## 5) Auditlog – wie es funktioniert (`core_audit_log`)

Ziel: ISO-fähig, Nachvollziehbarkeit.

Spalten:
- `modul` (z.B. `wartungstool`, `stoerungstool`)
- `entity_type` (z.B. `wartungspunkt`, `protokoll`, `ticket`, `aktion`, `dokument`)
- `entity_id`
- `action` (CREATE/UPDATE/STATUS/DELETE)
- `actor_user_id` + `actor_text` + `ip_addr`
- `old_json` / `new_json` als JSON (im Dump: LONGTEXT + json_valid CHECK)

Regel:
- bei jeder Änderung: `audit_log(...)` mit old/new Snapshot (so klein wie sinnvoll, aber aussagekräftig)

---

## 6) Aktueller Stand – Features (DONE)

### Wartung
- `wartung.dashboard` ✅
- `wartung.punkt` ✅
- `wartung.punkt_save` ✅
- `wartung.uebersicht` ✅
- `wartung.admin_punkte` ✅

### Störung
- `stoerung.melden` ✅ (public)
- `stoerung.inbox` ✅ (Ampel + Filter ausklappbar + Suche inkl. Aktionen)
- `stoerung.ticket` ✅ (Quick Status + Zuweisung + Aktionen + Dokumente; Sektionen ausklappbar/auto)

### Runtime
- Ingest (single+bulk) ✅
- Rollup (counter + agg_day) ✅

---

## 7) Nächste Schritte (empfohlen, in sinnvoller Reihenfolge)

### ✅ Next 1: Rechte/Objekttyp Konsistenz (DONE 2026-02-24)
- Alle Routen nutzen `objekt_typ='global'` für modul-weite Rechte.
- Migration `docs/db_migration_permissions_v1.sql` korrigiert abweichende Einträge
  (z.B. `stoerungstool/inbox` → `stoerungstool/global`).
- `admin_punkte.php`: erzwingt jetzt `require_can_edit('wartungstool','global')` –
  nur User mit `darf_aendern=1` können Wartungspunkte anlegen/bearbeiten/löschen.
- Alle defensiven `function_exists()`-Guards in Modulen entfernt –
  `user_can_see`, `user_can_edit`, `user_can_delete`, `require_can_edit` sind
  immer über `src/auth.php` verfügbar.

**Berechtigungsmatrix (Kurzform):**

| Route                | modul / objekt_typ     | darf_sehen | darf_aendern   | darf_loeschen |
|----------------------|------------------------|:----------:|:--------------:|:-------------:|
| wartung.dashboard    | wartungstool/dashboard | ✅         | —              | —             |
| wartung.punkt        | wartungstool/global    | ✅         | —              | —             |
| wartung.uebersicht   | wartungstool/global    | ✅         | —              | —             |
| wartung.punkt_save   | wartungstool/global    | ✅         | ✅ (erzwungen) | —             |
| wartung.admin_punkte | wartungstool/global    | ✅         | ✅ (erzwungen) | —             |
| stoerung.melden      | stoerungstool/global   | öffentlich | —              | —             |
| stoerung.inbox       | stoerungstool/global   | ✅         | —              | —             |
| stoerung.ticket      | stoerungstool/global   | ✅         | ✅ (UI-Flag)   | ✅ (UI-Flag)  |
| admin.*              | modul='*' (Wildcard)   | ✅ (Admin) | ✅ (Admin)     | ✅ (Admin)    |

### Next 2: Störung – UX/Prozess „Shopfloor Level 2“
- Inbox Quick-Filter (Klick auf Status-Badge setzt Filter)
- Ticket: kleine Timeline-Ansicht aus Aktionen (ohne neue Tabellen)
- Ticket: Standardtexte / Templates für Aktionen (z.B. „Teil bestellt“, „Warten auf Lieferung“)

### Next 3: Wartung – Dokumente an Wartungspunkten
- `core_dokument` auch für `wartungspunkt` nutzen
- Upload in `wartung.punkt` (oder eigener Tab)
- Anzeige im Detail (PDF/Foto)

### Next 4: SLA vorbereiten (später aktivieren, aber geplant)
DB (stoerungstool_ticket):
- `first_response_at`, `closed_at`
Scripts:
- Migration + Backfill
Code:
- Auto-Set beim Statuswechsel

### Next 5: Reports (Audit/ISO)
- CSV Export: Tickets im Zeitraum + Reaktionszeiten + Durchlaufzeiten
- CSV Export: Wartung (Punkte, Protokolle, Abweichungen, Tickets)

---

## 8) Arbeitsweise bei neuen Modulen (Template)

Wenn ein neues Modul kommt (z.B. `ersatzteile_*`, `energie_*`, `audit_*`):
1) DB: neue Tabellen mit Prefix `<modul>_*` (strukturelle Änderungen erlaubt im Prefix)
2) Routes: `core_route` Seeds für neue Seiten
3) Menü: `core_menu_item` Seeds
4) Rechte: `core_permission` Seeds
5) Audit: bei Änderungen `core_audit_log`

---

## 9) Wenn du Dateien zum Analysieren gibst
Du kannst jederzeit Dateien hochladen/pasten (z.B. `helpers.php`, `layout.php`, `user_can_see`), damit GPT den exakten Stand kennt.
Dann gilt:
- GPT darf verbessern, aber nur innerhalb der Regeln (app.php bleibt stabil, DB-Changes überwiegend additiv, Prefix ok).

---

# Ende


---

# 2026 Update – UI v2 Migration & Wartungssystem (additiv)

> Dieser Abschnitt ergänzt bestehende Inhalte. Nichts wurde entfernt. Bestehende Abschnitte können für einzelne Seiten als **Legacy** gelten.

## UI v2 – Neuer Standard

- `main.css` gilt als **Legacy** und wird nicht mehr erweitert.
- Neue/umgebaute Seiten verwenden ausschließlich `ui-*` Klassen (UI v2).
- Alt-Seiten bleiben bis zur Migration unverändert.
- Desktop-first (2026-Standard-Monitore), Grid/Flex-basiert.
- Tabellen immer mit `.ui-table-wrap`.
- Komponenten: `ui-card`, `ui-badge`, `ui-btn`, `ui-filterbar`, `ui-kpi-row`, `ui-table`.

Referenz: `docs/UI_V2_GUIDE.md`

### Migrationsstatus (Wartungstool)

- `wartung.dashboard` → UI v2 ✔
- `wartung.uebersicht` → UI v2 ✔
- `wartung.admin_punkte` → UI v2 ✔
- `wartung.punkt` → UI v2 TODO

---

## Architektur-Klarstellung

- Front-Controller: `app.php` (DB-Routing) – **nicht ändern**
- Navigation: `core_menu` / `core_menu_item`
- Rechte: `core_permission` (`user_can_see`, `user_can_edit`)
- Audit: `core_audit_log` (revisionssicher / ISO-tauglich)
- Telemetrie-Pipeline:
  `core_runtime_sample`
  → Rollup
  → `core_runtime_counter`
  → `core_runtime_agg_day`

---

## Wartungssystem – Bald-fällig Logik

Tabelle: `wartungstool_wartungspunkt`

Felder:

- `soon_hours` (DOUBLE, NULL)  
  Absolute Restschwelle in Stunden (**hat Priorität**, wenn gesetzt)

- `soon_ratio` (DOUBLE, NULL)  
  Relative Restschwelle 0..1  
  Beispiel: `0.10` ⇒ „bald“, wenn `rest/interval <= 0.10`

Fallback:
- Wenn beide NULL → Default-Schwelle im Code (aktuell 0.20)

Status-Regel:

1. Überfällig → `rest < 0`
2. Bald →
   - `rest <= soon_hours` (falls gesetzt)
   - sonst `rest/interval <= soon_ratio`
   - sonst Default 0.20
3. OK → sonst

> TODO: Logik zwischen Dashboard & Übersicht vollständig zentralisieren.
