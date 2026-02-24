-- =====================================================================
-- Migration: Rechtesystem – objekt_typ Konsistenz (v1)
-- Stand: 2026-02-24
--
-- Hintergrund:
--   core_route verwendet für fast alle Routen objekt_typ='global'.
--   Ältere Permission-Seeds nutzten teilweise objekt_typ='inbox' oder
--   andere spezifische Werte, die nicht mehr zu den Routen passen.
--
-- Ergebnis: alle stoerungstool-Permissions werden auf objekt_typ='global'
--   vereinheitlicht, damit user_can_see() in app.php korrekt greift.
--
-- Wichtig: Keine Daten werden gelöscht – nur Updates (additiv).
-- =====================================================================

-- 1) stoerungstool: objekt_typ='inbox' -> 'global' angleichen
--    (Betrifft z.B. User 4 aus dem initialen Dump)
UPDATE core_permission
SET objekt_typ = 'global'
WHERE modul = 'stoerungstool'
  AND objekt_typ = 'inbox';

-- 2) stoerungstool: objekt_typ='ticket' -> 'global' angleichen
UPDATE core_permission
SET objekt_typ = 'global'
WHERE modul = 'stoerungstool'
  AND objekt_typ = 'ticket';

-- 3) wartungstool: objekt_typ='admin_punkte' oder 'punkte' -> 'global'
UPDATE core_permission
SET objekt_typ = 'global'
WHERE modul = 'wartungstool'
  AND objekt_typ IN ('admin_punkte', 'punkte', 'punkt');

-- =====================================================================
-- Referenz-Seed: Berechtigungsmatrix für Standard-Rollen
-- (nur ausführen, wenn noch keine Permissions für user_id existieren)
-- Passe user_id-Werte an die tatsächliche DB an.
-- =====================================================================

-- Beispiel: Techniker-Rolle (user_id = 4)
--   Kann: Wartung sehen + durchführen, Störungen sehen + bearbeiten
--   Kann NICHT: Admin-Wartungspunkte bearbeiten, Admin-Seiten
--
-- INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
-- VALUES
--   (4, 'wartungstool', 'dashboard', NULL, 1, 0, 0),
--   (4, 'wartungstool', 'global',    NULL, 1, 1, 0),  -- sehen + durchführen, aber kein admin_punkte-CRUD
--   (4, 'stoerungstool','global',    NULL, 1, 1, 0)
-- ON DUPLICATE KEY UPDATE darf_sehen=VALUES(darf_sehen), darf_aendern=VALUES(darf_aendern), darf_loeschen=VALUES(darf_loeschen);

-- Beispiel: Nur-Lesen-Rolle (user_id = 5)
--   Kann: Wartung sehen, Störungen sehen
--   Kann NICHT: Änderungen vornehmen
--
-- INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
-- VALUES
--   (5, 'wartungstool', 'dashboard', NULL, 1, 0, 0),
--   (5, 'wartungstool', 'global',    NULL, 1, 0, 0),
--   (5, 'stoerungstool','global',    NULL, 1, 0, 0)
-- ON DUPLICATE KEY UPDATE darf_sehen=VALUES(darf_sehen), darf_aendern=VALUES(darf_aendern), darf_loeschen=VALUES(darf_loeschen);

-- =====================================================================
-- Hinweis zur Admin-Seite (admin_punkte):
--   route wartung.admin_punkte hat modul='wartungstool' / objekt_typ='global'
--   Das PHP erzwingt jetzt require_can_edit('wartungstool','global',null).
--   => Nur User mit darf_aendern=1 für wartungstool/global kommen durch.
--   Admins (modul='*') sind immer berechtigt (Wildcard in user_can_see/user_can_flag).
-- =====================================================================

-- Ende db_migration_permissions_v1.sql
