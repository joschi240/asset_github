-- Migration für soon_ratio und soon_hours
ALTER TABLE wartungstool_wartungspunkt
  ADD COLUMN soon_ratio DOUBLE DEFAULT NULL AFTER plan_interval,
  ADD COLUMN soon_hours DOUBLE DEFAULT NULL AFTER soon_ratio;
