<?php
// ============================================
// FILE: anc_visit.php
// PURPOSE: Record subsequent ANC visits (2nd, 3rd, 4th+)
// REDESIGNED - No scrolling, clean modern layout
// ============================================

require_once 'config.php';
requireLogin();
require_once 'nav.php';

$error = '';
$success = '';
$patient_info = null;
$episode_info = null;
$next_visit_number = 1;

// Get active patients for dropdown
$patients = $pdo->query("SELECT p.id, p.surname, p.first_name, pe.id as episode_id, pe.client_number, pe.gravida, pe.parity, pe.edd, pe.lmp
                         FROM patients p 
                         JOIN pregnancy_episodes pe ON p.id = pe.patient_id 
                         WHERE pe.status = 'active'
                         ORDER BY p.surname LIMIT 100")->fetchAll();

// If patient selected via GET
if (isset($_GET['episode_id'])) {
    $stmt = $pdo->prepare("SELECT p.*, pe.* FROM patients p 
                           JOIN pregnancy_episodes pe ON p.id = pe.patient_id 
                           WHERE pe.id = :eid AND pe.status = 'active'");
    $stmt->execute([':eid' => $_GET['episode_id']]);
    $patient_info = $stmt->fetch();
    
    if ($patient_info) {
        $stmt2 = $pdo->prepare("SELECT MAX(visit_number) as max_visit FROM anc_visits WHERE pregnancy_episode_id = :eid");
        $stmt2->execute([':eid' => $_GET['episode_id']]);
        $last_visit = $stmt2->fetch();
        $next_visit_number = ($last_visit['max_visit'] ?? 0) + 1;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        validateClinicalInput($_POST, [
            'weight_kg' => ['Weight (kg)', 25, 250],
            'height_cm' => ['Height (cm)', 100, 220],
            'muac_cm' => ['MUAC (cm)', 10, 50],
            'bp_systolic' => ['Systolic BP', 50, 300],
            'bp_diastolic' => ['Diastolic BP', 30, 200],
            'partner_age' => ['Partner age', 12, 100],
            'cd4_value' => ['CD4 value', 0, 5000],
            'iron_folate_tablets' => ['Iron/folate tablets', 0, 1000],
            'combined_fefan_tablets' => ['Combined Fefan tablets', 0, 1000],
            'misoprostol_tablets' => ['Misoprostol tablets', 0, 100],
        ]);
        $pdo->beginTransaction();
        $episode_id = filter_var($_POST['episode_id'] ?? null, FILTER_VALIDATE_INT);
        $visit_date = $_POST['visit_date'] ?? '';
        $validDate = static function (string $date): bool {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
        };
        $nextVisitDate = empty($_POST['next_visit_date']) ? null : $_POST['next_visit_date'];
        if (!$validDate($visit_date) || $visit_date > date('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid ANC visit date that is not in the future.');
        }
        if ($nextVisitDate !== null && (!$validDate($nextVisitDate) || $nextVisitDate < $visit_date)) {
            throw new InvalidArgumentException('The next ANC date must be valid and on or after this visit date.');
        }
        
        $stmt = $pdo->prepare(
            "SELECT pe.lmp, p.id AS patient_id
             FROM pregnancy_episodes pe
             JOIN patients p ON p.id = pe.patient_id
             WHERE pe.id = :id AND pe.status = 'active'
             FOR UPDATE"
        );
        $stmt->execute([':id' => $episode_id]);
        $episode = $stmt->fetch();
        if ($episode_id === false || $episode_id < 1 || !$episode) {
            throw new InvalidArgumentException('Choose an active pregnancy episode.');
        }
        $lmp = $episode['lmp'];
        if ($lmp && $visit_date < $lmp) {
            throw new InvalidArgumentException('The ANC visit date cannot be before the recorded LMP.');
        }
        $ga = calculateGestationalAge($lmp, $visit_date);
        acquireSerialLock($pdo, 'anc_visit_' . $episode_id);
        $visitStmt = $pdo->prepare('SELECT COALESCE(MAX(visit_number), 0) + 1 FROM anc_visits WHERE pregnancy_episode_id = :id');
        $visitStmt->execute([':id' => $episode_id]);
        $visit_number = (int) $visitStmt->fetchColumn();
        
        $serial_no = getNextSerialNo($pdo, 'anc_visits', 'visit_date');
        $muac_cm = !empty($_POST['muac_cm']) ? floatval($_POST['muac_cm']) : null;
        $muac_color = $muac_cm ? getMUACColor($muac_cm) : 'ND';
        
        $stmt = $pdo->prepare("INSERT INTO anc_visits (
            pregnancy_episode_id, serial_no, visit_number, visit_date, next_visit_date, gestational_age_weeks,
            weight_kg, height_cm, muac_cm, muac_color, inr_number,
            bp_systolic, bp_diastolic, diagnosis, complications,
            woman_hiv_code, hivst_kit_given, partner_age, partner_result_code, partner_tfv_code,
            partner_linked_to_art, partner_art_place, partner_art_number,
            cd4_value, cd4_date, viral_load_value, viral_load_date, who_stage, emtct_risk,
            syphilis_result, hepatitis_b_result, llin_given, mebendazole_status,
            iron_folate_tablets, combined_fefan_tablets, misoprostol_tablets,
            other_treatment, referral_type, referral_number, referred_to,
            tt_dose_received, iptp_dose, recorded_by
        ) VALUES (
            :episode_id, :serial_no, :visit_number, :visit_date, :next_visit, :ga,
            :weight, :height, :muac, :muac_color, :inr,
            :bp_sys, :bp_dias, :diagnosis, :complications,
            :hiv_code, :hivst_kit, :partner_age, :partner_result, :partner_tfv,
            :partner_link, :partner_place, :partner_num,
            :cd4, :cd4_date, :vl, :vl_date, :who, :risk,
            :syphilis, :hep_b, :llin, :mebenda,
            :iron, :fefan, :misoprostol,
            :other_tx, :ref_type, :ref_num, :ref_to,
            :tt, :iptp, :recorded_by
        )");
        
        $stmt->execute([
            ':episode_id' => $episode_id,
            ':serial_no' => $serial_no,
            ':visit_number' => $visit_number,
            ':visit_date' => $visit_date,
            ':next_visit' => $nextVisitDate,
            ':ga' => $ga,
            ':weight' => !empty($_POST['weight_kg']) ? $_POST['weight_kg'] : null,
            ':height' => !empty($_POST['height_cm']) ? $_POST['height_cm'] : null,
            ':muac' => $muac_cm,
            ':muac_color' => $muac_color,
            ':inr' => $_POST['inr_number'] ?? null,
            ':bp_sys' => !empty($_POST['bp_systolic']) ? $_POST['bp_systolic'] : null,
            ':bp_dias' => !empty($_POST['bp_diastolic']) ? $_POST['bp_diastolic'] : null,
            ':diagnosis' => $_POST['diagnosis'] ?? null,
            ':complications' => $_POST['complications'] ?? null,
            ':hiv_code' => $_POST['woman_hiv_code'] ?? null,
            ':hivst_kit' => formBoolean($_POST, 'hivst_kit_given'),
            ':partner_age' => !empty($_POST['partner_age']) ? $_POST['partner_age'] : null,
            ':partner_result' => $_POST['partner_result_code'] ?? null,
            ':partner_tfv' => $_POST['partner_tfv_code'] ?? null,
            ':partner_link' => formBoolean($_POST, 'partner_linked_to_art'),
            ':partner_place' => $_POST['partner_art_place'] ?? null,
            ':partner_num' => $_POST['partner_art_number'] ?? null,
            ':cd4' => !empty($_POST['cd4_value']) ? $_POST['cd4_value'] : null,
            ':cd4_date' => !empty($_POST['cd4_date']) ? $_POST['cd4_date'] : null,
            ':vl' => $_POST['viral_load_value'] ?? null,
            ':vl_date' => !empty($_POST['viral_load_date']) ? $_POST['viral_load_date'] : null,
            ':who' => !empty($_POST['who_stage']) ? $_POST['who_stage'] : null,
            ':risk' => $_POST['emtct_risk'] ?? null,
            ':syphilis' => $_POST['syphilis_result'] ?? null,
            ':hep_b' => $_POST['hepatitis_b_result'] ?? null,
            ':llin' => formBoolean($_POST, 'llin_given'),
            ':mebenda' => $_POST['mebendazole_status'] ?? null,
            ':iron' => !empty($_POST['iron_folate_tablets']) ? $_POST['iron_folate_tablets'] : null,
            ':fefan' => !empty($_POST['combined_fefan_tablets']) ? $_POST['combined_fefan_tablets'] : null,
            ':misoprostol' => !empty($_POST['misoprostol_tablets']) ? $_POST['misoprostol_tablets'] : null,
            ':other_tx' => emptyToNull($_POST['other_treatment'] ?? null),
            ':ref_type' => emptyToNull($_POST['referral_type'] ?? null),
            ':ref_num' => emptyToNull($_POST['referral_number'] ?? null),
            ':ref_to' => emptyToNull($_POST['referred_to'] ?? null),
            ':tt' => !empty($_POST['tt_dose_received']) ? $_POST['tt_dose_received'] : null,
            ':iptp' => emptyToNull($_POST['iptp_dose'] ?? null),
            ':recorded_by' => currentUserId()
        ]);
        
        logAction($pdo, $_SESSION['user_id'], 'CREATE_ANC_VISIT', 'anc_visits', $pdo->lastInsertId(), "Added ANC visit #$visit_number");
        $pdo->commit();
        
        $success = "ANC Visit #$visit_number recorded successfully!";
        
        echo '<script>setTimeout(function(){window.location.href='
            . json_encode('patient_profile.php?id=' . rawurlencode((string) $episode['patient_id']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';},2000);</script>';
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } else {
            error_log('ANC visit request failed: ' . $e->getMessage());
            $error = 'Could not save the ANC visit. Please review the details and try again.';
        }
    }
}
?>

<style>
    /* MAIN CONTAINER - NO SCROLLING */
    .anc-wrapper {
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
    
    .visit-badge {
        background: #1e88e5;
        color: white;
        padding: 8px 18px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
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
        .alert {
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

<div class="anc-wrapper">
    <div class="form-card">
        <!-- Header -->
        <div class="form-header">
            <h1><i class="fas fa-notes-medical"></i> ANC Follow-up Visit</h1>
            <p>Record subsequent antenatal care visits for an active pregnancy</p>
        </div>
        
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?> Redirecting to patient profile...</span>
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
                <i class="fas fa-user-md"></i> Select Active Patient
            </div>
            <select id="patientSelect" onchange="location.href='anc_visit.php?episode_id=' + this.value" class="patient-dropdown">
                <option value="">-- Select Patient --</option>
                <?php foreach ($patients as $patient): ?>
                    <option value="<?php echo $patient['episode_id']; ?>" <?php echo (isset($_GET['episode_id']) && $_GET['episode_id'] == $patient['episode_id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($patient['client_number'] . ' - ' . $patient['surname'] . ' ' . $patient['first_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($patient_info): ?>
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
                    <span class="info-label"><i class="fas fa-fetus"></i> G/P:</span>
                    <span class="info-value"><?php echo $patient_info['gravida']; ?>/<?php echo $patient_info['parity']; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar"></i> EDD:</span>
                    <span class="info-value"><?php echo formatDate($patient_info['edd']); ?></span>
                </div>
                <div class="visit-badge">
                    <i class="fas fa-chart-line"></i> Visit #<?php echo $next_visit_number; ?>
                </div>
            </div>
            
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="episode_id" value="<?php echo (int) $_GET['episode_id']; ?>">
                <input type="hidden" name="patient_id" value="<?php echo $patient_info['id']; ?>">
                <input type="hidden" name="visit_number" value="<?php echo $next_visit_number; ?>">
                
                <!-- Visit Date -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-calendar-day"></i></div>
                        <div>
                            <h3>Visit Information</h3>
                            <p>Date of this ANC visit</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group half-width">
                            <label>Visit Date <span class="required">*</span></label>
                            <input type="date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group half-width">
                            <label>Next Visit Date</label>
                            <input type="date" name="next_visit_date">
                            <div class="form-hint">Enter the follow-up date agreed by the clinician; no fixed interval is assumed.</div>
                        </div>
                    </div>
                </div>
                
                <!-- Anthropometry & Vital Signs -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-weight-scale"></i></div>
                        <div>
                            <h3>Anthropometry & Vital Signs</h3>
                            <p>Physical measurements and vitals</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" step="0.1" name="weight_kg" placeholder="e.g., 65.5">
                        </div>
                        <div class="form-group">
                            <label>Height (cm)</label>
                            <input type="number" step="0.1" name="height_cm" placeholder="e.g., 160">
                        </div>
                        <div class="form-group">
                            <label>MUAC (cm)</label>
                            <input type="number" step="0.1" name="muac_cm" placeholder="e.g., 23.5">
                            <div class="form-hint">R:&lt;19cm | Y:19-22.9cm | G:≥23cm</div>
                        </div>
                        <div class="form-group">
                            <label>INR Number</label>
                            <input type="text" name="inr_number" placeholder="Nutrition register number">
                        </div>
                        <div class="form-group">
                            <label>BP Systolic</label>
                            <input type="number" name="bp_systolic" placeholder="e.g., 120">
                        </div>
                        <div class="form-group">
                            <label>BP Diastolic</label>
                            <input type="number" name="bp_diastolic" placeholder="e.g., 80">
                        </div>
                    </div>
                </div>
                
                <!-- HIV / eMTCT -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-heartbeat"></i></div>
                        <div>
                            <h3>HIV / eMTCT Services</h3>
                            <p>Prevention of mother-to-child transmission</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Woman eMTCT Code</label>
                            <select name="woman_hiv_code">
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
                            <label>HIVST Kit Given?</label>
                            <select name="hivst_kit_given">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Partner Age</label>
                            <input type="number" name="partner_age" placeholder="Years">
                        </div>
                        <div class="form-group">
                            <label>Partner Result Code</label>
                            <input type="text" name="partner_result_code" placeholder="POS / NEG">
                        </div>
                        <div class="form-group">
                            <label>Partner TFV Code</label>
                            <input type="text" name="partner_tfv_code" placeholder="Test for Verification">
                        </div>
                        <div class="form-group">
                            <label>Partner Linked to ART?</label>
                            <select name="partner_linked_to_art">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Partner ART Place</label>
                            <input type="text" name="partner_art_place" maxlength="255" placeholder="Facility where partner receives ART">
                        </div>
                        <div class="form-group">
                            <label>Partner ART Number</label>
                            <input type="text" name="partner_art_number" maxlength="50" placeholder="ART number">
                        </div>
                    </div>
                </div>
                
                <!-- Lab Results -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-microscope"></i></div>
                        <div>
                            <h3>Laboratory Results</h3>
                            <p>CD4, Viral Load, and WHO staging</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>CD4 Value</label>
                            <input type="number" name="cd4_value" placeholder="e.g., 450">
                        </div>
                        <div class="form-group">
                            <label>CD4 Date</label>
                            <input type="date" name="cd4_date">
                        </div>
                        <div class="form-group">
                            <label>Viral Load</label>
                            <input type="text" name="viral_load_value" placeholder="e.g., &lt;40 copies/mL">
                        </div>
                        <div class="form-group">
                            <label>VL Date</label>
                            <input type="date" name="viral_load_date">
                        </div>
                        <div class="form-group">
                            <label>WHO Stage</label>
                            <select name="who_stage">
                                <option value="">-- Select --</option>
                                <option value="1">Stage 1</option>
                                <option value="2">Stage 2</option>
                                <option value="3">Stage 3</option>
                                <option value="4">Stage 4</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>eMTCT Risk</label>
                            <select name="emtct_risk">
                                <option value="">-- Select --</option>
                                <option value="High">High Risk</option>
                                <option value="Low">Low Risk</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Prophylaxis & Treatments -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-syringe"></i></div>
                        <div>
                            <h3>Prophylaxis & Treatments</h3>
                            <p>Preventive medications and interventions</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>TT Dose Received</label>
                            <select name="tt_dose_received">
                                <option value="">None</option>
                                <option value="1">Dose 1</option>
                                <option value="2">Dose 2</option>
                                <option value="3">Dose 3</option>
                                <option value="4">Dose 4</option>
                                <option value="5">Dose 5</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>IPTp Dose</label>
                            <input type="text" name="iptp_dose" placeholder="SP1, SP2, SP3">
                        </div>
                        <div class="form-group">
                            <label>LLIN Given?</label>
                            <select name="llin_given">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Mebendazole Status</label>
                            <select name="mebendazole_status">
                                <option value="">-- Select --</option>
                                <option value="given">✓ Given</option>
                                <option value="not_given">✗ Not Given</option>
                                <option value="na">N/A</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Iron/Folate Tablets</label>
                            <input type="number" name="iron_folate_tablets" placeholder="Number of tablets">
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
                            <label>Combined Fefan Tablets</label>
                            <input type="number" name="combined_fefan_tablets" placeholder="Number of tablets">
                        </div>
                        <div class="form-group">
                            <label>Misoprostol Tablets</label>
                            <input type="number" name="misoprostol_tablets" placeholder="From 34 weeks">
                        </div>
                    </div>
                </div>
                
                <!-- Diagnosis & Complications -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-stethoscope"></i></div>
                        <div>
                            <h3>Diagnosis & Complications</h3>
                            <p>Clinical findings and risk factors</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group half-width">
                            <label>Diagnosis</label>
                            <input type="text" name="diagnosis" placeholder="e.g., Normal Pregnancy (NP)">
                        </div>
                        <div class="form-group half-width">
                            <label>Other Treatments</label>
                            <input type="text" name="other_treatment" placeholder="Additional treatments given">
                        </div>
                        <div class="form-group full-width">
                            <label>Complications / Risk Factors</label>
                            <textarea name="complications" rows="2" placeholder="List any complications or write 'Nil'"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Referral Information -->
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-referral"></i></div>
                        <div>
                            <h3>Referral Information</h3>
                            <p>Track referrals in/out of the facility</p>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Referral Type</label>
                            <select name="referral_type">
                                <option value="">None</option>
                                <option value="REF_OUT">REF OUT - Referred out</option>
                                <option value="REF_IN">REF IN - Referred in</option>
                                <option value="C_REF_IN">C/REF IN - Community referral in</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Referral Number</label>
                            <input type="text" name="referral_number" placeholder="Referral number">
                        </div>
                        <div class="form-group">
                            <label>Referred To / From</label>
                            <input type="text" name="referred_to" placeholder="Facility name">
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="reset" class="btn-reset"><i class="fas fa-undo"></i> Reset Form</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save ANC Visit #<?php echo $next_visit_number; ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>