-- Upgrade a legacy Mama Care database to the cleaned schema.
-- Back up the database, review the script against its schema, and run once
-- before deploying the updated PHP. This migration is not safe to rerun.
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

-- 5. Add foreign keys. Existing constraints must be reviewed before running.
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

-- 6. Staff administration, appointment scheduling, and individual newborn records
ALTER TABLE `users`
  MODIFY COLUMN `role` enum('super_admin','staff_admin','admin','doctor','midwife','nurse','receptionist','manager','community_health_worker') NOT NULL DEFAULT 'staff_admin',
  ADD COLUMN IF NOT EXISTS `job_title` varchar(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `phone` varchar(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `email` varchar(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_by` int(10) UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_by` int(10) UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  ADD KEY `idx_users_role` (`role`,`is_active`);

ALTER TABLE `patients`
  ADD COLUMN IF NOT EXISTS `updated_by` int(10) UNSIGNED DEFAULT NULL AFTER `created_by`;

ALTER TABLE `delivery_records`
  ADD COLUMN IF NOT EXISTS `number_of_babies` tinyint(3) UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `appointment_types` (
  `code` varchar(20) NOT NULL,
  `label` varchar(50) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `appointment_types` (`code`, `label`) VALUES
('ANC', 'Antenatal care'),
('DELIVERY', 'Delivery review'),
('PNC', 'Postnatal care'),
('CHILD', 'Child health follow-up'),
('OTHER', 'Other');

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `pregnancy_episode_id` bigint(20) UNSIGNED DEFAULT NULL,
  `baby_id` bigint(20) UNSIGNED DEFAULT NULL,
  `appointment_type` varchar(20) NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `status` enum('scheduled','completed','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appt_date` (`scheduled_date`,`status`),
  KEY `idx_appt_patient` (`patient_id`),
  KEY `idx_appt_episode` (`pregnancy_episode_id`),
  CONSTRAINT `fk_appt_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_appt_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_type` FOREIGN KEY (`appointment_type`) REFERENCES `appointment_types` (`code`),
  CONSTRAINT `fk_appt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `appointments`
  ADD COLUMN IF NOT EXISTS `baby_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `pregnancy_episode_id`,
  ADD KEY `idx_appt_baby` (`baby_id`);

CREATE TABLE IF NOT EXISTS `babies` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_record_id` bigint(20) UNSIGNED NOT NULL,
  `birth_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `baby_sex` enum('M','F') NOT NULL,
  `baby_weight_kg` decimal(5,2) DEFAULT NULL,
  `apgar_1min` tinyint(4) DEFAULT NULL,
  `apgar_5min` tinyint(4) DEFAULT NULL,
  `apgar_asphyxia` tinyint(1) DEFAULT 0,
  `resuscitation_code` varchar(5) DEFAULT NULL,
  `skin_to_skin` tinyint(1) DEFAULT 0,
  `breastfed_1hr` tinyint(1) DEFAULT 0,
  `routine_meds_teo` tinyint(1) DEFAULT NULL,
  `routine_meds_vitk` tinyint(1) DEFAULT NULL,
  `routine_meds_chlorhexidine` tinyint(1) DEFAULT NULL,
  `condition_at_discharge` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_delivery_birth_order` (`delivery_record_id`,`birth_order`),
  KEY `fk_baby_resus` (`resuscitation_code`),
  KEY `fk_baby_condition` (`condition_at_discharge`),
  CONSTRAINT `fk_baby_delivery` FOREIGN KEY (`delivery_record_id`) REFERENCES `delivery_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_baby_resus` FOREIGN KEY (`resuscitation_code`) REFERENCES `resuscitation_codes` (`code`),
  CONSTRAINT `fk_baby_condition` FOREIGN KEY (`condition_at_discharge`) REFERENCES `baby_conditions` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `postnatal_visits`
  ADD COLUMN IF NOT EXISTS `baby_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `vitamin_a_given`,
  ADD KEY `fk_pnc_baby` (`baby_id`),
  ADD CONSTRAINT `fk_pnc_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL;

ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appt_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `screening_catalog` (
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `population` enum('maternal','newborn','any') NOT NULL DEFAULT 'maternal',
  `value_kind` enum('numeric','categorical','text') NOT NULL DEFAULT 'categorical',
  `default_unit` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `screening_catalog` (`code`, `name`, `population`, `value_kind`, `default_unit`) VALUES
('HAEMOGLOBIN', 'Haemoglobin', 'maternal', 'numeric', NULL),
('FERRITIN', 'Ferritin', 'maternal', 'numeric', NULL),
('THYROID', 'Thyroid function', 'maternal', 'text', NULL),
('HIV', 'HIV screening', 'any', 'categorical', NULL),
('SYPHILIS', 'Syphilis screening', 'maternal', 'categorical', NULL),
('HEPATITIS_B', 'Hepatitis B screening', 'maternal', 'categorical', NULL),
('URINALYSIS', 'Urinalysis', 'maternal', 'text', NULL),
('OTHER', 'Other screening', 'any', 'text', NULL);

CREATE TABLE IF NOT EXISTS `screening_results` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `pregnancy_episode_id` bigint(20) UNSIGNED DEFAULT NULL,
  `baby_id` bigint(20) UNSIGNED DEFAULT NULL,
  `screening_code` varchar(30) NOT NULL,
  `performed_at` date NOT NULL,
  `result_status` enum('pending','normal','abnormal','positive','negative','inconclusive') NOT NULL,
  `method` varchar(100) DEFAULT NULL,
  `numeric_value` decimal(10,3) DEFAULT NULL,
  `value_unit` varchar(30) DEFAULT NULL,
  `result_text` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_screening_patient_date` (`patient_id`,`performed_at`),
  KEY `idx_screening_episode` (`pregnancy_episode_id`),
  KEY `idx_screening_baby` (`baby_id`),
  KEY `idx_screening_code_date` (`screening_code`,`performed_at`),
  CONSTRAINT `fk_screening_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_screening_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_screening_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_screening_catalog` FOREIGN KEY (`screening_code`) REFERENCES `screening_catalog` (`code`),
  CONSTRAINT `fk_screening_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referrals` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `pregnancy_episode_id` bigint(20) UNSIGNED DEFAULT NULL,
  `baby_id` bigint(20) UNSIGNED DEFAULT NULL,
  `screening_result_id` bigint(20) UNSIGNED DEFAULT NULL,
  `referral_date` date NOT NULL,
  `reason_category` enum('danger_sign','abnormal_screening','specialist_review','emergency','community_followup','other') NOT NULL,
  `reason` varchar(255) NOT NULL,
  `urgency` enum('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
  `destination` varchar(150) NOT NULL,
  `status` enum('initiated','accepted','completed','not_completed','declined') NOT NULL DEFAULT 'initiated',
  `followup_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `outcome_notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_referral_status_date` (`status`,`referral_date`),
  KEY `idx_referral_patient` (`patient_id`,`referral_date`),
  KEY `idx_referral_episode` (`pregnancy_episode_id`),
  KEY `idx_referral_baby` (`baby_id`),
  KEY `idx_referral_screening` (`screening_result_id`),
  KEY `idx_referral_followup` (`followup_date`,`status`),
  CONSTRAINT `fk_referral_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_referral_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_referral_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_referral_screening` FOREIGN KEY (`screening_result_id`) REFERENCES `screening_results` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_referral_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `child_health_visits` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `baby_id` bigint(20) UNSIGNED NOT NULL,
  `visit_date` date NOT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `length_cm` decimal(5,2) DEFAULT NULL,
  `head_circumference_cm` decimal(5,2) DEFAULT NULL,
  `feeding_status` varchar(100) DEFAULT NULL,
  `developmental_concerns` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_child_visit_baby_date` (`baby_id`,`visit_date`),
  CONSTRAINT `fk_child_visit_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_child_visit_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `child_immunizations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `baby_id` bigint(20) UNSIGNED NOT NULL,
  `child_health_visit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `vaccine_name` varchar(120) NOT NULL,
  `dose_label` varchar(50) DEFAULT NULL,
  `event_date` date NOT NULL,
  `status` enum('given','not_given','missed','deferred','unknown') NOT NULL,
  `lot_number` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_child_immunization_baby_date` (`baby_id`,`event_date`),
  KEY `idx_child_immunization_visit` (`child_health_visit_id`),
  CONSTRAINT `fk_child_immunization_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_child_immunization_visit` FOREIGN KEY (`child_health_visit_id`) REFERENCES `child_health_visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_child_immunization_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commodities` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `reorder_level` decimal(12,2) NOT NULL DEFAULT 0,
  `lead_time_days` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_commodity_code` (`code`),
  KEY `idx_commodity_active` (`is_active`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commodity_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `commodity_id` int(10) UNSIGNED NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('receipt','issue','adjustment') NOT NULL,
  `quantity_delta` decimal(12,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_commodity_tx_date` (`commodity_id`,`transaction_date`),
  CONSTRAINT `fk_commodity_tx_item` FOREIGN KEY (`commodity_id`) REFERENCES `commodities` (`id`),
  CONSTRAINT `fk_commodity_tx_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staffing_records` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_start` date NOT NULL,
  `cadre` varchar(80) NOT NULL,
  `planned_fte` decimal(6,2) NOT NULL DEFAULT 0,
  `available_fte` decimal(6,2) NOT NULL DEFAULT 0,
  `available_hours` decimal(8,2) DEFAULT NULL,
  `source` varchar(150) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_staffing_period_cadre` (`month_start`,`cadre`),
  CONSTRAINT `fk_staffing_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
