-- Fresh-install schema and required lookup data only.
-- Import into a new, empty database, then create an administrator.
-- No credentials, patient records, or demo data are included.

-- Mama Care / Lubaga Hospital MCH
-- Fresh-install schema with foreign keys and integrity constraints.
-- Requires MariaDB 10.5+ / MySQL 8.0+
-- Charset: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";

-- --------------------------------------------------------
-- Lookup tables
-- --------------------------------------------------------

CREATE TABLE `client_categories` (
  `code` char(1) NOT NULL,
  `name` varchar(20) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `muac_colors` (
  `code` char(2) NOT NULL,
  `meaning` varchar(50) NOT NULL,
  `range_cm` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_modes` (
  `code` varchar(30) NOT NULL,
  `description` varchar(100) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `baby_conditions` (
  `code` varchar(10) NOT NULL,
  `meaning` varchar(100) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resuscitation_codes` (
  `code` varchar(5) NOT NULL,
  `meaning` varchar(100) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fp_methods` (
  `code` int(11) NOT NULL,
  `method_name` varchar(100) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pnc_timings` (
  `timing` varchar(20) NOT NULL,
  `label` varchar(50) NOT NULL,
  PRIMARY KEY (`timing`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pregnancy_statuses` (
  `status` varchar(20) NOT NULL,
  PRIMARY KEY (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hiv_result_codes` (
  `code` varchar(10) NOT NULL,
  `meaning` varchar(100) NOT NULL,
  `is_positive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Users
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` enum('super_admin','staff_admin','admin','doctor','midwife','nurse','receptionist','manager','community_health_worker') NOT NULL DEFAULT 'staff_admin',
  `job_title` varchar(80) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_role` (`role`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Patients and pregnancy episodes
-- --------------------------------------------------------

CREATE TABLE `patients` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nin` varchar(20) DEFAULT NULL,
  `surname` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `age` int(10) UNSIGNED DEFAULT NULL,
  `client_category` char(1) DEFAULT 'N',
  `phone` varchar(20) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `sub_county` varchar(100) DEFAULT NULL,
  `parish` varchar(100) DEFAULT NULL,
  `village` varchar(100) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nin` (`nin`),
  KEY `idx_name` (`surname`,`first_name`),
  KEY `idx_village` (`village`),
  KEY `idx_phone` (`phone`),
  KEY `fk_patients_category` (`client_category`),
  KEY `fk_patients_created_by` (`created_by`),
  CONSTRAINT `fk_patients_category` FOREIGN KEY (`client_category`) REFERENCES `client_categories` (`code`),
  CONSTRAINT `fk_patients_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pregnancy_episodes` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) UNSIGNED NOT NULL,
  `client_number` varchar(20) NOT NULL,
  `gravida` int(10) UNSIGNED NOT NULL,
  `parity` int(10) UNSIGNED NOT NULL,
  `lmp` date NOT NULL,
  `edd` date DEFAULT NULL,
  `booking_ga_weeks` int(10) UNSIGNED DEFAULT NULL,
  `booking_timing` enum('early','late') DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `closed_date` date DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_number` (`client_number`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_status` (`status`),
  KEY `idx_edd` (`edd`),
  KEY `idx_episode_status_edd` (`status`,`edd`),
  KEY `fk_episodes_created_by` (`created_by`),
  CONSTRAINT `fk_episodes_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_episodes_status` FOREIGN KEY (`status`) REFERENCES `pregnancy_statuses` (`status`),
  CONSTRAINT `fk_episodes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Clinical records
-- --------------------------------------------------------

CREATE TABLE `anc_visits` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pregnancy_episode_id` bigint(20) UNSIGNED NOT NULL,
  `serial_no` int(10) UNSIGNED NOT NULL,
  `visit_number` int(10) UNSIGNED NOT NULL,
  `visit_date` date NOT NULL,
  `next_visit_date` date DEFAULT NULL,
  `gestational_age_weeks` int(10) UNSIGNED DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `muac_cm` decimal(4,1) DEFAULT NULL,
  `muac_color` char(2) DEFAULT NULL,
  `inr_number` varchar(50) DEFAULT NULL,
  `bp_systolic` smallint(5) UNSIGNED DEFAULT NULL,
  `bp_diastolic` smallint(5) UNSIGNED DEFAULT NULL,
  `woman_hiv_code` varchar(10) DEFAULT NULL,
  `hivst_kit_given` tinyint(1) DEFAULT 0,
  `partner_age` int(10) UNSIGNED DEFAULT NULL,
  `partner_result_code` varchar(10) DEFAULT NULL,
  `partner_tfv_code` varchar(10) DEFAULT NULL,
  `partner_linked_to_art` tinyint(1) DEFAULT 0,
  `partner_art_place` varchar(255) DEFAULT NULL,
  `partner_art_number` varchar(50) DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `cd4_value` int(11) DEFAULT NULL,
  `cd4_date` date DEFAULT NULL,
  `viral_load_value` varchar(20) DEFAULT NULL,
  `viral_load_date` date DEFAULT NULL,
  `who_stage` tinyint(4) DEFAULT NULL,
  `emtct_risk` enum('High','Low') DEFAULT NULL,
  `tb_status` varchar(50) DEFAULT NULL,
  `gbv_risk` varchar(50) DEFAULT NULL,
  `blood_screening` varchar(50) DEFAULT NULL,
  `urine_results` varchar(50) DEFAULT NULL,
  `syphilis_result` varchar(10) DEFAULT NULL,
  `hepatitis_b_result` varchar(10) DEFAULT NULL,
  `llin_given` tinyint(1) DEFAULT 0,
  `mebendazole_status` enum('given','not_given','na','completed') DEFAULT NULL,
  `iron_folate_tablets` int(11) DEFAULT NULL,
  `combined_fefan_tablets` int(11) DEFAULT NULL,
  `misoprostol_tablets` int(11) DEFAULT NULL,
  `other_treatment` text DEFAULT NULL,
  `referral_type` enum('REF_OUT','REF_IN','C_REF_IN','FSG') DEFAULT NULL,
  `referral_number` varchar(50) DEFAULT NULL,
  `referred_to` text DEFAULT NULL,
  `complications` text DEFAULT NULL,
  `tt_dose_received` tinyint(4) DEFAULT NULL,
  `iptp_dose` varchar(10) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_episode_visit` (`pregnancy_episode_id`,`visit_number`),
  KEY `idx_visit_date` (`visit_date`),
  KEY `idx_anc_episode_date` (`pregnancy_episode_id`,`visit_date`),
  KEY `idx_next_visit` (`next_visit_date`),
  KEY `idx_muac_color` (`muac_color`),
  KEY `fk_anc_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_anc_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  CONSTRAINT `fk_anc_muac` FOREIGN KEY (`muac_color`) REFERENCES `muac_colors` (`code`),
  CONSTRAINT `fk_anc_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_anc_iron` CHECK (`iron_folate_tablets` IS NULL OR `iron_folate_tablets` >= 0),
  CONSTRAINT `chk_anc_fefan` CHECK (`combined_fefan_tablets` IS NULL OR `combined_fefan_tablets` >= 0),
  CONSTRAINT `chk_anc_misoprostol` CHECK (`misoprostol_tablets` IS NULL OR `misoprostol_tablets` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_records` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pregnancy_episode_id` bigint(20) UNSIGNED NOT NULL,
  `admission_no` int(10) UNSIGNED NOT NULL,
  `ipd_no` varchar(50) NOT NULL,
  `admission_datetime` datetime NOT NULL,
  `reason_for_admission` text DEFAULT NULL,
  `gestation_weeks` int(11) DEFAULT NULL,
  `term_preterm` enum('T','P') DEFAULT NULL,
  `who_stage` tinyint(4) DEFAULT NULL,
  `cd4_value` int(11) DEFAULT NULL,
  `cd4_date` date DEFAULT NULL,
  `viral_load_value` varchar(20) DEFAULT NULL,
  `viral_load_date` date DEFAULT NULL,
  `emtct_code_woman` varchar(10) DEFAULT NULL,
  `emtct_code_partner` varchar(10) DEFAULT NULL,
  `partner_art_code` varchar(10) DEFAULT NULL,
  `mother_art_code` varchar(10) DEFAULT NULL,
  `mother_art_number` varchar(50) DEFAULT NULL,
  `ctx_given` tinyint(1) DEFAULT 0,
  `syphilis_result` varchar(10) DEFAULT NULL,
  `hepatitis_b_result` varchar(10) DEFAULT NULL,
  `muac_cm` decimal(4,1) DEFAULT NULL,
  `muac_color` char(2) DEFAULT NULL,
  `inr_number` varchar(50) DEFAULT NULL,
  `mode_of_delivery` varchar(30) NOT NULL,
  `delivery_datetime` datetime NOT NULL,
  `bba` tinyint(1) DEFAULT 0,
  `active_management_3rd_stage` varchar(20) DEFAULT NULL,
  `management_complication` text DEFAULT NULL,
  `other_treatment` text DEFAULT NULL,
  `baby_sex` enum('M','F') NOT NULL,
  `baby_weight_kg` decimal(5,2) DEFAULT NULL,
  `apgar_1min` tinyint(4) DEFAULT NULL,
  `apgar_5min` tinyint(4) DEFAULT NULL,
  `apgar_asphyxia` tinyint(1) DEFAULT 0,
  `resuscitation_code` varchar(5) DEFAULT NULL,
  `skin_to_skin` tinyint(1) DEFAULT 0,
  `source_warmth` varchar(20) DEFAULT 'mother_warmth',
  `breastfed_1hr` tinyint(1) DEFAULT 0,
  `routine_meds_teo` tinyint(1) DEFAULT NULL,
  `routine_meds_vitk` tinyint(1) DEFAULT NULL,
  `routine_meds_chlorhexidine` tinyint(1) DEFAULT NULL,
  `risk_status` enum('HIGH','LOW') DEFAULT NULL,
  `infant_arv_given` tinyint(1) DEFAULT NULL,
  `infant_arv_duration` varchar(50) DEFAULT NULL,
  `postpartum_fp_code` int(11) DEFAULT NULL,
  `postpartum_fp_other` varchar(100) DEFAULT NULL,
  `baby_condition_at_discharge` varchar(10) DEFAULT NULL,
  `number_of_babies` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `maternal_death` tinyint(1) DEFAULT 0,
  `maternal_death_cause` text DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_episode_delivery` (`pregnancy_episode_id`),
  UNIQUE KEY `uniq_ipd_no` (`ipd_no`),
  KEY `idx_delivery_datetime` (`delivery_datetime`),
  KEY `fk_delivery_mode` (`mode_of_delivery`),
  KEY `fk_delivery_baby_condition` (`baby_condition_at_discharge`),
  KEY `fk_delivery_fp` (`postpartum_fp_code`),
  KEY `fk_delivery_resus` (`resuscitation_code`),
  KEY `fk_delivery_muac` (`muac_color`),
  KEY `fk_delivery_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_delivery_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  CONSTRAINT `fk_delivery_mode` FOREIGN KEY (`mode_of_delivery`) REFERENCES `delivery_modes` (`code`),
  CONSTRAINT `fk_delivery_baby_condition` FOREIGN KEY (`baby_condition_at_discharge`) REFERENCES `baby_conditions` (`code`),
  CONSTRAINT `fk_delivery_fp` FOREIGN KEY (`postpartum_fp_code`) REFERENCES `fp_methods` (`code`),
  CONSTRAINT `fk_delivery_resus` FOREIGN KEY (`resuscitation_code`) REFERENCES `resuscitation_codes` (`code`),
  CONSTRAINT `fk_delivery_muac` FOREIGN KEY (`muac_color`) REFERENCES `muac_colors` (`code`),
  CONSTRAINT `fk_delivery_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `postnatal_visits` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pregnancy_episode_id` bigint(20) UNSIGNED NOT NULL,
  `serial_no` int(10) UNSIGNED NOT NULL,
  `visit_timing` varchar(20) NOT NULL,
  `visit_date` date NOT NULL,
  `fp_method_code` int(11) DEFAULT NULL,
  `fp_other` text DEFAULT NULL,
  `breast_status` enum('FOM','SS','ND') DEFAULT NULL,
  `cervix_status` enum('FOM','SS','ND') DEFAULT NULL,
  `mother_weight_kg` decimal(5,2) DEFAULT NULL,
  `mother_muac_cm` decimal(4,1) DEFAULT NULL,
  `mother_muac_color` char(2) DEFAULT NULL,
  `mother_emtct_code` varchar(10) DEFAULT NULL,
  `partner_emtct_code` varchar(10) DEFAULT NULL,
  `partner_tfv_code` varchar(10) DEFAULT NULL,
  `mother_art_code` varchar(10) DEFAULT NULL,
  `mother_art_number` varchar(50) DEFAULT NULL,
  `mother_ctx_given` tinyint(1) DEFAULT NULL,
  `mother_diagnosis` text DEFAULT NULL,
  `baby_status` varchar(50) DEFAULT NULL,
  `baby_age_days` int(11) DEFAULT NULL,
  `baby_weight_kg` decimal(5,2) DEFAULT NULL,
  `baby_diagnosis` text DEFAULT NULL,
  `infant_feeding_option` varchar(100) DEFAULT NULL,
  `immunization_status` text DEFAULT NULL,
  `dbs_sample_taken` tinyint(1) DEFAULT NULL,
  `dbs_test_result` enum('negative','positive','pending') DEFAULT NULL,
  `baby_septrin_given` tinyint(1) DEFAULT NULL,
  `baby_referred` tinyint(1) DEFAULT NULL,
  `baby_referral_place` varchar(255) DEFAULT NULL,
  `vitamin_a_given` tinyint(1) DEFAULT NULL,
  `baby_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pnc_episode` (`pregnancy_episode_id`),
  KEY `idx_pnc_date` (`visit_date`),
  KEY `idx_pnc_episode_date` (`pregnancy_episode_id`,`visit_date`),
  KEY `fk_pnc_timing` (`visit_timing`),
  KEY `fk_pnc_fp` (`fp_method_code`),
  KEY `fk_pnc_muac` (`mother_muac_color`),
  KEY `fk_pnc_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_pnc_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`),
  CONSTRAINT `fk_pnc_timing` FOREIGN KEY (`visit_timing`) REFERENCES `pnc_timings` (`timing`),
  CONSTRAINT `fk_pnc_fp` FOREIGN KEY (`fp_method_code`) REFERENCES `fp_methods` (`code`),
  CONSTRAINT `fk_pnc_muac` FOREIGN KEY (`mother_muac_color`) REFERENCES `muac_colors` (`code`),
  CONSTRAINT `fk_pnc_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointment_types` (
  `code` varchar(20) NOT NULL,
  `label` varchar(50) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `appointments` (
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
  KEY `idx_appt_baby` (`baby_id`),
  CONSTRAINT `fk_appt_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  CONSTRAINT `fk_appt_episode` FOREIGN KEY (`pregnancy_episode_id`) REFERENCES `pregnancy_episodes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_type` FOREIGN KEY (`appointment_type`) REFERENCES `appointment_types` (`code`),
  CONSTRAINT `fk_appt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `babies` (
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

ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appt_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL;

CREATE TABLE `screening_catalog` (
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `population` enum('maternal','newborn','any') NOT NULL DEFAULT 'maternal',
  `value_kind` enum('numeric','categorical','text') NOT NULL DEFAULT 'categorical',
  `default_unit` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `screening_results` (
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

CREATE TABLE `referrals` (
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

CREATE TABLE `child_health_visits` (
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

CREATE TABLE `child_immunizations` (
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

CREATE TABLE `commodities` (
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

CREATE TABLE `commodity_transactions` (
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

CREATE TABLE `staffing_records` (
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

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`username`,`ip_address`,`attempted_at`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `postnatal_visits`
  ADD KEY `fk_pnc_baby` (`baby_id`),
  ADD CONSTRAINT `fk_pnc_baby` FOREIGN KEY (`baby_id`) REFERENCES `babies` (`id`) ON DELETE SET NULL;

-- Clinical range limits
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

SET FOREIGN_KEY_CHECKS = 1;


-- Lookup seed for fresh installations. Does not create users or patient records.

SET NAMES utf8mb4;

INSERT INTO `client_categories` (`code`, `name`) VALUES
('F', 'Foreigner'),
('N', 'National'),
('R', 'Refugee');

INSERT INTO `muac_colors` (`code`, `meaning`, `range_cm`) VALUES
('G', 'Normal', '≥23.0cm'),
('ND', 'Not Done', ''),
('R', 'Severe Acute Malnutrition', '<19.0cm'),
('Y', 'Moderate Acute Malnutrition', '19.0-22.9cm');

INSERT INTO `delivery_modes` (`code`, `description`) VALUES
('breech', 'Breech'),
('caesarean_section', 'Caesarean Section'),
('forceps', 'Forceps'),
('normal_vaginal', 'Normal Vaginal'),
('other', 'Other'),
('vacuum', 'Vacuum Extraction');

INSERT INTO `baby_conditions` (`code`, `meaning`) VALUES
('AL', 'Alive'),
('AL_R', 'Alive Referred'),
('BDF', 'Born with Defect'),
('FSB', 'Fresh Still Birth'),
('MSB', 'Macerated Still Birth'),
('NND', 'Neonatal Death');

INSERT INTO `resuscitation_codes` (`code`, `meaning`) VALUES
('BM', 'Bag & Mask'),
('BMD', 'Bag & Mask - Died'),
('NA', 'Normal breathing'),
('SS', 'Stimulation/Suction');

INSERT INTO `fp_methods` (`code`, `method_name`) VALUES
(1, 'Oral: Lo-Femeral'),
(2, 'Oral: Microgynon'),
(3, 'Oral: Overtre/POP'),
(4, 'Oral: Others'),
(5, 'Female Condoms'),
(6, 'Male Condoms'),
(7, 'IUDs'),
(8, 'Injectable'),
(9, 'Natural'),
(10, 'Female Sterilization'),
(11, 'Male Sterilization'),
(12, 'Implants'),
(13, 'Other FP'),
(14, 'No FP method');

INSERT INTO `pnc_timings` (`timing`, `label`) VALUES
('24_hours', '24 Hours'),
('6_days', '6 Days'),
('6_weeks', '6 Weeks'),
('6_months', '6 Months'),
('other', 'Other');

INSERT INTO `pregnancy_statuses` (`status`) VALUES
('aborted'),
('active'),
('delivered'),
('lost_to_followup'),
('transferred');

INSERT INTO `hiv_result_codes` (`code`, `meaning`, `is_positive`) VALUES
('TR', 'HIV negative', 0),
('TR+', 'HIV negative, known', 0),
('TRK', 'HIV negative, known status', 0),
('TRV', 'HIV negative, retested', 0),
('TRR', 'HIV positive', 1),
('TRR+', 'HIV positive, known', 1),
('TRRK', 'HIV positive, known status', 1);

INSERT INTO `appointment_types` (`code`, `label`) VALUES
('ANC', 'Antenatal care'),
('DELIVERY', 'Delivery review'),
('PNC', 'Postnatal care'),
('CHILD', 'Child health follow-up'),
('OTHER', 'Other');

INSERT INTO `screening_catalog` (`code`, `name`, `population`, `value_kind`, `default_unit`) VALUES
('HAEMOGLOBIN', 'Haemoglobin', 'maternal', 'numeric', NULL),
('FERRITIN', 'Ferritin', 'maternal', 'numeric', NULL),
('THYROID', 'Thyroid function', 'maternal', 'text', NULL),
('HIV', 'HIV screening', 'any', 'categorical', NULL),
('SYPHILIS', 'Syphilis screening', 'maternal', 'categorical', NULL),
('HEPATITIS_B', 'Hepatitis B screening', 'maternal', 'categorical', NULL),
('URINALYSIS', 'Urinalysis', 'maternal', 'text', NULL),
('OTHER', 'Other screening', 'any', 'text', NULL);
