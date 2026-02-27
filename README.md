# Asset KI – Instandhaltung & Störungsmanagement

Modulare Webanwendung für **Anlagenwartung** und **Störungsmanagement** (Shopfloor).  
Backend: PHP + MariaDB, UI: leichtgewichtiges HTML/CSS „UI v2“ Design System.

---

## Quick Facts

- Front-Controller: `app.php` (DB-Routing) – **nicht ändern**
- Navigation: DB-getrieben über `core_menu` / `core_menu_item`
- Rechte: `core_permission` (user_can_see / user_can_edit)
- Audit: `core_audit_log` (ISO-tauglich)
- Telemetrie: `core_runtime_sample` → Rollup → `core_runtime_counter` + `core_runtime_agg_day`
- Wartung: produktivstunden- oder zeitbasiert
- UI v2: einheitliche `ui-*` Klassen (Cards, Badges, Buttons, Filterbars, Tabellen, KPI)

---

## UI v2 Design System (wie das neue Design funktioniert)

Das Projekt nutzt ein konsistentes UI-System mit `ui-*` Klassen (in `src/css/main.css`).  
Ziel: Seiten sind **ruhig, scanbar, datenorientiert** und folgen wiederholbaren Patterns.

### Grundlayout
- Jede Seite hat eine `ui-container` als Root.
- Oben immer ein Page Header:
  - `ui-page-header`
  - `ui-page-title`
  - `ui-page-subtitle ui-muted`

### Bausteine (immer wieder gleich)
- Inhalte in `ui-card`
- Filter/Controls in `ui-card ui-filterbar`
- Dashboards: `ui-kpi-row` für Kennzahlen
- Tabellen: immer `ui-table-wrap` + `ui-table`
- Status: `ui-badge` (ok/warn/danger)
- Aktionen: `ui-btn` (primary/ghost, optional `--sm`)

### Typische Patterns
1) **Admin CRUD** (z.B. `wartung.admin_punkte`)
2) **Dashboard** (z.B. `wartung.dashboard`)
3) **Übersicht pro Asset** (z.B. `wartung.uebersicht`)

👉 Ausführliche Vorlage: `docs/UI_V2_GUIDE.md`

---

## Statuslogik „Bald fällig“ (soon_ratio)

Status wird aus Reststunden und Intervall berechnet:
- **Überfällig**: `restHours < 0`
- **Bald fällig**: `(restHours / intervalHours) <= soon_ratio`
- **OK**: sonst

`soon_ratio` ist pro Wartungspunkt vorgesehen: `wartungstool_wartungspunkt.soon_ratio`  
Fallback (NULL oder <= 0): `0.20`.

**TODO (geplant):**
- `soon_ratio` im Admin-Formular editierbar machen
- Statusberechnung zentralisieren (Dashboard + Übersicht identisch)
