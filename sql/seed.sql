-- Demo / lookup seed for Mama Care
-- Password for every demo user: Admin123
-- Hashed with PHP password_hash() (bcrypt). Change these in production.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `is_active`) VALUES
(1, 'admin', '$2y$12$JTu3ErrNDX72NRtWFsRP1ujczAL2/oseKcxYcPzpGTknvnwCteQpm', 'System Administrator', 'admin', 1),
(2, 'dr_namukwaya', '$2y$12$JTu3ErrNDX72NRtWFsRP1ujczAL2/oseKcxYcPzpGTknvnwCteQpm', 'Dr. Sarah Namukwaya', 'doctor', 1),
(3, 'midwife_grace', '$2y$12$JTu3ErrNDX72NRtWFsRP1ujczAL2/oseKcxYcPzpGTknvnwCteQpm', 'Grace Atim', 'midwife', 1),
(4, 'reception_john', '$2y$12$JTu3ErrNDX72NRtWFsRP1ujczAL2/oseKcxYcPzpGTknvnwCteQpm', 'John Mukasa', 'receptionist', 1),
(5, 'manager_mary', '$2y$12$JTu3ErrNDX72NRtWFsRP1ujczAL2/oseKcxYcPzpGTknvnwCteQpm', 'Mary Nabatanzi', 'manager', 1);

-- Anonymised sample mothers so ANC, maternity, and PNC screens have data to show.
INSERT INTO `patients` (`id`, `nin`, `surname`, `first_name`, `age`, `client_category`, `phone`, `district`, `sub_county`, `parish`, `village`, `created_by`) VALUES
(1, 'CM90000001DEMO', 'NABUKENYA', 'JANE', 23, 'N', '0775000001', 'KAMPALA', 'MATUGGA', 'KIBOWA', 'KIBOWA A', 1),
(2, 'CF90000002DEMO', 'MUKISA', 'KIRABO', 35, 'N', '0705000002', 'KAMPALA', 'CENTRAL', 'MAKERERE', 'KIKONI', 1),
(3, 'CF90000003DEMO', 'NAKAYIWA', 'MARY', 39, 'N', '0775000003', 'MASAKA', 'NYENDO', 'KABULAMU', 'KIGANYI', 1),
(4, 'CF90000004DEMO', 'HADIJAH', 'MADINAH', 32, 'N', '0775000004', 'KAMPALA', 'CENTRAL', 'MAKINDYE', 'KISENYI', 1),
(5, 'CF90000005DEMO', 'KANTANGWA', 'PROSSY', 34, 'N', '0775000005', 'WAKISO', 'KIREKA', 'KAMULI', 'WARD 1', 1),
(6, 'CF90000006DEMO', 'NALUBEGA', 'EDITH', 30, 'N', '0790000006', 'MASAKA', 'CENTRAL', 'NYENDO', 'MUKUNGWE', 1);

INSERT INTO `pregnancy_episodes` (`id`, `patient_id`, `client_number`, `gravida`, `parity`, `lmp`, `edd`, `booking_ga_weeks`, `booking_timing`, `status`, `closed_date`, `created_by`, `created_at`) VALUES
(1, 1, '001/2026', 2, 1, '2026-01-15', '2026-10-22', 12, 'early', 'active', NULL, 1, '2026-04-10 09:00:00'),
(2, 2, '002/2026', 3, 1, '2026-02-01', '2026-11-08', 16, 'late', 'active', NULL, 1, '2026-05-20 09:00:00'),
(3, 3, '003/2026', 3, 2, '2026-03-01', '2026-12-06', 8, 'early', 'active', NULL, 1, '2026-04-28 09:00:00'),
(4, 4, '004/2026', 4, 2, '2025-12-10', '2026-09-16', 10, 'early', 'delivered', '2026-09-18', 1, '2026-02-18 09:00:00'),
(5, 5, '005/2026', 2, 0, '2026-04-01', '2027-01-06', 6, 'early', 'active', NULL, 1, '2026-05-15 09:00:00'),
(6, 6, '006/2026', 2, 1, '2026-01-20', '2026-10-27', 18, 'late', 'active', NULL, 1, '2026-05-25 09:00:00');

INSERT INTO `anc_visits` (
  `id`, `pregnancy_episode_id`, `serial_no`, `visit_number`, `visit_date`, `next_visit_date`, `gestational_age_weeks`,
  `weight_kg`, `height_cm`, `muac_cm`, `muac_color`, `inr_number`, `bp_systolic`, `bp_diastolic`,
  `woman_hiv_code`, `hivst_kit_given`, `partner_age`, `partner_result_code`, `diagnosis`,
  `syphilis_result`, `hepatitis_b_result`, `llin_given`, `mebendazole_status`, `iron_folate_tablets`,
  `referral_type`, `complications`, `tt_dose_received`, `iptp_dose`, `recorded_by`
) VALUES
(1, 1, 1, 1, '2026-04-10', '2026-05-08', 12, 68.00, 162.00, 24.6, 'G', 'INR-001', 118, 76, 'TR', 1, 29, 'TR', 'Normal intrauterine pregnancy', 'NR', 'NR', 1, 'given', 30, NULL, NULL, 1, NULL, 1),
(2, 1, 2, 2, '2026-05-08', '2026-06-05', 16, 70.00, 162.00, 24.8, 'G', 'INR-001', 120, 78, 'TR', 0, 29, 'TR', 'Normal intrauterine pregnancy', 'NR', 'NR', 0, 'given', 30, NULL, NULL, 2, 'SP1', 1),
(3, 2, 3, 1, '2026-05-20', '2026-06-17', 16, 72.00, 158.00, 22.4, 'Y', 'INR-014', 128, 84, 'TR', 1, 40, 'TR', 'Moderate malnutrition, counselled', 'NR', 'NR', 1, 'given', 60, NULL, 'MUAC yellow — nutrition follow-up', 1, 'SP1', 1),
(4, 4, 4, 1, '2026-02-18', '2026-03-18', 10, 65.00, 160.00, 25.0, 'G', 'INR-022', 112, 70, 'TRR+', 1, 36, 'TR', 'Known HIV positive, on ART', 'NR', 'NR', 1, 'given', 30, NULL, NULL, 1, NULL, 1),
(5, 4, 5, 2, '2026-05-20', '2026-06-17', 23, 67.50, 160.00, 25.1, 'G', 'INR-022', 118, 74, 'TRR+', 0, 36, 'TR', 'On ART, viral load suppressed', 'NR', 'NR', 0, 'completed', 30, NULL, NULL, 2, 'SP2', 1),
(6, 6, 6, 1, '2026-05-25', '2026-06-22', 18, 70.00, 164.00, 23.3, 'G', 'INR-031', 120, 79, 'TR', 1, 45, 'TR', 'Normal intrauterine pregnancy', 'NR', 'NR', 1, 'given', 20, NULL, NULL, 1, 'SP1', 1);

INSERT INTO `delivery_records` (
  `id`, `pregnancy_episode_id`, `admission_no`, `ipd_no`, `admission_datetime`, `reason_for_admission`,
  `gestation_weeks`, `term_preterm`, `emtct_code_woman`, `ctx_given`, `syphilis_result`, `hepatitis_b_result`,
  `muac_cm`, `muac_color`, `mode_of_delivery`, `delivery_datetime`, `bba`, `active_management_3rd_stage`,
  `baby_sex`, `baby_weight_kg`, `apgar_1min`, `apgar_5min`, `apgar_asphyxia`, `resuscitation_code`,
  `skin_to_skin`, `source_warmth`, `breastfed_1hr`, `routine_meds_teo`, `routine_meds_vitk`,
  `routine_meds_chlorhexidine`, `risk_status`, `infant_arv_given`, `postpartum_fp_code`,
  `baby_condition_at_discharge`, `maternal_death`, `recorded_by`
) VALUES
(1, 4, 1, 'IPD/2026/00001', '2026-09-18 02:15:00', 'Labour at term',
 39, 'T', 'TRR+', 1, 'NR', 'NR',
 25.0, 'G', 'normal_vaginal', '2026-09-18 06:40:00', 0, 'oxytocin',
 'F', 3.20, 8, 9, 0, 'NA',
 1, 'mother_warmth', 1, 1, 1,
 1, 'HIGH', 1, 8,
 'AL', 0, 1);

INSERT INTO `postnatal_visits` (
  `id`, `pregnancy_episode_id`, `serial_no`, `visit_timing`, `visit_date`,
  `fp_method_code`, `breast_status`, `cervix_status`, `mother_weight_kg`,
  `mother_muac_cm`, `mother_muac_color`, `mother_emtct_code`, `mother_ctx_given`,
  `mother_diagnosis`, `baby_status`, `baby_age_days`, `baby_weight_kg`,
  `infant_feeding_option`, `dbs_sample_taken`, `dbs_test_result`, `vitamin_a_given`, `recorded_by`
) VALUES
(1, 4, 1, '24_hours', '2026-09-18',
 8, 'FOM', 'FOM', 63.00,
 25.0, 'G', 'TRR+', 1,
 'Stable after SVD', 'AL', 0, 3.20,
 'EBF', 0, NULL, 0, 1),
(2, 4, 2, '6_days', '2026-09-24',
 8, 'FOM', 'FOM', 62.50,
 25.0, 'G', 'TRR+', 1,
 'Breastfeeding well', 'AL', 6, 3.15,
 'EBF', 1, 'pending', 0, 1);

SET FOREIGN_KEY_CHECKS = 1;
