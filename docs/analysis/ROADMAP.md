# ROADMAP – Priorisierte Aufgaben & First PRs

> Stand: 2026-02-24 · Analysiert von Copilot Coding Agent  
> Basis: Code-Analyse + Projektkontext aus `docs/PRIJECT_CONTEXT_v2.md` + `RISKS.md`

---

## Priorisierungs-Schema

| Priorität | Kriterium |
|---|---|
| P1 – Kritisch | Sicherheitslücke oder Datenverlustrisiko |
| P2 – Hoch | Produktive Nutzung gefährdet, Betriebsstabilität |
| P3 – Mittel | UX, Code-Qualität, erkennbare Schwachstellen |
| P4 – Niedrig | Nice-to-have, zukünftige Skalierung |

---

## Aufgaben nach Priorität

### P1 – Kritisch (sofort angehen)

#### ✅ TASK-01: `hash.php` aus Repository entfernen *(erledigt – PR-18)*

**Risiko:** Klartext-Passwort `b3k78k0b` in versionierter Datei  
**Aufwand:** XS (< 30 Minuten)  
**Dateien:** `hash.php`  
**Vorschlag:**
```bash
git rm hash.php
echo "hash.php" >> .gitignore
```
**Referenz:** `RISKS.md` → S-1

**Status:** `hash.php` ist nicht im Repository vorhanden; Eintrag in `.gitignore` verhindert versehentliches Einchecken.

---

#### ✅ TASK-02: `tools/runtime_rollup.php` vor Webzugriff schützen *(erledigt – PR-18)*

**Risiko:** Beliebige Aggregations-Manipulation + DB-Last via GET-Requests  
**Aufwand:** XS  
**Dateien:** `tools/runtime_rollup.php`  
**Vorschlag:** CLI-Guard am Dateianfang:
```php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
```
Alternativ/zusätzlich: Webserver-Konfiguration sperrt `GET /tools/`.  
**Referenz:** `RISKS.md` → S-2

**Status:** CLI-Guard ist in Zeile 2 implementiert. Direkter Webzugriff liefert HTTP 403.

---

### P2 – Hoch (kurzfristig, nächster Sprint)

#### TASK-03: Brute-Force-Schutz auf Login

**Risiko:** Unbegrenzte Passwort-Rate auf `login.php`  
**Aufwand:** S  
**Dateien:** `login.php`, `src/auth.php`, ggf. neue Tabelle `core_login_attempt`  
**Vorschlag:**
- Fehlversuche je IP + Benutzername in DB oder APCu zählen
- Nach 5 Fehlversuchen innerhalb 10 Minuten: 429 + 60s Wartezeit
- Alternativ: Fail2ban auf Webserver-Ebene für `/login.php`

---

#### TASK-04: Uploads-Verzeichnis vor Direktzugriff schützen

**Risiko:** Dokumente ohne Login abrufbar  
**Aufwand:** S–M  
**Dateien:** `src/helpers.php` (neuer Download-Controller), `uploads/.htaccess`  
**Vorschlag:**
1. `uploads/.htaccess` mit `Deny from all` anlegen
2. Neuen Route-Key `dokument.download` registrieren → PHP-Controller mit `require_login()` + `readfile()`
3. Download-Links in `module/stoerungstool/ticket.php` auf neuen Controller umlenken

---

#### TASK-05: Veraltete `function_exists()`-Guards entfernen

**Risiko:** Falscher Fallback `true` bei fehlendem Include → unbeabsichtigter Zugriff  
**Aufwand:** XS  
**Datei:** `module/wartungstool/punkt.php` (Zeilen 13–14)  
**Vorschlag:**
```php
// Vorher
$canDoWartung = function_exists('user_can_edit') ? user_can_edit($userId, 'wartungstool', 'global', null) : true;
// Nachher
$canDoWartung = user_can_edit($userId, 'wartungstool', 'global', null);
```
**Referenz:** `RISKS.md` → Q-3

---

#### ✅ TASK-06: Whitelist-Validierung in `user_can_flag()` *(erledigt – PR-18)*

**Risiko:** SQL-Column-Injection bei zukünftigen Aufrufen  
**Aufwand:** XS  
**Datei:** `src/auth.php`  
**Vorschlag:**
```php
function user_can_flag(?int $userId, ?string $modul, ?string $objektTyp, $objektId, string $flagCol): bool {
    $allowed = ['darf_sehen', 'darf_aendern', 'darf_loeschen'];
    if (!in_array($flagCol, $allowed, true)) return false;
    // ... rest unverändert
}
```
**Referenz:** `RISKS.md` → S-5

**Status:** Allowlist `['darf_sehen', 'darf_aendern', 'darf_loeschen']` ist in `user_can_flag()` implementiert. Ungültige Spaltennamen werden abgelehnt (return false).

---

### P3 – Mittel (mittelfristig, folgende Sprints)

#### TASK-07: N+1-Queries im Dashboard optimieren

**Aufwand:** M  
**Datei:** `module/wartungstool/dashboard.php`  
**Vorschlag:** `berechneDashboard()` durch eine kombinierte Query ersetzen:
- `core_runtime_counter` per JOIN vorladen
- `core_runtime_agg_day` per GROUP BY auf alle Asset-IDs auf einmal aggregieren
- Fälligsten Wartungspunkt per Subquery oder separatem Bulk-Query vorladen

**Referenz:** `RISKS.md` → Q-1

---

#### TASK-08: Entwicklungs-Artefakte bereinigen

**Aufwand:** XS  
**Dateien:** `create.bat`, `Erzeuge`, `Done`  
**Vorschlag:**
```bash
git rm create.bat Erzeuge Done
```
Kein funktionaler Nutzen. Erhöht Übersichtlichkeit und Professionalität.

---

#### TASK-09: Ticket-Status als zentrale Konstante verwalten

**Aufwand:** S  
**Dateien:** `src/helpers.php`, `module/stoerungstool/inbox.php`, `module/stoerungstool/ticket.php`  
**Vorschlag:** In `src/helpers.php`:
```php
const TICKET_STATUS_LABELS = [
    'neu'         => ['cls' => 'badge--r', 'label' => 'neu'],
    'angenommen'  => ['cls' => 'badge--y', 'label' => 'angenommen'],
    'in_arbeit'   => ['cls' => 'badge--y', 'label' => 'in Arbeit'],
    'bestellt'    => ['cls' => 'badge--y', 'label' => 'bestellt'],
    'erledigt'    => ['cls' => 'badge--g', 'label' => 'erledigt'],
    'geschlossen' => ['cls' => '',         'label' => 'geschlossen'],
];
```
Doppelten `badge_for()`-Code in `inbox.php` und `ticket.php` entfernen.

---

#### TASK-10: Barrierefreiheit (A11Y) – Quick Wins

**Aufwand:** S  
**Datei:** `src/layout.php`, `src/css/main.css`  
Folgende Maßnahmen haben niedrigen Aufwand und hohe Wirkung:

1. Skip-to-Content Link in `src/layout.php` (vor `<aside>`)
2. `id="main-content"` auf `<main>` setzen
3. `:focus-visible` Outline in `src/css/main.css`
4. `aria-label` auf `<nav>` und `<aside>`
5. `scope="col"` auf alle Tabellen-Header

**Referenz:** `RISKS.md` → A-1, A-3, A-4, A-5

---

#### TASK-11: Dokumentationsdateinamen korrigieren (Tippfehler)

**Aufwand:** XS  
**Dateien:** `docs/PRIJECT_CONTEXT.md`, `docs/PRIJECT_CONTEXT_v2.md`  
**Vorschlag:**
```bash
git mv docs/PRIJECT_CONTEXT.md docs/PROJECT_CONTEXT.md
git mv docs/PRIJECT_CONTEXT_v2.md docs/PROJECT_CONTEXT_v2.md
```
Alle internen Links + `README.md`-Referenzen anpassen.

---

### P4 – Niedrig / Zukünftige Verbesserungen

#### TASK-12: Composer + PSR-4 Autoloading einführen

**Aufwand:** M–L  
**Begründung:** Ermöglicht Drittanbieter-Bibliotheken (PHPUnit, Monolog, phpdotenv) und sauberere Code-Struktur.  
**Referenz:** `RISKS.md` → M-1

---

#### TASK-13: PHPUnit-Tests einführen

**Aufwand:** M (initial Setup) + ongoing  
**Priorität:** Nach Composer (TASK-12)  
**Erste Testfälle:**
- `user_can_see()` – Permission-Logik mit Wildcard
- `user_can_flag()` – Whitelist-Validierung
- `split_interval_by_day()` – Tagessplitting im Rollup
- `handle_upload()` – MIME-Validierung

---

#### TASK-14: SLA-Felder in `stoerungstool_ticket`

**Aufwand:** S (DB-Migration + Backfill + Auto-Set)  
**Felder:** `first_response_at`, `closed_at`  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md` → Next 4

---

#### TASK-15: CSV-Export (Audit / ISO)

**Aufwand:** M  
**Features:**
- Tickets mit Zeitraum, Reaktionszeiten, Durchlaufzeiten
- Wartungsprotokolle mit Abweichungen  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md` → Next 5

---

#### TASK-16: Dokumente an Wartungspunkten

**Aufwand:** S  
**Tabelle:** `core_dokument` mit `referenz_typ='wartungspunkt'`  
**Dateien:** `module/wartungstool/punkt.php`, ggf. neuer Upload-Handler  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md` → Next 3

---

#### TASK-17: Störung UX „Shopfloor Level 2"

**Aufwand:** M  
**Features:**
- Inbox Quick-Filter per Status-Badge-Klick
- Ticket Timeline-Ansicht aus Aktionen
- Standardtexte / Templates für Aktionen  
**Referenz:** `docs/PRIJECT_CONTEXT_v2.md` → Next 2

---

## 3 Empfohlene „First PRs"

Diese drei Pull Requests sind bewusst klein, in sich abgeschlossen und sofort umsetzbar. Sie liefern sofortigen Mehrwert ohne Regressions-Risiko.

---

### First PR #1 – `fix: Sicherheits-Cleanup (hash.php, rollup guard, function_exists guards)`

**Umfang:**
- `git rm hash.php` + `.gitignore`-Eintrag
- CLI-Guard in `tools/runtime_rollup.php`
- `function_exists()`-Guards in `module/wartungstool/punkt.php` entfernen
- Whitelist in `user_can_flag()` (3 Zeilen)

**Betroffene Dateien:**
- `hash.php` (entfernen)
- `tools/runtime_rollup.php` (2 Zeilen)
- `module/wartungstool/punkt.php` (2 Zeilen)
- `src/auth.php` (3 Zeilen)
- `.gitignore` (1 Zeile)

**Warum:** Alle vier Maßnahmen sind P1/P2, XS-Aufwand, kein funktionaler Impact, kein Migrationsaufwand.

---

### First PR #2 – `feat: A11Y Quick Wins (Skip-Link, Focus-Outline, ARIA-Labels, Tabellen-Scope)`

**Umfang:**
- Skip-to-Content Link in `src/layout.php`
- `id="main-content"` auf `<main>`
- `aria-label` auf `<aside>` und `<nav>`
- `:focus-visible` Outline in `src/css/main.css`
- `scope="col"` auf Tabellen-Header in `dashboard.php` und `inbox.php`

**Betroffene Dateien:**
- `src/layout.php` (6 Zeilen)
- `src/css/main.css` (5 Zeilen)
- `module/wartungstool/dashboard.php` (6 Zeilen Tabellen-Header)
- `module/stoerungstool/inbox.php` (6 Zeilen Tabellen-Header)

**Warum:** Reines Markup/CSS, kein funktionaler Code, kein Regressions-Risiko. Verbessert die Barrierefreiheit spürbar für Tastatur- und Screenreader-Nutzer.

---

### First PR #3 – `refactor: Ticket-Status zentralisieren + badge_for() deduplizieren`

**Umfang:**
- `TICKET_STATUS_LABELS` Konstante in `src/helpers.php`
- `badge_for()` in `module/stoerungstool/inbox.php` durch Zentral-Lookup ersetzen
- Duplizierten `badge_for()`-Code in `module/stoerungstool/ticket.php` entfernen

**Betroffene Dateien:**
- `src/helpers.php` (ca. 15 neue Zeilen)
- `module/stoerungstool/inbox.php` (badge_for-Funktion ersetzt, ~8 Zeilen)
- `module/stoerungstool/ticket.php` (badge_for-Funktion ersetzt, ~8 Zeilen)

**Warum:** Reduziert Duplizierung, zentralisiert die einzige Quelle für Status-Labels/Farben, verhindert Inkonsistenz bei neuen Status-Werten (z.B. TASK-14 SLA). Regressions-Risiko: niedrig, da rein intern.

---

## Gesamtübersicht

| Task | Priorität | Aufwand | Kategorie |
|---|---|---|---|
| ~~TASK-01: hash.php entfernen~~ ✅ | P1 | XS | Security |
| ~~TASK-02: rollup CLI-Guard~~ ✅ | P1 | XS | Security |
| TASK-03: Brute-Force-Schutz Login | P2 | S | Security |
| TASK-04: Uploads-Schutz | P2 | S–M | Security |
| TASK-05: function_exists Guards entfernen | P2 | XS | Quality |
| ~~TASK-06: user_can_flag Whitelist~~ ✅ | P2 | XS | Security |
| TASK-07: N+1 Dashboard optimieren | P3 | M | Performance |
| TASK-08: Dev-Artefakte bereinigen | P3 | XS | Maintainability |
| TASK-09: Ticket-Status zentralisieren | P3 | S | Quality |
| TASK-10: A11Y Quick Wins | P3 | S | Accessibility |
| TASK-11: Tippfehler Doku-Dateien | P3 | XS | Maintainability |
| TASK-12: Composer einführen | P4 | M–L | Maintainability |
| TASK-13: PHPUnit einführen | P4 | M | Quality |
| TASK-14: SLA-Felder Ticket | P4 | S | Feature |
| TASK-15: CSV-Export | P4 | M | Feature |
| TASK-16: Dokumente an Wartungspunkten | P4 | S | Feature |
| TASK-17: Störung UX Level 2 | P4 | M | Feature |
