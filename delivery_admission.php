<?php
// ============================================
// FILE: delivery_admission.php
// PURPOSE: Record delivery and maternity admission
// REDESIGNED - No scrolling, clean modern layout
// ============================================

require_once 'config.php';
requireLogin();
require_once 'nav.php';

$error = '';
$success = '';
$patient_info = null;

// Get active pregnancies for dropdown
$patients = $pdo->query("SELECT p.id, pe.lmp, p.surname, p.first_name, pe.id as episode_id, pe.client_number, pe.gravida, pe.parity, pe.edd
                         FROM patients p 
                         JOIN pregnancy_episodes pe ON p.id = pe.patient_id 
                         WHERE pe.status = 'active'
                         ORDER BY pe.edd ASC LIMIT 100")->fetchAll();

if (isset($_GET['episode_id'])) {
    $stmt = $pdo->prepare("SELECT p.*, pe.* FROM patients p 
                           JOIN pregnancy_episodes pe ON p.id = pe.patient_id 
                           WHERE pe.id = :eid AND pe.status = 'active'
                             AND NOT EXISTS (
                                 SELECT 1 FROM delivery_records dr
                                 WHERE dr.pregnancy_episode_id = pe.id
                             )");
    $stmt->execute([':eid' => $_GET['episode_id']]);
    $patient_info = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        validateClinicalInput($_POST, [
            'gestation_weeks' => ['Gestation (weeks)', 20, 45],
            'baby_weight_kg' => ['Baby weight (kg)', 0.3, 7],
            'apgar_1min' => ['Apgar (1 min)', 0, 10],
            'apgar_5min' => ['Apgar (5 min)', 0, 10],
            'muac_cm' => ['MUAC (cm)', 10, 50],
        ]);
        $pdo->beginTransaction();
        
        $episode_id = filter_var($_POST['episode_id'] ?? null, FILTER_VALIDATE_INT);
        $episodeStmt = $pdo->prepare(
            "SELECT p.id, pe.lmp
             FROM pregnancy_episodes pe
             JOIN patients p ON p.id = pe.patient_id
             WHERE pe.id = :id AND pe.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM delivery_records dr
                   WHERE dr.pregnancy_episode_id = pe.id
               )"
        );
        $episodeStmt->execute([':id' => $episode_id]);
        $episode = $episodeStmt->fetch();
        $patient_id = $episode['id'] ?? null;
        if ($episode_id === false || $episode_id < 1 || !$patient_id) {
            throw new InvalidArgumentException('Choose an active pregnancy without an existing delivery record.');
        }
        $admissionInput = $_POST['admission_datetime'] ?? '';
        $deliveryInput = $_POST['delivery_datetime'] ?? '';
        $admissionDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $admissionInput);
        $deliveryDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $deliveryInput);
        if (
            !$admissionDate || $admissionDate->format('Y-m-d\TH:i') !== $admissionInput
            || !$deliveryDate || $deliveryDate->format('Y-m-d\TH:i') !== $deliveryInput
        ) {
            throw new InvalidArgumentException('Enter valid admission and delivery dates and times.');
        }
        $now = new DateTimeImmutable();
        if ($admissionDate > $now || $deliveryDate > $now || $deliveryDate < $admissionDate) {
            throw new InvalidArgumentException('Admission and delivery dates must be valid past dates, and delivery cannot precede admission.');
        }
        if (!empty($episode['lmp']) && $deliveryDate->format('Y-m-d') < $episode['lmp']) {
            throw new InvalidArgumentException('The delivery date cannot be before the recorded LMP.');
        }

        $number_of_babies = filter_var($_POST['number_of_babies'] ?? 1, FILTER_VALIDATE_INT);
        if ($number_of_babies === false || $number_of_babies < 1 || $number_of_babies > 8) {
            throw new InvalidArgumentException('The number of babies must be between 1 and 8.');
        }
        $additionalBabies = $_POST['additional_babies'] ?? [];
        if (!is_array($additionalBabies)) {
            throw new InvalidArgumentException('Invalid newborn details.');
        }
        $babySexes = ['M', 'F'];
        $resuscitationCodes = ['BM', 'BMD', 'NA', 'SS'];
        $babyConditionCodes = ['AL', 'AL_R', 'BDF', 'FSB', 'MSB', 'NND'];
        $firstBabySex = $_POST['baby_sex'] ?? '';
        if (!in_array($firstBabySex, $babySexes, true)) {
            throw new InvalidArgumentException('Select the first newborn’s sex.');
        }
        $babyRows = [[
            'baby_sex' => $firstBabySex,
            'baby_weight_kg' => emptyToNull($_POST['baby_weight_kg'] ?? null),
            'apgar_1min' => emptyToNull($_POST['apgar_1min'] ?? null),
            'apgar_5min' => emptyToNull($_POST['apgar_5min'] ?? null),
            'apgar_asphyxia' => formBoolean($_POST, 'apgar_asphyxia'),
            'resuscitation_code' => emptyToNull($_POST['resuscitation_code'] ?? null),
            'skin_to_skin' => formBoolean($_POST, 'skin_to_skin'),
            'breastfed_1hr' => formBoolean($_POST, 'breastfed_1hr'),
            'routine_meds_teo' => formBoolean($_POST, 'routine_meds_teo'),
            'routine_meds_vitk' => formBoolean($_POST, 'routine_meds_vitk'),
            'routine_meds_chlorhexidine' => formBoolean($_POST, 'routine_meds_chlorhexidine'),
            'condition_at_discharge' => emptyToNull($_POST['baby_condition_at_discharge'] ?? null),
        ]];
        for ($birthOrder = 2; $birthOrder <= $number_of_babies; $birthOrder++) {
            $baby = $additionalBabies[$birthOrder] ?? null;
            if (!is_array($baby) || !in_array($baby['baby_sex'] ?? '', $babySexes, true)) {
                throw new InvalidArgumentException('Enter the sex for each newborn.');
            }
            $resuscitation = emptyToNull($baby['resuscitation_code'] ?? null);
            $condition = emptyToNull($baby['condition_at_discharge'] ?? null);
            if ($resuscitation !== null && !in_array($resuscitation, $resuscitationCodes, true)) {
                throw new InvalidArgumentException('Choose a valid resuscitation code.');
            }
            if ($condition !== null && !in_array($condition, $babyConditionCodes, true)) {
                throw new InvalidArgumentException('Choose a valid newborn discharge condition.');
            }
            $babyRows[] = [
                'baby_sex' => $baby['baby_sex'],
                'baby_weight_kg' => emptyToNull($baby['baby_weight_kg'] ?? null),
                'apgar_1min' => emptyToNull($baby['apgar_1min'] ?? null),
                'apgar_5min' => emptyToNull($baby['apgar_5min'] ?? null),
                'apgar_asphyxia' => formBoolean($baby, 'apgar_asphyxia'),
                'resuscitation_code' => $resuscitation,
                'skin_to_skin' => formBoolean($baby, 'skin_to_skin'),
                'breastfed_1hr' => formBoolean($baby, 'breastfed_1hr'),
                'routine_meds_teo' => formBoolean($baby, 'routine_meds_teo'),
                'routine_meds_vitk' => formBoolean($baby, 'routine_meds_vitk'),
                'routine_meds_chlorhexidine' => formBoolean($baby, 'routine_meds_chlorhexidine'),
                'condition_at_discharge' => $condition,
            ];
        }
        foreach ($babyRows as &$baby) {
            if (
                $baby['resuscitation_code'] !== null
                && !in_array($baby['resuscitation_code'], $resuscitationCodes, true)
            ) {
                throw new InvalidArgumentException('Choose a valid resuscitation code for each newborn.');
            }
            if (
                $baby['condition_at_discharge'] !== null
                && !in_array($baby['condition_at_discharge'], $babyConditionCodes, true)
            ) {
                throw new InvalidArgumentException('Choose a valid discharge condition for each newborn.');
            }
            if ($baby['baby_weight_kg'] !== null) {
                if (!is_numeric($baby['baby_weight_kg']) || (float) $baby['baby_weight_kg'] < 0 || (float) $baby['baby_weight_kg'] > 10) {
                    throw new InvalidArgumentException('Newborn weights must be between 0 and 10 kg.');
                }
                $baby['baby_weight_kg'] = (float) $baby['baby_weight_kg'];
            }
            foreach (['apgar_1min', 'apgar_5min'] as $apgarField) {
                if ($baby[$apgarField] === null) {
                    continue;
                }
                $apgar = filter_var(
                    $baby[$apgarField],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0, 'max_range' => 10]]
                );
                if ($apgar === false) {
                    throw new InvalidArgumentException('Apgar scores must be whole numbers from 0 to 10.');
                }
                $baby[$apgarField] = $apgar;
            }
        }
        unset($baby);

        $admission_no = getNextSerialNo($pdo, 'delivery_records', 'admission_datetime');
        $ipd_no = generateIPDNumber($pdo);
        
        $muac_cm = !empty($_POST['muac_cm']) ? floatval($_POST['muac_cm']) : null;
        $muac_color = $muac_cm ? getMUACColor($muac_cm) : 'ND';
        
        $stmt = $pdo->prepare("INSERT INTO delivery_records (
            pregnancy_episode_id, admission_no, ipd_no, admission_datetime, reason_for_admission,
            gestation_weeks, term_preterm,
            emtct_code_woman, emtct_code_partner, mother_art_code, mother_art_number, ctx_given,
            syphilis_result, hepatitis_b_result, muac_cm, muac_color,
            mode_of_delivery, delivery_datetime, bba, active_management_3rd_stage,
            management_complication, other_treatment,
            baby_sex, baby_weight_kg, apgar_1min, apgar_5min, apgar_asphyxia, number_of_babies,
            resuscitation_code, skin_to_skin, source_warmth, breastfed_1hr,
            routine_meds_teo, routine_meds_vitk, routine_meds_chlorhexidine,
            risk_status, infant_arv_given, infant_arv_duration,
            postpartum_fp_code, baby_condition_at_discharge, maternal_death, maternal_death_cause, recorded_by
        ) VALUES (
            :episode_id, :admission_no, :ipd_no, :admission_datetime, :reason,
            :gest_weeks, :term_preterm,
            :emtct_w, :emtct_p, :mother_art, :mother_art_num, :ctx,
            :syphilis, :hep_b, :muac, :muac_color,
            :mode, :delivery_dt, :bba, :active_3rd,
            :management, :other_tx,
            :baby_sex, :baby_wt, :apgar1, :apgar5, :asphyxia, :number_of_babies,
            :resus, :skin2skin, :warmth, :bf_1hr,
            :teo, :vitk, :chlor,
            :risk, :infant_arv, :infant_arv_dur,
            :fp_code, :baby_condition, :maternal_death, :maternal_cause, :recorded_by
        )");
        
        $stmt->execute([
            ':episode_id' => $episode_id,
            ':admission_no' => $admission_no,
            ':ipd_no' => $ipd_no,
            ':admission_datetime' => $admissionDate->format('Y-m-d H:i:s'),
            ':reason' => $_POST['reason_for_admission'] ?? null,
            ':gest_weeks' => !empty($_POST['gestation_weeks']) ? $_POST['gestation_weeks'] : null,
            ':term_preterm' => $_POST['term_preterm'] ?? null,
            ':emtct_w' => $_POST['emtct_code_woman'] ?? null,
            ':emtct_p' => $_POST['emtct_code_partner'] ?? null,
            ':mother_art' => $_POST['mother_art_code'] ?? null,
            ':mother_art_num' => $_POST['mother_art_number'] ?? null,
            ':ctx' => formBoolean($_POST, 'ctx_given'),
            ':syphilis' => $_POST['syphilis_result'] ?? null,
            ':hep_b' => $_POST['hepatitis_b_result'] ?? null,
            ':muac' => $muac_cm,
            ':muac_color' => $muac_color,
            ':mode' => $_POST['mode_of_delivery'],
            ':delivery_dt' => $deliveryDate->format('Y-m-d H:i:s'),
            ':bba' => formBoolean($_POST, 'bba'),
            ':active_3rd' => $_POST['active_management_3rd_stage'] ?? null,
            ':management' => $_POST['management_complication'] ?? null,
            ':other_tx' => $_POST['other_treatment'] ?? null,
            ':baby_sex' => $_POST['baby_sex'],
            ':number_of_babies' => $number_of_babies,
            ':baby_wt' => !empty($_POST['baby_weight_kg']) ? $_POST['baby_weight_kg'] : null,
            ':apgar1' => !empty($_POST['apgar_1min']) ? $_POST['apgar_1min'] : null,
            ':apgar5' => !empty($_POST['apgar_5min']) ? $_POST['apgar_5min'] : null,
            ':asphyxia' => formBoolean($_POST, 'apgar_asphyxia'),
            ':resus' => emptyToNull($_POST['resuscitation_code'] ?? null),
            ':skin2skin' => formBoolean($_POST, 'skin_to_skin'),
            ':warmth' => $_POST['source_warmth'] ?? 'mother_warmth',
            ':bf_1hr' => formBoolean($_POST, 'breastfed_1hr'),
            ':teo' => formBoolean($_POST, 'routine_meds_teo'),
            ':vitk' => formBoolean($_POST, 'routine_meds_vitk'),
            ':chlor' => formBoolean($_POST, 'routine_meds_chlorhexidine'),
            ':risk' => $_POST['risk_status'] ?? null,
            ':infant_arv' => formBoolean($_POST, 'infant_arv_given'),
            ':infant_arv_dur' => $_POST['infant_arv_duration'] ?? null,
            ':fp_code' => !empty($_POST['postpartum_fp_code']) ? $_POST['postpartum_fp_code'] : null,
            ':baby_condition' => emptyToNull($_POST['baby_condition_at_discharge'] ?? null),
            ':maternal_death' => formBoolean($_POST, 'maternal_death'),
            ':maternal_cause' => emptyToNull($_POST['maternal_death_cause'] ?? null),
            ':recorded_by' => currentUserId()
        ]);
        $delivery_record_id = $pdo->lastInsertId();

        $babyInsert = $pdo->prepare(
            "INSERT INTO babies (
                delivery_record_id, birth_order, baby_sex, baby_weight_kg,
                apgar_1min, apgar_5min, apgar_asphyxia, resuscitation_code,
                skin_to_skin, breastfed_1hr, routine_meds_teo, routine_meds_vitk,
                routine_meds_chlorhexidine, condition_at_discharge
             ) VALUES (
                :delivery_id, :birth_order, :baby_sex, :weight,
                :apgar_1, :apgar_5, :asphyxia, :resuscitation,
                :skin_to_skin, :breastfed, :teo, :vitk,
                :chlorhexidine, :condition
             )"
        );
        foreach ($babyRows as $index => $baby) {
            $babyInsert->execute([
                ':delivery_id' => $delivery_record_id,
                ':birth_order' => $index + 1,
                ':baby_sex' => $baby['baby_sex'],
                ':weight' => $baby['baby_weight_kg'],
                ':apgar_1' => $baby['apgar_1min'],
                ':apgar_5' => $baby['apgar_5min'],
                ':asphyxia' => $baby['apgar_asphyxia'],
                ':resuscitation' => $baby['resuscitation_code'],
                ':skin_to_skin' => $baby['skin_to_skin'],
                ':breastfed' => $baby['breastfed_1hr'],
                ':teo' => $baby['routine_meds_teo'],
                ':vitk' => $baby['routine_meds_vitk'],
                ':chlorhexidine' => $baby['routine_meds_chlorhexidine'],
                ':condition' => $baby['condition_at_discharge'],
            ]);
        }
        
        // Update pregnancy episode status
        $update = $pdo->prepare("UPDATE pregnancy_episodes SET status = 'delivered', closed_date = :closed WHERE id = :id");
        $update->execute([':id' => $episode_id, ':closed' => $deliveryDate->format('Y-m-d')]);
        
        logAction($pdo, $_SESSION['user_id'], 'CREATE_DELIVERY', 'delivery_records', $delivery_record_id, "Recorded delivery with IPD: $ipd_no");
        $pdo->commit();
        
        $success = "Delivery recorded successfully! IPD Number: $ipd_no";
        
        echo '<script>setTimeout(function(){window.location.href='
            . json_encode('patient_profile.php?id=' . rawurlencode((string) $patient_id), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';},2000);</script>';
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } else {
            error_log('Delivery request failed: ' . $e->getMessage());
            $error = 'Could not record the delivery. Please review the details and try again.';
        }
    }
}
?>

<style>
    /* MAIN CONTAINER - NO SCROLLING */
    .delivery-wrapper {
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .form-card {
        background: var(--card-bg);
        border-radius: 24px;
        border: 1px solid var(--border);
        overflow: hidden;
        margin-bottom: 30px;
    }
    
    /* HEADER */
    .form-header {
        background: #173c50;
        padding: 28px 32px;
        color: white;
    }
    
    .form-header h1 {
        font-size: 24px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .form-header p {
        font-size: 14px;
        opacity: 0.85;
    }
    
    /* ALERTS */
    .alert {
        padding: 14px 20px;
        margin: 20px 32px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .alert-success {
        background: #e8f5e9;
        color: #2e7d64;
        border-left: 4px solid #2e7d64;
    }
    
    .alert-error {
        background: #ffebee;
        color: #c62828;
        border-left: 4px solid #c62828;
    }
    
    /* PATIENT SELECTOR */
    .patient-selector {
        padding: 24px 32px;
        background: var(--bg-body);
        border-bottom: 1px solid var(--border);
    }
    
    .selector-label {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 12px;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .patient-dropdown {
        width: 100%;
        padding: 14px 16px;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: var(--card-bg);
        color: var(--text-primary);
        font-size: 14px;
        cursor: pointer;
    }
    
    .patient-dropdown:focus {
        outline: none;
        border-color: #1e88e5;
    }
    
    /* PATIENT INFO BADGE */
    .info-badge {
        background: #e8f2f4;
        padding: 16px 24px;
        margin: 20px 32px;
        border-radius: 16px;
        display: flex;
        flex-wrap: wrap;
        gap: 24px;
        justify-content: space-between;
        align-items: center;
    }
    
    .info-item {
        display: flex;
        align-items: baseline;
        gap: 8px;
    }
    
    .info-label {
        font-size: 11px;
        font-weight: 600;
        color: #1565c0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: #0a2b44;
    }
    
    /* FORM SECTIONS */
    .form-section {
        padding: 24px 32px;
        border-bottom: 1px solid var(--border);
    }
    
    .section-title {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
    }
    
    .section-icon {
        width: 40px;
        height: 40px;
        background: #176f83;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 18px;
    }
    
    .section-title h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }
    
    .section-title p {
        font-size: 12px;
        color: var(--text-muted);
        margin: 4px 0 0 0;
    }
    
    /* FORM GRID */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .form-group.full-width {
        grid-column: span 4;
    }
    
    .form-group.half-width {
        grid-column: span 2;
    }
    
    .form-group label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary);
    }
    
    .form-group label .required {
        color: #c62828;
        margin-left: 3px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 10px 14px;
        border: 1.5px solid var(--border);
        border-radius: 10px;
        background: var(--bg-body);
        color: var(--text-primary);
        font-size: 14px;
        transition: all 0.2s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #1e88e5;
    }
    
    .form-hint {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 4px;
    }
    
    /* BUTTONS */
    .form-actions {
        padding: 24px 32px;
        background: var(--bg-body);
        display: flex;
        gap: 16px;
        justify-content: flex-end;
    }
    
    .btn-submit {
        background: #28764f;
        color: white;
        border: none;
        padding: 12px 32px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.2s;
    }
    
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(46, 125, 100, 0.3);
    }
    
    .btn-reset {
        background: var(--border);
        color: var(--text-primary);
        border: none;
        padding: 12px 28px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    
    .btn-reset:hover {
        background: var(--text-secondary);
        color: white;
    }
    
    /* IPD Badge */
    .ipd-badge {
        background: #1e88e5;
        color: white;
        padding: 8px 18px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
    }
    
    /* Warning Box */
    .warning-box {
        background: #fff3e0;
        border-left: 4px solid #e65100;
        padding: 12px 16px;
        margin: 0 32px 20px 32px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: #e65100;
        font-size: 13px;
    }
    
    /* RESPONSIVE */
    @media (max-width: 1000px) {
        .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .form-group.full-width,
        .form-group.half-width {
            grid-column: span 2;
        }
        .form-section {
            padding: 20px 24px;
        }
        .form-header {
            padding: 20px 24px;
        }
        .patient-selector {
            padding: 20px 24px;
        }
        .info-badge {
            margin: 16px 24px;
            flex-direction: column;
            align-items: flex-start;
        }
        .alert, .warning-box {
            margin: 16px 24px;
        }
    }
    
    @media (max-width: 600px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
        .form-group.full-width,
        .form-group.half-width {
            grid-column: span 1;
        }
        .form-actions {
            flex-direction: column;
        }
        .btn-submit, .btn-reset {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="delivery-wrapper">
    <div class="form-card">
        <!-- Header -->
        <div class="form-header">
            <h1><i class="fas fa-birthday-cake"></i> Delivery & Maternity Admission</h1>
            <p>Record labour, delivery, and immediate postpartum information</p>
        </div>
        
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success; ?> Redirecting to patient profile...</span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Patient Selector -->
        <div class="patient-selector">
            <div class="selector-label">
                <i class="fas fa-female"></i> Select Patient in Labour
            </div>
            <select id="patientSelect" onchange="location.href='delivery_admission.php?episode_id=' + this.value" class="patient-dropdown">
                <option value="">-- Select Patient --</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?php echo $patient['episode_id']; ?>" <?php echo (isset($_GET['episode_id']) && $_GET['episode_id'] == $patient['episode_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($patient['client_number'] . ' - ' . $patient['surname'] . ' ' . $patient['first_name'] . ' (EDD: ' . formatDate($patient['edd']) . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($patient_info): ?>
            <!-- Warning: This will complete the pregnancy -->
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Note:</strong> Recording delivery will automatically close this pregnancy episode. The patient will no longer appear in active ANC lists.
            </div>
            
            <!-- Patient Information Badge -->
            <div class="info-badge">
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-user"></i> Patient:</span>
                    <span class="info-value"><?php echo htmlspecialchars($patient_info['surname'] . ' ' . $patient_info['first_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-id-card"></i> Client No:</span>
                    <span class="info-value"><?php echo $patient_info['client_number']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-baby"></i> G/P:</span>
                    <span class="info-value"><?php echo $patient_info['gravida']; ?>/<?php echo $patient_info['parity']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar"></i> EDD:</span>
                    <span class="info-value"><?php echo formatDate($patient_info['edd']); ?></span>
                </div>
                <div class="ipd-badge">
                    <i class="fas fa-barcode"></i> New IPD: <?php echo generateIPDNumber($pdo); ?>
                </div>
            </div>
            
            <form method="POST" id="deliveryForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="episode_id" value="<?php echo (int) $_GET['episode_id']; ?>">
                <input type="hidden" name="patient_id" value="<?php echo $patient_info['id']; ?>">
                
                <!-- Section 1: Admission Information -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-hospital-user"></i></div>
                        <div>
                            <h3>Admission Information</h3>
                            <p>Maternity admission details</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Admission Date/Time <span class="required">*</span></label>
                            <input type="datetime-local" name="admission_datetime" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Reason for Admission</label>
                            <input type="text" name="reason_for_admission" placeholder="Active labour, False labour, Malaria, UTI...">
                        </div>
                        <div class="form-group">
                            <label>Gestation (weeks)</label>
                            <input type="number" step="0.1" name="gestation_weeks" placeholder="e.g., 38.5">
                        </div>
                        <div class="form-group">
                            <label>Term/Pre-term</label>
                            <select name="term_preterm">
                                <option value="">-- Select --</option>
                                <option value="T">Term (≥37 weeks)</option>
                                <option value="P">Pre-term (&lt;37 weeks)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Delivery Information -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-birthday-cake"></i></div>
                        <div>
                            <h3>Delivery Information</h3>
                            <p>Labour and delivery details</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Mode of Delivery <span class="required">*</span></label>
                            <select name="mode_of_delivery" required>
                                <option value="">-- Select --</option>
                                <option value="normal_vaginal">🤱 Normal Vaginal Delivery</option>
                                <option value="caesarean_section">🏥 Caesarean Section</option>
                                <option value="vacuum">🔧 Vacuum Extraction</option>
                                <option value="forceps">🔧 Forceps Delivery</option>
                                <option value="breech">🦶 Breech Delivery</option>
                                <option value="other">📝 Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Delivery Date/Time <span class="required">*</span></label>
                            <input type="datetime-local" name="delivery_datetime" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Born Before Arrival (BBA)</label>
                            <select name="bba">
                                <option value="0">No</option>
                                <option value="1">Yes - Born on the way</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Active Management 3rd Stage</label>
                            <select name="active_management_3rd_stage">
                                <option value="">-- Select --</option>
                                <option value="Oxytocin">Oxytocin (Pitocin)</option>
                                <option value="Misoprostol">Misoprostol</option>
                                <option value="Ergometrine">Ergometrine</option>
                            </select>
                        </div>
                        <div class="form-group half-width">
                            <label>Management of Complications</label>
                            <textarea name="management_complication" rows="2" placeholder="MVA, D&amp;C, laparotomy, hysterectomy, manual placenta removal..."></textarea>
                        </div>
                        <div class="form-group half-width">
                            <label>Other Treatment Given</label>
                            <textarea name="other_treatment" rows="2" placeholder="MgSO4, antibiotics, IV fluids, blood transfusion, dexamethasone..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Section 3: Baby Information -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-baby"></i></div>
                        <div>
                            <h3>Baby Information</h3>
                            <p>Newborn assessment and immediate care</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="number_of_babies">Number of babies delivered</label>
                            <select name="number_of_babies" id="number_of_babies" required>
                                <?php for ($count = 1; $count <= 8; $count++): ?>
                                    <option value="<?php echo $count; ?>"><?php echo $count; ?></option>
                                <?php endfor; ?>
                            </select>
                            <div class="form-hint">Record separate newborn assessments for twins or multiple births.</div>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Baby Sex <span class="required">*</span></label>
                            <select name="baby_sex" required>
                                <option value="">-- Select --</option>
                                <option value="M">👶 Male</option>
                                <option value="F">👶 Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Baby Weight (kg)</label>
                            <input type="number" step="0.01" name="baby_weight_kg" placeholder="e.g., 3.2">
                            <div class="form-hint">&lt;2.5kg = Low Birth Weight</div>
                        </div>
                        <div class="form-group">
                            <label>Apgar Score (1 min)</label>
                            <input type="number" min="0" max="10" name="apgar_1min" placeholder="0-10">
                        </div>
                        <div class="form-group">
                            <label>Apgar Score (5 min)</label>
                            <input type="number" min="0" max="10" name="apgar_5min" placeholder="0-10">
                        </div>
                        <div class="form-group">
                            <label>Asphyxia?</label>
                            <select name="apgar_asphyxia">
                                <option value="0">No</option>
                                <option value="1">Yes (add 'A' to score)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Resuscitation Code</label>
                            <select name="resuscitation_code">
                                <option value="">-- Select --</option>
                                <option value="SS">SS - Stimulation/Suction</option>
                                <option value="BM">BM - Bag &amp; Mask</option>
                                <option value="BMD">BMD - Bag &amp; Mask (Died)</option>
                                <option value="NA">NA - Normal breathing</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Skin to Skin Contact?</label>
                            <select name="skin_to_skin">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Breastfed within 1 hour?</label>
                            <select name="breastfed_1hr">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Source of Warmth</label>
                            <select name="source_warmth">
                                <option value="mother_warmth">🤱 Mother's Warmth</option>
                                <option value="incubator">🏥 Incubator</option>
                                <option value="kmc">🦘 Kangaroo Mother Care (KMC)</option>
                                <option value="other">📝 Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Routine Medications</label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="checkbox" name="routine_meds_teo" value="1"> TEO
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="checkbox" name="routine_meds_vitk" value="1"> Vitamin K
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px;">
                                    <input type="checkbox" name="routine_meds_chlorhexidine" value="1"> Chlorhexidine
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Baby Condition at Discharge</label>
                            <select name="baby_condition_at_discharge">
                                <option value="">-- Select --</option>
                                <option value="AL">✅ Alive</option>
                                <option value="AL_R">🔄 Alive - Referred</option>
                                <option value="MSB">⚠️ Macerated Still Birth</option>
                                <option value="FSB">⚠️ Fresh Still Birth</option>
                                <option value="NND">⚠️ Neonatal Death (&lt;24hrs)</option>
                                <option value="BDF">⚠️ Born with Defect</option>
                            </select>
                        </div>
                    </div>
                    <?php for ($birthOrder = 2; $birthOrder <= 8; $birthOrder++): ?>
                        <fieldset class="form-section additional-baby" data-birth-order="<?php echo $birthOrder; ?>" hidden disabled>
                            <legend>Baby <?php echo $birthOrder; ?> Assessment</legend>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Sex <span class="required">*</span></label>
                                    <select name="additional_babies[<?php echo $birthOrder; ?>][baby_sex]">
                                        <option value="">-- Select --</option><option value="M">Male</option><option value="F">Female</option>
                                    </select>
                                </div>
                                <div class="form-group"><label>Weight (kg)</label><input type="number" step="0.01" min="0" max="10" name="additional_babies[<?php echo $birthOrder; ?>][baby_weight_kg]"></div>
                                <div class="form-group"><label>Apgar (1 min)</label><input type="number" min="0" max="10" name="additional_babies[<?php echo $birthOrder; ?>][apgar_1min]"></div>
                                <div class="form-group"><label>Apgar (5 min)</label><input type="number" min="0" max="10" name="additional_babies[<?php echo $birthOrder; ?>][apgar_5min]"></div>
                                <div class="form-group"><label>Asphyxia?</label><select name="additional_babies[<?php echo $birthOrder; ?>][apgar_asphyxia]"><option value="0">No</option><option value="1">Yes</option></select></div>
                                <div class="form-group"><label>Resuscitation</label><select name="additional_babies[<?php echo $birthOrder; ?>][resuscitation_code]"><option value="">-- Select --</option><option value="SS">Stimulation/Suction</option><option value="BM">Bag &amp; Mask</option><option value="BMD">Bag &amp; Mask - Died</option><option value="NA">Normal breathing</option></select></div>
                                <div class="form-group"><label>Skin to skin</label><select name="additional_babies[<?php echo $birthOrder; ?>][skin_to_skin]"><option value="0">No</option><option value="1">Yes</option></select></div>
                                <div class="form-group"><label>Breastfed within 1 hour</label><select name="additional_babies[<?php echo $birthOrder; ?>][breastfed_1hr]"><option value="0">No</option><option value="1">Yes</option></select></div>
                                <div class="form-group"><label>Condition at discharge</label><select name="additional_babies[<?php echo $birthOrder; ?>][condition_at_discharge]"><option value="">-- Select --</option><option value="AL">Alive</option><option value="AL_R">Alive - Referred</option><option value="BDF">Born with Defect</option><option value="FSB">Fresh Still Birth</option><option value="MSB">Macerated Still Birth</option><option value="NND">Neonatal Death</option></select></div>
                                <div class="form-group">
                                    <label>Routine medications</label>
                                    <label><input type="checkbox" name="additional_babies[<?php echo $birthOrder; ?>][routine_meds_teo]" value="1"> TEO</label>
                                    <label><input type="checkbox" name="additional_babies[<?php echo $birthOrder; ?>][routine_meds_vitk]" value="1"> Vitamin K</label>
                                    <label><input type="checkbox" name="additional_babies[<?php echo $birthOrder; ?>][routine_meds_chlorhexidine]" value="1"> Chlorhexidine</label>
                                </div>
                            </div>
                        </fieldset>
                    <?php endfor; ?>
                </div>
                
                <!-- Section 4: HIV & Lab Results -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-heartbeat"></i></div>
                        <div>
                            <h3>HIV & Laboratory Results</h3>
                            <p>eMTCT and other test results</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>eMTCT Code (Woman)</label>
                            <select name="emtct_code_woman">
                                <option value="">-- Select --</option>
                                <option value="C">C - Counseled, declined</option>
                                <option value="T">T - Tested, no results</option>
                                <option value="TR">TR - HIV Negative</option>
                                <option value="TRR">TRR - HIV Positive</option>
                                <option value="TRV">TRV - Known HIV Negative</option>
                                <option value="TR+">TR+ - Re-test Negative</option>
                                <option value="TRR+">TRR+ - Re-test Positive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>eMTCT Code (Partner)</label>
                            <input type="text" name="emtct_code_partner" placeholder="e.g., TR, TRR, NA">
                        </div>
                        <div class="form-group">
                            <label>Mother ART Code</label>
                            <select name="mother_art_code">
                                <option value="">-- Select --</option>
                                <option value="ART">ART - Newly initiated</option>
                                <option value="ART-K">ART-K - Already on ART</option>
                                <option value="NA">NA - HIV Negative</option>
                                <option value="ND">ND - No drugs given</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Mother ART Number</label>
                            <input type="text" name="mother_art_number" placeholder="ART registration number">
                        </div>
                        <div class="form-group">
                            <label>CTX (Septrin) Given?</label>
                            <select name="ctx_given">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Syphilis Result</label>
                            <select name="syphilis_result">
                                <option value="">-- Select --</option>
                                <option value="NR">NR - Non-reactive</option>
                                <option value="Rx">Rx - Reactive (treated)</option>
                                <option value="+Ve">+Ve - Positive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Hepatitis B Result</label>
                            <select name="hepatitis_b_result">
                                <option value="">-- Select --</option>
                                <option value="NR">NR - Non-reactive</option>
                                <option value="+Ve">+Ve - Positive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>MUAC (cm)</label>
                            <input type="number" step="0.1" name="muac_cm" placeholder="e.g., 23.5">
                            <div class="form-hint">R:&lt;19cm | Y:19-22.9cm | G:≥23cm</div>
                        </div>
                    </div>
                </div>
                
                <!-- Section 5: Postpartum Family Planning -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-heart"></i></div>
                        <div>
                            <h3>Postpartum Family Planning</h3>
                            <p>Contraceptive method and risk assessment</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>FP Method Code</label>
                            <select name="postpartum_fp_code">
                                <option value="">-- Select --</option>
                                <option value="1">1 - PP-BTL (Tubal Ligation)</option>
                                <option value="2">2 - PP-IUD</option>
                                <option value="3">3 - PAC-IUD</option>
                                <option value="4">4 - PAC-Implant</option>
                                <option value="5">5 - PAC-Oral pills</option>
                                <option value="6">6 - PAC-Depo</option>
                                <option value="7">7 - Other FP method</option>
                                <option value="8">8 - No FP method given</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Risk Status</label>
                            <select name="risk_status">
                                <option value="">-- Select --</option>
                                <option value="HIGH">🔴 HIGH Risk</option>
                                <option value="LOW">🟢 LOW Risk</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Infant ARV Given?</label>
                            <select name="infant_arv_given">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Infant ARV Duration</label>
                            <input type="text" name="infant_arv_duration" placeholder="e.g., 6 weeks, 12 weeks">
                        </div>
                    </div>
                </div>
                
                <!-- Section 6: Maternal Outcomes (if applicable) -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <h3>Maternal Outcomes</h3>
                            <p>Report any maternal complications or death</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Maternal Death?</label>
                            <select name="maternal_death" id="maternal_death">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group" id="maternal_cause_group" style="display: none;">
                            <label>Cause of Maternal Death</label>
                            <textarea name="maternal_death_cause" rows="2" placeholder="Specify cause of death"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="reset" class="btn-reset"><i class="fas fa-undo"></i> Reset Form</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Record Delivery & Complete Pregnancy</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    const babyCount = document.getElementById('number_of_babies');
    const additionalBabySections = document.querySelectorAll('.additional-baby');
    function updateBabySections() {
        const count = Number(babyCount.value);
        additionalBabySections.forEach(section => {
            const visible = Number(section.dataset.birthOrder) <= count;
            section.hidden = !visible;
            section.disabled = !visible;
            const sexField = section.querySelector('select[name$="[baby_sex]"]');
            if (sexField) sexField.required = visible;
        });
    }
    babyCount?.addEventListener('change', updateBabySections);
    updateBabySections();

    // Show/hide maternal death cause field
    document.getElementById('maternal_death')?.addEventListener('change', function() {
        const causeGroup = document.getElementById('maternal_cause_group');
        if (this.value == '1') {
            causeGroup.style.display = 'block';
        } else {
            causeGroup.style.display = 'none';
        }
    });
</script>

<?php require_once 'footer.php'; ?>