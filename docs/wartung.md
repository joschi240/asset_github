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