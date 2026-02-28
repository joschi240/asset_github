# WARTUNG_LOGIK.md – Wartungslogik & soon_ratio Verifikation

> Repo-verifiziert. Stand: 2026-02-28.  
> Jede Aussage mit Fundstelle. Keine Annahmen.

---

## 1) Überblick

Das Wartungstool unterstützt zwei Intervalltypen:

| `intervall_typ` | Basis | Quelle |
|---|---|---|
| `'produktiv'` | Produktivstunden (`core_runtime_counter.productive_hours`) | `module/wartungstool/punkt.php:61–69` |
| `'zeit'` | Wallclock-Zeit (`time()`, Basis: `datum`) | `module/wartungstool/punkt.php:71–81` |

---

## 2) Fälligkeit berechnen

### Produktiv-Intervall (`intervall_typ='produktiv'`)

(Quelle: `module/wartungstool/punkt.php:61–69`)

```
Voraussetzung: wp.letzte_wartung IS NOT NULL AND wp.plan_interval > 0

dueAt [in Produktivstunden] = wp.letzte_wartung + wp.plan_interval
rest  [in Produktivstunden] = dueAt - core_runtime_counter.productive_hours
```

`productive_hours` kommt aus `core_runtime_counter` (wird vom Rollup befüllt).  
(Quelle: `tools/runtime_rollup.php`)

### Zeit-Intervall (`intervall_typ='zeit'`)

(Quelle: `module/wartungstool/punkt.php:71–81`)

```
Voraussetzung: wp.datum IS NOT NULL AND wp.plan_interval > 0

dueTs [Unix-Timestamp] = strtotime(wp.datum) + round(wp.plan_interval * 3600)
rest  [in Stunden]     = (dueTs - time()) / 3600
```

---

## 3) Statuslogik (Ampel)

### Status-Bestimmung (vollständig, exakt)

Die Statuslogik ist vollständig in `src/helpers.php` in der Funktion `wartung_status_from_rest()` zentralisiert.
Alle drei Seiten (Dashboard, Übersicht, Detail) delegieren dorthin.

**Zentralisierte Funktion `wartung_status_from_rest()` in `src/helpers.php`:**

```
Parameter: $restHours, $intervalHours, $soonRatio, $soonHours

1) $restHours === null
   → "Neu/Unbekannt" (ui-badge)

2) $restHours < 0
   → "Überfällig" (ui-badge--danger)

3) Bald-Prüfung:
   $soonHours = wartung_normalize_soon_hours($soonHours)
   $soonRatio = wartung_normalize_soon_ratio($soonRatio)
   Falls BEIDE null → $soonRatio = 0.20 (Fallback)

   if $soonHours !== null:
     isSoon = ($restHours <= $soonHours)
   else:
     limit = ($intervalHours > 0) ? $intervalHours * $soonRatio : 0
     isSoon = ($intervalHours > 0) ? ($restHours <= $limit) : false
   if isSoon → "Bald fällig" (ui-badge--warn)

4) sonst → "OK" (ui-badge--ok)
```

**In `module/wartungstool/punkt.php` (Detail-Seite):**

Ruft `wartung_status_from_rest($restVal, $planInterval, $soonRatio, $soonHours)` auf.
Bei Rückgabe `type='new'` wird das Label auf `'Nicht initialisiert'` überschrieben.

**In `module/wartungstool/dashboard.php`:**

Ruft `wartung_status_from_rest($rest, $interval, $wp['soon_ratio'], $wp['soon_hours'])` auf.

**In `module/wartungstool/uebersicht.php`:**

Ruft `wartung_status_from_rest($restHours, $interval, $soonRatio, $soonHours)` auf,
wobei `$soonRatio` und `$soonHours` zuvor via `wartung_normalize_soon_ratio()` / `wartung_normalize_soon_hours()` normalisiert wurden.

**Normalisierungsfunktionen in `src/helpers.php`:**
- `wartung_normalize_soon_ratio(?float $ratio): ?float` — gibt NULL zurück wenn `<= 0` oder `> 1`
- `wartung_normalize_soon_hours(?float $hours): ?float` — gibt NULL zurück wenn `<= 0`

---

## 4) soon_ratio & soon_hours – Spezialprüfung

### Felder in `wartungstool_wartungspunkt`

(Quelle: `docs/db_schema_v2.sql:298–300`)

```sql
-- soon_hours gewinnt vor soon_ratio  (Kommentar in db_schema_v2.sql:298)
soon_ratio DOUBLE DEFAULT NULL,
soon_hours DOUBLE DEFAULT NULL,
```

### Fallback-Wert `0.20`

Der Fallback `0.20` (20 % des Intervalls) ist **einmalig** in `src/helpers.php` in `wartung_status_from_rest()` definiert.
Alle drei Seiten (Dashboard, Übersicht, Detail) rufen diese Funktion auf und erben den Fallback.

| Datei | Kontext |
|---|---|
| `src/helpers.php` | `wartung_status_from_rest()` — einzige Definition des Fallback-Werts |

Bedingung für Fallback: `$soonHours === null && $soonRatio === null`

### Abweichungen zwischen den Seiten

| Prüfung | `dashboard.php` | `uebersicht.php` | `punkt.php` |
|---|:---:|:---:|:---:|
| `soon_ratio` → `ratioLimit` | ✅ | ✅ | ✅ |
| Fallback `0.20` | ✅ | ✅ | ✅ |
| `soon_hours` (absolut) | ✅ | ✅ | ✅ |
| `rest === null` → Keine Punkte | ✅ (als "Neu/Unbekannt") | ✅ (als "Neu/Unbekannt") | ✅ (als "Nicht initialisiert") |
| Dashboard-spezifisch: zeigt alle aktiven WP (zeit + produktiv) | ✅ | — | — |

**Fazit:** Die gesamte `soon_hours`/`soon_ratio`/Fallback-Logik ist in `src/helpers.php::wartung_status_from_rest()` zentralisiert und wird von allen drei Seiten korrekt aufgerufen.

---

## 5) Dashboard: Besonderheiten

(Quelle: `module/wartungstool/dashboard.php:17–25`, `module/wartungstool/dashboard.php:103–130`)

- Das Dashboard zeigt **alle** aktiven WP aller Assets (sowohl `zeit` als auch `produktiv`):
  ```sql
  WHERE wp.aktiv = 1 AND a.aktiv = 1
  ORDER BY a.name ASC, wp.text_kurz ASC
  ```
- Kein `LIMIT 1` per Asset, kein Typ-Filter auf `intervall_typ`.

> **TODO:** Dashboard-Darstellung umstellen auf:
> - Pro Asset nur **eine Zeile** anzeigen.
> - Wartungspunkte mit demselben Fälligkeitszeitpunkt gruppieren.
> - Trendberechnung (basierend auf `core_runtime_agg_day`) wieder einbauen.

### Filter & Scope

(Quelle: `module/wartungstool/dashboard.php:17–25`)

- Scope-Filter: `?scope=heute|woche|alle` – begrenzt nach Reststunden (heute ≤ 8h, woche ≤ 40h, alle unbegrenzt)
- Status-Filter: `?f=all|due|soon|ok|new|planned` (GET-Parameter)
- Suche `?q=...` (serverseitig, sucht in Code/Name/Kategorie/WP)
- KPI-Row: `?kpi=1` blendet eine klickbare KPI-Übersichtszeile (Überfällig/Bald/OK/Gesamt) ein
- Geplant-Flag: `?f=planned` zeigt alle WP mit `planned_at IS NOT NULL`

### Trend-Berechnung (TODO – noch nicht implementiert)

> **TODO:** Trendberechnung in `dashboard.php` noch einzubauen.  
> Datenbasis: `core_runtime_agg_day` (letzte 28 Tage)

```
h14_new = SUM(run_seconds)/3600 der letzten 14 Tage
h14_old = SUM(run_seconds)/3600 der 14 Tage davor

Trend:
  ▲ steigend: h14_new > h14_old * 1.10  (>+10%)
  ▼ fallend:  h14_new < h14_old * 0.90  (<-10%)
  ➝ stabil:  sonst

wochenschnitt = h28 / 4   (Stunden/Woche Durchschnitt über 4 Wochen)
```

---

## 6) Übersicht: Besonderheiten

(Quelle: `module/wartungstool/uebersicht.php:33–50`)

- Zeigt **alle** WP eines Assets (`zeit` und `produktiv`)
- Filter: `?mode=offen|alle` – „offen" = überfällig + bald fällig + unbekannt
  (Quelle: `module/wartungstool/uebersicht.php:43–49`)
- Default-Asset: erstes in der nach Kritikalität sortierten Liste
  (Quelle: `module/wartungstool/uebersicht.php:27–30`)

---

## 7) Admin: Wartungspunkte anlegen/bearbeiten

(Quelle: `module/wartungstool/admin_punkte.php`)

- Erfordert `require_can_edit('wartungstool', 'global')` → HTTP 403 ohne Schreibrecht
- `soon_hours` wird via `clamp_soon_hours()` validiert (Zeile 33)
- CSV-Import unterstützt: `soon_hours`, `soon_ratio`, `text_lang` als optionale Spalten  
  (Quelle: `module/wartungstool/admin_punkte.php:341, 369–385`)
- Kopieren von WP eines anderen Assets möglich (`action=copy_from_asset`)
- Alle CRUD-Aktionen werden in `core_audit_log` protokolliert

---

## 8) Protokoll speichern

(Quelle: `module/wartungstool/punkt_save.php`)

Beim Speichern eines Wartungsprotokolls:

1. INSERT in `wartungstool_protokoll` (Zeile 86–99)
2. UPDATE `wartungstool_wartungspunkt`:
   - bei `produktiv`: `letzte_wartung = core_runtime_counter.productive_hours`
   - bei `zeit`: `datum = NOW()`
   (Quelle: `punkt_save.php:107–112`)
3. `audit_log(wartungstool, protokoll, CREATE, ...)` + `audit_log(wartungstool, wartungspunkt, UPDATE, ...)`  
   (Quelle: `punkt_save.php:114, 124`)
4. Optional: Wenn der User die Checkbox „Ticket erzeugen" aktiviert (`create_ticket=1`), wird ein Ticket in `stoerungstool_ticket` angelegt.
   Bei Messwert-Grenzwertüberschreitung setzt JavaScript die Checkbox automatisch vor; der Nutzer kann diese jedoch deaktivieren.  
   (Quelle: `punkt_save.php:28, 127–199`)

---

Ende.
