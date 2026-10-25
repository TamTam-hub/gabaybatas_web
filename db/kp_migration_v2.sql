-- ============================================================
-- GabayBatas KP System — Migration v2 (procedural corrections)
-- Barangay Muñoz, Catbalogan City, Samar
--
-- ADDITIVE ONLY — existing rows and columns keep their values.
-- Safe to run more than once.
-- ============================================================

-- ── 1. Case disposition (how the case legally ended) ──
ALTER TABLE cases
  ADD COLUMN IF NOT EXISTS kp_disposition VARCHAR(60) DEFAULT NULL AFTER kp_status;

ALTER TABLE cases
  ADD COLUMN IF NOT EXISTS conciliation_extended TINYINT(1) NOT NULL DEFAULT 0 AFTER conciliation_deadline;

UPDATE cases SET kp_disposition = 'not_covered'
 WHERE kp_status = 'not_eligible' AND kp_disposition IS NULL;

-- ── 2. Hearing purpose (regular hearing, Pangkat constitution meeting,
--       failure-to-appear explanation hearing, execution motion hearing) ──
ALTER TABLE kp_hearings
  ADD COLUMN IF NOT EXISTS purpose_type VARCHAR(40) NOT NULL DEFAULT 'regular' AFTER stage;

ALTER TABLE kp_hearings
  ADD COLUMN IF NOT EXISTS related_hearing_id INT UNSIGNED DEFAULT NULL AFTER purpose_type;

-- ── 3. Settlement origin ──
ALTER TABLE kp_settlements
  ADD COLUMN IF NOT EXISTS stage VARCHAR(30) DEFAULT NULL AFTER settlement_type;

ALTER TABLE kp_settlements
  ADD COLUMN IF NOT EXISTS arbitrator VARCHAR(30) DEFAULT NULL AFTER stage;

-- ── 4. Pangkat: members may be agreed upon by both parties ──
ALTER TABLE kp_pangkat
  MODIFY chosen_by ENUM('complainant','respondent','drawing_lots','both_parties') NOT NULL;

-- ── 5. Agreements for arbitration (KP Form 14) ──
CREATE TABLE IF NOT EXISTS kp_arbitration_agreements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  arbitrator ENUM('punong_barangay','pangkat') NOT NULL,
  from_stage VARCHAR(30) NOT NULL,
  agreement_date DATE NOT NULL,
  repudiation_deadline DATE NOT NULL,
  repudiated TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_arb_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 6. Sworn repudiations (KP Form 17) ──
CREATE TABLE IF NOT EXISTS kp_repudiations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  target_type ENUM('settlement','arbitration_agreement') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  party_name VARCHAR(191) NOT NULL,
  party_role ENUM('complainant','respondent') NOT NULL,
  ground ENUM('fraud','violence','intimidation') NOT NULL,
  details TEXT DEFAULT NULL,
  sworn_date DATE NOT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_repud_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 7. Per-generation form inputs (e.g. witness, chosen Pangkat member) ──
ALTER TABLE kp_documents
  ADD COLUMN IF NOT EXISTS input_json TEXT DEFAULT NULL AFTER content_html;

-- ============================================================
-- DONE.
-- ============================================================
