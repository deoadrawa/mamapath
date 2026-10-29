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
