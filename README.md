# Asset KI – Instandhaltung & Störungsmanagement

Eine modulare Webanwendung für **Anlagenwartung** und **Störungsmanagement** im Shopfloor-Umfeld. Entwickelt mit PHP 8.2, MariaDB 10.4 und datenbankgetriebenem Routing.

---

## Inhaltsverzeichnis

- [Projektbeschreibung](#projektbeschreibung)
- [Funktionsumfang](#funktionsumfang)
- [Technologie-Stack](#technologie-stack)
- [Architektur](#architektur)
- [Verzeichnisstruktur](#verzeichnisstruktur)
- [Datenbank](#datenbank)
- [Installation & Setup](#installation--setup)
- [Berechtigungssystem](#berechtigungssystem)
- [Telemetrie / Produktivstunden](#telemetrie--produktivstunden)
- [Nächste Schritte](#nächste-schritte)
- [Dokumentation](#dokumentation)

---

## Projektbeschreibung

**Asset KI** ist ein PHP-basiertes Instandhaltungssystem für Produktionsumgebungen. Es verbindet:

- **Wartungsplanung** (zeit- und produktivstundenbasiert)
- **Störungsmanagement** mit Ticket-Workflow
- **Telemetrie-Integration** (Produktivstunden per Polling)
- **Auditfähigkeit** (ISO-konformer Audit-Trail)

Das System ist bewusst **schlank und modular** gehalten – Erweiterungen erfolgen additiv über neue Routen, Module und Datenbankeinträge, ohne das Kernsystem zu verändern.

---

## Funktionsumfang

### ✅ Wartungstool (`wartungstool`)
- **Dashboard**: Fälligkeitsübersicht mit Ampelstatus (rot/gelb/grün)
- **Wartungspunkt-Detail**: Anleitung, letzte Protokolle, Durchführungsformular
- **Protokoll speichern**: Messwert, Status (ok/Abweichung), Bemerkung, Audit-Eintrag
- **Übersicht**: Wartungspunkte pro Asset
- **Admin**: CRUD-Verwaltung der Wartungspunkte (nur mit `darf_aendern`-Berechtigung)

### ✅ Störungstool (`stoerungstool`)
- **Störung melden** (öffentlich, kein Login erforderlich)
- **Inbox**: Ticket-Übersicht mit Ampel, Filter (Status, Kategorie, Asset) und Suche
- **Ticket-Detail**: Statuswechsel (Quick-Status), Zuweisung, Aktionen, Dokumente

### ✅ Runtime / Telemetrie
- **Ingest**: Single- und Bulk-Import von Maschinenzuständen (run/stop) via REST
- **Rollup**: Aggregation der Rohdaten zu Produktivstunden und Tageswerten

### ✅ Admin
- Benutzerverwaltung, Routen, Menü, Berechtigungen

---

## Technologie-Stack

| Komponente | Version / Technologie |
|---|---|
| Backend | PHP 8.2.x |
| Datenbank | MariaDB 10.4.x |
| Datenbankzugriff | PDO |
| Frontend | HTML/CSS (kein JS-Framework) |
| Routing | Datenbankgetrieben via `core_route` |
| Navigation | Datenbankgetrieben via `core_menu` / `core_menu_item` |

---

## Architektur

### Front-Controller (`app.php`)

Alle Seiten laufen über `app.php`. Der Route-Key kommt aus `?r=<route_key>`:

1. Route wird aus `core_route` geladen (`aktiv=1`)
2. Login-Pflicht geprüft (`require_login=1`)
3. Berechtigung geprüft: `user_can_see($userId, modul, objekt_typ, objekt_id)`
4. Pfad-Absicherung (`realpath`, blockt `..`)
5. Layout wird gerendert:
   - `render_header(route.titel)`
   - `require route.file_path` (**INNER-VIEW**)
   - `render_footer()`

> **Regel:** `app.php` wird **nie** verändert. Neue Seiten immer über `core_route` integrieren.

### INNER-VIEWs

Views, die über `app.php` geladen werden, dürfen **kein eigenes Layout** rendern:
- ❌ Kein `render_header()` / `render_footer()`
- ❌ Kein `require src/layout.php`
- ✅ Stattdessen: `require_once __DIR__ . '/../../src/helpers.php';`

---

## Verzeichnisstruktur

```
/app.php                        # Front-Controller (DB-Routing) – NICHT ändern
/index.php                      # Weiterleitung zu app.php
/login.php, /logout.php         # Standalone Login/Logout

/src/
  config.php                    # DB + App + Telemetrie-Einstellungen
  db.php                        # PDO + db_one() / db_all() / db_exec()
  auth.php                      # Session, Login, CSRF, require_login, current_user
  helpers.php                   # e(), audit_log(), CSRF-Helpers
  layout.php                    # render_header() / render_footer() + Menüausgabe
  css/main.css

/tools/
  runtime_ingest.php            # Telemetrie-Ingest (Single + Bulk)
  runtime_rollup.php            # Aggregator: Sample → Counter + agg_day

/module/
  /wartungstool/
    dashboard.php               # INNER-VIEW: Wartungs-Dashboard
    punkt.php                   # INNER-VIEW: Wartungspunkt Detail + Durchführung
    punkt_save.php              # INNER-VIEW: POST – Protokoll speichern
    uebersicht.php              # INNER-VIEW: Übersicht pro Asset
    admin_punkte.php            # INNER-VIEW: Admin CRUD Wartungspunkte

  /stoerungstool/
    melden.php                  # INNER-VIEW: Störung melden (öffentlich)
    inbox.php                   # INNER-VIEW: Ticket-Inbox
    ticket.php                  # INNER-VIEW: Ticket-Detail

  /admin/
    setup.php                   # Erstbenutzer anlegen
    users.php                   # Benutzerverwaltung
    routes.php                  # Routenverwaltung
    menu.php                    # Menüverwaltung
    permissions.php             # Berechtigungsverwaltung

/uploads/                       # Hochgeladene Dateien (Dokumente/Fotos)

/docs/                          # Projektdokumentation
  db_schema_v2.sql              # Vollständiges Datenbankschema (v2, aktuell)
  db_schema.sql                 # Datenbankschema (v1, historisch)
  db_migration_permissions_v1.sql  # Migration: Rechte-Konsistenz
  DB_SCHEMA_DELTA_NOTES.md      # Erläuterungen Core vs. Modul-Tabellen
  KNOWN_ROUTE_KEYS.md           # Alle bekannten Route-Keys (DB-validiert)
  PRIJECT_CONTEXT_v2.md         # Projektkontext & Arbeitsvertrag (aktuell)
```

---

## Datenbank

Das Datenbankschema liegt unter [`docs/db_schema_v2.sql`](docs/db_schema_v2.sql).  
Das Script ist idempotent (`IF NOT EXISTS`, kein `DROP`) und kann auf bestehenden Daten ausgeführt werden.

### Tabellen-Übersicht

#### Core (Prefix `core_`) – stabile Basis

| Tabelle | Zweck |
|---|---|
| `core_asset` | Anlagenstamm (Maschinen, Kompressoren, …) |
| `core_asset_kategorie` | Asset-Kategorien mit Kritikalitätsstufe (1–3) |
| `core_standort` | Standorte und Bereiche |
| `core_user` | Benutzerverwaltung |
| `core_permission` | Berechtigungen (user/modul/objekt) |
| `core_route` | DB-Routing für `app.php` |
| `core_menu` / `core_menu_item` | Navigation als Baum |
| `core_dokument` | Universelle Dateianhänge |
| `core_audit_log` | ISO-Audit-Trail (JSON alt/neu, Actor, IP) |

#### Runtime / Telemetrie (Prefix `core_runtime_`)

| Tabelle | Zweck |
|---|---|
| `core_runtime_sample` | Polling-Rohdaten (ts + state run/stop) |
| `core_runtime_counter` | Aktueller Produktivstunden-Stand pro Asset |
| `core_runtime_agg_day` | Tagesaggregation (run/stop Sekunden, Gaps) |

#### Wartungstool (Prefix `wartungstool_`)

| Tabelle | Zweck |
|---|---|
| `wartungstool_wartungspunkt` | Wartungsplan (zeit- oder produktivstundenbasiert) |
| `wartungstool_protokoll` | Durchführungshistorie (Messwert, Status ok/Abweichung) |

#### Störungstool v2 (Prefix `stoerungstool_`)

| Tabelle | Zweck |
|---|---|
| `stoerungstool_ticket` | Störungstickets inkl. Statusworkflow + v2-Felder |
| `stoerungstool_aktion` | Aktionen, Kommentare, Statuswechsel pro Ticket |

**v2-Felder in `stoerungstool_ticket`:**
- `meldungstyp` – Art der Meldung (Störmeldung, Mängelkarte, Logeintrag, …)
- `fachkategorie` – Fachbereich (Mechanik, Elektrik, Sicherheit, Qualität, …)
- `maschinenstillstand` – Kennzeichen: Anlage steht (0/1)
- `ausfallzeitpunkt` – Zeitpunkt des Ausfalls
- `assigned_user_id` – Zugewiesener Techniker (FK → `core_user`)

---

## Installation & Setup

### Voraussetzungen

- PHP 8.2+
- MariaDB 10.4+
- Webserver (Apache/Nginx) mit PHP-Unterstützung

### Schritte

1. **Repository klonen**
   ```bash
   git clone <repo-url> /var/www/html/asset_ki
   cd /var/www/html/asset_ki
   ```

2. **Datenbank erstellen**
   ```sql
   CREATE DATABASE asset_ki CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

3. **Schema einspielen**
   ```bash
   mysql -u <user> -p asset_ki < docs/db_schema_v2.sql
   ```

4. **Konfiguration anlegen**
   ```bash
   cp src/config.default src/config.php
   # Datenbankzugangsdaten in src/config.php eintragen
   ```

5. **Uploads-Verzeichnis vorbereiten**
   ```bash
   mkdir -p uploads
   chmod 775 uploads
   ```

6. **Erstbenutzer anlegen**  
   Aufruf im Browser: `http://<host>/asset_ki/app.php?r=admin.setup`

7. **Telemetrie-Cron einrichten** (optional)
   ```bash
   # Rollup alle 5 Minuten ausführen
   */5 * * * * php /var/www/html/asset_ki/tools/runtime_rollup.php
   ```

---

## Berechtigungssystem

Berechtigungen werden in `core_permission` je User/Modul/Objekttyp vergeben.

| Spalte | Bedeutung |
|---|---|
| `modul` | z.B. `wartungstool`, `stoerungstool`, `admin`, oder `*` (Wildcard) |
| `objekt_typ` | z.B. `global`, `dashboard` |
| `darf_sehen` | Lesezugriff |
| `darf_aendern` | Schreibzugriff |
| `darf_loeschen` | Löschzugriff |

### Berechtigungsmatrix

| Route | modul / objekt_typ | sehen | ändern | löschen |
|---|---|:---:|:---:|:---:|
| `wartung.dashboard` | wartungstool / dashboard | ✅ | — | — |
| `wartung.punkt` | wartungstool / global | ✅ | — | — |
| `wartung.uebersicht` | wartungstool / global | ✅ | — | — |
| `wartung.punkt_save` | wartungstool / global | ✅ | ✅ | — |
| `wartung.admin_punkte` | wartungstool / global | ✅ | ✅ | — |
| `stoerung.melden` | stoerungstool / global | öffentlich | — | — |
| `stoerung.inbox` | stoerungstool / global | ✅ | — | — |
| `stoerung.ticket` | stoerungstool / global | ✅ | ✅ | ✅ |
| `admin.*` | `*` (Wildcard) | ✅ | ✅ | ✅ |

---

## Telemetrie / Produktivstunden

### Ingest (Single)
```bash
curl -X POST \
  -H "X-INGEST-TOKEN: <token>" \
  -H "Content-Type: application/json" \
  -d '{"asset_id":1,"state":"run","ts":"2026-02-24 08:00:00"}' \
  http://<host>/asset_ki/tools/runtime_ingest.php
```

### Ingest (Bulk)
```bash
curl -X POST \
  -H "X-INGEST-TOKEN: <token>" \
  -H "Content-Type: application/json" \
  -d '{"samples":[{"asset_id":1,"state":"run","ts":"2026-02-24 08:00:00"},{"asset_id":1,"state":"stop","ts":"2026-02-24 09:00:00"}]}' \
  http://<host>/asset_ki/tools/runtime_ingest.php
```

### Rollup (manuell)
```bash
php tools/runtime_rollup.php
```

---

## Nächste Schritte

Geplante Erweiterungen (in sinnvoller Reihenfolge):

1. **Störung – UX/Prozess „Shopfloor Level 2"**
   - Inbox Quick-Filter per Status-Badge-Klick
   - Ticket Timeline-Ansicht aus Aktionen
   - Standardtexte / Templates für Aktionen

2. **Wartung – Dokumente an Wartungspunkten**
   - `core_dokument` auch für `wartungspunkt` nutzen
   - Upload und Anzeige in `wartung.punkt`

3. **SLA-Vorbereitung (geplant)**
   - Felder `first_response_at`, `closed_at` in `stoerungstool_ticket`
   - Auto-Set beim Statuswechsel

4. **Reports (Audit/ISO)**
   - CSV-Export: Tickets (Zeitraum, Reaktionszeiten, Durchlaufzeiten)
   - CSV-Export: Wartungen (Punkte, Protokolle, Abweichungen)

---

## Dokumentation

| Datei | Inhalt |
|---|---|
| [`docs/PRIJECT_CONTEXT_v2.md`](docs/PRIJECT_CONTEXT_v2.md) | Vollständiger Projektkontext, Architektur-Regeln, Arbeitsanweisungen |
| [`docs/db_schema_v2.sql`](docs/db_schema_v2.sql) | Datenbankschema v2 (vollständig, idempotent) |
| [`docs/db_schema.sql`](docs/db_schema.sql) | Datenbankschema v1 (historisch) |
| [`docs/db_migration_permissions_v1.sql`](docs/db_migration_permissions_v1.sql) | Migration: objekt_typ-Konsistenz für Berechtigungen |
| [`docs/DB_SCHEMA_DELTA_NOTES.md`](docs/DB_SCHEMA_DELTA_NOTES.md) | Erläuterungen zur Tabellenstruktur (Core vs. Modul) |
| [`docs/KNOWN_ROUTE_KEYS.md`](docs/KNOWN_ROUTE_KEYS.md) | Alle bekannten Route-Keys mit Metadaten |
---

 
 update Wartungstool:
 
 # Wartungstool

## Überblick

Das Wartungstool bietet:
- **Dashboard**: Anlagen-Übersicht nach Kritikalität inkl. nächstem Wartungspunkt (produktiv-basiert) und Trend-Auswertung.
- **Übersicht**: Detailübersicht pro Anlage inkl. aller Wartungspunkte (Zeit- und Produktiv-Intervall).
- **Wartungspunkt-Detail**: Erfassung/Protokollierung und Bewertung (Messwert, Status etc.).

---

## Status-Logik (Ampel)

Jeder Wartungspunkt liefert `rest` (Reststunden bis fällig):

- `rest = NULL`  
  → *Neu/Unbekannt* (z. B. keine letzte Wartung vorhanden)

- `rest < 0`  
  → **Überfällig**

- `rest >= 0` und `(rest / interval) <= soon_ratio`  
  → **Bald fällig**

- sonst  
  → **OK**

### soon_ratio (pro Wartungspunkt)
Die Schwelle „Bald fällig“ ist pro Wartungspunkt einstellbar über:

- Tabelle: `wartungstool_wartungspunkt`
- Feld: `soon_ratio` (float)
- Bedeutung: Anteil des Intervalls (z. B. `0.2` = 20% Restzeit)
- Fallback: wenn `soon_ratio` NULL oder <= 0 → **0.20**
- Empfehlung: Bereich **0 < soon_ratio <= 1.0**

Beispiele:
- `interval=100h`, `soon_ratio=0.2` → bald fällig ab `rest <= 20h`
- `interval=50h`, `soon_ratio=0.1` → bald fällig ab `rest <= 5h`

---

## Fälligkeit berechnen

### Produktiv-Intervall (`intervall_typ='produktiv'`)
Quelle: `core_runtime_counter.productive_hours`

Berechnung:
- `dueAt = letzte_wartung + plan_interval`
- `rest = dueAt - productive_hours`

### Zeit-Intervall (`intervall_typ='zeit'`)
Quelle: Unix-Zeit / `time()`

Berechnung:
- `dueTs = strtotime(datum) + (plan_interval * 3600)`
- `rest = (dueTs - time()) / 3600`

---

## Dashboard

### Filter
- `f=all|due|soon|critical`
- `q=...` (Suche in Code/Name/Kategorie/Next WP)

KPI-Karten sind klickbar und setzen `f`. Der aktuelle Suchbegriff `q` bleibt erhalten.

### „Nächster Punkt“
Das Dashboard zeigt pro Anlage den **nächst fälligen produktiv-basierten** Wartungspunkt (`intervall_typ='produktiv'`), sofern `letzte_wartung` gesetzt ist.

---

## Trend (4-Wochen-Proxy)

Datenbasis: `core_runtime_agg_day`

Vergleich:
- Summe Laufzeit der **letzten 14 Tage** vs. **davorliegende 14 Tage**

Trend-Symbol:
- ▲ steigend: `new > old * 1.10`
- ▼ fallend: `new < old * 0.90`
- ➝ stabil: sonst

Zusätzlich:
- Δh = `new - old` (primär stabil)
- % = `(Δh / old) * 100` (sekundär, capped)




# UI v2 Guide (Design System & Seiten-Patterns)

Stand: 2026-02-27 (Europe/Berlin)  
Ziel: Einheitliches UI für alle Module (Wartung / Störung / Admin) als Grundlage für weitere Seiten.

Dieses UI ist **Desktop-first** (typische 2026-Monitore), aber so gebaut, dass ein späterer Mobile/Tablet-Step möglich ist:
- Layouts nutzen Grid/Flex
- Tabellen liegen in `.ui-table-wrap` (horizontal scroll möglich)
- Komponenten sind wiederverwendbar (Cards, Badges, Buttons, Filterbar, KPI)

### Wichtig: `main.css` ist veraltet

- **Bitte nichts an `main.css` ändern.**
- Neue/umgebaute Seiten nutzen ausschließlich das **UI-v2 Template** (Klassen mit Prefix `ui-…`).
- Alt-Seiten werden schrittweise migriert. Während der Migration gilt:
  - Neue Komponenten/Seiten: **UI-v2**
  - Bestehende Alt-Seiten: bleiben bis zur Umstellung unverändert

---

## 1) Grundlayout

Jede INNER-VIEW startet typischerweise so:

```php
<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();
// optional: require_can_edit(...)
$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';
?>
<div class="ui-container">
  <!-- ... -->
</div>
```

### Page Header (Standard)

```html
<div class="ui-page-header">
  <h1 class="ui-page-title">Titel</h1>
  <p class="ui-page-subtitle ui-muted">Kurzbeschreibung / Kontext</p>

  <!-- Optional: Statuschips -->
  <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
    <span class="ui-badge ui-badge--ok">Gespeichert</span>
    <span class="ui-badge ui-badge--danger">Fehlertext</span>
  </div>
</div>
```

---

## 2) Layout-Bausteine

### 2.1 Card
`ui-card` ist der Standard-Container für Inhalte.

```html
<div class="ui-card">
  <h2>Card Titel</h2>
  <p class="small ui-muted">Text…</p>
</div>
```

**Regel:** Alles „sichtbar zusammengehörige“ gehört in eine Card.

---

### 2.2 Grid (2-Spalten Desktop)
Desktop-first: wir nutzen Grid für Seiten mit „links Hauptarbeit, rechts Kontext/Listen“.

```html
<div class="ui-grid" style="display:grid; grid-template-columns: 1.1fr 1.4fr; gap: var(--s-6); align-items:start;">
  <div><!-- links --></div>
  <div><!-- rechts --></div>
</div>
```

**Best practice:**
- Links: Form / KPIs / Primary Actions
- Rechts: Listen / Tabellen / Audit / Insights

---

### 2.3 Filterbar (Form-Header)
`ui-filterbar` + `ui-filterbar__form` für Filter/Select/Controls.

```html
<div class="ui-card ui-filterbar">
  <form class="ui-filterbar__form">
    <div class="ui-filterbar__group">
      <label for="x">Label</label>
      <select id="x" name="x"></select>
    </div>
    <div class="ui-filterbar__actions">
      <button class="ui-btn ui-btn--primary">Filter anwenden</button>
    </div>
  </form>
</div>
```

**Regel:** Filterbar ist die „Steuerzentrale“ der Seite.

---

### 2.4 KPI Row (Clickables möglich)
Für Dashboards/Übersichten: KPI-Boxen als schnelle Navigation.

```html
<div class="ui-kpi-row">
  <a class="ui-kpi ui-kpi--danger" href="#">
    <div class="ui-kpi__label">Überfällig</div>
    <div class="ui-kpi__value">3</div>
  </a>
  <div class="ui-kpi">
    <div class="ui-kpi__label">Gesamt</div>
    <div class="ui-kpi__value">42</div>
  </div>
</div>
```

---

## 3) Komponenten (UI v2)

### 3.1 Buttons
- Primary: `ui-btn ui-btn--primary`
- Ghost/Secondary: `ui-btn ui-btn--ghost`
- Small: `ui-btn ui-btn--sm`

Beispiele:

```html
<button class="ui-btn ui-btn--primary">Speichern</button>
<a class="ui-btn ui-btn--ghost" href="#">Abbrechen</a>
<a class="ui-btn ui-btn--sm ui-btn--primary" href="#">Öffnen</a>
```

---

### 3.2 Badges (Status)
- Neutral: `ui-badge`
- OK: `ui-badge ui-badge--ok`
- Warn: `ui-badge ui-badge--warn`
- Danger: `ui-badge ui-badge--danger`

Beispiele:

```html
<span class="ui-badge ui-badge--ok">aktiv</span>
<span class="ui-badge ui-badge--warn">Bald fällig</span>
<span class="ui-badge ui-badge--danger">Überfällig</span>
```

---

### 3.3 Inputs
- Input: `ui-input`
- Textarea: `ui-input` (gleiches Styling)

```html
<label for="name">Name</label>
<input id="name" class="ui-input" name="name">

<label for="desc">Beschreibung</label>
<textarea id="desc" class="ui-input" name="desc"></textarea>
```

---

### 3.4 Tabellen
**Wichtig:** Tabellen immer in `.ui-table-wrap`, damit bei schmaler View kein Layout bricht.

```html
<div class="ui-table-wrap">
  <table class="ui-table">
    <thead>...</thead>
    <tbody>...</tbody>
  </table>
</div>
```

Actions-Spalte:
- Header: `ui-th-actions`
- Cells: `ui-td-actions`

---

### 3.5 Links
- Standard: `ui-link`

```html
<a class="ui-link" href="#">Wartungspunkt öffnen</a>
```

---

### 3.6 Collapsible Sections
Für „Optional/Secondary“ Inhalte (z.B. OK-Liste, Details, Historien):

```html
<details>
  <summary style="cursor:pointer; font-weight:700;">Mehr anzeigen</summary>
  <div style="margin-top: var(--s-4);">...</div>
</details>
```

---

## 4) Seiten-Pattern Vorlagen

### 4.1 Admin CRUD (Beispiel: Wartungspunkte)
Pattern:
- oben: Page Header + Statuschips
- Filterbar: Asset auswählen
- 2-Spalten:
  - Links: Create/Update Form + Imports/Tools
  - Rechts: Listen + Audit

Genau so ist `wartung.admin_punkte` gebaut.

---

### 4.2 Dashboard (Beispiel: Wartung Dashboard)
Pattern:
- Page Header + Quick Links
- KPI row (clickable Filter)
- 2-Spalten:
  - Links: Haupttabellen (kritisch + weitere einklappbar)
  - Rechts: Insights (Trend, Top up/down)

---

### 4.3 Übersicht pro Asset (Beispiel: Wartung Übersicht)
Pattern:
- Page Header
- KPI row (Überfällig/Bald/OK/Gesamt)
- Filterbar (Asset + Mode)
- Tabelle (Offene oder Alle)
- Optional: OK-Liste in `<details>`

---

## 5) Style-Regeln (konkret)

1) **Keine „alten“ Klassen** (`card`, `table`, `btn`, `badge` etc.) in neuen/überarbeiteten Seiten.  
   Nur `ui-*` verwenden.

2) **Tabellen nur mit Wrap** (`ui-table-wrap`).  
   Sonst „zerschießt“ es bei enger Breite oder langen Texten.

3) **Inline-Styles nur für Layout** (Grid Columns, kleine flex alignments).  
   Farben/Abstände grundsätzlich über CSS Klassen.

4) **Actions immer rechts** (Buttons in eigener Actions-Spalte).  
   Macht Listen scanbar.

5) **Sekundärinfos klein + muted** (`small ui-muted`).  
   Dadurch bleibt die Seite “ruhig”.

---

## 6) “Copy-Paste” Mini-Template (Neue Seite starten)

```php
<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';
?>

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Titel</h1>
    <p class="ui-page-subtitle ui-muted">Kurzbeschreibung…</p>
  </div>

  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="route.key">

      <div class="ui-filterbar__group">
        <label for="x">Filter</label>
        <input id="x" name="x" class="ui-input" placeholder="...">
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary" type="submit">Anwenden</button>
      </div>
    </form>
  </div>

  <div class="ui-grid" style="display:grid; grid-template-columns: 1.2fr 1fr; gap: var(--s-6); align-items:start;">
    <div class="ui-card">
      <h2>Hauptteil</h2>
      ...
    </div>

    <aside class="ui-card">
      <h2>Sidebar / Kontext</h2>
      ...
    </aside>
  </div>

</div>
```
