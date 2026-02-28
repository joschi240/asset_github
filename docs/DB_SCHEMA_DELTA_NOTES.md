
# DB_SCHEMA_DELTA_NOTES.md (Warum gibt’s was, was ist Core vs Modul)

> Hinweis: Das aktuelle Datenbankschema befindet sich in **asset_github_schema_v3.sql**. Testdaten/Seeds sind in **asset_github_seed_testdaten_v3.sql** zu finden.

Stand: 2026-02-28 (aktualisiert; ursprünglich: 24.02.2026)  
Ziel: Schnell verstehen, welche Tabellen „Core“ sind (stabil) und welche pro Modul flexibel verändert werden dürfen.

---

## 1) Core vs Modul – Prinzip

### Core (Prefix `core_`)
- Core-Tabellen sind die stabile Basis des Systems.
- Änderungen hier nur additiv und mit Vorsicht, weil alle Module darauf aufbauen.

### Module (Prefix `<modul>_`)
- Jedes Modul darf sein eigenes Datenmodell strukturell entwickeln (auch Änderungen), solange:
  - wir das Rad nicht neu erfinden
  - Migrationen sauber geliefert werden
  - Interfaces zu Core stabil bleiben (Asset/User/Dokument/Audit)

---

## 2) Core Tabellen (stabil, DB-validiert)

### Navigation/Routing
- `core_route`: Quelle der Wahrheit für `app.php` (route_key → file_path + require_login + modul/objekt_typ)
- `core_menu`, `core_menu_item`: Navigation als Baum

### Auth/Permissions
- `core_user`: Benutzer
- `core_permission`: minimaler Rechte-Layer (user_can_see entscheidet)

### Stammdaten
- `core_asset`: Anlagenstamm
- `core_asset_kategorie`: Kategorien + kritischkeitsstufe
- `core_standort`: Standorte

### Dokumente
- `core_dokument`: universelle Anhänge (modul + referenz_typ + referenz_id)
  - dateiname = relativer Pfad unter `/uploads/...`
  - sha256 optional

### Audit
- `core_audit_log`: ISO-Audittrail
  - action: CREATE/UPDATE/STATUS/DELETE
  - old_json/new_json: JSON (longtext + json_valid)

---

## 3) Runtime / Telemetrie (quasi Core, aber technisch Modul „telemetry“)

- `core_runtime_sample`: Rohsamples (Polling) — UNIQUE(asset_id, ts)
- `core_runtime_counter`: aggregierter Produktivstundenstand pro Asset
- `core_runtime_agg_day`: Tagesaggregation (run/stop/gaps)

Diese Tabellen sind Grundlage für Wartungs-Fälligkeit und Prognosen.

---

## 4) Wartung (Prefix `wartungstool_`)

### Tabellen
- `wartungstool_wartungspunkt`
  - intervall_typ: zeit|produktiv
  - plan_interval (h), letzte_wartung (bei produktiv), datum (bei zeit)
  - messwert_pflicht + grenzwerte + einheit
  - **soon_ratio** (`decimal(5,4) DEFAULT 0.2000`): relativer Schwellwert für "Bald fällig" (0..1); DB-Default 0.2000 entspricht 20 % (Quelle: `docs/asset_github_schema_v3.sql`)
  - **soon_hours** (`decimal(10,2) DEFAULT NULL`): absoluter Schwellwert in Stunden (hat Vorrang vor soon_ratio)
  - Fallback: wenn beide NULL → Code-Default 0.20 (20 %); da DB `soon_ratio DEFAULT 0.2000` setzt, greift der Code-Fallback nur bei explizit NULL-gesetzten Feldern
  - (Quelle: `docs/asset_github_schema_v3.sql`, `module/wartungstool/punkt.php:50–74`)
  - aktiv

- `wartungstool_protokoll`
  - status ok/abweichung
  - messwert optional
  - team_text + bemerkung
  - datum + user_id optional

### Ticket-Linking (Option A)
- Ticket wird optional erzeugt
- Ticket-ID wird als Marker ins Protokoll geschrieben:
  - `[#TICKET:<id>]`

Warum so?
- Keine zusätzliche Link-Tabelle nötig
- Später kann man immer noch eine `wartungstool_link` ergänzen (additiv), wenn nötig.

---

## 5) Störung/Ticket (Prefix `stoerungstool_`) – flexibel erweiterbar

### Tabellen
- `stoerungstool_ticket`
  - status enum: neu/angenommen/in_arbeit/bestellt/erledigt/geschlossen
  - asset_id optional
  - prioritaet
  - melder/kontakt/anonym
  - v2 Felder:
    - meldungstyp (Störmeldung/Mängelkarte/Logeintrag/…)
    - fachkategorie (Mechanik/Elektrik/Sicherheit/Qualität/…)
    - maschinenstillstand (0/1)
    - ausfallzeitpunkt (datetime)
    - assigned_user_id (FK core_user)

- `stoerungstool_aktion`
  - text
  - status_neu (inkl. bestellt)
  - arbeitszeit_min optional
  - user_id optional (NULL bei anonym/public)

### Dokumente
- laufen über `core_dokument`:
  - modul='stoerungstool', referenz_typ='ticket', referenz_id=ticket.id

### SLA (in Schema vorhanden, Code-Nutzung ausstehend)
- `first_response_at` und `closed_at` existieren bereits in `stoerungstool_ticket` (Quelle: `docs/asset_github_schema_v3.sql:214–215`, Migration: `docs/db_migration_sla_v1.sql`)
- Code setzt diese Felder noch nicht automatisch beim Statuswechsel (TODO: Auto-Set in ticket.php)
- Backfill aus `stoerungstool_aktion` ist via `db_migration_sla_v1.sql` vorbereitet

---

## 6) Warum diese Trennung gut ist
- Core bleibt stabil und klein
- Module können wachsen, ohne “alles anzufassen”
- Audit/Dokumente/Assets bleiben systemweit konsistent