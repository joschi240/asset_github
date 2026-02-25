
-- Hinweis: Das aktuelle Datenbankschema befindet sich in asset_github_schema.sql. Testdaten/Seeds sind in asset_github_seed_testdaten.sql zu finden.
--  - stoerungstool_aktion: status_neu ENUM ergänzt um 'bestellt'
--
-- WICHTIG (Design-Regeln):
--  - Möglichst additive Erweiterungen (neue Tabellen/Spalten), keine Breaking Changes.
--  - app.php ist Front-Controller und erwartet core_route + Permission-Check.
--  - Views, die über app.php geladen werden, rendern KEIN eigenes Layout.
--
-- Hinweis:
--  - Dieses Script erstellt Tabellen in sinnvoller Reihenfolge.
--  - Es nutzt IF NOT EXISTS und vermeidet DROP (damit bestehende Daten nicht zerstört werden).
--  - Collation/Charset: utf8mb4_general_ci.
-- =====================================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- CORE: Stammdaten (Kategorien, Standorte, Assets)
-- =====================================================================

-- core_asset_kategorie
-- Zweck: Klassifizierung von Assets inkl. Kritikalität (1..3).
CREATE TABLE IF NOT EXISTS core_asset_kategorie (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  beschreibung TEXT DEFAULT NULL,
  kritischkeitsstufe TINYINT(4) NOT NULL DEFAULT 1,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_standort
-- Zweck: Standort-/Bereichsstruktur (Halle, Technikraum, Büro ...).
CREATE TABLE IF NOT EXISTS core_standort (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  beschreibung TEXT DEFAULT NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_asset
-- Zweck: Anlagenstamm (Maschinen/Kompressoren/Gebäude/Anything = Asset).
-- code: Kurzcode (z.B. BAZ-03) ist UNIQUE.
CREATE TABLE IF NOT EXISTS core_asset (
  id INT(11) NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  asset_typ VARCHAR(80) DEFAULT NULL,
  kategorie_id INT(11) DEFAULT NULL,
  standort_id INT(11) DEFAULT NULL,
  prioritaet INT(11) NOT NULL DEFAULT 0,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_code (code),
  KEY idx_kategorie (kategorie_id),
  KEY idx_standort (standort_id),
  KEY idx_aktiv (aktiv),
  CONSTRAINT fk_asset_kategorie FOREIGN KEY (kategorie_id) REFERENCES core_asset_kategorie (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_asset_standort FOREIGN KEY (standort_id) REFERENCES core_standort (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- CORE: Benutzer & Berechtigungen
-- =====================================================================

-- core_user
-- Zweck: Minimaler Login (später RBAC möglich).
CREATE TABLE IF NOT EXISTS core_user (
  id INT(11) NOT NULL AUTO_INCREMENT,
  benutzername VARCHAR(100) NOT NULL,
  passwort_hash VARCHAR(255) NOT NULL,
  anzeigename VARCHAR(120) DEFAULT NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  last_login_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_permission
-- Zweck: Minimal-Permissions je User/Modul/Objekt.
-- Objektbindung:
--  - objekt_id NULL => global für modul+objekt_typ
--  - objekt_id gesetzt => feinere Rechte auf Objekt-Ebene
--  - modul='*' => Admin-Wildcard (alle Module)
CREATE TABLE IF NOT EXISTS core_permission (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  modul VARCHAR(50) NOT NULL,
  objekt_typ VARCHAR(50) NOT NULL,
  objekt_id INT(11) DEFAULT NULL,
  darf_sehen TINYINT(1) NOT NULL DEFAULT 0,
  darf_aendern TINYINT(1) NOT NULL DEFAULT 0,
  darf_loeschen TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_modul (modul),
  KEY idx_obj (objekt_typ, objekt_id),
  CONSTRAINT fk_perm_user FOREIGN KEY (user_id) REFERENCES core_user (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- CORE: Audit & Dokumente
-- =====================================================================

-- core_audit_log
-- Zweck: ISO-/Audit-Trail für CREATE/UPDATE/STATUS/DELETE.
-- old_json/new_json sind JSON-validiert (MariaDB: LONGTEXT + CHECK json_valid()).
CREATE TABLE IF NOT EXISTS core_audit_log (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  modul VARCHAR(50) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT(20) NOT NULL,
  action VARCHAR(30) NOT NULL,
  actor_user_id INT(11) DEFAULT NULL,
  actor_text VARCHAR(120) DEFAULT NULL,
  ip_addr VARCHAR(45) DEFAULT NULL,
  old_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(old_json)),
  new_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(new_json)),
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_entity (modul, entity_type, entity_id),
  KEY idx_actor (actor_user_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_dokument
-- Zweck: Universelle Attachments für alle Module.
-- referenz_typ + referenz_id = z.B. ('ticket', 123) oder ('wartungspunkt', 55).
-- dateiname = relativer Pfad unter /uploads/
CREATE TABLE IF NOT EXISTS core_dokument (
  id INT(11) NOT NULL AUTO_INCREMENT,
  modul VARCHAR(50) NOT NULL,
  referenz_typ VARCHAR(50) NOT NULL,
  referenz_id INT(11) NOT NULL,
  dateiname VARCHAR(255) NOT NULL,
  originalname VARCHAR(255) DEFAULT NULL,
  mime VARCHAR(120) DEFAULT NULL,
  size_bytes BIGINT(20) DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  hochgeladen_am DATETIME NOT NULL DEFAULT current_timestamp(),
  hochgeladen_von_user_id INT(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_mod_ref (modul, referenz_typ, referenz_id),
  KEY idx_upload_user (hochgeladen_von_user_id),
  CONSTRAINT fk_doc_user FOREIGN KEY (hochgeladen_von_user_id) REFERENCES core_user (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- CORE: Menü & Routing (DB-getrieben)
-- =====================================================================

-- core_menu
-- Zweck: Menü-Container (z.B. 'main', 'admin').
CREATE TABLE IF NOT EXISTS core_menu (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  titel VARCHAR(120) NOT NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_menu_item
-- Zweck: Menübaum (parent-child) mit Route-Key oder URL.
-- route_key bevorzugen: app.php?r=<route_key>
CREATE TABLE IF NOT EXISTS core_menu_item (
  id INT(11) NOT NULL AUTO_INCREMENT,
  menu_id INT(11) NOT NULL,
  parent_id INT(11) DEFAULT NULL,
  label VARCHAR(120) NOT NULL,
  route_key VARCHAR(80) DEFAULT NULL,
  url VARCHAR(255) DEFAULT NULL,
  modul VARCHAR(50) DEFAULT NULL,
  objekt_typ VARCHAR(50) DEFAULT NULL,
  objekt_id INT(11) DEFAULT NULL,
  sort INT(11) NOT NULL DEFAULT 0,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  icon VARCHAR(40) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_menu (menu_id),
  KEY idx_parent (parent_id),
  KEY idx_sort (sort),
  KEY idx_route_key (route_key),
  CONSTRAINT fk_menuitem_menu FOREIGN KEY (menu_id) REFERENCES core_menu (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_menuitem_parent FOREIGN KEY (parent_id) REFERENCES core_menu_item (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_route
-- Zweck: Routing-Tabelle für app.php.
-- app.php lädt: titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv.
CREATE TABLE IF NOT EXISTS core_route (
  id INT(11) NOT NULL AUTO_INCREMENT,
  route_key VARCHAR(80) NOT NULL,
  titel VARCHAR(120) NOT NULL,
  file_path VARCHAR(255) NOT NULL,       -- relativ zum Projektroot
  modul VARCHAR(50) DEFAULT NULL,
  objekt_typ VARCHAR(50) DEFAULT NULL,
  objekt_id INT(11) DEFAULT NULL,
  require_login TINYINT(1) NOT NULL DEFAULT 1,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  sort INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_route_key (route_key),
  KEY idx_mod (modul, objekt_typ, objekt_id),
  KEY idx_aktiv (aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- RUNTIME: Telemetrie (Polling Samples) + Aggregation
-- =====================================================================

-- core_runtime_sample
-- Zweck: Rohdaten aus Steuerung: ts + state (run/stop).
-- Bulk-Insert möglich; UNIQUE(asset_id, ts) erlaubt Upserts.
CREATE TABLE IF NOT EXISTS core_runtime_sample (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) NOT NULL,
  ts DATETIME NOT NULL,
  state TINYINT(4) NOT NULL,             -- 1=run, 0=stop
  source VARCHAR(40) NOT NULL DEFAULT 'plc_poll',
  quality TINYINT(4) NOT NULL DEFAULT 1, -- 1=ok, 0=unsicher
  payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(payload_json)),
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_asset_ts (asset_id, ts),
  KEY idx_asset_ts (asset_id, ts),
  KEY idx_ts (ts),
  CONSTRAINT fk_runtime_sample_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_runtime_counter
-- Zweck: Aktueller Produktivstunden-Stand pro Asset (für Wartung).
-- Wird durch runtime_rollup.php gepflegt.
CREATE TABLE IF NOT EXISTS core_runtime_counter (
  asset_id INT(11) NOT NULL,
  productive_hours DOUBLE NOT NULL DEFAULT 0,
  last_ts DATETIME DEFAULT NULL,
  last_state TINYINT(4) DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (asset_id),
  CONSTRAINT fk_runtime_counter_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- core_runtime_agg_day
-- Zweck: Tagesaggregation (run/stop Sekunden, gaps, intervals).
-- Dient für Trends/Prognosen/Reports und schnelle Dashboards.
CREATE TABLE IF NOT EXISTS core_runtime_agg_day (
  asset_id INT(11) NOT NULL,
  day DATE NOT NULL,
  run_seconds INT(11) NOT NULL DEFAULT 0,
  stop_seconds INT(11) NOT NULL DEFAULT 0,
  intervals INT(11) NOT NULL DEFAULT 0,
  gaps INT(11) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (asset_id, day),
  KEY idx_day (day),
  CONSTRAINT fk_runtime_agg_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- WARTUNGSTOOL: Wartungspunkte + Protokoll
-- =====================================================================

-- wartungstool_wartungspunkt
-- Zweck: Wartungsplan pro Asset (zeit- oder produktivstundenbasiert).
-- plan_interval: Stunden (bei produktiv) oder Tage (bei zeit)
-- letzte_wartung: Produktivstunden-Stand bei letzter Wartung (für intervall_typ='produktiv')
-- datum: Zeitpunkt letzter Wartung (für intervall_typ='zeit')
CREATE TABLE IF NOT EXISTS wartungstool_wartungspunkt (
  id INT(11) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) NOT NULL,
  text_kurz VARCHAR(255) NOT NULL,
  text_lang TEXT DEFAULT NULL,
  intervall_typ ENUM('zeit','produktiv') NOT NULL DEFAULT 'zeit',
  plan_interval DOUBLE NOT NULL,
  letzte_wartung DOUBLE DEFAULT NULL,
  datum DATETIME DEFAULT NULL,
  messwert_pflicht TINYINT(1) NOT NULL DEFAULT 0,
  grenzwert_min DOUBLE DEFAULT NULL,
  grenzwert_max DOUBLE DEFAULT NULL,
  einheit VARCHAR(50) DEFAULT NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_asset (asset_id),
  KEY idx_aktiv (aktiv),
  KEY idx_intervall (intervall_typ),
  CONSTRAINT fk_wp_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- wartungstool_protokoll
-- Zweck: Durchführung/Ergebnis eines Wartungspunkts (Historie).
CREATE TABLE IF NOT EXISTS wartungstool_protokoll (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  wartungspunkt_id INT(11) NOT NULL,
  asset_id INT(11) NOT NULL,
  datum DATETIME NOT NULL DEFAULT current_timestamp(),
  user_id INT(11) DEFAULT NULL,
  team_text VARCHAR(255) DEFAULT NULL,
  messwert DOUBLE DEFAULT NULL,
  status ENUM('ok','abweichung') NOT NULL DEFAULT 'ok',
  bemerkung TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_wp (wartungspunkt_id),
  KEY idx_asset (asset_id),
  KEY idx_datum (datum),
  KEY idx_status (status),
  CONSTRAINT fk_prot_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prot_wp FOREIGN KEY (wartungspunkt_id) REFERENCES wartungstool_wartungspunkt (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- STOERUNGSTOOL v2: Tickets + Aktionen
-- =====================================================================

-- stoerungstool_ticket (v2)
-- Zweck: Mängel/Störungen inkl. Status-Workflow:
--  neu → angenommen → in_arbeit → bestellt → erledigt → geschlossen
--
-- v2-Erweiterungen:
--  - meldungstyp: Art der Meldung (Störmeldung, Mängelkarte, Logeintrag, ...)
--  - fachkategorie: Fachbereich (Mechanik, Elektrik, Sicherheit, Qualität, ...)
--  - maschinenstillstand: 1 wenn Anlage steht
--  - ausfallzeitpunkt: Wann ist die Anlage ausgefallen
--  - assigned_user_id: Zugewiesener Techniker
CREATE TABLE IF NOT EXISTS stoerungstool_ticket (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) DEFAULT NULL,
  titel VARCHAR(200) NOT NULL,
  beschreibung TEXT NOT NULL,
  kategorie VARCHAR(60) DEFAULT NULL,            -- legacy, bleibt erhalten
  meldungstyp VARCHAR(60) DEFAULT NULL,          -- v2: z.B. 'Störmeldung', 'Mängelkarte', 'Logeintrag'
  fachkategorie VARCHAR(60) DEFAULT NULL,        -- v2: z.B. 'Mechanik', 'Elektrik', 'Sicherheit', 'Qualität'
  maschinenstillstand TINYINT(1) NOT NULL DEFAULT 0,  -- v2: 1 = Anlage steht
  ausfallzeitpunkt DATETIME DEFAULT NULL,        -- v2: Zeitpunkt des Ausfalls
  prioritaet TINYINT(4) NOT NULL DEFAULT 2,
  status ENUM('neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen') NOT NULL DEFAULT 'neu',
  gemeldet_von VARCHAR(120) DEFAULT NULL,
  kontakt VARCHAR(120) DEFAULT NULL,
  anonym TINYINT(1) NOT NULL DEFAULT 0,
  assigned_user_id INT(11) DEFAULT NULL,         -- v2: FK zu core_user
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_asset (asset_id),
  KEY idx_status (status),
  KEY idx_created (created_at),
  KEY idx_prior (prioritaet),
  KEY idx_assigned (assigned_user_id),
  CONSTRAINT fk_ticket_asset FOREIGN KEY (asset_id) REFERENCES core_asset (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ticket_assigned FOREIGN KEY (assigned_user_id) REFERENCES core_user (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- stoerungstool_aktion (v2)
-- Zweck: Kommentare, Statuswechsel, Zeiten pro Ticket (auditfähig).
-- v2: status_neu ergänzt um 'bestellt'
CREATE TABLE IF NOT EXISTS stoerungstool_aktion (
  id BIGINT(20) NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT(20) NOT NULL,
  datum DATETIME NOT NULL DEFAULT current_timestamp(),
  user_id INT(11) DEFAULT NULL,
  text TEXT NOT NULL,
  status_neu ENUM('neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen') DEFAULT NULL,
  arbeitszeit_min INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ticket (ticket_id),
  KEY idx_datum (datum),
  CONSTRAINT fk_aktion_ticket FOREIGN KEY (ticket_id) REFERENCES stoerungstool_ticket (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- Abschluss
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 1;

-- Ende db_schema_v2.sql
