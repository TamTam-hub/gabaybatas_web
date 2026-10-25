-- ============================================================
-- GabayBatas KP System — Database Migration
-- Barangay Muñoz, Catbalogan City, Samar
-- 
-- ADDITIVE ONLY — No existing columns or tables are modified.
-- Safe to run on a database with existing case data.
-- ============================================================

-- ── 0. kp_documents version history ──

ALTER TABLE kp_documents
  ADD COLUMN IF NOT EXISTS version_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER template_version;

ALTER TABLE kp_documents
  ADD COLUMN IF NOT EXISTS is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER version_number;

ALTER TABLE kp_documents
  ADD COLUMN IF NOT EXISTS previous_document_id INT UNSIGNED DEFAULT NULL AFTER is_current;

UPDATE kp_documents SET version_number = 1 WHERE version_number IS NULL OR version_number = 0;

UPDATE kp_documents d
LEFT JOIN kp_documents newer
  ON newer.case_id = d.case_id
  AND newer.form_number = d.form_number
  AND newer.id > d.id
SET d.is_current = CASE WHEN newer.id IS NULL THEN 1 ELSE 0 END;

-- ── 1. ALTER cases table: add KP-specific columns ──

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS kp_status VARCHAR(60) DEFAULT NULL AFTER status;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS complainant_address VARCHAR(255) DEFAULT NULL AFTER name;

ALTER TABLE cases
  ADD COLUMN IF NOT EXISTS complainant_age VARCHAR(10) DEFAULT NULL AFTER complainant_address;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS respondent_address VARCHAR(255) DEFAULT NULL AFTER complained_person;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS complainant_purok VARCHAR(50) DEFAULT NULL;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS respondent_purok VARCHAR(50) DEFAULT NULL;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS relief_sought TEXT DEFAULT NULL;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS mediation_deadline DATE DEFAULT NULL;

ALTER TABLE cases 
  ADD COLUMN IF NOT EXISTS conciliation_deadline DATE DEFAULT NULL;


-- ── 2. CREATE kp_settlements ──

CREATE TABLE IF NOT EXISTS kp_settlements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  settlement_type ENUM('amicable','arbitration_award') NOT NULL,
  terms TEXT NOT NULL,
  settlement_date DATE NOT NULL,
  repudiation_deadline DATE NOT NULL,
  repudiated TINYINT(1) DEFAULT 0,
  repudiation_ground VARCHAR(60) DEFAULT NULL,
  repudiation_details TEXT DEFAULT NULL,
  repudiation_date DATE DEFAULT NULL,
  final_at DATETIME DEFAULT NULL,
  attested_by VARCHAR(191) DEFAULT 'HON. ULYSIS B. MACABARE',
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_settlements_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 3. CREATE kp_pangkat ──

CREATE TABLE IF NOT EXISTS kp_pangkat (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  member_name VARCHAR(191) NOT NULL,
  chosen_by ENUM('complainant','respondent','drawing_lots') NOT NULL,
  role ENUM('member','chairman','secretary') DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_pangkat_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 4. CREATE kp_executions ──

CREATE TABLE IF NOT EXISTS kp_executions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  settlement_id INT UNSIGNED NOT NULL,
  motion_date DATE NOT NULL,
  hearing_date DATE DEFAULT NULL,
  voluntary_compliance TINYINT(1) DEFAULT 0,
  compliance_deadline DATE DEFAULT NULL,
  execution_issued_at DATETIME DEFAULT NULL,
  amount DECIMAL(12,2) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status VARCHAR(40) DEFAULT 'pending',
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_executions_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 5. CREATE kp_service_attempts ──

CREATE TABLE IF NOT EXISTS kp_service_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  hearing_id INT UNSIGNED DEFAULT NULL,
  party_name VARCHAR(191) NOT NULL,
  party_role VARCHAR(50) NOT NULL,
  service_method ENUM('personal','refused','left_at_dwelling','left_at_office') DEFAULT NULL,
  served_date DATE DEFAULT NULL,
  served_time TIME DEFAULT NULL,
  received_by VARCHAR(191) DEFAULT NULL,
  proof_file_path VARCHAR(500) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kp_service_case (case_id),
  INDEX idx_kp_service_hearing (hearing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── 6. Set kp_status for existing cases ──
-- Mark existing 'Pending' cases as 'filed', so they appear in the new workflow.

UPDATE cases SET kp_status = 'filed' WHERE status = 'Pending' AND kp_status IS NULL;
UPDATE cases SET kp_status = 'mediation_ongoing' WHERE status = 'In Progress' AND kp_status IS NULL;
UPDATE cases SET kp_status = 'closed' WHERE status = 'Resolved' AND kp_status IS NULL;

-- ============================================================
-- DONE. All new tables and columns created.
-- ============================================================
