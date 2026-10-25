-- ============================================================
-- GabayBatas KP System — Migration v4
-- Nature of case (Criminal / Civil / Others) for KP Monitoring Form No. 1.
-- ADDITIVE ONLY; safe to re-run.
-- ============================================================

ALTER TABLE cases
  ADD COLUMN IF NOT EXISTS kp_nature VARCHAR(20) DEFAULT NULL AFTER case_type;
