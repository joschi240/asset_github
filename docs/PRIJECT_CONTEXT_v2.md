# Asset KI – Projektvertrag (Kontext für neue Chats)

Stand: 2026-02-27 (Europe/Berlin)

Ziel: modulare Instandhaltung + Störung, auditfähig, datenbankgetriebenes Routing.  
Arbeitsmodus: Änderungen bevorzugt als komplette Copy-Paste Dateien / downloadbare Bundles.

---

## UI v2 – so bauen wir neue Seiten

### Kern-Komponenten
- `ui-container`, `ui-page-header`, `ui-card`
- `ui-filterbar` für Steuerung
- `ui-kpi-row` für Kennzahlen
- `ui-table-wrap` + `ui-table` für Listen
- `ui-badge` Status, `ui-btn` Actions

### Desktop-first Pattern
- 2-Spalten Grid: links Primary Work, rechts Kontext/Insights.
- Tabellen immer in `.ui-table-wrap`.

---

## Wartung – soon_ratio (per Wartungspunkt)

DB Feld: `wartungstool_wartungspunkt.soon_ratio`  
Semantik: Anteil vom Intervall (0..1), ab dem „Bald fällig“ gilt.

- Überfällig: rest < 0
- Bald: rest/interval <= soon_ratio (fallback 0.20)
- OK: sonst

TODO:
- Admin-Formular: Feld `soon_ratio` integrieren
- Logik vereinheitlichen zwischen Dashboard & Übersicht (zentral)
