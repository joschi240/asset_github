> ⚠️ Hinweis: Neue Version unter **PRIJECT_CONTEXT_v2.md**  
> Projektübersicht: **[README.md](../README.md)**  
> Datenbankschema (aktuell): **[db_schema_v2.sql](db_schema_v2.sql)**  
> Stand vom **24.02.2026**  
> Die alte Datei ist nur noch historisch.

# Asset KI / Instandhaltung – Projekt-Context (aktualisiert)

Stand: 2026-02-22 (Europa/Berlin)  
DB: MariaDB 10.4.x (kompatibel), Projekt-DB: `asset_ki`  
Frontend: PHP (PDO), Front-Controller: `app.php` (DB-Routing), Layout/Navigation DB-getrieben  
Leitmotiv: **modular, auditfähig, shopfloor-tauglich** – Erweiterungen möglichst **additiv** (kaum Änderungen an Background-System & DB).

> Diese Datei ist als „Arbeitsvertrag“ für ein VS-Code eingebundenes GPT gedacht:  
> **Was existiert, wie es zusammenspielt, welche Regeln gelten, und was als Nächstes zu bauen ist – Schritt für Schritt.**

---

## 0) Grundregeln (wichtig!)

1) **`app.php` nicht verändern.**  
   Es ist der stabile Background/Router.

2) **Views, die über `app.php` geladen werden, sind INNER-VIEWS.**  
   - **Nie** `render_header()` / `render_footer()` in INNER-VIEWS aufrufen  
   - **Nie** `src/layout.php` in INNER-VIEWS includen  
											 
   - Stattdessen: `require_once __DIR__ . '/../../src/helpers.php';` (pfadabhängig)

3) **DB-Änderungen nur additiv.**  
   Ausnahme: **`stoerungstool_*` darf angepasst werden**, um Ticket-System sauber zu bauen.

4) **Audit**: bei Änderungen an Wartungspunkten, Tickets, Statuswechseln usw. `core_audit_log` schreiben (via `audit_log(...)` Helper).
---

## 1) Architektur: Routing, Menü, Rechte

### 1.1 Front-Controller `app.php`
- liest Route-Key aus `?r=...` (Fallback: `cfg['app']['default_route']`)
- lädt Route aus `core_route` (muss `aktiv=1` sein)
																					 
- `require_login()` wenn `require_login=1`
- Permission zentral: `user_can_see($userId, modul, objekt_typ, objekt_id)`
- Pfad-Absicherung via `realpath`, blockt `..`
- rendert Rahmen:
  1) `render_header(route.titel)`
  2) `require route.file_path` (INNER-VIEW)
  3) `render_footer()`

➡️ Konsequenz: Alle Module, die über Menü laufen, sollten **core_route** nutzen.

### 1.2 Menü (DB-getrieben)
		 
- `core_menu` (Container, z.B. `main`, `admin`)
- `core_menu_item` (Baum via `parent_id`, Sort via `sort`)
  - bevorzugt `route_key` → Link: `app.php?r=<route_key>`
  - optional `url`
  - optional `modul/objekt_typ/objekt_id` für Permission-Filter

➡️ Ziel: Neue Seiten immer:
- Route in `core_route` anlegen
- Menüpunkt in `core_menu_item` anlegen
- Rechte via `core_permission`/bestehende Permission-Logik vergeben

  ich bin faul - bei jeder änderung bitte msql sctipt lieferm ;)

### 1.3 Permissions
Tabelle:
- `core_permission` (minimal):
  - `user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen`

Funktion:
- `user_can_see(...)` entscheidet zentral (liegt im bestehenden Code).

---

## 2) Dateisystem (Ist-Zustand / Konvention)

```
/app.php                      # Front-Controller (DB Routing) – NICHT ändern
/src/
  config.php                  # DB + app + telemetry settings
  db.php                      # PDO + db_one/db_all/db_exec
  auth.php                    # session, login, csrf, require_login, current_user
  helpers.php                 # e(), audit_log(), (optional upload helpers)
  layout.php                  # render_header/render_footer + Menüausgabe
  css/main.css

/tools/
  runtime_ingest.php          # Telemetrie Ingest (single + bulk) – vorhanden
  runtime_rollup.php          # Aggregator Sample -> Counter + agg_day

/module/
  /wartungstool/
    dashboard.php             # INNER-VIEW (kein Header/Footer!)
    (später) punkt.php
    (später) punkt_save.php
  /stoerungstool/
    inbox.php                 # INNER-VIEW (kein Header/Footer!)
    melden.php                # aktuell funktional (öffentlich oder routed)
    (später) ticket.php
    (später) ticket_action.php

/login.php, /logout.php       # Standalone
/uploads/                     # optional Dokumente/Anhänge
```

**Konvention:**
- INNER-VIEWS: `require_once helpers.php` + Content.
- Standalone: dürfen `layout.php` + `render_header/footer` nutzen.

---

## 3) Datenbank: Tabellen und Zweck (Kurzreferenz)

### CORE
- `core_asset_kategorie` – Kategorien + Kritikalität (1..3)
- `core_standort` – Standorte/Bereiche
- `core_asset` – Anlagenstamm (Maschinen, Kompressoren, Gebäude…)
- `core_user` – Login
- `core_permission` – Rechte (minimal)
- `core_menu`, `core_menu_item` – Navigation
- `core_route` – DB-Routing für `app.php`
- `core_dokument` – universelle Anhänge (modul + referenz_typ + referenz_id)
- `core_audit_log` – Audittrail (JSON alt/neu, Actor, IP, Timestamp)

### RUNTIME / Produktivstunden
- `core_runtime_sample` – Polling Rohdaten (ts + state run/stop, UNIQUE asset_id+ts)
- `core_runtime_counter` – aktueller Produktivstundenstand pro Asset (Dashboard-Quelle)
- `core_runtime_agg_day` – Tagesaggregation (run/stop seconds, gaps, intervals)

### WARTUNG
- `wartungstool_wartungspunkt` – Wartungsplan (zeit oder produktiv), Grenzwerte, letzte Wartung
- `wartungstool_protokoll` – Durchführungshistorie (Messwert, Status ok/abweichung)

### STÖRUNG
- `stoerungstool_ticket` – Tickets + Statusworkflow
- `stoerungstool_aktion` – Aktionen/Kommentare/Statuswechsel + optional Arbeitszeit

---

## 4) Runtime: Ingest & Rollup (Ist)

### 4.1 Ingest (`tools/runtime_ingest.php`)
- Auth: Header `X-INGEST-TOKEN` gegen `cfg['telemetry']['ingest_token']`
- Input:
  - Single: `{asset_id, state, ts?, source?, quality?, payload?}`
  - Bulk: `{samples:[{...},{...}]}`  (deine aktuelle Implementierung)
- Validiert:
  - Asset existiert + aktiv
  - state normalisiert (run/stop/1/0)
  - ts Format `Y-m-d H:i:s` und max clock skew
- Upsert in `core_runtime_sample`

### 4.2 Rollup (`tools/runtime_rollup.php`)
- Liest Samples pro Asset ab `core_runtime_counter.last_ts`
- Delta zwischen Samples:
  - run: addiert Sekunden zu `run_seconds` + `productive_hours`
  - stop: addiert Sekunden zu `stop_seconds`
  - gap (zu große Delta): zählt `gaps`, rechnet nicht (audit-sicher)
- Schreibt:
  - `core_runtime_agg_day` (für Trends/Prognose)
  - `core_runtime_counter` (für Wartungsfälligkeit)

---

## 5) Wartung Dashboard (Ist)

Quelle:
- `core_runtime_counter.productive_hours`
- `wartungstool_wartungspunkt` (nur `intervall_typ='produktiv'` im aktuellen Dashboard)

Fälligkeit:
- `due_at = letzte_wartung + plan_interval`
- `rest_hours = due_at - productive_hours`
Ampel:
- rot: rest < 0
- gelb: rest/interval <= 0.2
- grün: sonst

**Wichtig**: Dashboard ist als INNER-VIEW gebaut (wie Inbox) → kein header/footer.

---

## 6) Störungen Inbox/Melden (Ist)

- Inbox ist INNER-VIEW, zeigt Tickets sortiert nach Status.
- Melden ist funktionsfähig (öffentlich oder routed, je nach Route/Setup).

---

## 7) Zielbild (wo wir hinwollen)

### 7.1 Wartung (nächste Schritte)
1) **Wartungspunkt-Detailseite** (`wartung.punkt`)
   - Anleitung `text_lang`
   - Dokumente via `core_dokument` (referenz_typ='wartungspunkt', referenz_id=wp.id)
   - letzte 5 Protokolle
2) **Durchführen / Protokoll speichern** (`wartung.punkt_save`)
   - Team (Session-Default möglich)
   - Messwert wenn Pflicht
   - Status ok/abweichung + Bemerkung
   - schreibt `wartungstool_protokoll`
   - aktualisiert Wartungspunkt:
     - produktiv: `letzte_wartung = core_runtime_counter.productive_hours`
     - zeit: `datum = NOW()`
   - schreibt `core_audit_log` (CREATE/UPDATE)
3) **Abweichung → 1-Klick Ticket** (optional)
   - erzeugt `stoerungstool_ticket` und verlinkt (z.B. in Bemerkung oder später separate Link-Tabelle)
4) **Prognose**
   - `avg_daily_hours` aus `core_runtime_agg_day` (letzte 7/30 Tage)
   - `days_left = rest_hours / avg_daily_hours`

### 7.2 Störung (nächste Schritte)
1) **Ticket-Detailseite** (`stoerung.ticket`)
   - Aktionenliste + Formular für neue Aktion
   - Statuswechsel (Workflow)
   - Auditlog bei Status/Update
2) **Filter/Suche** in Inbox (Status, Kategorie, Asset)
3) **Anhänge** via `core_dokument` (referenz_typ='ticket')

### 7.3 Admin (später, nur wenn nötig)
- CRUD: Assets/Kategorien/Standorte
- CRUD: Wartungspunkte
- Alles auditfähig

---

## 8) Schritt-für-Schritt Plan (langsam & sicher)

### Phase A – Stabilisierung
A1) Prüfen, dass alle Menü-Routen auf `app.php?r=...` zeigen und Views INNER sind.  
A2) Ingest + Rollup läuft (Cron/Task).  
A3) Dashboard zeigt plausible Werte.

### Phase B – Wartung Durchführung (MVP)
B1) Route `wartung.punkt` anlegen (core_route + menu_item falls nötig).  
B2) `module/wartungstool/punkt.php` (INNER):
   - WP + Asset laden
   - Letzte Protokolle anzeigen
   - Formular „Durchführen“
B3) `module/wartungstool/punkt_save.php` (INNER, POST):
   - Validierung
   - Insert protokoll
   - Update wartungspunkt
   - Audit log
B4) Optional: „Bei Abweichung Ticket erzeugen“

### Phase C – Störung Ticket Detail (MVP)
C1) Route `stoerung.ticket`  
C2) `module/stoerungstool/ticket.php` (INNER):
   - Ticket anzeigen, Aktionen anzeigen
   - Aktion hinzufügen + Status ändern
   - Audit log

### Phase D – Reports (später)
D1) Wartungen im Zeitraum, Abweichungen, Reaktionszeiten (CSV/PDF)

---

## 9) „Nicht anfassen“ Liste
- `app.php` bleibt unverändert.
- `core_menu/core_menu_item` Struktur nicht umbauen.
- `core_route` Felder nicht umbauen (passt zu app.php).
- Runtime-Tabellen nicht umbenennen (ingest/rollup hängen dran).
- DB-Änderungen nur additiv.

---

## 10) Quick Checks / Commands

**Rollup manuell:**
- `php tools/runtime_rollup.php`

**Ingest Single (curl):**
- `curl -X POST -H "X-INGEST-TOKEN: <token>" -H "Content-Type: application/json" -d '{"asset_id":1,"state":"run","ts":"2026-02-22 10:01:00"}' http://localhost/asset_ki/tools/runtime_ingest.php`

**Ingest Bulk (curl):**
- `curl -X POST -H "X-INGEST-TOKEN: <token>" -H "Content-Type: application/json" -d '{"samples":[{"asset_id":1,"state":"run","ts":"2026-02-22 10:01:00"},{"asset_id":1,"state":"stop","ts":"2026-02-22 10:02:00"}]}' http://localhost/asset_ki/tools/runtime_ingest.php`

---

# Ende
Dieses Projekt ist bereits stabil/modular. Weiterentwicklung erfolgt schrittweise über:
- neue Route(s) in `core_route`
- neue INNER-VIEW(s) in `module/<modul>/...`
- additive DB-Erweiterungen nur wenn zwingend
- Auditlog konsequent befüllen
