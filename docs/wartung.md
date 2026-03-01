# Wartungstool

## Überblick

Das Wartungstool bietet:
- **Dashboard**: Anlagen-Übersicht inkl. aller Wartungspunkte (zeit- und produktiv-basiert).
- **Übersicht**: Detailübersicht pro Anlage inkl. aller Wartungspunkte (Zeit- und Produktiv-Intervall).
- **Wartungspunkt-Detail**: Erfassung/Protokollierung und Bewertung (Messwert, Status etc.).

---

## Status-Logik (Ampel)

Jeder Wartungspunkt liefert `rest` (Reststunden bis fällig):

- `rest = NULL`  
  → *Neu/Unbekannt* (z. B. keine letzte Wartung vorhanden)

- `rest < 0`  
  → **Überfällig**

- `rest >= 0` und `soon_hours` gesetzt und `rest <= soon_hours`  
  → **Bald fällig** (`soon_hours` hat Vorrang vor `soon_ratio`)

- `rest >= 0` und `(rest / interval) <= soon_ratio`  
  → **Bald fällig**

- sonst  
  → **OK**

### soon_ratio (pro Wartungspunkt)
Die Schwelle „Bald fällig“ ist pro Wartungspunkt einstellbar über:

- Tabelle: `wartungstool_wartungspunkt`
- Feld: `soon_ratio` (float)
- Bedeutung: Anteil des Intervalls (z. B. `0.2` = 20% Restzeit)
- Fallback: **0.20** – aber nur wenn **sowohl** `soon_hours` **als auch** `soon_ratio` NULL oder <= 0 sind
- Empfehlung: Bereich **0 < soon_ratio <= 1.0**

Beispiele:
- `interval=100h`, `soon_ratio=0.2` → bald fällig ab `rest <= 20h`
- `interval=50h`, `soon_ratio=0.1` → bald fällig ab `rest <= 5h`

### soon_hours (pro Wartungspunkt, hat Vorrang)

- Tabelle: `wartungstool_wartungspunkt`
- Feld: `soon_hours` (float)
- Bedeutung: Absoluter Reststunden-Schwellwert (z. B. `24` = bald fällig wenn weniger als 24h verbleiben)
- Vorrang: wenn `soon_hours` gesetzt (> 0), wird `soon_ratio` ignoriert
- Fallback: wenn `soon_hours` NULL oder <= 0 → Fallback auf `soon_ratio`

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
- `f=all|due|soon|ok|new|planned`
- `scope=heute|woche|alle` (begrenzt nach Reststunden: heute ≤ 8h, woche ≤ 40h)
- `q=...` (Suche in Code/Name/Kategorie/WP)

Das Dashboard zeigt **alle** aktiven Wartungspunkte aller Anlagen (sowohl `zeit`- als auch `produktiv`-basierte).

---

## Trend (4-Wochen-Proxy)

> **TODO:** Trend-Berechnung wieder in das Dashboard einbauen.  
> Geplantes Verhalten:
> - Pro Asset wird nur **ein** Eintrag angezeigt.
> - Wartungspunkte mit demselben Fälligkeitszeitpunkt werden gruppiert.
> - Trendberechnung basierend auf `core_runtime_agg_day`.

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