# ROADMAP.md – Asset KI (Stand: 2026-02-25)

Priorisierte Task-Liste auf Basis der Codeanalyse und Risikoidentifikation.  
**P0** = kritisch/sofort · **P1** = hoch/kurzfristig · **P2** = mittel/mittelfristig

---

## P0 – Kritisch (sofort angehen)

### T-01 · Logout via POST + CSRF absichern
- **What:** `logout.php` auf POST umstellen und CSRF-Token prüfen (vgl. R-01)
- **Where:** `logout.php`, alle Templates mit Logout-Link (`src/layout.php` Sidebar)
- **DoD:** GET `logout.php` gibt 405 zurück; nur POST mit gültigem CSRF-Token zerstört Session
- **Risk:** Login-CSRF / Forced Logout (R-01)
- **Effort:** XS (1–2h)
- **Suggested PR:** `fix(auth): enforce POST + CSRF for logout`

---

### T-02 · Rate-Limiting für Login
- **What:** Fehlversuche zählen; nach N Fehlschlägen IP/User temporär sperren (vgl. R-02)
- **Where:** `src/auth.php` (`login()`), `core_user` (neue Spalten: `failed_login_count`, `locked_until`)
- **DoD:** Nach 5 Fehlversuchen binnen 15 min ist Account 15 min gesperrt; `locked_until` wird in `login()` geprüft; nach erfolgreichem Login Reset auf 0
- **Risk:** Online-Brute-Force auf Login (R-02)
- **Effort:** S (2–4h inkl. DB-Migration)
- **Suggested PR:** `feat(auth): add login rate limiting`

---

### T-03 · Security-Header in render_header() setzen
- **What:** Mindest-HTTP-Security-Header zentral hinzufügen (vgl. R-08)
- **Where:** `src/layout.php` (`render_header()`), `login.php`
- **DoD:** Alle Responses senden: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`
- **Risk:** XSS-Persistenz, Clickjacking (R-08)
- **Effort:** XS (1h)
- **Suggested PR:** `fix(security): add HTTP security headers`

---

### T-04 · `telemetry`-Konfiguration in `config.default` ergänzen
- **What:** Fehlenden `telemetry`-Block in `src/config.default` nachziehen (vgl. R-07)
- **Where:** `src/config.default`
- **DoD:** `config.default` enthält `telemetry` mit `ingest_token`, `max_clock_skew_sec`; `runtime_ingest.php` nutzt `$cfg['telemetry']['ingest_token'] ?? ''` mit fallback
- **Risk:** Fatal Error bei fehlendem Schlüssel (R-07)
- **Effort:** XS (<1h)
- **Suggested PR:** `fix(config): add telemetry block to config.default`

---

## P1 – Hoch

### T-05 · Download-Controller für `uploads/` (Auth-geschützter Datei-Zugriff)
- **What:** PHP-Download-Controller `download.php` einführen; `uploads/` per .htaccess sperren (vgl. R-04)
- **Where:** neues `download.php`, `src/helpers.php`, `module/stoerungstool/ticket.php` (Dok-Links), `.htaccess`
- **DoD:** Direkter HTTP-Zugriff auf `uploads/` liefert 403; Datei-Download nur über `download.php?id=<dok_id>` mit Session- und Permissions-Prüfung; `core_dokument.dateiname` bleibt relativ zu `base_dir`
- **Risk:** Öffentlicher Datei-Zugriff ohne Auth (R-04)
- **Effort:** M (4–6h)
- **Suggested PR:** `feat(security): add authenticated download controller`

---

### T-06 · Spam-Schutz für öffentliches Melde-Formular
- **What:** Rate-Limiting und Honeypot-Feld für `stoerung.melden` (vgl. R-05)
- **Where:** `module/stoerungstool/melden.php`
- **DoD:** Max. 5 Ticket-Einreichungen pro IP pro Stunde (DB-Abfrage auf `stoerungstool_ticket.created_at + IP`); Honeypot-Feld (Hidden-Input) führt zu stiller Ablehnung; Upload im Formular optional mit Größenbegrenzung
- **Risk:** Spam-Flood / DoS (R-05)
- **Effort:** S (2–4h)
- **Suggested PR:** `feat(stoerung): add rate limiting and honeypot to melden`

---

### T-07 · Upload-Duplikation eliminieren
- **What:** `upload_ticket_file()` und `upload_first_ticket_file()` auf gemeinsame Hilfsfunktion reduzieren (vgl. R-06)
- **Where:** `module/stoerungstool/ticket.php`, `module/stoerungstool/melden.php`, optional `src/helpers.php`
- **DoD:** Eine Funktion `store_ticket_dokument(int $ticketId, array $fileArr, int $userId = 0): void` in `src/helpers.php`; beide Modul-Files nutzen diese Funktion
- **Risk:** Sicherheits-Drift bei Patches (R-06)
- **Effort:** S (2–3h)
- **Suggested PR:** `refactor(upload): extract shared store_ticket_dokument helper`

---

### T-08 · Audit-Log für Störungstool + Admin lückenlos machen
- **What:** `audit_log()` in allen kritischen Schreibpfaden aufrufen (vgl. R-11)
- **Where:** `module/stoerungstool/ticket.php` (Statuswechsel, Aktionen), `module/admin/users.php` (create/update/disable), `module/admin/permissions.php` (set/delete)
- **DoD:** Jeder INSERT/UPDATE/DELETE der genannten Dateien schreibt einen Eintrag in `core_audit_log` (modul, entity_type, entity_id, action, old_json, new_json)
- **Risk:** Fehlende Compliance-Nachvollziehbarkeit (R-11)
- **Effort:** M (4–6h)
- **Suggested PR:** `feat(audit): complete audit_log coverage for stoerung + admin`

---

### T-09 · SLA-Felder in `stoerungstool_ticket`
- **What:** `first_response_at` und `closed_at` als DATETIME-Spalten hinzufügen; automatisch bei Statusübergang setzen
- **Where:** `docs/db_schema_v2.sql` (oder neue Migrations-SQL), `module/stoerungstool/ticket.php`
- **DoD:** `first_response_at` wird gesetzt, wenn Status von `neu` auf `angenommen` wechselt (und noch NULL); `closed_at` beim Übergang zu `erledigt`/`geschlossen`; Migration-Script vorhanden
- **Risk:** Fehlende SLA-Messung, Compliance
- **Effort:** S (3–4h)
- **Suggested PR:** `feat(stoerung): add SLA timestamps first_response_at + closed_at`

---

### T-10 · `password_hash` explizit mit Algorithmus + Rehash-Check
- **What:** Expliziten bcrypt-Cost-Faktor setzen; `password_needs_rehash()` beim Login prüfen (vgl. R-12)
- **Where:** `module/admin/setup.php`, `module/admin/users.php`, `src/auth.php` (`login()`)
- **DoD:** Alle `password_hash`-Aufrufe nutzen `PASSWORD_BCRYPT, ['cost' => 12]`; in `login()` nach erfolgreichem Verify: `password_needs_rehash()` prüfen und ggf. Hash-Update ausführen
- **Risk:** Hash-Kompatibilität bei PHP-Upgrade (R-12)
- **Effort:** XS (1–2h)
- **Suggested PR:** `fix(auth): explicit bcrypt cost + rehash check on login`

---

## P2 – Mittel

### T-11 · Legacy `src/menu.php` entfernen
- **What:** Ungenutzte Datei aus dem Repo löschen (vgl. R-10)
- **Where:** `src/menu.php`
- **DoD:** `src/menu.php` ist gelöscht; kein `require`/`include` in anderen Dateien zeigt darauf
- **Risk:** Verwirrung, versehentlicher Include
- **Effort:** XS (<30min)
- **Suggested PR:** `chore(cleanup): remove unused src/menu.php`

---

### T-12 · Einfaches DB-Migrations-Framework einführen
- **What:** Migrations-Tabelle `core_migration` + CLI-Script `tools/migrate.php` (vgl. R-13)
- **Where:** `tools/migrate.php`, `docs/migrations/` (neue SQL-Dateien nummeriert)
- **DoD:** `tools/migrate.php` prüft `core_migration`, spielt ausstehende Dateien aus `docs/migrations/` in Reihenfolge ein, trägt Filename + Timestamp ein; bestehende Schema-Dateien als Migration 001/002 dokumentiert
- **Risk:** Unkontrolliertes Schema-Drift im Produktivbetrieb (R-13)
- **Effort:** M (4–6h)
- **Suggested PR:** `feat(infra): add simple migration framework`

---

### T-13 · Konfigurationscheck beim Bootstrap (fehlende config.php)
- **What:** Lesbare Fehlermeldung wenn `src/config.php` fehlt (vgl. R-09)
- **Where:** `src/db.php` oder `src/auth.php`
- **DoD:** Wenn `config.php` nicht existiert → klare HTML-Meldung mit Setup-Anleitung statt PHP-Fatal; Produktiv-Umgebungen zeigen generische Meldung
- **Risk:** White Page beim ersten Setup (R-09)
- **Effort:** XS (1h)
- **Suggested PR:** `fix(bootstrap): friendly error when config.php missing`

---

### T-14 · Inbox: Quick-Filter per Status-Badge-Klick
- **What:** Status-Badges in der Inbox sind anklickbar und setzen `?status=<value>` als Filter
- **Where:** `module/stoerungstool/inbox.php`
- **DoD:** Klick auf Badge in der Tabelle navigiert zu `inbox.php?status=<value>`; aktiver Filter wird optisch hervorgehoben
- **Risk:** UX-Qualität
- **Effort:** S (2–3h)
- **Suggested PR:** `feat(stoerung): clickable status filter badges in inbox`

---

### T-15 · CSV-Export für Wartungs- und Störungsberichte
- **What:** CSV-Export für Tickets (Zeitraum, Reaktionszeiten) und Protokolle (Abweichungen) als Admin-Funktion
- **Where:** neue Routes `wartung.export` und `stoerung.export` in `module/`
- **DoD:** Download eines CSV mit Spalten-Header, UTF-8 BOM, korrektem MIME-Type; Permission-Prüfung `darf_aendern`; Route in `core_route` und Menü eingetragen
- **Risk:** Fehlende ISO-Reporting-Fähigkeit
- **Effort:** M (4–6h)
- **Suggested PR:** `feat(report): CSV export for maintenance and incident reports`

---

## First 3 PRs (klein & reviewbar)

| PR | Branch | Scope | Effort |
|---|---|---|---|
| 1 | `fix/logout-csrf` | T-01: Logout POST + CSRF | XS |
| 2 | `fix/security-headers` | T-03: Security-Header in render_header + T-04: config.default telemetry | XS |
| 3 | `fix/auth-rate-limit` | T-02: Login Rate-Limiting (+ DB-Migration) | S |

Diese drei PRs schließen die beiden P0-Sicherheitsthemen und den fehlenden Konfigurations-Block in weniger als einem Arbeitstag ab.
