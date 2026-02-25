# ROADMAP – Priorisierte Aufgaben & First PRs

> Stand: 2026-02-25 · Analysiert von Copilot Coding Agent (vollständige Neuanalyse)  
> Basis: Code-Analyse + `RISKS.md` + `docs/PRIJECT_CONTEXT_v2.md`

---

## Priorisierungs-Schema

| Priorität | Kriterium |
|---|---|
| **P0** | Sicherheitslücke mit direktem Schadpotenzial oder Datenverlustrisiko |
| **P1** | Produktive Nutzung gefährdet, hohe Auswirkung auf Betrieb oder Sicherheit |
| **P2** | Code-Qualität, erkennbare Schwachstellen, UX-Probleme |

**Aufwand-Skala:** XS < 30 Min · S 1–3 h · M 3–8 h · L > 1 Tag

---

## Aufgaben nach Priorität

---

### P0 – Kritisch (sofort)

#### TASK-01 – Uploads vor Direktzugriff schützen

**Was:** Hochgeladene Dokumente sind ohne Auth direkt via URL abrufbar.  
**Wo:** `module/stoerungstool/ticket.php` Zeile 412 (`/uploads/...`-Links), `src/helpers.php: handle_upload()`  
**Risiko:** `RISKS.md → P0-S-1`  
**Aufwand:** M  
**DoD:**
- `uploads/.htaccess` mit `Deny from all` existiert
- Neuer PHP-Controller (Route `dokument.download`) mit `require_login()` + Permission-Check + `readfile()`
- Alle Download-Links in `ticket.php` zeigen auf den Controller
- Direktzugriff auf `uploads/` liefert HTTP 403

**Vorschlag PR:** `security: serve uploads through authenticated download controller`

---

#### TASK-02 – Rate-Limiting auf öffentlichem Meldeformular

**Was:** `stoerung.melden` ist öffentlich erreichbar, kein Schutz vor Masseneinträgen.  
**Wo:** `module/stoerungstool/melden.php`  
**Risiko:** `RISKS.md → P0-S-2`  
**Aufwand:** S  
**DoD:**
- Webserver-Konfiguration begrenzt Requests auf `/app.php?r=stoerung.melden` (z.B. nginx `limit_req_zone`)
  – ODER –
- APCu-basierter PHP-Throttle: max. 10 Meldungen pro IP pro Stunde, Response HTTP 429
- Test: 20 schnelle POST-Requests liefern ab Request 11 eine Fehlermeldung

**Vorschlag PR:** `security: rate-limit public störung melden form`

---

### P1 – Hoch (nächster Sprint)

#### TASK-03 – Brute-Force-Schutz auf Login

**Was:** `login.php` ohne Loginversuch-Begrenzung.  
**Wo:** `login.php`, `src/auth.php: login()`  
**Risiko:** `RISKS.md → P1-S-3`  
**Aufwand:** S  
**DoD:**
- Nach 5 Fehlversuchen je IP + Benutzername in 10 Minuten: HTTP 429 + Wartemeldung
- Fehlversuche werden in `core_login_attempt` (neue Tabelle) oder APCu gespeichert
- Erfolgreicher Login setzt Fehlversuche zurück
- Test: 6 falsche Logins → 7. Versuch wird blockiert

**Vorschlag PR:** `security: add brute-force protection to login`

---

#### TASK-04 – Ticket-Asset-ID validieren in `melden.php`

**Was:** `asset_id` aus POST wird ohne Existenz-Check in die DB geschrieben.  
**Wo:** `module/stoerungstool/melden.php` Zeile 56  
**Risiko:** `RISKS.md → P1-D-1`  
**Aufwand:** XS  
**DoD:**
- `db_one("SELECT id FROM core_asset WHERE id=? AND aktiv=1")` vor INSERT
- Bei ungültiger ID: Fehlermeldung, kein INSERT

**Vorschlag PR:** `fix: validate asset_id existence before ticket creation`

---

#### TASK-05 – `function_exists()`-Guards mit unsicherem Fallback entfernen

**Was:** Fallback `true` in `punkt.php` bei fehlendem Include → unbeabsichtigter Zugriff.  
**Wo:** `module/wartungstool/punkt.php` Zeilen 13–14  
**Risiko:** `RISKS.md → P2-Q-3`  
**Aufwand:** XS  
**DoD:**
- Direkter Aufruf ohne `function_exists()`-Guard:
  ```php
  $canDoWartung    = user_can_edit($userId, 'wartungstool', 'global', null);
  $canCreateTicket = user_can_edit($userId, 'stoerungstool', 'global', null);
  ```
- Kein `function_exists` mehr in `punkt.php`

**Vorschlag PR:** `fix: remove unsafe function_exists fallback in punkt.php`

---

#### TASK-06 – `runtime_rollup.php` GET-Parameter klemmen

**Was:** `$maxAssets` und `$limitSamplesPerAsset` werden ungepuffert in SQL interpoliert.  
**Wo:** `tools/runtime_rollup.php` Zeilen 8–11  
**Risiko:** `RISKS.md → P2-S-5`  
**Aufwand:** XS  
**DoD:**
- Werte werden auf positive Integer mit Obergrenze begrenzt:
  ```php
  $maxAssets = max(1, min((int)($_GET['max_assets'] ?? 500), 5000));
  $limitSamplesPerAsset = max(1, min((int)($_GET['limit'] ?? 50000), 200000));
  ```

**Vorschlag PR:** `fix: clamp runtime_rollup GET params to prevent SQL edge cases`

---

#### TASK-07 – Ingest-Bulk-Größe begrenzen

**Was:** Kein Payload-Größen-Limit im Ingest-Endpoint.  
**Wo:** `tools/runtime_ingest.php` nach `$samples` Aufbau  
**Risiko:** `RISKS.md → P1-S-4`  
**Aufwand:** XS  
**DoD:**
- `if (count($samples) > 1000) { http_response_code(400); exit(...); }`
- Webserver-seitiges Rate-Limiting dokumentiert in `docs/` oder `README.md`

**Vorschlag PR:** `security: cap ingest bulk size and document rate limiting`

---

### P2 – Mittel (folgende Sprints)

#### TASK-08 – N+1-Queries im Dashboard optimieren

**Was:** Pro Asset 4 DB-Queries in `berechneDashboard()` → bei 20 Assets = 80+ Queries.  
**Wo:** `module/wartungstool/dashboard.php: berechneDashboard()` Zeilen 31–116  
**Risiko:** `RISKS.md → P1-Q-1`  
**Aufwand:** M  
**DoD:**
- `core_runtime_counter` aller Assets in einem Query vorladen
- `core_runtime_agg_day` aller Assets aggregiert in einem Query vorladen (GROUP BY asset_id + CASE für Zeiträume)
- Nächste Wartungspunkte aller Assets in einem Query vorladen
- Dashboard-Aufruf bei 20 Assets: max. 5 DB-Queries total

**Vorschlag PR:** `perf: eliminate N+1 queries in wartung dashboard`

---

#### TASK-09 – `short_text()` Duplikat entfernen

**Was:** `short_text()` ist in `uebersicht.php` und `inbox.php` separat definiert; identisch zu `src/helpers.php`-würdiger Funktion.  
**Wo:** `module/wartungstool/uebersicht.php` Zeile 57, `module/stoerungstool/inbox.php` Zeile 24  
**Risiko:** `RISKS.md → P1-Q-2`  
**Aufwand:** XS  
**DoD:**
- `short_text()` in `src/helpers.php` hinzufügen (falls noch nicht vorhanden)
- Beide Inline-Definitionen entfernen

**Vorschlag PR:** `refactor: move short_text() helper to src/helpers.php`

---

#### TASK-10 – Ticket-Status als zentrale Konstante

**Was:** Status-Werte als Literalstrings in 4+ Dateien verstreut.  
**Wo:** `src/helpers.php`, `module/stoerungstool/inbox.php`, `module/stoerungstool/ticket.php`, `module/stoerungstool/melden.php`, `module/wartungstool/punkt_save.php`  
**Risiko:** `RISKS.md → P2-Q-4`  
**Aufwand:** S  
**DoD:**
- `TICKET_STATUS_FLOW` als Konstante oder Array in `src/helpers.php`
- Alle Inline-Status-Arrays in Formularen und Validierungen nutzen die Konstante
- `badge_for_ticket_status()` in `src/helpers.php` bleibt die Single Source of Truth

**Vorschlag PR:** `refactor: centralize ticket status constants`

---

#### TASK-11 – Entwicklungs-Artefakte bereinigen

**Was:** `create.bat`, `Erzeuge`, `Done` im Repository ohne produktiven Nutzen.  
**Wo:** Projekt-Root  
**Risiko:** `RISKS.md → P2-Q-5`  
**Aufwand:** XS  
**DoD:**
- `git rm create.bat Erzeuge Done`
- Relevant für `.gitignore`: Build-Artefakte eingetragen

**Vorschlag PR:** `chore: remove dev artifacts (create.bat, Erzeuge, Done)`

---

#### TASK-12 – A11Y Quick Wins (Focus-Outline, Badge-Role, Tabellen-Scope)

**Was:** Fehlender Fokusindikator, fehlende `role`-Attribute auf Badges, fehlendes `scope="col"` auf `<th>`.  
**Wo:** `src/css/main.css`, `module/stoerungstool/inbox.php`, `module/wartungstool/dashboard.php`, `module/wartungstool/uebersicht.php`  
**Risiko:** `RISKS.md → P2-A-1, P2-A-2, P2-A-3`  
**Aufwand:** S  
**DoD:**
- `:focus-visible { outline: 3px solid #005fcc; outline-offset: 2px; }` in `main.css`
- `role="status"` auf alle `.badge`-Elemente in `inbox.php` und `dashboard.php`
- `scope="col"` auf alle `<th>` in Datentabellen

**Vorschlag PR:** `a11y: focus-visible outline, badge role, th scope`

---

#### TASK-13 – Dokumentationsdateinamen Tippfehler korrigieren

**Was:** `PRIJECT_CONTEXT` statt `PROJECT_CONTEXT`.  
**Wo:** `docs/PRIJECT_CONTEXT.md`, `docs/PRIJECT_CONTEXT_v2.md`  
**Risiko:** `RISKS.md → P2-M-5`  
**Aufwand:** XS  
**DoD:**
- `git mv docs/PRIJECT_CONTEXT.md docs/PROJECT_CONTEXT.md`
- `git mv docs/PRIJECT_CONTEXT_v2.md docs/PROJECT_CONTEXT_v2.md`
- Alle internen Querverweise angepasst

**Vorschlag PR:** `docs: rename PRIJECT_CONTEXT to PROJECT_CONTEXT`

---

#### TASK-14 – Toten Code `src/menu.php` entfernen

**Was:** Veraltete `load_menu_tree()` in `src/menu.php`, nie eingebunden.  
**Wo:** `src/menu.php`  
**Risiko:** `RISKS.md → P2-Q-6`  
**Aufwand:** XS  
**DoD:**
- `grep -r "src/menu.php"` liefert keine produktiven Treffer
- `git rm src/menu.php`

**Vorschlag PR:** `chore: remove unused legacy src/menu.php`

---

#### TASK-15 – Composer + PSR-4 Autoloading einführen

**Was:** Kein Dependency-Management, kein Autoloading.  
**Wo:** Gesamtes Projekt  
**Risiko:** `RISKS.md → P1-M-1`  
**Aufwand:** M–L  
**DoD:**
- `composer.json` vorhanden mit PSR-4-Autoloading
- `vendor/` in `.gitignore`
- Alle manuellen `require_once` in einer Bootstrap-Datei zentralisiert
- PHPUnit als Dev-Dependency verfügbar

**Vorschlag PR:** `build: add composer and PSR-4 autoloading`

---

#### TASK-16 – PHPUnit-Tests einführen

**Was:** Keine automatisierten Tests.  
**Wo:** Neues Verzeichnis `tests/`  
**Risiko:** `RISKS.md → P1-M-2`  
**Aufwand:** M (initial) + laufend  
**Voraussetzung:** TASK-15 (Composer)  
**DoD:**
- `tests/` mit ersten Unit-Tests:
  - `user_can_see()` – Permission-Logik mit Wildcard
  - `user_can_flag()` – Whitelist-Validierung
  - `split_interval_by_day()` – Tagessplitting
  - `badge_for_ticket_status()` – Status-Labels
- `composer test` oder `phpunit` läuft durch

**Vorschlag PR:** `test: add PHPUnit with initial unit tests for core functions`

---

#### TASK-17 – SLA-Felder in `stoerungstool_ticket`

**Was:** Keine Zeitstempel für `first_response_at` und `closed_at`.  
**Wo:** `docs/db_schema_v2.sql`, `module/stoerungstool/ticket.php`  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md → Next 4`  
**Aufwand:** S  
**DoD:**
- Migration: `ALTER TABLE stoerungstool_ticket ADD COLUMN first_response_at DATETIME DEFAULT NULL, ADD COLUMN closed_at DATETIME DEFAULT NULL`
- `ticket.php`: `first_response_at` beim ersten Statuswechsel von `neu` setzen
- `ticket.php`: `closed_at` beim Status `geschlossen` setzen
- Schema-Datei aktualisiert

**Vorschlag PR:** `feat: add SLA timestamp fields to stoerungstool_ticket`

---

#### TASK-18 – CSV-Export (Audit / ISO)

**Was:** Kein Export von Tickets/Wartungsprotokollen.  
**Wo:** Neue Module `module/admin/export_tickets.php`, `module/admin/export_wartung.php`  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md → Next 5`  
**Aufwand:** M  
**DoD:**
- CSV-Export mit Zeitraum-Filter für Tickets (Status, Reaktionszeit, Durchlaufzeit)
- CSV-Export Wartungsprotokolle mit Abweichungen
- Download via `Content-Disposition: attachment` Header

**Vorschlag PR:** `feat: add CSV export for tickets and maintenance protocols`

---

#### TASK-19 – Dokumente an Wartungspunkten anhängen

**Was:** `core_dokument` unterstützt `referenz_typ='wartungspunkt'` bereits im Schema, aber keine UI.  
**Wo:** `module/wartungstool/punkt.php`, ggf. neuer Upload-Handler  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md → Next 3`  
**Aufwand:** S  
**DoD:**
- `punkt.php` zeigt Dokumente zum Wartungspunkt
- Upload-Formular für Wartungspunkt-Dokumente
- Dokumente in `uploads/wartungstool/wartungspunkte/<wp_id>/`

**Vorschlag PR:** `feat: attach documents to wartungspunkte`

---

#### TASK-20 – Störung UX „Shopfloor Level 2"

**Was:** Schnellzugriff auf Status, Timeline-Ansicht, Standardtexte.  
**Wo:** `module/stoerungstool/inbox.php`, `module/stoerungstool/ticket.php`  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md → Next 2`  
**Aufwand:** M  
**DoD:**
- Inbox: Status-Badge-Klick filtert direkt (ohne Formular-Submit)
- Ticket-Detail: Chronologische Timeline-Ansicht aus `stoerungstool_aktion`
- Standardtexte/Templates für häufige Aktionen (konfigurierbar)

**Vorschlag PR:** `feat: shopfloor UX improvements for stoerungstool`

---

## Erste 3 Pull Requests (klein, reviewbar, sofort umsetzbar)

---

### First PR #1 – `fix: security cleanup (function_exists guards, ingest bulk cap, rollup param clamp)`

**Umfang (alle XS-Aufwand, P1/P2):**
- `module/wartungstool/punkt.php`: `function_exists()`-Guards entfernen (TASK-05, 2 Zeilen)
- `tools/runtime_ingest.php`: Bulk-Limit einbauen (TASK-07, 3 Zeilen)
- `tools/runtime_rollup.php`: GET-Parameter klemmen (TASK-06, 2 Zeilen)
- `module/stoerungstool/melden.php`: Asset-ID validieren (TASK-04, 5 Zeilen)

**Betroffene Dateien:**
- `module/wartungstool/punkt.php` (2 Zeilen)
- `tools/runtime_ingest.php` (3 Zeilen)
- `tools/runtime_rollup.php` (2 Zeilen)
- `module/stoerungstool/melden.php` (5 Zeilen)

**Warum:** Alle vier Maßnahmen sind minimal-invasiv, kein funktionaler Impact, kein Migrations-Aufwand, und decken mehrere P1/P2-Risiken ab.

---

### First PR #2 – `chore: remove dev artifacts and dead code`

**Umfang:**
- `git rm create.bat Erzeuge Done` (TASK-11)
- `git rm src/menu.php` (TASK-14)
- `git mv docs/PRIJECT_CONTEXT*.md` → korrekte Namen (TASK-13)

**Betroffene Dateien:**
- `create.bat`, `Erzeuge`, `Done` (entfernt)
- `src/menu.php` (entfernt)
- `docs/PRIJECT_CONTEXT.md` → `docs/PROJECT_CONTEXT.md`
- `docs/PRIJECT_CONTEXT_v2.md` → `docs/PROJECT_CONTEXT_v2.md`

**Warum:** Rein organisatorisch, null Regressions-Risiko, verbessert sofort die Übersicht für neue Entwickler.

---

### First PR #3 – `a11y+refactor: focus-visible, ticket status constants, short_text consolidation`

**Umfang:**
- `:focus-visible` in `src/css/main.css` (TASK-12, 4 Zeilen CSS)
- `scope="col"` auf `<th>` in `dashboard.php`, `inbox.php`, `uebersicht.php` (TASK-12, ~10 Zeilen)
- `short_text()` aus Inline-Definitionen in `src/helpers.php` konsolidieren (TASK-09, ~10 Zeilen)
- `TICKET_STATUS_FLOW` Konstante in `src/helpers.php` (TASK-10, ~10 Zeilen)

**Betroffene Dateien:**
- `src/css/main.css` (4 Zeilen)
- `src/helpers.php` (~20 neue Zeilen)
- `module/wartungstool/dashboard.php` (~5 Zeilen)
- `module/wartungstool/uebersicht.php` (~5 Zeilen)
- `module/stoerungstool/inbox.php` (~10 Zeilen)

**Warum:** Kombiniert A11Y-Quick-Wins (kein Regressions-Risiko) mit sinnvoller Code-Konsolidierung in einer Review-Session.

---

## Gesamtübersicht

| Task | Priorität | Aufwand | Kategorie |
|---|---|---|---|
| TASK-01: Uploads Auth-Controller | P0 | M | Security |
| TASK-02: Rate-Limit Meldeformular | P0 | S | Security |
| TASK-03: Brute-Force-Schutz Login | P1 | S | Security |
| TASK-04: Asset-ID validieren (melden.php) | P1 | XS | Data Integrity |
| TASK-05: function_exists Guards entfernen | P1 | XS | Security/Quality |
| TASK-06: rollup GET-Params klemmen | P1 | XS | Security/Quality |
| TASK-07: Ingest Bulk-Limit | P1 | XS | Security |
| TASK-08: N+1 Dashboard optimieren | P2 | M | Performance |
| TASK-09: short_text() Duplikat entfernen | P2 | XS | Quality |
| TASK-10: Ticket-Status zentralisieren | P2 | S | Quality |
| TASK-11: Dev-Artefakte bereinigen | P2 | XS | Maintainability |
| TASK-12: A11Y Quick Wins | P2 | S | Accessibility |
| TASK-13: Tippfehler Doku-Dateien | P2 | XS | Maintainability |
| TASK-14: src/menu.php entfernen | P2 | XS | Maintainability |
| TASK-15: Composer + Autoloading | P2 | M–L | Maintainability |
| TASK-16: PHPUnit Tests | P2 | M | Quality |
| TASK-17: SLA-Felder Ticket | P2 | S | Feature |
| TASK-18: CSV-Export | P2 | M | Feature |
| TASK-19: Dokumente an Wartungspunkten | P2 | S | Feature |
| TASK-20: Störung UX Level 2 | P2 | M | Feature |
