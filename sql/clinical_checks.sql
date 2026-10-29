-- Database-level range limits for clinical values (defence in depth behind the PHP validation).
-- Fresh installs already include these via schema.sql. For an existing database run this file ONCE,
-- after confirming the pre-check queries at the bottom return no rows (existing rows are validated).

ALTER TABLE `patients`
  ADD CONSTRAINT `chk_patients_age` CHECK (`age` IS NULL OR `age` BETWEEN 0 AND 120);

ALTER TABLE `pregnancy_episodes`
  ADD CONSTRAINT `chk_episode_gravida` CHECK (`gravida` BETWEEN 0 AND 30),
  ADD CONSTRAINT `chk_episode_parity` CHECK (`parity` BETWEEN 0 AND 30);

ALTER TABLE `anc_visits`
  ADD CONSTRAINT `chk_anc_weight` CHECK (`weight_kg` IS NULL OR `weight_kg` BETWEEN 20 AND 300),
  ADD CONSTRAINT `chk_anc_height` CHECK (`height_cm` IS NULL OR `height_cm` BETWEEN 80 AND 250),
  ADD CONSTRAINT `chk_anc_muac` CHECK (`muac_cm` IS NULL OR `muac_cm` BETWEEN 5 AND 60),
  ADD CONSTRAINT `chk_anc_bp_systolic` CHECK (`bp_systolic` IS NULL OR `bp_systolic` BETWEEN 40 AND 320),
  ADD CONSTRAINT `chk_anc_bp_diastolic` CHECK (`bp_diastolic` IS NULL OR `bp_diastolic` BETWEEN 20 AND 220),
  ADD CONSTRAINT `chk_anc_bp_order` CHECK (`bp_systolic` IS NULL OR `bp_diastolic` IS NULL OR `bp_diastolic` < `bp_systolic`),
  ADD CONSTRAINT `chk_anc_partner_age` CHECK (`partner_age` IS NULL OR `partner_age` BETWEEN 10 AND 120),
  ADD CONSTRAINT `chk_anc_cd4` CHECK (`cd4_value` IS NULL OR `cd4_value` BETWEEN 0 AND 5000);

ALTER TABLE `delivery_records`
  ADD CONSTRAINT `chk_delivery_gestation` CHECK (`gestation_weeks` IS NULL OR `gestation_weeks` BETWEEN 15 AND 50),
  ADD CONSTRAINT `chk_delivery_muac` CHECK (`muac_cm` IS NULL OR `muac_cm` BETWEEN 5 AND 60),
  ADD CONSTRAINT `chk_delivery_baby_weight` CHECK (`baby_weight_kg` IS NULL OR `baby_weight_kg` BETWEEN 0.2 AND 8),
  ADD CONSTRAINT `chk_delivery_apgar1` CHECK (`apgar_1min` IS NULL OR `apgar_1min` BETWEEN 0 AND 10),
  ADD CONSTRAINT `chk_delivery_apgar5` CHECK (`apgar_5min` IS NULL OR `apgar_5min` BETWEEN 0 AND 10);

ALTER TABLE `babies`
  ADD CONSTRAINT `chk_baby_weight` CHECK (`baby_weight_kg` IS NULL OR `baby_weight_kg` BETWEEN 0.2 AND 8),
  ADD CONSTRAINT `chk_baby_apgar1` CHECK (`apgar_1min` IS NULL OR `apgar_1min` BETWEEN 0 AND 10),
  ADD CONSTRAINT `chk_baby_apgar5` CHECK (`apgar_5min` IS NULL OR `apgar_5min` BETWEEN 0 AND 10);

ALTER TABLE `postnatal_visits`
  ADD CONSTRAINT `chk_pnc_mother_weight` CHECK (`mother_weight_kg` IS NULL OR `mother_weight_kg` BETWEEN 20 AND 300),
  ADD CONSTRAINT `chk_pnc_mother_muac` CHECK (`mother_muac_cm` IS NULL OR `mother_muac_cm` BETWEEN 5 AND 60),
  ADD CONSTRAINT `chk_pnc_baby_age` CHECK (`baby_age_days` IS NULL OR `baby_age_days` BETWEEN 0 AND 400),
  ADD CONSTRAINT `chk_pnc_baby_weight` CHECK (`baby_weight_kg` IS NULL OR `baby_weight_kg` BETWEEN 0.2 AND 8);

ALTER TABLE `child_health_visits`
  ADD CONSTRAINT `chk_child_weight` CHECK (`weight_kg` IS NULL OR `weight_kg` BETWEEN 0.2 AND 100);

-- Pre-check (run BEFORE the ALTERs on an existing database; every query should return 0):
-- SELECT COUNT(*) FROM anc_visits WHERE weight_kg NOT BETWEEN 20 AND 300 OR height_cm NOT BETWEEN 80 AND 250
--   OR muac_cm NOT BETWEEN 5 AND 60 OR bp_systolic NOT BETWEEN 40 AND 320 OR bp_diastolic NOT BETWEEN 20 AND 220
--   OR bp_diastolic >= bp_systolic OR partner_age NOT BETWEEN 10 AND 120 OR cd4_value NOT BETWEEN 0 AND 5000;
-- SELECT COUNT(*) FROM delivery_records WHERE gestation_weeks NOT BETWEEN 15 AND 50 OR muac_cm NOT BETWEEN 5 AND 60
--   OR baby_weight_kg NOT BETWEEN 0.2 AND 8 OR apgar_1min NOT BETWEEN 0 AND 10 OR apgar_5min NOT BETWEEN 0 AND 10;
-- SELECT COUNT(*) FROM postnatal_visits WHERE mother_weight_kg NOT BETWEEN 20 AND 300 OR mother_muac_cm NOT BETWEEN 5 AND 60
--   OR baby_age_days NOT BETWEEN 0 AND 400 OR baby_weight_kg NOT BETWEEN 0.2 AND 8;
