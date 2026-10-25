-- ============================================================
-- GabayBatas — Migration v5: structured Legal Information articles
-- ADDITIVE ONLY; safe to re-run. Content is loaded by
-- tools/seed_legal_information.php from db/legal_information_content.php.
-- ============================================================

ALTER TABLE legal_information ADD COLUMN IF NOT EXISTS summary VARCHAR(255) DEFAULT NULL AFTER title;
ALTER TABLE legal_information ADD COLUMN IF NOT EXISTS offense_class VARCHAR(120) DEFAULT NULL AFTER category;
ALTER TABLE legal_information ADD COLUMN IF NOT EXISTS report_to VARCHAR(160) DEFAULT NULL AFTER offense_class;
ALTER TABLE legal_information ADD COLUMN IF NOT EXISTS case_type VARCHAR(100) DEFAULT NULL AFTER report_to;
ALTER TABLE legal_information ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER case_type;
