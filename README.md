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

6. **Server-Limits für Datei-Uploads anpassen** (empfohlen)

   Damit das Hochladen größerer Bilder und PDFs funktioniert, müssen die Serverlimits angepasst werden:

   **PHP (`php.ini`):**
   ```ini
   upload_max_filesize = 20M
   post_max_size       = 25M
   ```

   **Apache (`.htaccess` oder VirtualHost):**
   ```apache
   php_value upload_max_filesize 20M
   php_value post_max_size 25M
   ```

   **Nginx (`nginx.conf` oder Site-Konfiguration):**
   ```nginx
   client_max_body_size 25M;
   ```

   > **Hinweis:** Bei Überschreitung des Serverlimits zeigt die Anwendung die Meldung  
   > *„Datei zu groß (Serverlimit überschritten)."* statt eines generischen Fehlers.

7. **Erstbenutzer anlegen**  
   Aufruf im Browser: `http://<host>/asset_ki/app.php?r=admin.setup`

8. **Telemetrie-Cron einrichten** (optional)
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
