# RISKS – Sicherheit, Qualität, Wartbarkeit & Barrierefreiheit

> Stand: 2026-02-24 · Analysiert von Copilot Coding Agent  
> Basis: Quellcode-Analyse aller Dateien in `src/`, `module/`, `tools/`, `login.php`, `app.php`

Bewertung: 🔴 Kritisch · 🟠 Hoch · 🟡 Mittel · 🟢 Niedrig / Info

---

## 1. Sicherheit (Security)

### 🔴 S-1 – `hash.php` enthält hartkodierten Passwort-Hash im Repository

**Datei:** `hash.php` (Projekt-Root)  
**Inhalt:** `<?php echo password_hash('b3k78k0b', PASSWORD_DEFAULT), PHP_EOL;`

Das Klartextpasswort `b3k78k0b` steht im Repository. Diese Datei ist ein Entwicklungs-Hilfsskript das nie in das Repository hätte eingecheckt werden sollen.

**Maßnahme:** `hash.php` aus dem Repository entfernen (`git rm hash.php`).

---

### 🟠 S-2 – `tools/runtime_rollup.php` ohne Webzugriff-Schutz

**Datei:** `tools/runtime_rollup.php`

Das Rollup-Skript ist ein CLI-/Cron-Tool, aber es ist über den Webserver direkt erreichbar (z.B. `GET /tools/runtime_rollup.php`). Es akzeptiert GET-Parameter, die das Verhalten steuern:

```php
$expectedIntervalSec = (int)($_GET['interval'] ?? 60);
$gapThresholdSec     = (int)($_GET['gap'] ?? ...);
$maxAssets           = (int)($_GET['max_assets'] ?? 500);
$limitSamplesPerAsset= (int)($_GET['limit'] ?? 50000);
```

Ein Angreifer könnte durch wiederholte Requests Datenbank-Last erzeugen (rudimentärer DoS-Vektor) und Aggregations-Parameter manipulieren.

**Maßnahme:** Webzugriff via Apache/Nginx auf das `tools/`-Verzeichnis sperren, oder CLI-Guard am Dateianfang einbauen:

```php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Forbidden'); }
```

---

### 🟠 S-3 – Kein Brute-Force-Schutz auf `login.php`

**Datei:** `login.php`

Es gibt kein Rate-Limiting, keine Loginversuch-Zählung und keine Account-Sperrung. Ein Angreifer kann beliebig viele Passwort-Versuche ohne Gegenmassnahme durchführen.

**Maßnahme:** Login-Fehlversuche in der DB oder einem Cache (z.B. APCu) zählen und nach N Versuchen für X Sekunden sperren. Alternativ: Fail2ban auf Webserver-Ebene konfigurieren.

---

### 🟠 S-4 – Uploads-Verzeichnis ohne Authentifizierungsschutz

**Verzeichnis:** `uploads/`

Hochgeladene Dokumente und Fotos werden direkt unter `uploads/` abgelegt und sind über die URL `<base>/uploads/<dateiname>` erreichbar – **ohne Login-Prüfung**. Ein Angreifer, der den Pfad kennt oder errät, kann fremde Dokumente abrufen.

Da Dateinamen zufällig generiert werden (`bin2hex(random_bytes(8))`), ist direktes Erraten schwierig, aber nicht ausgeschlossen.

**Maßnahme:** Download-Requests für Uploads über einen PHP-Controller leiten, der Login und Permissions prüft. Webserver-Direktzugriff auf `uploads/` sperren:

```apache
# Apache .htaccess in uploads/
Deny from all
```

---

### 🟡 S-5 – `user_can_flag()` fügt Spaltenname ungepuffert in SQL ein

**Datei:** `src/auth.php`, Funktion `user_can_flag()`

```php
$row = db_one(
  "SELECT MAX($flagCol) AS ok
   FROM core_permission ...",
  [...]
);
```

`$flagCol` wird direkt als Spaltenname in den SQL-String interpoliert. Die aufrufenden Funktionen `user_can_edit()` und `user_can_delete()` übergeben ausschließlich Literalwerte (`'darf_aendern'`, `'darf_loeschen'`), weshalb aktuell keine SQL-Injection möglich ist. Die Funktion ist jedoch für zukünftige Aufrufer ohne diese Einschränkung gefährlich.

**Maßnahme:** Whitelist-Validierung in `user_can_flag()` selbst einbauen:

```php
$allowed = ['darf_sehen', 'darf_aendern', 'darf_loeschen'];
if (!in_array($flagCol, $allowed, true)) return false;
```

---

### 🟡 S-6 – Telemetrie-Ingest ohne Rate-Limiting

**Datei:** `tools/runtime_ingest.php`

Der Ingest-Endpoint ist nur durch einen statischen Token geschützt (`X-INGEST-TOKEN`). Bei bekanntem Token können unbegrenzt viele Anfragen gestellt werden (Bulk-Ingest ohne Limit). Es gibt kein Rate-Limiting oder Payload-Size-Limit auf Anwendungsebene.

**Maßnahme:** Webserver-seitiges Rate-Limiting (nginx `limit_req`, Apache `mod_ratelimit`) konfigurieren. Alternativ: Bulk-Größe im PHP-Code deckeln:

```php
if (count($samples) > 1000) { http_response_code(400); exit(...); }
```

---

### 🟢 S-7 – Positive Befunde (keine Maßnahme erforderlich)

| Bereich | Befund |
|---|---|
| SQL Injection | PDO Prepared Statements überall, kein String-Concatenation in WHERE-Clauses mit Userdata |
| XSS | `e()` Helper (`htmlspecialchars` mit `ENT_QUOTES`) konsistent in allen Views |
| CSRF | `csrf_token()` + `csrf_check()` in allen POST-Formularen implementiert |
| Session | `httponly=true`, `samesite=Lax`, `secure` (bei HTTPS) |
| Passwort-Hashing | `password_hash(..., PASSWORD_DEFAULT)` + `password_verify()` (bcrypt) |
| Pfad-Traversal | `realpath()` + `strpos($file, '..')` Check in `app.php` |
| CSRF bei Setup | `csrf_check()` auch in `module/admin/setup.php` vorhanden |
| Setup-Guard | `has_any_user()` verhindert erneuten Setup-Aufruf nach Erstinstallation |
| Audit-Trail | Alle sicherheitsrelevanten Aktionen in `core_audit_log` (ISO-konform) |

---

## 2. Qualität (Code Quality)

### 🟠 Q-1 – N+1-Queries im Wartungs-Dashboard

**Datei:** `module/wartungstool/dashboard.php`, Funktion `berechneDashboard()`

Pro Asset werden **4 separate DB-Queries** ausgeführt (Produktivstunden, 28-Tage-Laufzeit, Trend, Wartungspunkt). Bei 20 Assets = 80+ Queries pro Dashboard-Aufruf.

**Maßnahme:** Subqueries oder JOINs in eine einzige Query pro Asset zusammenfassen, oder Ergebnisse aggregiert vorladen.

---

### 🟠 Q-2 – Business-Logik-Funktionen in View-Dateien definiert

**Dateien:** `module/wartungstool/dashboard.php` (`berechneDashboard`, `ampel_for`, `renderTable`), `module/stoerungstool/inbox.php` (`badge_for`, `short_text`, `fmt_minutes`)

Funktionen werden inline in View-Dateien deklariert. Das verhindert Wiederverwendung und Testbarkeit.

**Maßnahme:** Hilfsfunktionen in `src/helpers.php` auslagern oder eigene Modul-Helper-Dateien anlegen (z.B. `src/wartungstool_helpers.php`).

---

### 🟡 Q-3 – Veraltete `function_exists()`-Guards in `module/wartungstool/punkt.php`

**Datei:** `module/wartungstool/punkt.php`, Zeilen 13–14

```php
$canDoWartung    = function_exists('user_can_edit') ? user_can_edit(...) : true;
$canCreateTicket = function_exists('user_can_edit') ? user_can_edit(...) : true;
```

Diese Guards stammen aus einer früheren Version und sind laut `docs/PRIJECT_CONTEXT_v2.md` (Abschnitt „Next 1") bereits als entfernt markiert – wurden aber noch nicht aus `punkt.php` entfernt. Sie sind irreführend und können bei einem Fehler im Require-Chain ein Sicherheitsloch öffnen (Fallback `true`).

**Maßnahme:** Guards entfernen, direkte Aufrufe verwenden:

```php
$canDoWartung    = user_can_edit($userId, 'wartungstool', 'global', null);
$canCreateTicket = user_can_edit($userId, 'stoerungstool', 'global', null);
```

---

### 🟡 Q-4 – Entwicklungs-Artefakte im Repository

**Dateien:** `hash.php`, `create.bat`, `Erzeuge`, `Done` (Projekt-Root)

Diese Dateien haben keinen produktiven Nutzen und erhöhen die Angriffsfläche oder erzeugen Verwirrung.

**Maßnahme:** `git rm hash.php create.bat Erzeuge Done` + in `.gitignore` aufnehmen.

---

### 🟡 Q-5 – Hard-kodierte ENUM-Werte an mehreren Stellen

Ticket-Status (`neu`, `angenommen`, `in_arbeit`, `bestellt`, `erledigt`, `geschlossen`) sind sowohl im DB-Schema (ENUM) als auch im PHP-Code (`inbox.php`, `ticket.php`) als Literalstrings verstreut. Eine neue Status-Stufe erfordert Änderungen an DB + mehreren PHP-Dateien.

**Maßnahme:** Konstanten-Datei oder einfaches Array in `src/helpers.php` zentralisieren:

```php
const TICKET_STATUS_FLOW = ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'];
```

---

### 🟢 Q-6 – Positive Befunde

| Bereich | Befund |
|---|---|
| Output-Escaping | `e()` konsequent verwendet (kein `echo $var` ohne Escape) |
| DB-Schema | Idempotentes Schema (`IF NOT EXISTS`, kein `DROP`) |
| Audit-Coverage | `audit_log()` in `module/wartungstool/punkt_save.php` und Störungstool-Aktionen |
| CSRF | Alle POST-Formulare haben CSRF-Token |
| Upload-Validierung | MIME-Check via `finfo`, zufällige Dateinamen, SHA-256 |
| Routing | Zentraler Front-Controller verhindert direkten Dateizugriff auf Module |

---

## 3. Wartbarkeit (Maintainability)

### 🟠 M-1 – Kein Dependency-Management (kein Composer)

Das Projekt verwendet kein Composer. Es gibt keine `composer.json`, kein Autoloading und keine Drittanbieter-Bibliotheken über einen Package-Manager.

**Konsequenz:** Jede externe Abhängigkeit müsste manuell eingebunden und aktualisiert werden.

**Maßnahme:** Composer einführen und PSR-4-Autoloading aktivieren. Kurzfristig: explizite `require_once`-Liste in einer Bootstrapdatei zentralisieren.

---

### 🟠 M-2 – Keine automatisierten Tests

Es gibt keine Unit-Tests, Integrations-Tests oder End-to-End-Tests. Refactorings und neue Features können ohne Sicherheitsnetz nicht zuverlässig getestet werden.

**Maßnahme:** PHPUnit einführen. Priorität: Tests für `user_can_see()`, `user_can_flag()`, `audit_log()`, `handle_upload()`, `split_interval_by_day()`.

---

### 🟡 M-3 – Keine Umgebungsvariablen / `.env`-Unterstützung

Konfiguration läuft über `src/config.php` (wird aus `src/config.default` kopiert). Es gibt kein `.env`-basiertes System (z.B. `phpdotenv`). Deployments in verschiedene Umgebungen (dev/staging/prod) sind manuell.

**Maßnahme:** `vlucas/phpdotenv` oder ein natives `$_ENV`-basiertes System einführen. Kurzfristig: Sicherstellen, dass `src/config.php` in `.gitignore` steht (ist es aktuell nicht gelistet).

---

### 🟡 M-4 – Kein strukturiertes Logging

Fehler und Laufzeitinformationen werden nur über PHP-Standardfehler (`echo`) ausgegeben (`runtime_rollup.php`). Kein PSR-3-Logger, kein zentralisiertes Log.

**Maßnahme:** Monolog oder ein einfaches PSR-3-konformes Logging einführen, zumindest für `tools/`.

---

### 🟡 M-5 – SQL-Queries direkt in View-Dateien

Alle Module schreiben SQL-Queries direkt in die View-PHP-Dateien. Es gibt keine Repository-Schicht oder Data-Access-Objects.

**Maßnahme:** Schrittweise Verlagerung von DB-Queries in Modul-Helper-Dateien (kein vollständiges ORM erforderlich).

---

### 🟡 M-6 – Tippfehler in Dokumentationsdateinamen

**Dateien:** `docs/PRIJECT_CONTEXT.md`, `docs/PRIJECT_CONTEXT_v2.md` (Tippfehler: „PRIJECT" statt „PROJECT")

**Maßnahme:** Dateien umbenennen + alle internen Links anpassen. Kein funktionaler Impact, aber erhöht die Professionalität.

---

### 🟢 M-7 – Positive Befunde

| Bereich | Befund |
|---|---|
| Modulares Design | Klare Trennung von Core (`src/`), Modulen (`module/`) und Tools (`tools/`) |
| DB-getriebenes Routing | Neue Seiten ohne Code-Änderungen in `app.php` integrierbar |
| Additives Schema | DB-Schema-Erweiterungen ohne `DROP` (safe für Produktion) |
| Audit-Trail | Vollständiger `core_audit_log` mit old/new JSON |
| Konfigurationsvorlage | `src/config.default` als Template vorhanden |
| Dokumentation | `docs/PRIJECT_CONTEXT_v2.md` als umfassender „Arbeitsvertrag" |

---

## 4. Barrierefreiheit / Accessibility (A11Y)

### 🟡 A-1 – Fehlende ARIA-Attribute auf Navigations-Komponenten

**Datei:** `src/layout.php`

Die Sidebar-Navigation verwendet `<aside>` und `<nav>` (semantisch korrekt), aber es fehlen ARIA-Labels:

```html
<!-- Aktuell -->
<aside class="sidebar">
<nav class="sidebar__nav">

<!-- Empfohlen -->
<aside class="sidebar" aria-label="Hauptnavigation">
<nav class="sidebar__nav" aria-label="Seiten-Navigation">
```

---

### 🟡 A-2 – Status-Badges nutzen Farbe als einzigen Indikator ohne `role` / `aria-label`

**Datei:** `src/css/main.css`, Nutzung in `module/stoerungstool/inbox.php`, `module/wartungstool/dashboard.php`

Die Ampel-Badges (`badge--r`, `badge--y`, `badge--g`) zeigen Text UND Farbe – das ist positiv. Allerdings fehlt ein semantisches Mapping für Screenreader, z.B.:

```html
<!-- Aktuell -->
<span class="badge badge--r">neu</span>

<!-- Empfohlen -->
<span class="badge badge--r" role="status" aria-label="Status: neu (kritisch)">neu</span>
```

---

### 🟡 A-3 – Kein „Skip to Content"-Link

**Datei:** `src/layout.php`

Tastatur-Nutzer und Screenreader-Nutzer müssen die gesamte Sidebar-Navigation für jede Seite durchlaufen, bevor sie zum Hauptinhalt gelangen.

**Maßnahme:** Skip-Link am Anfang des `<body>` einfügen:

```html
<a class="skip-link" href="#main-content">Zum Hauptinhalt springen</a>
...
<main class="content" id="main-content">
```

---

### 🟡 A-4 – Kein sichtbarer Fokusindikator im CSS

**Datei:** `src/css/main.css`

Das Stylesheet definiert keine `:focus`-Styles. Browser-Standard-Outline wird häufig durch `box-sizing: border-box` und globale Resets unterdrückt oder ist optisch zu schwach.

**Maßnahme:**

```css
:focus-visible {
  outline: 3px solid #005fcc;
  outline-offset: 2px;
}
```

---

### 🟡 A-5 – Tabellen ohne `<caption>` und ohne `scope`-Attribute auf `<th>`

**Dateien:** `module/wartungstool/dashboard.php`, `module/stoerungstool/inbox.php`

Datentabellen haben keine `<caption>` und die `<th>`-Elemente haben kein `scope="col"`. Screenreader können Spalten-Header nicht korrekt Zellen zuordnen.

**Maßnahme:**

```html
<table class="table">
  <caption>Wartungs-Dashboard – Aktuelle Fälligkeiten</caption>
  <thead>
    <tr>
      <th scope="col">Ampel</th>
      <th scope="col">Anlage</th>
      ...
```

---

### 🟢 A-6 – Positive Befunde

| Bereich | Befund |
|---|---|
| Semantisches HTML | `<aside>`, `<nav>`, `<main>`, `<form>`, `<label>` korrekt verwendet |
| `<label>` für Formularfelder | Alle Inputs haben `<label>`-Elemente |
| `lang="de"` | HTML-Tag hat korrektes Sprachattribut |
| Meta Viewport | `<meta name="viewport" content="width=device-width, initial-scale=1">` |
| Responsives Layout | CSS Media Query bei 900px für mobile Darstellung |
| Kontrastverhältnisse | Dunkles Sidebar-Design mit ausreichend weißem Text |
| Badges haben Text | Status-Ampel zeigt immer Text (nicht nur Farbe) |
