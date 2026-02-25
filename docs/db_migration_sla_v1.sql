-- =====================================================================
-- Migration: SLA-Felder für stoerungstool_ticket (v1)
-- Stand: 2026-02-25
--
-- Neue Felder:
--   first_response_at DATETIME NULL  – Zeitpunkt der ersten Reaktion
--   closed_at         DATETIME NULL  – Zeitpunkt der Schließung
--
-- Regel: additiv, keine bestehenden Spalten werden verändert.
-- =====================================================================

-- 1) Spalten hinzufügen
ALTER TABLE stoerungstool_ticket
  ADD COLUMN first_response_at DATETIME NULL DEFAULT NULL AFTER updated_at,
  ADD COLUMN closed_at         DATETIME NULL DEFAULT NULL AFTER first_response_at;

-- =====================================================================
-- 2) Backfill
--
-- first_response_at:
--   Früheste Aktion (MIN(datum)) pro Ticket, unabhängig vom Aktionstyp.
--   Begründung: Es gibt kein separates "Relevanz"-Feld in stoerungstool_aktion,
--   daher wird MIN(datum) als pragmatische Annäherung verwendet.
--   Nur für Tickets, bei denen mindestens eine Aktion existiert.
-- =====================================================================

UPDATE stoerungstool_ticket t
  JOIN (
    SELECT ticket_id, MIN(datum) AS min_datum
    FROM stoerungstool_aktion
    GROUP BY ticket_id
  ) a ON a.ticket_id = t.id
SET t.first_response_at = a.min_datum
WHERE t.first_response_at IS NULL;

-- =====================================================================
-- closed_at:
--   Frühestes datum einer Aktion mit status_neu = 'geschlossen' pro Ticket.
--   'geschlossen' ist die Abschluss-Konstante laut helpers.php /
--   stoerungstool_ticket.status ENUM.
-- =====================================================================

UPDATE stoerungstool_ticket t
  JOIN (
    SELECT ticket_id, MIN(datum) AS min_closed
    FROM stoerungstool_aktion
    WHERE status_neu = 'geschlossen'
    GROUP BY ticket_id
  ) a ON a.ticket_id = t.id
SET t.closed_at = a.min_closed
WHERE t.closed_at IS NULL;

-- =====================================================================
-- 3) Optionale Indexes (nur wenn Abfragen nach SLA-Feldern erwartet werden)
-- =====================================================================

-- ALTER TABLE stoerungstool_ticket ADD INDEX idx_first_response_at (first_response_at);
-- ALTER TABLE stoerungstool_ticket ADD INDEX idx_closed_at (closed_at);

-- =====================================================================
-- Ende db_migration_sla_v1.sql
-- =====================================================================
