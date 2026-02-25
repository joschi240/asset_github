# RISKS.md - Asset KI (Stand: 2026-02-25)

Risikobewertung auf Basis einer Code-Analyse des aktuellen `main`-Stands.  
Abkürzungen: **P0** = kritisch/sofort, **P1** = hoch/kurzfristig, **P2** = mittel/mittelfristig.

---

## P0 - Kritisch

### R-01 - CSRF-Check fehlt in `logout.php`

**Problem:** `logout.php` akzeptiert GET-Requests ohne CSRF-Schutz. Ein Angreifer kann mit einem einfachen `<img src=".../logout.php">` einen Login-CSRF (Forced Logout) auslösen.

**Betroffene Dateien:** `logout.php`

**Auswirkung:** Jeder Benutzer kann ohne Interaktion aus der Session geworfen werden (Denial-of-Service auf Session-Ebene). Bei kombinierten Angriffen ist Re-Login auf fremdes Konto möglich.

**Gegenmaßnahme:** Logout auf POST umstellen, CSRF-Token prüfen. Alternativ kurze Nonce als GET-Parameter (`logout.php?tok=<nonce>`) mit Session-Vergleich.

---

### R-02 - Kein Rate-Limiting auf `login.php`

**Problem:** `login.php` prüft Passwörter ohne Begrenzung der Versuche. Brute-Force-Angriffe auf `core_user.passwort_hash` (bcrypt) sind möglich.

**Betroffene Dateien:** `login.php`, `src/auth.php` (`login()`)

**Auswirkung:** Offline-Brute-Force wird durch bcrypt erschwert, Online-Brute-Force (direkter HTTP-POST) ist unbegrenzt möglich.

**Gegenmaßnahme:** Fehlschlag-Zähler pro IP und/oder Benutzername in DB oder Session speichern; nach N Fehlversuchen temporärer Lockout (z. B. `failed_login_count + locked_until` in `core_user`).

---

### R-03 - `config.php` darf nicht per HTTP erreichbar sein

**Problem:** Die Konfigurationsdatei (`src/config.php`) enthält DB-Zugangsdaten, Ingest-Token und CSRF-Key. Wenn der Webserver PHP nicht ausführt oder ein Misconfiguration vorliegt, kann die Datei als Plaintext ausgegeben werden.

**Betroffene Dateien:** `src/config.php` (wird von `src/config.default` erzeugt)

**Auswirkung:** Vollständige Kompromittierung (DB-Credentials, Token).

**Gegenmaßnahme:** `src/` außerhalb des Document-Root verschieben (Annahme: aktuell im Webroot) ODER `.htaccess`/Nginx-Regel hinzufügen, die direkten HTTP-Zugriff auf `src/` und `docs/` blockt.

---

## P1 - Hoch

### R-04 - `uploads/` ohne Download-Kontrolle

**Problem:** Das Upload-Verzeichnis (`uploads/`) liegt per Default direkt im Projektroot (s. `src/config.default`: `'base_dir' => __DIR__ . '/../uploads'`). Hochgeladene Dateien sind damit direkt per HTTP abrufbar - ohne Auth-Prüfung.

**Betroffene Dateien:** `src/helpers.php` (`handle_upload`), `module/stoerungstool/melden.php`, `module/stoerungstool/ticket.php`

**Auswirkung:** Alle hochgeladenen Dokumente (Fotos, PDFs) sind öffentlich lesbar, sobald der Pfad bekannt ist (`stoerungstool/tickets/<id>/<stored>`). Der `stored`-Name ist `date('Ymd_His') + bin2hex(random_bytes(8))` - nicht rätselbar, aber kein Auth-Schutz.

**Gegenmaßnahme:** `uploads/` außerhalb des Document-Root legen und Downloads über einen PHP-Download-Controller (`download.php`) mit Permission-Check ausliefern. Alternativ `.htaccess` `Deny from all` in `uploads/`.

---

### R-05 - `stoerung.melden` ist komplett öffentlich - kein Spam-Schutz

**Problem:** `module/stoerungstool/melden.php` erfordert keinen Login (`require_login=0`). Jeder kann beliebig viele Tickets anlegen, optional mit Datei-Upload.

**Betroffene Dateien:** `module/stoerungstool/melden.php`

**Auswirkung:** Spam-Flood der `stoerungstool_ticket`-Tabelle und des Upload-Verzeichnisses; DoS auf DB und Filesystem möglich.

**Gegenmaßnahme:** Honeypot-Feld, serverseitiges Rate-Limiting pro IP (z. B. max. N Tickets pro Stunde per `created_at` + `ip`), optional CAPTCHA.

---

### R-06 - Duplicate-Code: `upload_ticket_file()` und `upload_first_ticket_file()`

**Problem:** Die Upload-Logik ist nahezu identisch in `module/stoerungstool/ticket.php` (`upload_ticket_file`) und `module/stoerungstool/melden.php` (`upload_first_ticket_file`) dupliziert. `src/helpers.php` stellt bereits `handle_upload()` bereit, wird aber von den Modul-Dateien nicht genutzt.

**Betroffene Dateien:** `module/stoerungstool/ticket.php`, `module/stoerungstool/melden.php`, `src/helpers.php`

**Auswirkung:** Sicherheitspatch in einem File muss manuell in beiden Files nachgezogen werden (Drift-Risiko).

**Gegenmaßnahme:** Modul-Upload-Funktionen auf `handle_upload()` aus `src/helpers.php` umstellen oder eine gemeinsame Hilfsfunktion `store_ticket_dokument(int $ticketId, array $file): void` extrahieren.

---

### R-07 - Fehlende `telemetry`-Konfiguration führt zu Fatal Error

**Problem:** `tools/runtime_ingest.php` greift auf `$cfg['telemetry']['ingest_token']` zu. Die Musterdatei `src/config.default` enthält keinen `telemetry`-Abschnitt. Bei fehlendem Schlüssel produziert PHP einen Warning oder Fatal Error.

**Betroffene Dateien:** `tools/runtime_ingest.php`, `src/config.default`

**Auswirkung:** Telemetrie-Ingest schlägt mit einem Fehler fehl, statt einen konfigurierten Fehler auszugeben; ein Admin sieht eventuell PHP-Warnings in der HTTP-Response (Information Disclosure).

**Gegenmaßnahme:** `telemetry`-Block mit Pflichtkommentar in `src/config.default` ergänzen:
```php
'telemetry' => [
  'ingest_token' => '',          // PFLICHT: starkes Secret setzen
  'max_clock_skew_sec' => 300,
],
```

---

### R-08 - Keine HTTP-Security-Header

**Problem:** Weder `app.php`, `login.php` noch `tools/` senden Security-Header (Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy).

**Betroffene Dateien:** `src/layout.php` (`render_header`), `login.php`

**Auswirkung:** Erhöhtes Risiko für XSS-Persistenz, Clickjacking, MIME-Sniffing.

**Gegenmaßnahme:** Mindest-Header in `render_header()` einmal zentral setzen:
```php
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
```
Content-Security-Policy als mittelfristiges Ziel (erfordert Inline-Style-Prüfung).

---

## P2 - Mittel

### R-09 - Kein Fehlerhandling für `db()` / fehlende `config.php`

**Problem:** Wenn `src/config.php` fehlt (z. B. nach frischem Clone), liefert `require __DIR__ . '/config.php'` in `src/db.php` einen Fatal Error ohne Benutzer-freundliche Meldung.

**Betroffene Dateien:** `src/db.php`, `src/auth.php`

**Auswirkung:** Unverständliche Fehlermeldung / White Page für den Admin beim ersten Setup.

**Gegenmaßnahme:** In `db()` prüfen ob `config.php` existiert, andernfalls lesbare Anleitung ausgeben:
```php
if (!file_exists($configPath)) {
  die('config.php fehlt. Kopiere src/config.default nach src/config.php und trage DB-Zugangsdaten ein.');
}
```

---

### R-10 - Legacy `src/menu.php` nicht mehr verwendet

**Problem:** `src/menu.php` definiert eine ältere `load_menu_tree()`-Funktion ohne Parameter. Die aktuelle Version ist in `src/helpers.php` implementiert (mit `$menuName`-Parameter und Legacy-Kompatibilität). `src/menu.php` wird laut Code-Analyse nicht mehr included.

**Betroffene Dateien:** `src/menu.php`

**Auswirkung:** Potenzielle Verwirrung; bei versehentlichem Include könnte die Funktion überschrieben werden (obwohl `if (!function_exists(...))` fehlt hier - würde Fatal Error auslösen, wenn `helpers.php` vorher geladen wurde).

**Gegenmaßnahme:** `src/menu.php` entfernen oder mit `@deprecated`-Kommentar versehen.

---

### R-11 - `core_audit_log` wird nur punktuell genutzt

**Problem:** `audit_log()` ist in `src/helpers.php` implementiert, wird aber nur in `module/wartungstool/punkt_save.php` aktiv genutzt (Annahme basierend auf Code-Analyse). Das Störungstool (`ticket.php`) und Admin-Module schreiben bei Status-/Datenänderungen keinen Audit-Eintrag.

**Betroffene Dateien:** `module/stoerungstool/ticket.php`, `module/admin/users.php`, `module/admin/permissions.php`

**Auswirkung:** Fehlende Nachvollziehbarkeit bei Statuswechseln, Benutzerverwaltungs-Aktionen und Rechteänderungen; Compliance-Risiko (ISO-Anforderungen).

**Gegenmaßnahme:** `audit_log()` systematisch bei allen INSERT/UPDATE/DELETE in den Modul-Views aufrufen. Priorität: `stoerungstool/ticket.php` (Status-Workflow), `admin/users.php`, `admin/permissions.php`.

---

### R-12 - `password_hash` ohne expliziten Algorithmus und Kosten-Faktor

**Problem:** `password_hash($pw, PASSWORD_DEFAULT)` nutzt den PHP-Default-Algorithmus (aktuell bcrypt, `cost=10`). Bei künftigen PHP-Versionen könnte `PASSWORD_DEFAULT` auf Argon2 wechseln, was zu Migration-Problemen führen kann (wenn `password_needs_rehash` nicht verwendet wird).

**Betroffene Dateien:** `module/admin/setup.php`, `module/admin/users.php`

**Auswirkung:** Niedrig bei aktueller PHP-Version; mittelfristig bei PHP-Upgrade ohne Rehash-Mechanismus.

**Gegenmaßnahme:** Explizit `PASSWORD_BCRYPT` mit `['cost' => 12]` setzen und `password_needs_rehash()` beim Login prüfen.

---

### R-13 - Keine Datenbankmigrationshistorie / kein Schema-Versions-Mechanismus

**Problem:** Das Schema liegt in `docs/db_schema_v2.sql` (IF NOT EXISTS, idempotent). Es gibt eine Migrations-Datei `docs/db_migration_permissions_v1.sql`. Ein formales Migrationsframework oder Versions-Tracking fehlt.

**Betroffene Dateien:** `docs/db_schema_v2.sql`, `docs/db_migration_permissions_v1.sql`

**Auswirkung:** Bei Weiterentwicklung ist unklar, welche Schemaversion ein Produktivsystem hat. Additive Migrationen können in falscher Reihenfolge eingespielt werden.

**Gegenmaßnahme:** Migrations-Tabelle `core_migration` einführen (id, filename, applied_at) und ein einfaches CLI-Script `tools/migrate.php` zum geordneten Einspielen nummerierter SQL-Dateien.
