<?php
// ============================================
// FILE: postnatal_visit.php
// PURPOSE: Record postnatal visits for mother and baby
// REDESIGNED - Compact, clean, matches other forms
// ============================================

require_once 'config.php';
requireLogin();
require_once 'nav.php';

$error = '';
$success = '';

// Get delivered patients
$query = "SELECT p.id as patient_id, p.surname, p.first_name, pe.id as episode_id, pe.client_number,
                 dr.id as delivery_record_id, dr.delivery_datetime, dr.baby_sex, dr.baby_weight_kg
          FROM patients p
          JOIN pregnancy_episodes pe ON p.id = pe.patient_id
          JOIN delivery_records dr ON dr.pregnancy_episode_id = pe.id
          WHERE pe.status = 'delivered'
          ORDER BY dr.delivery_datetime DESC LIMIT 100";
$patients = $pdo->query($query)->fetchAll();

$selected_episode_id = filter_var($_GET['episode_id'] ?? null, FILTER_VALIDATE_INT);
$babies = [];
if ($selected_episode_id !== false && $selected_episode_id !== null && $selected_episode_id > 0) {
    $babyStmt = $pdo->prepare(
        'SELECT b.id, b.birth_order, b.baby_sex, b.baby_weight_kg
         FROM babies b
         JOIN delivery_records dr ON dr.id = b.delivery_record_id
         WHERE dr.pregnancy_episode_id = :episode_id
         ORDER BY b.birth_order'
    );
    $babyStmt->execute([':episode_id' => $selected_episode_id]);
    $babies = $babyStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $pdo->beginTransaction();
        $episode_id = filter_var($_POST['episode_id'] ?? null, FILTER_VALIDATE_INT);
        $episodeStmt = $pdo->prepare(
            "SELECT p.id
             FROM pregnancy_episodes pe
             JOIN patients p ON p.id = pe.patient_id
             JOIN delivery_records dr ON dr.pregnancy_episode_id = pe.id
             WHERE pe.id = :episode_id AND pe.status = 'delivered'"
        );
        $episodeStmt->execute([':episode_id' => $episode_id]);
        $patient_id = $episodeStmt->fetchColumn();
        if ($episode_id === false || $episode_id < 1 || !$patient_id) {
            throw new InvalidArgumentException('Choose a valid delivered pregnancy.');
        }
        $visitDate = $_POST['visit_date'] ?? '';
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) !== 1
            || !checkdate((int) substr($visitDate, 5, 2), (int) substr($visitDate, 8, 2), (int) substr($visitDate, 0, 4))
            || $visitDate > date('Y-m-d')
        ) {
            throw new InvalidArgumentException('Enter a valid postnatal visit date that is not in the future.');
        }
        $deliveryDateStmt = $pdo->prepare('SELECT DATE(delivery_datetime) FROM delivery_records WHERE pregnancy_episode_id = :episode_id');
        $deliveryDateStmt->execute([':episode_id' => $episode_id]);
        $deliveryDate = $deliveryDateStmt->fetchColumn();
        if ($deliveryDate && $visitDate < $deliveryDate) {
            throw new InvalidArgumentException('A postnatal visit cannot be dated before delivery.');
        }

        $episodeBabies = $pdo->prepare(
            'SELECT b.id FROM babies b
             JOIN delivery_records dr ON dr.id = b.delivery_record_id
             WHERE dr.pregnancy_episode_id = :episode_id
             ORDER BY b.birth_order'
        );
        $episodeBabies->execute([':episode_id' => $episode_id]);
        $episodeBabyIds = $episodeBabies->fetchAll(PDO::FETCH_COLUMN);

        $serial_no = getNextSerialNo($pdo, 'postnatal_visits', 'visit_date');
        $babyId = empty($_POST['baby_id']) ? null : filter_var($_POST['baby_id'], FILTER_VALIDATE_INT);
        if ($babyId === false || ($babyId !== null && $babyId < 1)) {
            throw new InvalidArgumentException('Choose a valid newborn.');
        }
        if ($babyId === null && count($episodeBabyIds) === 1) {
            $babyId = (int) $episodeBabyIds[0];
        } elseif ($babyId === null && count($episodeBabyIds) > 1) {
            throw new InvalidArgumentException('Choose which newborn this postnatal visit is for.');
        }
        if ($babyId !== null) {
            $babyCheck = $pdo->prepare(
                'SELECT 1 FROM babies b
                 JOIN delivery_records dr ON dr.id = b.delivery_record_id
                 WHERE b.id = :baby_id AND dr.pregnancy_episode_id = :episode_id'
            );
            $babyCheck->execute([':baby_id' => $babyId, ':episode_id' => $episode_id]);
            if (!$babyCheck->fetchColumn()) {
                throw new InvalidArgumentException('The selected newborn does not belong to this pregnancy.');
            }
        }
        
        $muac_cm = !empty($_POST['mother_muac_cm']) ? floatval($_POST['mother_muac_cm']) : null;
        $muac_color = $muac_cm ? getMUACColor($muac_cm) : 'ND';
        
        $stmt = $pdo->prepare("INSERT INTO postnatal_visits (
            pregnancy_episode_id, serial_no, visit_timing, visit_date,
            fp_method_code, fp_other, breast_status, cervix_status,
            mother_weight_kg, mother_muac_cm, mother_muac_color,
            mother_emtct_code, partner_emtct_code, partner_tfv_code,
            mother_art_code, mother_art_number, mother_ctx_given,
            mother_diagnosis, baby_status, baby_age_days, baby_weight_kg,
            baby_diagnosis, infant_feeding_option, immunization_status,
            dbs_sample_taken, dbs_test_result, baby_septrin_given,
            baby_referred, baby_referral_place, vitamin_a_given, baby_id, recorded_by
        ) VALUES (
            :episode_id, :serial_no, :timing, :visit_date,
            :fp_code, :fp_other, :breast, :cervix,
            :weight, :muac, :muac_color,
            :mother_emtct, :partner_emtct, :partner_tfv,
            :art_code, :art_num, :ctx,
            :diagnosis, :baby_status, :baby_age, :baby_wt,
            :baby_dx, :feeding, :immunization,
            :dbs, :dbs_result, :baby_septrin,
            :baby_ref, :baby_ref_place, :vit_a, :baby_id, :recorded_by
        )");
        
        $stmt->execute([
            ':episode_id' => $episode_id,
            ':serial_no' => $serial_no,
            ':timing' => $_POST['visit_timing'],
            ':visit_date' => $visitDate,
            ':fp_code' => !empty($_POST['fp_method_code']) ? $_POST['fp_method_code'] : null,
            ':fp_other' => $_POST['fp_other'] ?? null,
            ':breast' => $_POST['breast_status'] ?? null,
            ':cervix' => $_POST['cervix_status'] ?? null,
            ':weight' => !empty($_POST['mother_weight_kg']) ? $_POST['mother_weight_kg'] : null,
            ':muac' => $muac_cm,
            ':muac_color' => $muac_color,
            ':mother_emtct' => $_POST['mother_emtct_code'] ?? null,
            ':partner_emtct' => $_POST['partner_emtct_code'] ?? null,
            ':partner_tfv' => $_POST['partner_tfv_code'] ?? null,
            ':art_code' => $_POST['mother_art_code'] ?? null,
            ':art_num' => $_POST['mother_art_number'] ?? null,
            ':ctx' => formBoolean($_POST, 'mother_ctx_given'),
            ':diagnosis' => $_POST['mother_diagnosis'] ?? null,
            ':baby_status' => $_POST['baby_status'] ?? null,
            ':baby_age' => !empty($_POST['baby_age_days']) ? $_POST['baby_age_days'] : null,
            ':baby_wt' => !empty($_POST['baby_weight_kg']) ? $_POST['baby_weight_kg'] : null,
            ':baby_dx' => $_POST['baby_diagnosis'] ?? null,
            ':feeding' => $_POST['infant_feeding_option'] ?? null,
            ':immunization' => $_POST['immunization_status'] ?? null,
            ':dbs' => formBoolean($_POST, 'dbs_sample_taken'),
            ':dbs_result' => $_POST['dbs_test_result'] ?? 'pending',
            ':baby_septrin' => formBoolean($_POST, 'baby_septrin_given'),
            ':baby_ref' => formBoolean($_POST, 'baby_referred'),
            ':baby_ref_place' => emptyToNull($_POST['baby_referral_place'] ?? null),
            ':vit_a' => formBoolean($_POST, 'vitamin_a_given'),
            ':baby_id' => $babyId,
            ':recorded_by' => currentUserId()
        ]);
        
        logAction($pdo, $_SESSION['user_id'], 'CREATE_PNC_VISIT', 'postnatal_visits', $pdo->lastInsertId(), "Recorded PNC visit: {$_POST['visit_timing']}");
        $pdo->commit();
        
        $success = "Postnatal visit recorded successfully!";
        
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
            error_log('Postnatal visit request failed: ' . $e->getMessage());
            $error = 'Could not record the postnatal visit. Please review the details and try again.';
        }
    }
}
?>

<style>
    .pnc-wrapper {
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
    
    .form-header {
        background: linear-gradient(135deg, #0a2b44 0%, #0d3b5e 100%);
        padding: 24px 32px;
        color: white;
    }
    
    .form-header h1 {
        font-size: 24px;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .form-header p {
        font-size: 13px;
        opacity: 0.85;
    }
    
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
    
    .patient-selector {
        padding: 24px 32px;
        background: var(--bg-body);
        border-bottom: 1px solid var(--border);
    }
    
    .selector-label {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .patient-dropdown {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: var(--card-bg);
        color: var(--text-primary);
        font-size: 14px;
    }
    
    .info-badge {
        background: linear-gradient(135deg, #e3f2fd, #bbdef5);
        padding: 14px 24px;
        margin: 20px 32px;
        border-radius: 14px;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
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
    }
    
    .info-value {
        font-size: 14px;
        font-weight: 600;
        color: #0a2b44;
    }
    
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
        background: linear-gradient(135deg, #1e88e5, #1565c0);
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
        padding: 10px 12px;
        border: 1.5px solid var(--border);
        border-radius: 10px;
        background: var(--bg-body);
        color: var(--text-primary);
        font-size: 13px;
        transition: all 0.2s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #1e88e5;
    }
    
    .form-hint {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 4px;
    }
    
    .form-actions {
        padding: 24px 32px;
        background: var(--bg-body);
        display: flex;
        gap: 16px;
        justify-content: flex-end;
    }
    
    .btn-submit {
        background: linear-gradient(135deg, #2e7d64, #1b5e4a);
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
    }
    
    .btn-reset:hover {
        background: var(--text-secondary);
        color: white;
    }
    
    @media (max-width: 1000px) {
        .form-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .form-group.full-width,
        .form-group.half-width {
            grid-column: span 2;
        }
        .form-section, .form-header, .patient-selector {
            padding: 20px 24px;
        }
        .info-badge, .alert {
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
        .info-badge {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<div class="pnc-wrapper">
    <div class="form-card">
        <div class="form-header">
            <h1><i class="fas fa-hand-holding-heart"></i> Postnatal Care Visit</h1>
            <p>Record mother and baby postnatal assessment</p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?> Redirecting...</span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="patient-selector">
                <div class="selector-label">
                    <i class="fas fa-female"></i> Select Mother
                </div>
                <select name="episode_id" class="patient-dropdown" required onchange="updatePatientId(this)">
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?php echo $patient['episode_id']; ?>" 
                                data-patient-id="<?php echo $patient['patient_id']; ?>" 
                                data-delivery-date="<?php echo $patient['delivery_datetime']; ?>"
                                <?php echo ((string)$selected_episode_id === (string)$patient['episode_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($patient['client_number'] . ' - ' . $patient['surname'] . ' ' . $patient['first_name'] . ' (Delivered: ' . formatDate($patient['delivery_datetime']) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="patient_id" id="patient_id" value="">
            </div>
            <?php if ($babies): ?>
                <div class="form-group">
                    <label for="baby_id">Newborn</label>
                    <select name="baby_id" id="baby_id" <?php echo count($babies) > 1 ? 'required' : ''; ?>>
                        <?php if (count($babies) > 1): ?><option value="">-- Select newborn --</option><?php endif; ?>
                        <?php foreach ($babies as $baby): ?>
                            <option value="<?php echo (int) $baby['id']; ?>" <?php echo count($babies) === 1 ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars('Baby ' . $baby['birth_order'] . ' · ' . $baby['baby_sex'] . ($baby['baby_weight_kg'] !== null ? ' · ' . $baby['baby_weight_kg'] . ' kg' : ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($selected_episode_id): ?>
                <div class="form-hint">No individual newborn record is linked to this delivery yet.</div>
            <?php endif; ?>
            
            <?php if (!empty($patients)): ?>
            <div class="info-badge" id="deliveryInfo" style="display: none;">
                <div class="info-item"><span class="info-label">Delivery Date:</span><span class="info-value" id="deliveryDateDisplay"></span></div>
                <div class="info-item"><span class="info-label">Days Postpartum:</span><span class="info-value" id="daysPostpartum"></span></div>
                <div class="info-item"><span class="info-label">Recommended Visit:</span><span class="info-value" id="recommendedVisit"></span></div>
            </div>
            <?php endif; ?>
            
            <!-- Visit Information -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <h3>Visit Information</h3>
                        <p>Postnatal visit timing and date</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Visit Timing <span class="required">*</span></label>
                        <select name="visit_timing" id="visit_timing" required>
                            <option value="">-- Select --</option>
                            <option value="24_hours">24 Hours after delivery</option>
                            <option value="6_days">6 Days after delivery</option>
                            <option value="6_weeks">6 Weeks after delivery</option>
                            <option value="6_months">6 Months after delivery</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Visit Date <span class="required">*</span></label>
                        <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
            </div>
            
            <!-- Mother Assessment -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-female"></i></div>
                    <div>
                        <h3>Mother Assessment</h3>
                        <p>Postnatal maternal health</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Family Planning Method</label>
                        <select name="fp_method_code">
                            <option value="">-- Select --</option>
                            <option value="1">1 - Oral: Lo-Femeral</option>
                            <option value="2">2 - Oral: Microgynon</option>
                            <option value="3">3 - Oral: Overtre/POP</option>
                            <option value="4">4 - Oral: Others</option>
                            <option value="5">5 - Female Condoms</option>
                            <option value="6">6 - Male Condoms</option>
                            <option value="7">7 - IUDs</option>
                            <option value="8">8 - Injectable</option>
                            <option value="9">9 - Natural</option>
                            <option value="10">10 - Female Sterilization</option>
                            <option value="11">11 - Male Sterilization</option>
                            <option value="12">12 - Implants</option>
                            <option value="13">13 - Other FP</option>
                            <option value="14">14 - No FP method</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Other FP Specify</label>
                        <input type="text" name="fp_other" placeholder="Specify other method">
                    </div>
                    <div class="form-group">
                        <label>Breast Status</label>
                        <select name="breast_status">
                            <option value="">-- Select --</option>
                            <option value="FOM">FOM - Normal</option>
                            <option value="SS">SS - Abnormal discharge</option>
                            <option value="ND">ND - Not assessed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cervix Status</label>
                        <select name="cervix_status">
                            <option value="">-- Select --</option>
                            <option value="FOM">FOM - Normal</option>
                            <option value="SS">SS - Suspicious</option>
                            <option value="ND">ND - Not assessed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Weight (kg)</label>
                        <input type="number" step="0.1" name="mother_weight_kg" placeholder="e.g., 65.5">
                    </div>
                    <div class="form-group">
                        <label>MUAC (cm)</label>
                        <input type="number" step="0.1" name="mother_muac_cm" placeholder="e.g., 23.5">
                        <div class="form-hint">R:&lt;19cm | Y:19-22.9cm | G:≥23cm</div>
                    </div>
                    <div class="form-group">
                        <label>Mother eMTCT Code</label>
                        <select name="mother_emtct_code">
                            <option value="">-- Select --</option>
                            <option value="C">C - Counseled declined</option>
                            <option value="T">T - Tested no results</option>
                            <option value="TR">TR - HIV Negative</option>
                            <option value="TRR">TRR - HIV Positive</option>
                            <option value="NA">NA - Not applicable</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Partner eMTCT Code</label>
                        <input type="text" name="partner_emtct_code" placeholder="e.g., TR, TRR">
                    </div>
                    <div class="form-group">
                        <label>Mother ART Code</label>
                        <select name="mother_art_code">
                            <option value="">-- Select --</option>
                            <option value="ART">ART - Newly initiated</option>
                            <option value="ART-K">ART-K - Already on ART</option>
                            <option value="NA">NA - HIV Negative</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mother ART Number</label>
                        <input type="text" name="mother_art_number" placeholder="ART number">
                    </div>
                    <div class="form-group">
                        <label>CTX (Septrin) Given?</label>
                        <select name="mother_ctx_given">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="form-group half-width">
                        <label>Mother Diagnosis</label>
                        <input type="text" name="mother_diagnosis" placeholder="Any health issues">
                    </div>
                </div>
            </div>
            
            <!-- Baby Assessment -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-baby-carriage"></i></div>
                    <div>
                        <h3>Baby Assessment</h3>
                        <p>Newborn health and care</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Baby Status</label>
                        <select name="baby_status">
                            <option value="">-- Select --</option>
                            <option value="Alive">Alive and well</option>
                            <option value="Dead">Deceased</option>
                            <option value="Referred">Referred</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Baby Age (days)</label>
                        <input type="number" name="baby_age_days" placeholder="Age in days">
                    </div>
                    <div class="form-group">
                        <label>Baby Weight (kg)</label>
                        <input type="number" step="0.01" name="baby_weight_kg" placeholder="e.g., 3.5">
                        <div class="form-hint">&lt;2.5kg = Low Birth Weight</div>
                    </div>
                    <div class="form-group">
                        <label>Baby Diagnosis</label>
                        <input type="text" name="baby_diagnosis" placeholder="Any health issues">
                    </div>
                    <div class="form-group">
                        <label>Infant Feeding Option</label>
                        <select name="infant_feeding_option">
                            <option value="">-- Select --</option>
                            <option value="EBF">Exclusive Breastfeeding (EBF)</option>
                            <option value="Mixed">Mixed Feeding</option>
                            <option value="Formula">Formula Feeding</option>
                        </select>
                    </div>
                    <div class="form-group half-width">
                        <label>Immunization Status</label>
                        <textarea name="immunization_status" rows="2" placeholder="BCG, OPV, Penta, PCV with dates"></textarea>
                    </div>
                    <div class="form-group">
                        <label>DBS Sample Taken?</label>
                        <select name="dbs_sample_taken" id="dbs_sample">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="form-group" id="dbs_result_group" style="display: none;">
                        <label>DBS Test Result</label>
                        <select name="dbs_test_result">
                            <option value="pending">Pending</option>
                            <option value="negative">Negative</option>
                            <option value="positive">Positive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Baby Septrin Given?</label>
                        <select name="baby_septrin_given">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Baby Referred?</label>
                        <select name="baby_referred" id="baby_referred">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="form-group" id="referral_place_group" style="display: none;">
                        <label>Referral Place</label>
                        <input type="text" name="baby_referral_place" placeholder="Facility name">
                    </div>
                    <div class="form-group">
                        <label>Vitamin A Given?</label>
                        <select name="vitamin_a_given">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="reset" class="btn-reset"><i class="fas fa-undo"></i> Reset</button>
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Postnatal Visit</button>
            </div>
        </form>
    </div>
</div>

<script>
    function updatePatientId(select) {
        const option = select.options[select.selectedIndex];
        const patientId = option.getAttribute('data-patient-id');
        const deliveryDate = option.getAttribute('data-delivery-date');

        if (select.value) {
            window.location.href = 'postnatal_visit.php?episode_id=' + encodeURIComponent(select.value);
            return;
        }
        
        document.getElementById('patient_id').value = patientId;
        
        if (deliveryDate && patientId) {
            const delivery = new Date(deliveryDate);
            const today = new Date();
            const daysDiff = Math.floor((today - delivery) / (1000 * 60 * 60 * 24));
            
            document.getElementById('deliveryDateDisplay').innerText = delivery.toLocaleDateString();
            document.getElementById('daysPostpartum').innerText = daysDiff + ' days';
            
            let recommended = '';
            if (daysDiff <= 1) recommended = '24 hours';
            else if (daysDiff <= 6) recommended = '6 days';
            else if (daysDiff <= 42) recommended = '6 weeks';
            else if (daysDiff <= 180) recommended = '6 months';
            else recommended = 'Overdue';
            
            document.getElementById('recommendedVisit').innerText = recommended;
            document.getElementById('deliveryInfo').style.display = 'flex';
        } else {
            document.getElementById('deliveryInfo').style.display = 'none';
        }
    }
    
    document.getElementById('dbs_sample')?.addEventListener('change', function() {
        document.getElementById('dbs_result_group').style.display = this.value == '1' ? 'block' : 'none';
    });
    
    document.getElementById('baby_referred')?.addEventListener('change', function() {
        document.getElementById('referral_place_group').style.display = this.value == '1' ? 'block' : 'none';
    });
</script>

<?php require_once 'footer.php'; ?>