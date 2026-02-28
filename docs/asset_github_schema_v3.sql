-- =====================================================================
-- Asset GitHub – konsolidiertes DB-Schema (v3, aus aktuellem Dump generiert)
-- Generiert: 2026-02-28
-- Ziel: Install-/Reset-Schema inkl. Keys/Constraints, ohne Seeds.
-- MariaDB 10.4.x kompatibel, Charset/Collation: utf8mb4_general_ci
-- =====================================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `core_menu_item`;
DROP TABLE IF EXISTS `wartungstool_protokoll`;
DROP TABLE IF EXISTS `stoerungstool_aktion`;
DROP TABLE IF EXISTS `wartungstool_wartungspunkt`;
DROP TABLE IF EXISTS `stoerungstool_ticket`;
DROP TABLE IF EXISTS `core_runtime_sample`;
DROP TABLE IF EXISTS `core_runtime_counter`;
DROP TABLE IF EXISTS `core_runtime_agg_day`;
DROP TABLE IF EXISTS `core_permission`;
DROP TABLE IF EXISTS `core_dokument`;
DROP TABLE IF EXISTS `core_asset`;
DROP TABLE IF EXISTS `core_user`;
DROP TABLE IF EXISTS `core_standort`;
DROP TABLE IF EXISTS `core_route`;
DROP TABLE IF EXISTS `core_menu`;
DROP TABLE IF EXISTS `core_audit_log`;
DROP TABLE IF EXISTS `core_asset_kategorie`;

-- ---------------------------------------------------------------------
-- Table: core_asset_kategorie
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_asset_kategorie` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `beschreibung` text DEFAULT NULL,
  `kritischkeitsstufe` tinyint(4) NOT NULL DEFAULT 1,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_audit_log
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_audit_log` (
  `id` bigint(20) NOT NULL,
  `modul` varchar(50) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `action` varchar(30) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_text` varchar(120) DEFAULT NULL,
  `ip_addr` varchar(45) DEFAULT NULL,
  `old_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_json`)),
  `new_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_menu
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_menu` (
  `id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `titel` varchar(120) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_route
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_route` (
  `id` int(11) NOT NULL,
  `route_key` varchar(80) NOT NULL,
  `titel` varchar(120) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `modul` varchar(50) DEFAULT NULL,
  `objekt_typ` varchar(50) DEFAULT NULL,
  `objekt_id` int(11) DEFAULT NULL,
  `require_login` tinyint(1) NOT NULL DEFAULT 1,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_standort
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_standort` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `beschreibung` text DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_user
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_user` (
  `id` int(11) NOT NULL,
  `benutzername` varchar(100) NOT NULL,
  `passwort_hash` varchar(255) NOT NULL,
  `anzeigename` varchar(120) DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_asset
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_asset` (
  `id` int(11) NOT NULL,
  `code` varchar(30) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `asset_typ` varchar(80) DEFAULT NULL,
  `kategorie_id` int(11) DEFAULT NULL,
  `standort_id` int(11) DEFAULT NULL,
  `prioritaet` int(11) NOT NULL DEFAULT 0,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_dokument
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_dokument` (
  `id` int(11) NOT NULL,
  `modul` varchar(50) NOT NULL,
  `referenz_typ` varchar(50) NOT NULL,
  `referenz_id` int(11) NOT NULL,
  `dateiname` varchar(255) NOT NULL,
  `originalname` varchar(255) DEFAULT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `size_bytes` bigint(20) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `hochgeladen_am` datetime NOT NULL DEFAULT current_timestamp(),
  `hochgeladen_von_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_permission
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_permission` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `modul` varchar(50) NOT NULL,
  `objekt_typ` varchar(50) NOT NULL,
  `objekt_id` int(11) DEFAULT NULL,
  `darf_sehen` tinyint(1) NOT NULL DEFAULT 0,
  `darf_aendern` tinyint(1) NOT NULL DEFAULT 0,
  `darf_loeschen` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_runtime_agg_day
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_runtime_agg_day` (
  `asset_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `run_seconds` int(11) NOT NULL DEFAULT 0,
  `stop_seconds` int(11) NOT NULL DEFAULT 0,
  `intervals` int(11) NOT NULL DEFAULT 0,
  `gaps` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_runtime_counter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_runtime_counter` (
  `asset_id` int(11) NOT NULL,
  `productive_hours` double NOT NULL DEFAULT 0,
  `last_ts` datetime DEFAULT NULL,
  `last_state` tinyint(4) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_runtime_sample
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_runtime_sample` (
  `id` bigint(20) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `ts` datetime NOT NULL,
  `state` tinyint(4) NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'plc_poll',
  `quality` tinyint(4) NOT NULL DEFAULT 1,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: stoerungstool_ticket
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stoerungstool_ticket` (
  `id` bigint(20) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `titel` varchar(200) NOT NULL,
  `beschreibung` text NOT NULL,
  `kategorie` varchar(60) DEFAULT NULL,
  `meldungstyp` varchar(60) DEFAULT NULL,
  `fachkategorie` varchar(60) DEFAULT NULL,
  `maschinenstillstand` tinyint(1) NOT NULL DEFAULT 0,
  `ausfallzeitpunkt` datetime DEFAULT NULL,
  `prioritaet` tinyint(4) NOT NULL DEFAULT 2,
  `status` enum('neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen') NOT NULL DEFAULT 'neu',
  `gemeldet_von` varchar(120) DEFAULT NULL,
  `kontakt` varchar(120) DEFAULT NULL,
  `anonym` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `first_response_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: wartungstool_wartungspunkt
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wartungstool_wartungspunkt` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `text_kurz` varchar(255) NOT NULL,
  `text_lang` text DEFAULT NULL,
  `intervall_typ` enum('zeit','produktiv') NOT NULL DEFAULT 'zeit',
  `plan_interval` double NOT NULL,
  `letzte_wartung` double DEFAULT NULL,
  `datum` datetime DEFAULT NULL,
  `messwert_pflicht` tinyint(1) NOT NULL DEFAULT 0,
  `grenzwert_min` double DEFAULT NULL,
  `grenzwert_max` double DEFAULT NULL,
  `einheit` varchar(50) DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `soon_ratio` decimal(5,4) DEFAULT 0.2000 COMMENT 'Schwelle bald fällig als Anteil vom Intervall (z.B. 0.20)',
  `soon_hours` decimal(10,2) DEFAULT NULL COMMENT 'Alternative fixe Stunden-Schwelle',
  `planned_at` datetime DEFAULT NULL,
  `planned_text` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: stoerungstool_aktion
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stoerungstool_aktion` (
  `id` bigint(20) NOT NULL,
  `ticket_id` bigint(20) NOT NULL,
  `datum` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `text` text NOT NULL,
  `status_neu` enum('neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen') DEFAULT NULL,
  `arbeitszeit_min` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: wartungstool_protokoll
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wartungstool_protokoll` (
  `id` bigint(20) NOT NULL,
  `wartungspunkt_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `datum` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `team_text` varchar(255) DEFAULT NULL,
  `messwert` double DEFAULT NULL,
  `status` enum('ok','abweichung') NOT NULL DEFAULT 'ok',
  `bemerkung` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table: core_menu_item
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `core_menu_item` (
  `id` int(11) NOT NULL,
  `menu_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `label` varchar(120) NOT NULL,
  `route_key` varchar(80) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `modul` varchar(50) DEFAULT NULL,
  `objekt_typ` varchar(50) DEFAULT NULL,
  `objekt_id` int(11) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT 0,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `icon` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- Keys / Indizes / Auto-Increment / Foreign Keys
-- =====================================================================

ALTER TABLE `core_asset`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_code` (`code`),
  ADD KEY `idx_kategorie` (`kategorie_id`),
  ADD KEY `idx_standort` (`standort_id`),
  ADD KEY `idx_aktiv` (`aktiv`);
ALTER TABLE `core_asset_kategorie`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);
ALTER TABLE `core_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_entity` (`modul`,`entity_type`,`entity_id`),
  ADD KEY `idx_actor` (`actor_user_id`),
  ADD KEY `idx_created` (`created_at`);
ALTER TABLE `core_dokument`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mod_ref` (`modul`,`referenz_typ`,`referenz_id`),
  ADD KEY `idx_upload_user` (`hochgeladen_von_user_id`);
ALTER TABLE `core_menu`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);
ALTER TABLE `core_menu_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_menu` (`menu_id`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_sort` (`sort`),
  ADD KEY `idx_route_key` (`route_key`);
ALTER TABLE `core_permission`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_modul` (`modul`),
  ADD KEY `idx_obj` (`objekt_typ`,`objekt_id`);
ALTER TABLE `core_route`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_route_key` (`route_key`),
  ADD KEY `idx_mod` (`modul`,`objekt_typ`,`objekt_id`),
  ADD KEY `idx_aktiv` (`aktiv`);
ALTER TABLE `core_runtime_agg_day`
  ADD PRIMARY KEY (`asset_id`,`day`),
  ADD KEY `idx_day` (`day`);
ALTER TABLE `core_runtime_counter`
  ADD PRIMARY KEY (`asset_id`);
ALTER TABLE `core_runtime_sample`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_asset_ts` (`asset_id`,`ts`),
  ADD KEY `idx_asset_ts` (`asset_id`,`ts`),
  ADD KEY `idx_ts` (`ts`);
ALTER TABLE `core_standort`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_name` (`name`);
ALTER TABLE `core_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user` (`benutzername`);
ALTER TABLE `stoerungstool_aktion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket` (`ticket_id`),
  ADD KEY `idx_datum` (`datum`);
ALTER TABLE `stoerungstool_ticket`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset` (`asset_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_prior` (`prioritaet`),
  ADD KEY `idx_assigned` (`assigned_user_id`);
ALTER TABLE `wartungstool_protokoll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wp` (`wartungspunkt_id`),
  ADD KEY `idx_asset` (`asset_id`),
  ADD KEY `idx_datum` (`datum`),
  ADD KEY `idx_status` (`status`);
ALTER TABLE `wartungstool_wartungspunkt`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset` (`asset_id`),
  ADD KEY `idx_aktiv` (`aktiv`),
  ADD KEY `idx_intervall` (`intervall_typ`),
  ADD KEY `idx_wp_planned_at` (`planned_at`);
ALTER TABLE `core_asset`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;
ALTER TABLE `core_asset_kategorie`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;
ALTER TABLE `core_audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=165;
ALTER TABLE `core_dokument`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
ALTER TABLE `core_menu`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `core_menu_item`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;
ALTER TABLE `core_permission`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;
ALTER TABLE `core_route`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;
ALTER TABLE `core_runtime_sample`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
ALTER TABLE `core_standort`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;
ALTER TABLE `core_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;
ALTER TABLE `stoerungstool_aktion`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=733;
ALTER TABLE `stoerungstool_ticket`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=164;
ALTER TABLE `wartungstool_protokoll`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;
ALTER TABLE `wartungstool_wartungspunkt`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;
ALTER TABLE `core_asset`
  ADD CONSTRAINT `fk_asset_kategorie` FOREIGN KEY (`kategorie_id`) REFERENCES `core_asset_kategorie` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_asset_standort` FOREIGN KEY (`standort_id`) REFERENCES `core_standort` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `core_dokument`
  ADD CONSTRAINT `fk_doc_user` FOREIGN KEY (`hochgeladen_von_user_id`) REFERENCES `core_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `core_menu_item`
  ADD CONSTRAINT `fk_menuitem_menu` FOREIGN KEY (`menu_id`) REFERENCES `core_menu` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_menuitem_parent` FOREIGN KEY (`parent_id`) REFERENCES `core_menu_item` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `core_permission`
  ADD CONSTRAINT `fk_perm_user` FOREIGN KEY (`user_id`) REFERENCES `core_user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `core_runtime_agg_day`
  ADD CONSTRAINT `fk_runtime_agg_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `core_runtime_counter`
  ADD CONSTRAINT `fk_runtime_counter_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `core_runtime_sample`
  ADD CONSTRAINT `fk_runtime_sample_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `stoerungstool_aktion`
  ADD CONSTRAINT `fk_aktion_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `stoerungstool_ticket` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `stoerungstool_ticket`
  ADD CONSTRAINT `fk_ticket_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ticket_assigned` FOREIGN KEY (`assigned_user_id`) REFERENCES `core_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE `wartungstool_protokoll`
  ADD CONSTRAINT `fk_prot_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prot_wp` FOREIGN KEY (`wartungspunkt_id`) REFERENCES `wartungstool_wartungspunkt` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `wartungstool_wartungspunkt`
  ADD CONSTRAINT `fk_wp_asset` FOREIGN KEY (`asset_id`) REFERENCES `core_asset` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
-- END