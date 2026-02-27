-- Migration für soon_ratio
ALTER TABLE wartungstool_wartungspunkt ADD COLUMN soon_ratio DECIMAL(6,4) NULL AFTER plan_interval;
