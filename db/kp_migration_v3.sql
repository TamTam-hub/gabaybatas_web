-- ============================================================
-- GabayBatas KP System — Migration v3
-- Transmittal of settlements/awards to the city/municipal court
-- (LGC Sec. 419: within 5 days). ADDITIVE ONLY; safe to re-run.
-- Coverage reasons for complaints not covered by KP are stored in
-- the existing kp_referrals table (no change needed).
-- ============================================================

ALTER TABLE kp_settlements
  ADD COLUMN IF NOT EXISTS transmitted_at DATE DEFAULT NULL AFTER final_at;
