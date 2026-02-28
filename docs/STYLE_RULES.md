# STYLE_RULES.md – UI v2 „Final“ Regeln (Shopfloor / Operativ)

Stand: 2026-02-28  
Gilt für: alle neuen oder migrierten Seiten (UI v2)

## 0) Non-negotiables
- Keine Legacy Klassen / kein main.css erweitern. Nur `ui-*`.
- Tabellen immer in `.ui-table-wrap`.
- Desktop-first, scanbar, wenig Text, klare Priorität.

## 1) Operatives Dashboard Pattern (Wartung / Werkhalle)
Ziel: „Was ist jetzt zu tun?“

### Struktur
1. Page Header + Search
2. Zeit-Scope Tabs: Heute / Diese Woche / Alle
3. Status Tabs: Alle / Überfällig / Bald / OK / Geplant
4. Haupttabelle
5. Sidebar: Quickinfo (gruppiert), kompakte Counts

### Weglassen
- Keine zusätzliche KPI-Row (Überfällig/Bald/OK/Gesamt) oben: zu viel, redundant.
- Keine Charts / Trends auf operativen Seiten.

## 2) Tabelle – Pflichtspalten
- Asset (Code fett, Name grau darunter)
- Wartungspunkt (Link)
- Rest
- Intervall
- Letzte Wartung
- Status (Badge)
- Aktion (Öffnen)

### Asset Darstellung
- Nur Code fett (z.B. `BAZ-02`, `KOM-03`)
- Name darunter muted (grau)
- Keine Kritikalität anzeigen (optional später nur in Detail)

## 3) Status & Badges
Basisstatus (Ampel):
- Überfällig
- Bald fällig
- OK
- Neu/Unbekannt

### Geplant (Flag)
- `planned_at` gesetzt => Flag „Geplant“
- UI: zweiter Badge zusätzlich zum Basisstatus (z.B. „Überfällig“ + „Geplant“)
- Filter: Tab „Geplant“ zeigt alle mit `planned_at` gesetzt

## 4) Quickinfo (Sidebar)
- Immer gruppiert nach Asset (Maschine)
- Pro Maschine: x Aktionen, Top-3 Einträge, +n weitere
- Ziel: Scanbar in 2 Sekunden

## 5) Filters: Active States
- Aktive Tabs müssen deutlich hervorgehoben sein (`ui-tab--active`).
- Optional: aktive KPI/Filter können zusätzlich ein „aktiv“ Label haben.

## 6) Migration / Regeln für neue Seiten
- Jede neue Seite beginnt mit dem UI-v2 Template (siehe docs/UI_V2_GUIDE.md).
- Keine modul-spezifischen CSS Dateien.
- Layout inline styles nur für Grid-Spalten/kleine Abstände.