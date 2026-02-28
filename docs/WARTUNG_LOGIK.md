# WARTUNG_LOGIK.md – Wartungslogik & soon_ratio Verifikation

> Repo-verifiziert. Stand: 2026-02-28.  
> Jede Aussage mit Fundstelle. Keine Annahmen.

---

## 1) Überblick

Das Wartungstool unterstützt zwei Intervalltypen:

| `intervall_typ` | Basis | Quelle |
|---|---|---|
| `'produktiv'` | Produktivstunden (`core_runtime_counter.productive_hours`) | `module/wartungstool/punkt.php:77–97` |
| `'zeit'` | Wallclock-Zeit (`time()`, Basis: `datum`) | `module/wartungstool/punkt.php:99–120` |

---

## 2) Fälligkeit berechnen

### Produktiv-Intervall (`intervall_typ='produktiv'`)

(Quelle: `module/wartungstool/punkt.php:77–97`)

```
Voraussetzung: wp.letzte_wartung IS NOT NULL AND wp.plan_interval > 0

dueAt [in Produktivstunden] = wp.letzte_wartung + wp.plan_interval
rest  [in Produktivstunden] = dueAt - core_runtime_counter.productive_hours
```

`productive_hours` kommt aus `core_runtime_counter` (wird vom Rollup befüllt).  
(Quelle: `tools/runtime_rollup.php`)

### Zeit-Intervall (`intervall_typ='zeit'`)

(Quelle: `module/wartungstool/punkt.php:99–120`)

```
Voraussetzung: wp.datum IS NOT NULL AND wp.plan_interval > 0

dueTs [Unix-Timestamp] = strtotime(wp.datum) + round(wp.plan_interval * 3600)
rest  [in Stunden]     = (dueTs - time()) / 3600
```

---

## 3) Statuslogik (Ampel)

### Status-Bestimmung (vollständig, exakt)

**In `module/wartungstool/punkt.php:50–124` (Detail-Seite):**

```
Initialisierung:
  soonHours = wp.soon_hours wenn > 0, sonst NULL           (Zeile 52, 58)
  soonRatio = wp.soon_ratio wenn > 0 und <= 1, sonst NULL  (Zeile 53–57)
  Falls BEIDE null → soonRatio = 0.20 (Fallback)            (Zeile 74)

Status:
  1) Kein Startwert (datum/letzte_wartung fehlt)
     → "Nicht initialisiert"                                 (Zeile 122–123)

  2) rest < 0
     → "Überfällig" (ui-badge--danger)                       (Zeile 87–88)

  3) Bald-Prüfung:
     if soonHours !== null:
       isSoon = (rest <= soonHours)                          (Zeile 91–92)
     else:
       isSoon = (rest <= planInterval * soonRatio)           (Zeile 94)
     if isSoon → "Bald fällig" (ui-badge--warn)              (Zeile 96)

  4) sonst → "OK" (ui-badge--ok)
```

**In `module/wartungstool/dashboard.php:41–58` (Dashboard, Funktion `ui_badge_for()`):**

```
  1) rest === null → "Keine Punkte"
  2) rest < 0     → "Überfällig"
  3) ratioLimit = soonRatio wenn > 0, sonst 0.20 (Fallback)  (Zeile 49–51)
     ratio = rest / interval (wenn interval > 0, sonst 1.0)  (Zeile 53)
     if ratio <= ratioLimit → "Bald fällig"                   (Zeile 55–56)
  4) sonst → "OK"

  HINWEIS: soon_hours wird NICHT berücksichtigt.
```

**In `module/wartungstool/uebersicht.php:34–50` (Übersicht, Funktion `ampel_from_rest()`):**

```
  1) restHours === null → "Neu/Unbekannt"
  2) restHours < 0     → "Überfällig"
  3) ratioLimit = soonRatio wenn > 0, sonst 0.20 (Fallback)  (Zeile 37)
     ratio = restHours / intervalHours                         (Zeile 38)
     if ratio <= ratioLimit → "Bald fällig"                   (Zeile 39)
  4) sonst → "OK"

  HINWEIS: soon_hours wird NICHT berücksichtigt.
```

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

Der Fallback `0.20` (20 % des Intervalls) ist an drei unabhängigen Stellen definiert:

| Datei | Zeile | Kontext |
|---|---|---|
| `module/wartungstool/dashboard.php` | 51 | `ui_badge_for()` |
| `module/wartungstool/uebersicht.php` | 37 | `ampel_from_rest()` |
| `module/wartungstool/punkt.php` | 74 | inline (Detail-Seite) |

Bedingung für Fallback (alle drei Stellen): `soonRatio === null` oder `soonRatio <= 0`

### Abweichungen zwischen den Seiten

| Prüfung | `dashboard.php` | `uebersicht.php` | `punkt.php` |
|---|:---:|:---:|:---:|
| `soon_ratio` → `ratioLimit` | ✅ | ✅ | ✅ |
| Fallback `0.20` | ✅ | ✅ | ✅ |
| `soon_hours` (absolut) | ❌ **nicht** | ❌ **nicht** | ✅ |
| `rest === null` → Keine Punkte | ✅ | ✅ (als "Neu/Unbekannt") | ✅ (als "Nicht initialisiert") |
| Dashboard-spezifisch: zeigt nur produktiv-WP | ✅ (`intervall_typ='produktiv'`) | — | — |

**Fazit:** `soon_hours` wird nur in `punkt.php` (Detail-Seite) korrekt berücksichtigt. Dashboard und Übersicht ignorieren `soon_hours`.

> TODO: Logik vollständig zentralisieren.  
> (Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Wartungssystem – Bald-fällig Logik")

---

## 5) Dashboard: Besonderheiten

(Quelle: `module/wartungstool/dashboard.php:135–197`)

- Das Dashboard zeigt pro Asset **nur den nächst fälligen produktiv-basierten** WP:
  ```sql
  WHERE wp.aktiv=1 AND wp.intervall_typ='produktiv' AND wp.letzte_wartung IS NOT NULL
  ORDER BY (wp.letzte_wartung + wp.plan_interval) ASC
  LIMIT 1
  ```
- Zeit-basierte WP erscheinen **nicht** in der Dashboard-Haupttabelle

### Trend-Berechnung

(Quelle: `module/wartungstool/dashboard.php:90–133`)

```
Datenbasis: core_runtime_agg_day (letzte 28 Tage)

h14_new = SUM(run_seconds)/3600 der letzten 14 Tage
h14_old = SUM(run_seconds)/3600 der 14 Tage davor

Trend:
  ▲ steigend: h14_new > h14_old * 1.10  (>+10%)
  ▼ fallend:  h14_new < h14_old * 0.90  (<-10%)
  ➝ stabil:  sonst

wochenschnitt = h28 / 4   (Stunden/Woche Durchschnitt über 4 Wochen)
```

### Filter & KPI-Karten

(Quelle: `module/wartungstool/dashboard.php:17–24`, `module/wartungstool/dashboard.php:327–342`)

- Filter `?f=all|due|soon|critical` (GET-Parameter)
- Suche `?q=...` (clientseitig in PHP, sucht in Code/Name/Kategorie/WP)
- KPI-Karten: überfällig, bald fällig, kritische Assets, alle Assets – klickbar, setzen `?f`

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

1. INSERT in `wartungstool_protokoll` (Zeile 87–99)
2. UPDATE `wartungstool_wartungspunkt`:
   - bei `produktiv`: `letzte_wartung = core_runtime_counter.productive_hours`
   - bei `zeit`: `datum = NOW()`
   (Quelle: `punkt_save.php:110–112`)
3. `audit_log(wartungstool, protokoll, CREATE, ...)` + `audit_log(wartungstool, wartungspunkt, UPDATE, ...)`  
   (Quelle: `punkt_save.php:115, 125`)
4. Optional: Bei Status `abweichung` kann Ticket in `stoerungstool_ticket` angelegt werden  
   (Quelle: `punkt_save.php:156–170`)

---

Ende.
