# PERMISSIONS_GUIDE.md (Rechtesystem + Best Practices + Seeds + Debug)

> Repo-verifiziert. Stand: 2026-02-28.

## Upload: empfohlene Server-Einstellungen
(Quelle: Konfigurationsempfehlung für php.ini / Webserver)

- `upload_max_filesize = 20M`
- `post_max_size = 25M`
- (nginx) `client_max_body_size 20M;`

Diese Werte sollten in der php.ini und ggf. im Webserver gesetzt werden, damit große Bilder und PDFs hochgeladen werden können.

---

Stand: 2026-02-28 (aktualisiert; ursprünglich: 24.02.2026)  
Ziel: In neuen Chats/Modulen schnell korrekt Rechte setzen, 403 sauber debuggen, und konsistente `modul/objekt_typ` Konventionen nutzen.

> **Hinweis (2026-02-28):** Das System hat zwei parallele Permission-APIs:
> - `user_can_see()` / `user_can_edit()` / `user_can_delete()` / `require_can_edit()` – in `src/helpers.php` + `src/auth.php` (primär, von `app.php` genutzt)
> - `can()` / `user_permissions()` / `require_permission()` – in `src/permission.php` (vereinfachte Alternative ohne `objekt_typ`)
> 
> Für neue Module: primäre API via `src/auth.php` verwenden.
> (Quelle: `src/helpers.php:56–76`, `src/auth.php:113–184`, `src/permission.php`)

---

## 1) Quelle der Wahrheit

`app.php` lädt `core_route` und prüft dann zentral:

- `require_login` (wenn 1 → `require_login()`)
- Permission: `user_can_see(user_id, route.modul, route.objekt_typ, route.objekt_id)`

**Konsequenz:**  
Wenn eine Seite 403 wirft, ist fast immer:
- `core_route.modul/objekt_typ/objekt_id` passt nicht zu `core_permission`, **oder**
- `user_can_see` mappt/normalisiert anders als erwartet.

---

## 2) DB-Struktur `core_permission` (minimal)

Tabelle: `core_permission`

Felder:
- `user_id` (int)
- `modul` (varchar) — z.B. `wartungstool`, `stoerungstool`, `admin`, oder `*`
- `objekt_typ` (varchar) — z.B. `global`, `dashboard`, `users`, `routes`, … oder `*`
- `objekt_id` (int, nullable) — NULL = globaler Scope
- `darf_sehen` (0/1)
- `darf_aendern` (0/1)
- `darf_loeschen` (0/1)

Wildcard:
- `modul='*'` und/oder `objekt_typ='*'` existiert im System (im Dump: Admin user 3 hat `*/*`).

---

## 3) Konventionen für neue Routen (Best Practice)

### 3.1 Robuster Default
Für „normale“ Seiten innerhalb eines Moduls:

- `modul = <modulname>`
- `objekt_typ = 'global'`
- `objekt_id = NULL`

Beispiele (DB-validiert):
- `stoerung.inbox` → `modul='stoerungstool'`, `objekt_typ='global'`
- `wartung.uebersicht` → `modul='wartungstool'`, `objekt_typ='global'`

Warum?
- weniger Permission-Fragilität
- schnelleres Setup
- weniger Spezialfälle bei neuen Seiten

### 3.2 Spezialfälle (fein granulare Rechte)
Wenn du wirklich fein granulare Rechte willst:

- `objekt_typ` als Feature-Namen (z.B. `dashboard`, `admin_punkte`, `inbox`)
- Dann müssen Permissions exakt dazu passen.

**Wichtig:**  
Mischbetrieb führt leicht zu 403 (Route `global`, Permission `inbox`).

---

## 4) Empfehlung für dein aktuelles System (konkret)

### 4.1 Konsistente Linie wählen
**Option A (einfach/robust):**
- Alle Modul-Routen mit `objekt_typ='global'`
- Rechte pro Modul über `modul + global`

**Option B (feiner/granular):**
- Jede Route bekommt eigenen `objekt_typ`
- Dann braucht jede Route explizite Permissions

**Empfehlung:** Option A für Shopfloor/Lean MVP, Option B später.

---

## 5) Seeds: Standard-Rechte (Copy/Paste SQL)

> Achtung: Passe `user_id` an (im Dump: 3=admin, 4=instandhaltung).

### 5.1 Instandhaltung (sehen + ändern, nicht löschen)
```sql
-- permissions_seed_instandhaltung.sql
INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
VALUES
(4, 'wartungstool',  'global', NULL, 1, 1, 0),
(4, 'stoerungstool', 'global', NULL, 1, 1, 0)
ON DUPLICATE KEY UPDATE
darf_sehen=VALUES(darf_sehen),
darf_aendern=VALUES(darf_aendern),
darf_loeschen=VALUES(darf_loeschen);
```

### 5.2 Viewer (nur sehen)
```sql
-- permissions_seed_viewer.sql
INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
VALUES
(<USER_ID>, 'wartungstool',  'global', NULL, 1, 0, 0),
(<USER_ID>, 'stoerungstool', 'global', NULL, 1, 0, 0)
ON DUPLICATE KEY UPDATE
darf_sehen=VALUES(darf_sehen),
darf_aendern=VALUES(darf_aendern),
darf_loeschen=VALUES(darf_loeschen);
```

### 5.3 Admin (Wildcard Vollzugriff)
```sql
-- permissions_seed_admin_wildcard.sql
INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
VALUES
(3, '*', '*', NULL, 1, 1, 1)
ON DUPLICATE KEY UPDATE
darf_sehen=VALUES(darf_sehen),
darf_aendern=VALUES(darf_aendern),
darf_loeschen=VALUES(darf_loeschen);
```

---

## 6) Debug bei 403 (Checkliste)

### 6.1 Route-Definition prüfen
```sql
SELECT route_key, modul, objekt_typ, objekt_id, require_login, aktiv, file_path
FROM core_route
WHERE route_key = '<ROUTE_KEY>'
LIMIT 1;
```

### 6.2 User-Permissions prüfen
```sql
SELECT *
FROM core_permission
WHERE user_id = <USER_ID>
ORDER BY modul, objekt_typ, objekt_id;
```

### 6.3 Typische Ursachen
- Permission hat `objekt_typ='inbox'`, Route hat `objekt_typ='global'`
- Permission existiert nur für `wartungstool/dashboard`, aber Route ist `wartungstool/global`
- `darf_sehen=0`
- User nicht eingeloggt (Route require_login=1) → Login/Redirect
- Route deaktiviert (`aktiv=0`) → dann 404, nicht 403

---

## 7) Vorgehen bei neuen Modulen (Template)

Wenn ein neues Modul (z.B. `ersatzteile_*`) kommt:
1) DB: Tabellen mit Prefix `ersatzteile_*`
2) Routen: `core_route` Einträge (Best Practice: `objekt_typ='global'`)
3) Menü: `core_menu_item` via `route_key`
4) Rechte: `core_permission` Seeds
5) Audit: Änderungen → `core_audit_log` via `audit_log(...)`

---

## 8) Empfehlung für Produktionsbetrieb
- Zwei Profile reichen oft:
  - Viewer (nur sehen)
  - Instandhaltung (sehen + ändern, kein löschen)
- Löschen selten: lieber deaktivieren/soft-delete (audit-sicher).

---

Ende.
