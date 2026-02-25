# RISKS – Sicherheit, Qualität, Wartbarkeit & Barrierefreiheit

> Stand: 2026-02-25 · Analysiert von Copilot Coding Agent (vollständige Neuanalyse)  
> Basis: Quellcode-Analyse aller Dateien in `src/`, `module/`, `tools/`, `login.php`, `app.php`

Bewertung: 🔴 P0 Kritisch · 🟠 P1 Hoch · 🟡 P2 Mittel · 🟢 Positiv / kein Handlungsbedarf

---

## 1. Sicherheit (Security)

### 🔴 P0-S-1 – Uploads ohne Authentifizierung abrufbar

**Dateien:** `module/stoerungstool/ticket.php` (Zeilen 410–414), `module/stoerungstool/melden.php`  
**Stelle:** `<a href="<?= e($base) ?>/uploads/<?= e($d['dateiname']) ?>" ...>`

Hochgeladene Dokumente liegen unter `uploads/` und sind direkt über die URL `<base>/uploads/<pfad>` abrufbar – ohne Login-Prüfung. Wer den Pfad kennt oder errät, kann fremde Schadensdokumentationen und Fotos einsehen.

Pfade folgen dem Muster `stoerungstool/tickets/<id>/<datum>_<8 zufällige Bytes>.<ext>`. Die `<id>` ist eine sequenzielle Zahl (erratbar). Bei bekannter Ticket-ID sind Uploads in linearer Enumeration erreichbar.

**Auswirkung:** Datenvertraulichkeit verletzt (Fotos, PDFs, Mängelkarten).

**Gegenmaßnahme:**
1. `uploads/.htaccess` mit `Deny from all` anlegen (Apache).
2. Neuen Download-Controller erstellen (z.B. `module/stoerungstool/download.php` oder Route `dokument.download`), der `require_login()` + `user_can_see()` prüft und dann mit `readfile()` ausliefert.
3. Alle Download-Links in `ticket.php` auf den Controller umlenken.

---

### 🔴 P0-S-2 – Öffentliches Meldeformular ohne Rate-Limiting

**Datei:** `module/stoerungstool/melden.php`  
**Stelle:** Gesamtes POST-Handling; Route `stoerung.melden` mit `require_login=0`

Das Formular ist öffentlich (kein Login erforderlich) und erlaubt beliebig viele Ticket-Einträge ohne Gegenwehr. Ein Angreifer kann innerhalb von Sekunden tausende Dummy-Tickets einschleusen und die Datenbank fluten.

**Auswirkung:** DoS durch DB-Überlastung; Produktive Tickets in Rauschen vergraben.

**Gegenmaßnahme:**
- IP-basiertes Rate-Limiting auf Webserver-Ebene (z.B. nginx `limit_req_zone`).
- Alternativ: PHP-seitiger Throttle via APCu: max. N Tickets pro IP pro Stunde.
- Optional: reCAPTCHA oder ein einfaches CAPTCHA auf `melden.php`.

---

### 🟠 P1-S-3 – Kein Brute-Force-Schutz auf `login.php`

**Datei:** `login.php` (gesamte Login-Logik in `src/auth.php: login()`)

Es gibt kein Rate-Limiting, keine Loginversuch-Zählung und keine Account-Sperrung. Ein Angreifer kann beliebig viele Passwort-Versuche ohne Gegenmassnahme durchführen.

**Auswirkung:** Passwörter sind durch Wörterbuchangriffe kompromittierbar.

**Gegenmaßnahme:**
- Fehlversuche je IP + Benutzername in Tabelle `core_login_attempt` (neu) oder APCu zählen.
- Nach 5 Fehlversuchen in 10 Minuten: HTTP 429 + 60 Sekunden Wartezeit.
- Alternativ: Fail2ban auf Webserver-Ebene für `POST /login.php`.

---

### 🟠 P1-S-4 – Telemetrie-Ingest ohne Rate-Limiting

**Datei:** `tools/runtime_ingest.php`

Der Ingest-Endpoint ist durch einen statischen Token geschützt (`X-INGEST-TOKEN`). Bei bekanntem Token können unbegrenzt viele Bulk-Anfragen gestellt werden. Es gibt kein Request-Rate-Limiting und kein Payload-Size-Limit auf Anwendungsebene.

**Auswirkung:** Bei kompromittiertem Token: DB-Flut mit Rohdaten; `core_runtime_sample` wächst unkontrolliert.

**Gegenmaßnahme:**
- Webserver-seitiges Rate-Limiting (nginx `limit_req`, Apache `mod_ratelimit`).
- Bulk-Limit im PHP-Code:
  ```php
  if (count($samples) > 1000) { http_response_code(400); exit(...); }
  ```
- Token rotierbar halten und aus `config.php` steuern (bereits korrekt implementiert).

---

### 🟡 P2-S-5 – `runtime_rollup.php` liest GET-Parameter in SQL-LIMIT ohne PDO

**Datei:** `tools/runtime_rollup.php`, Zeilen 8–11 und 50, 73

```php
$maxAssets = (int)($_GET['max_assets'] ?? 500);
$limitSamplesPerAsset = (int)($_GET['limit'] ?? 50000);
// ...
$assets = db_all("... LIMIT $maxAssets");
$samples = db_all("... LIMIT $limitSamplesPerAsset", ...);
```

Der Webzugriff ist durch den CLI-Guard (Zeile 2) gesperrt. Da `$_GET` im CLI-Modus leer ist, erhalten die Variablen immer die Defaultwerte. Die SQL-Interpolation ist damit aktuell **nicht** ausnutzbar.

**Latentes Risiko:** Sollte der CLI-Guard versehentlich entfernt werden oder das Skript via PHP-CGI/PHP-FPM aufgerufen werden können, besteht ein Integer-Overflow-Risiko im LIMIT-Ausdruck.

**Gegenmaßnahme:** Variablen vor der SQL-Interpolation auf positive Integer klemmen und mit absolutem Maximum begrenzen:
```php
$maxAssets = max(1, min((int)($_GET['max_assets'] ?? 500), 5000));
```

---

### 🟢 S-Positiv – Bereits korrekt implementiert

| Bereich | Befund | Fundstelle |
|---|---|---|
| SQL-Injection | PDO Prepared Statements überall; kein String-Concat mit Userdaten in WHERE | `src/db.php` |
| XSS | `e()` = `htmlspecialchars(ENT_QUOTES)` konsequent in allen Views | `src/helpers.php: e()` |
| CSRF | `csrf_token()` + `csrf_check()` in allen POST-Formularen | `src/auth.php` |
| Session-Flags | `httponly=true`, `samesite=Lax`, `secure` (bei HTTPS) | `src/auth.php: session_boot()` |
| Passwort-Hashing | `password_hash(PASSWORD_DEFAULT)` + `password_verify()` (bcrypt) | `src/auth.php: login()` |
| Session-Fixation | `session_regenerate_id(true)` nach erfolgreichem Login | `src/auth.php: login()` |
| Pfad-Traversal | `realpath()` + `strpos($file, '..')` Check | `app.php` Zeile 50–60 |
| Column-Whitelist | `user_can_flag()` prüft `$flagCol` gegen Allowlist | `src/auth.php` Zeile 118–119 |
| Setup-Guard | `has_any_user()` verhindert erneuten Admin-Setup | `module/admin/setup.php` |
| Telemetrie-Auth | `hash_equals()` verhindert Timing-Angriffe auf Token-Vergleich | `tools/runtime_ingest.php` |
| Rollup-Web-Schutz | CLI-Guard (`php_sapi_name() !== 'cli'`) → HTTP 403 | `tools/runtime_rollup.php` Zeile 2 |

---

## 2. Datenintegrität (Data Integrity)

### 🟠 P1-D-1 – Ticket-Erstellung ohne Validierung der `asset_id`

**Datei:** `module/stoerungstool/melden.php`, Zeile 56

```php
$assetId = $_POST['asset_id'] !== '' ? (int)$_POST['asset_id'] : null;
```

Es wird geprüft, ob der Wert nicht leer ist, aber **nicht**, ob die Asset-ID tatsächlich in `core_asset` existiert und aktiv ist. Ein Angreifer (oder ein Formularfehler) könnte eine ungültige Asset-ID einschleusen.

**Auswirkung:** `stoerungstool_ticket.asset_id` referenziert ein nicht-existentes Asset. Der FK `fk_ticket_asset` in MariaDB ist `ON DELETE SET NULL` – aber das verhindert nicht das initiale Einfügen ungültiger IDs, wenn FK-Checks aktiv sind. Tatsächlich würde ein ungültiger Wert einen FK-Constraint-Fehler auslösen, aber nur wenn FK-Checks aktiviert sind und der Wert nicht NULL ist.

*Annahme:* Bei deaktivierten FK-Checks (in manchen Hosting-Umgebungen) wäre ein inkonsistenter Eintrag möglich.

**Gegenmaßnahme:**
```php
if ($assetId !== null) {
  $assetCheck = db_one("SELECT id FROM core_asset WHERE id=? AND aktiv=1 LIMIT 1", [$assetId]);
  if (!$assetCheck) { $err = "Ungültige Anlage."; /* ... */ }
}
```

---

### 🟡 P2-D-2 – Keine Bereinigung verwaister `core_dokument`-Einträge

**Dateien:** `module/stoerungstool/melden.php`, `module/stoerungstool/ticket.php`

Hochgeladene Dateien werden in `core_dokument` eingetragen. Wird ein Ticket gelöscht (soweit möglich), bleibt die Datei auf dem Filesystem und der Eintrag in `core_dokument` erhalten (sofern FK `ON DELETE CASCADE` nicht greift – `fk_doc_user` ist `ON DELETE SET NULL`, kein CASCADE auf Ticket).

**Auswirkung:** Datei-Leichen auf dem Filesystem; potenziell datenschutzrelevante Dateien bleiben erhalten.

**Gegenmaßnahme:** Cleanup-Routine implementieren; bei Ticket-Schließung/Löschung zugehörige Dokumente entfernen oder archivieren.

---

## 3. Code-Qualität (Code Quality)

### 🟠 P1-Q-1 – N+1-Queries im Wartungs-Dashboard

**Datei:** `module/wartungstool/dashboard.php`, Funktion `berechneDashboard()` (Zeilen 31–116)

Pro Asset werden **4 separate DB-Queries** ausgeführt:
1. `core_runtime_counter` (Produktivstunden)
2. `core_runtime_agg_day` (28-Tage-Schnitt)
3. `core_runtime_agg_day` (Trend: 14 Tage neu vs. alt)
4. `wartungstool_wartungspunkt` (nächste Fälligkeit)

Bei 20 Assets = 80+ Queries pro Dashboard-Aufruf, plus die initiale Asset-Abfrage.

**Auswirkung:** Langsame Dashboard-Ladezeiten bei wachsendem Asset-Bestand; erhöhte DB-Last.

**Gegenmaßnahme:** Alle 4 Subabfragen in eine kombinierte Abfrage (JOINs + Subqueries) zusammenfassen oder Ergebnisse in einem einzigen Bulk-Query pro Kennzahl über alle Asset-IDs vorladen.

---

### 🟠 P1-Q-2 – Business-Logik-Funktionen inline in View-Dateien

**Dateien:**
- `module/wartungstool/dashboard.php`: `berechneDashboard()`, `ampel_for()`, `renderTable()`
- `module/wartungstool/uebersicht.php`: `ampel_from_rest()`, `is_open_item()`, `extract_ticket_marker()`, `short_text()`
- `module/stoerungstool/inbox.php`: `short_text()`, `fmt_minutes()`

Funktionen werden inline in View-Dateien deklariert. Die Funktion `short_text()` ist sogar in zwei verschiedenen Modulen separat definiert (Duplikat in `uebersicht.php` und `inbox.php`).

**Auswirkung:** Code-Duplizierung, keine Wiederverwendung, nicht testbar.

**Gegenmaßnahme:** Gemeinsame Hilfsfunktionen in `src/helpers.php` konsolidieren. Modul-spezifische Logik in eigene `src/<modul>_helpers.php` auslagern.

---

### 🟡 P2-Q-3 – `function_exists()`-Guards mit unsicherem Fallback

**Datei:** `module/wartungstool/punkt.php`, Zeilen 13–14

```php
$canDoWartung    = function_exists('user_can_edit') ? user_can_edit($userId, 'wartungstool', 'global', null) : true;
$canCreateTicket = function_exists('user_can_edit') ? user_can_edit($userId, 'stoerungstool', 'global', null) : true;
```

Der Fallback `true` bedeutet: Bei einem Fehler im Include-Chain (z.B. wenn `src/auth.php` nicht geladen wird) hat der Benutzer implizit **alle Rechte**.

**Auswirkung:** Potenzielle Privilege-Escalation bei Include-Fehlern.

**Gegenmaßnahme:** Guards entfernen, direkten Aufruf verwenden:
```php
$canDoWartung    = user_can_edit($userId, 'wartungstool', 'global', null);
$canCreateTicket = user_can_edit($userId, 'stoerungstool', 'global', null);
```

---

### 🟡 P2-Q-4 – Hard-kodierte ENUM-Werte an mehreren Stellen

Ticket-Status (`neu`, `angenommen`, `in_arbeit`, `bestellt`, `erledigt`, `geschlossen`) sind sowohl im DB-Schema (ENUM) als auch im PHP-Code in mindestens 4 Dateien als Literalstrings verstreut:
- `module/stoerungstool/inbox.php` (Filter-Logik)
- `module/stoerungstool/ticket.php` (Status-Buttons, Validierung)
- `module/stoerungstool/melden.php` (`'neu'` als Default)
- `module/wartungstool/punkt_save.php` (`'neu'` beim Ticket-Anlegen)
- `src/helpers.php`: `badge_for_ticket_status()` (einzige bereits zentrale Funktion)

**Auswirkung:** Eine neue Status-Stufe erfordert Änderungen an DB + mindestens 4 PHP-Dateien; Inkonsistenz-Risiko.

**Gegenmaßnahme:** Zentrale Konstante in `src/helpers.php`:
```php
const TICKET_STATUS_FLOW = ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'];
```

---

### 🟡 P2-Q-5 – Entwicklungs-Artefakte im Repository

**Dateien:** `create.bat`, `Erzeuge`, `Done` (Projekt-Root)

Diese Dateien haben keinen produktiven Nutzen.

**Auswirkung:** Unklare Zuständigkeiten, unprofessioneller Eindruck, potenzielle Konfusion bei neuen Entwicklern.

**Gegenmaßnahme:**
```bash
git rm create.bat Erzeuge Done
echo "create.bat" >> .gitignore
```

---

### 🟡 P2-Q-6 – Toter Code: `src/menu.php`

**Datei:** `src/menu.php`

Enthält eine veraltete `load_menu_tree()` Funktion (Legacy-Schema). Die produktive Implementierung befindet sich in `src/helpers.php`. `src/menu.php` wird von keiner Produktionsdatei per `require_once` eingebunden (nur theoretisch über das Legacy-Schema aktiv, wenn `core_menu_item` nicht existiert – aber dann würde `src/helpers.php: load_menu_tree()` die Legacy-Variante bereits intern abdecken).

**Auswirkung:** Verwirrung für Entwickler; zwei `load_menu_tree()` Implementierungen im Projekt.

**Gegenmaßnahme:** `src/menu.php` entfernen (nach Verifikation, dass keine externe Einbindung existiert).

---

### 🟢 Q-Positiv – Bereits korrekt implementiert

| Bereich | Befund | Fundstelle |
|---|---|---|
| Output-Escaping | `e()` konsequent in allen Views verwendet | Alle module/*.php |
| DB-Schema | Idempotentes Schema (`IF NOT EXISTS`, kein DROP) | `docs/db_schema_v2.sql` |
| Audit-Coverage | `audit_log()` in Wartungstool + Störungstool | `module/wartungstool/punkt_save.php`, `ticket.php` |
| Transaktionen | Alle Multi-Step-Writes in `beginTransaction()` + `rollBack()` | `punkt_save.php`, `ticket.php`, `admin_punkte.php` |
| Upload-Validierung | MIME via `finfo`, SHA-256, zufällige Dateinamen | `src/helpers.php: handle_upload()` |
| Routing | Zentraler Front-Controller, Path-Traversal-Schutz | `app.php` |
| badge_for_ticket_status | Zentrale Funktion für Status-Labels | `src/helpers.php` |

---

## 4. Wartbarkeit (Maintainability)

### 🟠 P1-M-1 – Kein Dependency-Management (kein Composer)

Das Projekt verwendet kein Composer. Es gibt keine `composer.json`, kein Autoloading und keine Drittanbieter-Bibliotheken.

**Auswirkung:** Jede externe Abhängigkeit (PHPUnit, Monolog, phpdotenv) müsste manuell eingebunden werden. Autoloading fehlt, alle Includes sind manuell.

**Gegenmaßnahme:** Composer einführen, PSR-4-Autoloading aktivieren. Kurzfristig: Alle `require_once`-Aufrufe in einer zentralen Bootstrap-Datei zusammenfassen.

---

### 🟠 P1-M-2 – Keine automatisierten Tests

Es gibt keine Unit-Tests, Integrations-Tests oder End-to-End-Tests.

**Auswirkung:** Refactorings und neue Features können nicht sicher getestet werden; Regressionen nicht erkennbar.

**Gegenmaßnahme:** PHPUnit einführen. Erste Test-Prioritäten:
- `user_can_see()` (Permission-Logik mit Wildcard)
- `user_can_flag()` (Whitelist-Validierung)
- `split_interval_by_day()` (Tagessplitting im Rollup)
- `badge_for_ticket_status()` (Status-Mapping)
- `handle_upload()` (MIME-Validierung, Fehlerbehandlung)

---

### 🟡 P2-M-3 – Keine `.env`-Unterstützung / Konfiguration nur via `config.php`

Konfiguration läuft über `src/config.php`. Es gibt kein `.env`-basiertes System. Deployments in verschiedene Umgebungen (dev/staging/prod) erfordern manuelle Kopien.

**Zusätzlich:** `src/config.php` ist nicht in `.gitignore` aufgelistet (Annahme: es gibt keine `.gitignore`-Überprüfung für diese Datei).

**Gegenmaßnahme:** `vlucas/phpdotenv` einführen oder natives `$_ENV`-basiertes System. Sicherstellen, dass `src/config.php` in `.gitignore` steht.

---

### 🟡 P2-M-4 – Kein strukturiertes Logging

Fehler und Laufzeitinfos werden nur über `echo` ausgegeben (`tools/runtime_rollup.php`). Kein PSR-3-Logger.

**Auswirkung:** Keine zentrale Fehlerübersicht; schwierige Diagnose in Produktion.

**Gegenmaßnahme:** Monolog oder minimales PSR-3-konformes Logging für `tools/`.

---

### 🟡 P2-M-5 – Tippfehler in Dokumentationsdateinamen

**Dateien:** `docs/PRIJECT_CONTEXT.md`, `docs/PRIJECT_CONTEXT_v2.md` (Tippfehler: `PRIJECT` statt `PROJECT`)

**Gegenmaßnahme:**
```bash
git mv docs/PRIJECT_CONTEXT.md docs/PROJECT_CONTEXT.md
git mv docs/PRIJECT_CONTEXT_v2.md docs/PROJECT_CONTEXT_v2.md
```

---

### 🟢 M-Positiv – Bereits korrekt implementiert

| Bereich | Befund |
|---|---|
| Modulares Design | Klare Trennung: `src/` (Core), `module/` (Logik), `tools/` (CLI) |
| DB-getriebenes Routing | Neue Seiten ohne Code-Änderung in `app.php` |
| Additives Schema | DB-Erweiterungen ohne `DROP` (produktionssicher) |
| Konfigurationsvorlage | `src/config.default` als Template vorhanden |
| Audit-Trail | Vollständiger `core_audit_log` mit old/new JSON |

---

## 5. Barrierefreiheit (A11Y)

### 🟡 P2-A-1 – Status-Badges ohne semantisches Role-Attribut

**Dateien:** `module/stoerungstool/inbox.php`, `module/wartungstool/dashboard.php`

Die Ampel-Badges zeigen Text und Farbe. Es fehlt ein semantisches Role-Attribut für Screenreader.

**Gegenmaßnahme:**
```html
<!-- Statt -->
<span class="badge badge--r">neu</span>
<!-- Empfohlen -->
<span class="badge badge--r" role="status">neu</span>
```

---

### 🟡 P2-A-2 – Kein sichtbarer allgemeiner Fokusindikator im CSS

**Datei:** `src/css/main.css`

Das Stylesheet enthält CSS für den Skip-Link (`:focus { top: 0; }`), aber keinen allgemeinen `:focus-visible`-Stil. Browser-Standard-Outline wird durch globale Resets häufig unterdrückt.

**Gegenmaßnahme:**
```css
:focus-visible {
  outline: 3px solid #005fcc;
  outline-offset: 2px;
}
```

---

### 🟡 P2-A-3 – Datentabellen ohne `scope`-Attribute auf `<th>`

**Dateien:** `module/wartungstool/dashboard.php`, `module/stoerungstool/inbox.php`, `module/wartungstool/uebersicht.php`

`<th>`-Elemente haben kein `scope="col"`. Screenreader können Spalten-Header nicht korrekt zuordnen.

**Gegenmaßnahme:** `<th scope="col">` auf alle Spalten-Header setzen.

---

### 🟢 A-Positiv – Bereits korrekt implementiert

| Bereich | Befund | Fundstelle |
|---|---|---|
| Skip-Link | `<a class="skip-link" href="#main-content">` + CSS-Implementierung | `src/layout.php` Zeile 22, `src/css/main.css` |
| main-content ID | `<main class="content" id="main-content" tabindex="-1">` | `src/layout.php` Zeile 81 |
| ARIA auf Navigation | `<nav aria-label="Hauptnavigation">` | `src/layout.php` Zeile 41 |
| Semantisches HTML | `<aside>`, `<nav>`, `<main>`, `<form>`, `<label>` korrekt | `src/layout.php` |
| Labels für Inputs | Alle Inputs haben `<label>`-Elemente | Alle Formular-Views |
| `lang="de"` | Korrekt gesetzt | `src/layout.php` |
| Meta Viewport | `<meta name="viewport" content="width=device-width, initial-scale=1">` | `src/layout.php` |
| Badge-Text | Ampel zeigt immer Text + Farbe | `src/helpers.php: badge_for_ticket_status()` |
