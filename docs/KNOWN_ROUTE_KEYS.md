# KNOWN_ROUTE_KEYS.md (DB-validiert)

Stand: 24.02.2026  
Quelle: `core_route` Dump (asset_ki)

Format:
- route_key
  - titel
  - file_path
  - modul / objekt_typ / objekt_id
  - require_login
  - sort

---

## Wartung (wartungstool)

- `wartung.dashboard`
  - Titel: Wartung – Dashboard
  - Datei: module/wartungstool/dashboard.php
  - modul/objekt_typ/objekt_id: wartungstool / dashboard / NULL
  - require_login: 1
  - sort: 10

- `wartung.punkt`
  - Titel: Wartungspunkt
  - Datei: module/wartungstool/punkt.php
  - modul/objekt_typ/objekt_id: wartungstool / global / NULL
  - require_login: 1
  - sort: 110

- `wartung.punkt_save`
  - Titel: Wartung speichern
  - Datei: module/wartungstool/punkt_save.php
  - modul/objekt_typ/objekt_id: wartungstool / global / NULL
  - require_login: 1
  - sort: 111

- `wartung.uebersicht`
  - Titel: Wartung – Übersicht
  - Datei: module/wartungstool/uebersicht.php
  - modul/objekt_typ/objekt_id: wartungstool / global / NULL
  - require_login: 1
  - sort: 12

- `wartung.admin_punkte`
  - Titel: Admin – Wartungspunkte
  - Datei: module/wartungstool/admin_punkte.php
  - modul/objekt_typ/objekt_id: wartungstool / global / NULL
  - require_login: 1
  - sort: 200

---

## Störung (stoerungstool)

- `stoerung.melden`
  - Titel: Störung melden
  - Datei: module/stoerungstool/melden.php
  - modul/objekt_typ/objekt_id: stoerungstool / global / NULL
  - require_login: 0
  - sort: 22

- `stoerung.inbox`
  - Titel: Störungen – Inbox
  - Datei: module/stoerungstool/inbox.php
  - modul/objekt_typ/objekt_id: stoerungstool / global / NULL
  - require_login: 1
  - sort: 20

- `stoerung.ticket`
  - Titel: Ticket
  - Datei: module/stoerungstool/ticket.php
  - modul/objekt_typ/objekt_id: stoerungstool / global / NULL
  - require_login: 1
  - sort: 21

---

## Admin (admin)

- `admin.setup`
  - Titel: Setup – Erstuser
  - Datei: module/admin/setup.php
  - modul/objekt_typ/objekt_id: NULL / NULL / NULL
  - require_login: 0
  - sort: 1

- `admin.users`
  - Titel: Admin – Benutzer
  - Datei: module/admin/users.php
  - modul/objekt_typ/objekt_id: admin / users / NULL
  - require_login: 1
  - sort: 10

- `admin.routes`
  - Titel: Admin – Routes
  - Datei: module/admin/routes.php
  - modul/objekt_typ/objekt_id: admin / routes / NULL
  - require_login: 1
  - sort: 20

- `admin.menu`
  - Titel: Admin – Menü
  - Datei: module/admin/menu.php
  - modul/objekt_typ/objekt_id: admin / menu / NULL
  - require_login: 1
  - sort: 30

- `admin.permissions`
  - Titel: Admin – Berechtigungen
  - Datei: module/admin/permissions.php
  - modul/objekt_typ/objekt_id: admin / permissions / NULL
  - require_login: 1
  - sort: 40

---

## Hinweise / Stolperfallen
- Menü-Items haben teilweise sowohl `route_key` als auch `url`. Best Practice: `url` leer lassen und über `route_key` routen.
- Permissions müssen zum `core_route.modul` + `objekt_typ` passen (sonst 403).


