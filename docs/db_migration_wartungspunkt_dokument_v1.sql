-- =====================================================================
-- Migration: Wartungspunkt Dokument-Upload – Route Seed (v1)
-- Stand: 2026-02-25
--
-- Hintergrund:
--   Next 3: core_dokument für referenz_typ='wartungspunkt' aktiviert.
--   Upload-Handler ist eine eigene INNER VIEW (punkt_dokument_upload.php).
--   Diese Route muss in core_route eingetragen werden, damit app.php
--   die Datei laden darf.
--
-- Wichtig: INSERT IGNORE – idempotent, kann mehrfach ausgeführt werden.
-- =====================================================================

INSERT IGNORE INTO core_route
  (route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv, sort)
VALUES
  (
    'wartung.punkt_dokument_upload',
    'Wartungspunkt – Dokument hochladen',
    'module/wartungstool/punkt_dokument_upload.php',
    'wartungstool',
    'global',
    NULL,
    1,
    1,
    112
  );

-- Ende db_migration_wartungspunkt_dokument_v1.sql
