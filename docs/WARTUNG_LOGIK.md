# WARTUNG_LOGIK.md – Fälligkeitsberechnung und Ampel-Logik

Stand: 2026-02-28  
Quellen: `module/wartungstool/dashboard.php`, `uebersicht.php`, `punkt.php`

---

## 1) Intervall-Typen

`wartungstool_wartungspunkt.intervall_typ` ist ein ENUM mit zwei Werten:

| Wert        | Bedeutung                                                    |
|-------------|--------------------------------------------------------------|
| `zeit`      | Zeitbasiertes Intervall (Stunden = Kalenderzeit)             |
| `produktiv` | Produktivstunden-basiertes Intervall (aus `core_runtime_counter`) |

---

## 2) Fälligkeitsberechnung

### 2.1 Typ `produktiv`

```
dueAt     = letzte_wartung + plan_interval   (in Produktivstunden)
restHours = dueAt - core_runtime_counter.productive_hours
```

- `letzte_wartung`: Produktivstunden zum Zeitpunkt der letzten Wartung
- `productive_hours`: aktueller Gesamtstand aus `core_runtime_counter`
- `restHours < 0` → überfällig

### 2.2 Typ `zeit`

```
dueTs     = UNIX_TIMESTAMP(datum) + plan_interval * 3600
restHours = (dueTs - NOW()) / 3600
```

- `datum`: Zeitstempel der letzten Wartung
- `plan_interval`: Intervall in Stunden
- `restHours < 0` → überfällig

---

## 3) Ampel-Logik

Die Ampel berechnet sich aus `restHours` und `plan_interval`:

```
ratio = restHours / plan_interval
```

| Bedingung            | Ampelfarbe | Badge-Klasse | Label          |
|----------------------|------------|--------------|----------------|
| `restHours < 0`      | Rot        | `badge--r`   | Überfällig     |
| `ratio <= 0.20`      | Gelb       | `badge--y`   | Bald fällig    |
| `ratio > 0.20`       | Grün       | `badge--g`   | OK             |
| `restHours = null`   | —          | —            | Neu/Unbekannt  |

**`soon_ratio` Default:** `0.20` (= 20 % des Intervalls)

Beispiel: Intervall 500 h → Ampel gelb wenn weniger als 100 h verbleiben.

> **Hinweis:** Es gibt kein `soon_hours`-Feld in der DB. Die Grenze ist ausschließlich
> `soon_ratio = 0.20` und gilt überall gleich (Dashboard, Übersicht, Detailseite).

---

## 4) Konsistenz Dashboard / Übersicht / Detailseite

Alle drei Views verwenden dieselbe Logik (`ratio <= 0.20` für gelb):

| View                          | Funktion           | soon_ratio | soon_hours |
|-------------------------------|--------------------|:----------:|:----------:|
| `dashboard.php` `ampel_for()` | `ratio <= 0.20`    | ✅ 0.20    | ❌ n/a     |
| `uebersicht.php` `ampel_from_rest()` | `ratio <= 0.20` | ✅ 0.20 | ❌ n/a |
| `punkt.php` (inline)          | `ratio <= 0.20`    | ✅ 0.20    | ❌ n/a     |

Die Logik ist **konsistent** über alle drei Views.

---

## 5) Dashboard-Besonderheit (nur produktiv)

Das Dashboard (`dashboard.php`) zeigt nur **produktivstunden-basierte** Wartungspunkte für
die Fälligkeitsprognose:

```sql
SELECT wp.id, wp.text_kurz, wp.plan_interval, wp.letzte_wartung
FROM wartungstool_wartungspunkt wp
WHERE wp.asset_id=? AND wp.aktiv=1
  AND wp.intervall_typ='produktiv'      -- NUR produktiv!
  AND wp.letzte_wartung IS NOT NULL
ORDER BY (wp.letzte_wartung + wp.plan_interval) ASC
LIMIT 1
```

**Zeitbasierte Wartungspunkte** werden im Dashboard **nicht** angezeigt (kein nächster
Punkt, keine KW-Prognose). Die Übersichts- und Detailseite zeigen beide Typen.

---

## 6) Prognose (nur Dashboard)

Zusätzlich zur Ampel zeigt das Dashboard eine KW-Prognose:

```
wochenschnitt = (Summe run_seconds letzte 28 Tage) / 3600 / 4
weeksLeft     = restHours / wochenschnitt
daysLeft      = round(weeksLeft * 7)
dueDate       = NOW() + daysLeft Tage
kw            = date('W', dueDate)  →  "KW NN"
```

- `core_runtime_agg_day` ist Datenquelle für den 28-Tage-Schnitt
- Bei `wochenschnitt = 0` wird kein KW ausgegeben (`—`)

---

## 7) Protokoll – Rückwirkungseffekt auf Fälligkeit

Nach einem Wartungsdurchgang (`punkt_save.php`) wird `letzte_wartung` / `datum` aktualisiert:

- Typ `produktiv`: `letzte_wartung = aktuelle productive_hours`
- Typ `zeit`: `datum = NOW()`

Das Datum der letzten Wartung wird damit neu gesetzt und die Fälligkeit neu berechnet.

---

Ende.
