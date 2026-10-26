-- Upgrade an existing u454222977_lubaga / Mama Care database
-- to the cleaned schema. Run this on the live database BEFORE
-- deploying the updated PHP that writes recorded_by / next_visit_date.
-- MariaDB 10.5+ required (ADD COLUMN IF NOT EXISTS).

SET NAMES utf8mb4;

-- 1. Clean invalid values that would block constraints
UPDATE `anc_visits`
SET `referral_type` = NULL
WHERE `referral_type` = '' OR `referral_type` IS NULL;

UPDATE `anc_visits`
SET `iron_folate_tablets` = NULL
WHERE `iron_folate_tablets` < 0;

UPDATE `anc_visits`
SET `combined_fefan_tablets` = NULL
WHERE `combined_fefan_tablets` < 0;

UPDATE `anc_visits`
SET `misoprostol_tablets` = NULL
WHERE `misoprostol_tablets` < 0;

UPDATE `patients`
SET `nin` = NULL
WHERE `nin` = '';

-- 2. New columns
ALTER TABLE `patients`
  ADD COLUMN IF NOT EXISTS `created_by` int(10) UNSIGNED DEFAULT NULL AFTER `village`;

ALTER TABLE `pregnancy_episodes`
  ADD COLUMN IF NOT EXISTS `created_by` int(10) UNSIGNED DEFAULT NULL AFTER `closed_date`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `anc_visits`
  ADD COLUMN IF NOT EXISTS `next_visit_date` date DEFAULT NULL AFTER `visit_date`,
  ADD COLUMN IF NOT EXISTS `recorded_by` int(10) UNSIGNED DEFAULT NULL AFTER `iptp_dose`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `delivery_records`
  ADD COLUMN IF NOT EXISTS `recorded_by` int(10) UNSIGNED DEFAULT NULL AFTER `maternal_death_cause`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `postnatal_visits`
  ADD COLUMN IF NOT EXISTS `recorded_by` int(10) UNSIGNED DEFAULT NULL AFTER `vitamin_a_given`,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `pnc_timings`
  ADD COLUMN IF NOT EXISTS `label` varchar(50) DEFAULT NULL AFTER `timing`;

UPDATE `pnc_timings` SET `label` = '24 Hours' WHERE `timing` = '24_hours' AND (`label` IS NULL OR `label` = '');
UPDATE `pnc_timings` SET `label` = '6 Days' WHERE `timing` = '6_days' AND (`label` IS NULL OR `label` = '');
UPDATE `pnc_timings` SET `label` = '6 Weeks' WHERE `timing` = '6_weeks' AND (`label` IS NULL OR `label` = '');
UPDATE `pnc_timings` SET `label` = '6 Months' WHERE `timing` = '6_months' AND (`label` IS NULL OR `label` = '');
UPDATE `pnc_timings` SET `label` = 'Other' WHERE `timing` = 'other' AND (`label` IS NULL OR `label` = '');

-- 3. Lookup that was missing
CREATE TABLE IF NOT EXISTS `hiv_result_codes` (
  `code` varchar(10) NOT NULL,
  `meaning` varchar(100) NOT NULL,
  `is_positive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hiv_result_codes` (`code`, `meaning`, `is_positive`) VALUES
('TR', 'HIV negative', 0),
('TR+', 'HIV negative, known', 0),
('TRK', 'HIV negative, known status', 0),
('TRV', 'HIV negative, retested', 0),
('TRR', 'HIV positive', 1),
('TRR+', 'HIV positive, known', 1),
('TRRK', 'HIV positive, known status', 1);

-- 4. Unique keys (ignore error if they already exist)
ALTER TABLE `anc_visits`
  ADD UNIQUE KEY `uniq_episode_visit` (`pregnancy_episode_id`, `visit_number`);

ALTER TABLE `delivery_records`
  ADD UNIQUE KEY `uniq_episode_delivery` (`pregnancy_episode_id`),
  ADD UNIQUE KEY `uniq_ipd_no` (`ipd_no`);

-- 5. Foreign keys — skip any that already exist
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patients_category` FOREIGN KEY (`client_category`) REFERENCES `client_categories` (`code`),
  ADD CONSTRAINT `fk_patients_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `pregnancy_episodes`
  ADD CONSTRAINT `fk_episodes_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `fk_episodes_status` FOREIGN KEY (`status`) REFERENCES `pregnancy_statuses` (`status`),
  ADD CONSTRAINT `fk_episodes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `anc_visits`
  ADD CONSTRAINT `fk_anc_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  ADD CONSTRAINT `fk_anc_muac` FOREIGN KEY (`muac_color`) REFERENCES `muac_colors` (`code`),
  ADD CONSTRAINT `fk_anc_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `delivery_records`
  ADD CONSTRAINT `fk_delivery_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  ADD CONSTRAINT `fk_delivery_mode` FOREIGN KEY (`mode_of_delivery`) REFERENCES `delivery_modes` (`code`),
  ADD CONSTRAINT `fk_delivery_baby_condition` FOREIGN KEY (`baby_condition_at_discharge`) REFERENCES `baby_conditions` (`code`),
  ADD CONSTRAINT `fk_delivery_fp` FOREIGN KEY (`postpartum_fp_code`) REFERENCES `fp_methods` (`code`),
  ADD CONSTRAINT `fk_delivery_resus` FOREIGN KEY (`resuscitation_code`) REFERENCES `resuscitation_codes` (`code`),
  ADD CONSTRAINT `fk_delivery_muac` FOREIGN KEY (`muac_color`) REFERENCES `muac_colors` (`code`),
  ADD CONSTRAINT `fk_delivery_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `postnatal_visits`
  ADD CONSTRAINT `fk_pnc_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  ADD CONSTRAINT `fk_pnc_timing` FOREIGN KEY (`visit_timing`) REFERENCES `pnc_timings` (`timing`),
  ADD CONSTRAINT `fk_pnc_fp` FOREIGN KEY (`fp_method_code`) REFERENCES `fp_methods` (`code`),
  ADD CONSTRAINT `fk_pnc_muac` FOREIGN KEY (`mother_muac_color`) REFERENCES `muac_colors` (`code`),
  ADD CONSTRAINT `fk_pnc_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `system_logs`
  ADD CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
