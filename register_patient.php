<?php
// ============================================
// FILE: register_patient.php
// PURPOSE: Complete ANC First Visit Registration - NO SCROLLING
// Clean form that flows naturally down the page
// ============================================

require_once 'config.php';
requireLogin();
require_once 'nav.php';

$error = '';
$success = '';
$patient_id = null;
$today = date('Y-m-d');
$selected_patient = null;
$selected_patient_has_active_episode = false;
$requestedPatientId = $_GET['patient_id'] ?? $_POST['existing_patient_id'] ?? null;
$selected_patient_id = $requestedPatientId === null || $requestedPatientId === ''
    ? null
    : filter_var($requestedPatientId, FILTER_VALIDATE_INT);
if ($selected_patient_id === false) {
    http_response_code(400);
    exit('Invalid patient selection.');
}
if ($selected_patient_id !== false && $selected_patient_id !== null && $selected_patient_id > 0) {
    $patientStmt = $pdo->prepare(
        "SELECT p.*,
                (SELECT COUNT(*) FROM pregnancy_episodes pe WHERE pe.patient_id = p.id) AS episode_count,
                (SELECT MAX(COALESCE(dr.delivery_datetime, pe.closed_date))
                 FROM pregnancy_episodes pe
                 LEFT JOIN delivery_records dr ON dr.pregnancy_episode_id = pe.id
                 WHERE pe.patient_id = p.id) AS last_pregnancy_end
         FROM patients p
         WHERE p.id = :id"
    );
    $patientStmt->execute([':id' => $selected_patient_id]);
    $selected_patient = $patientStmt->fetch();
    if (!$selected_patient) {
        http_response_code(404);
        exit('Patient not found.');
    }

    $activeEpisodeStmt = $pdo->prepare("SELECT 1 FROM pregnancy_episodes WHERE patient_id = :id AND status = 'active' LIMIT 1");
    $activeEpisodeStmt->execute([':id' => $selected_patient_id]);
    if ($activeEpisodeStmt->fetchColumn()) {
        $error = 'This patient already has an active pregnancy. Open the current pregnancy record instead.';
        $selected_patient_has_active_episode = true;
    }
}

// Get next client number
$client_number = generateClientNumber($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        validateClinicalInput($_POST, [
            'age' => ['Age', 10, 60],
            'gravida' => ['Gravida', 1, 30],
            'parity' => ['Parity', 0, 30],
            'weight_kg' => ['Weight (kg)', 25, 250],
            'height_cm' => ['Height (cm)', 100, 220],
            'muac_cm' => ['MUAC (cm)', 10, 50],
            'bp_systolic' => ['Systolic BP', 50, 300],
            'bp_diastolic' => ['Diastolic BP', 30, 200],
            'partner_age' => ['Partner age', 12, 100],
            'iron_folate_tablets' => ['Iron/folate tablets', 0, 1000],
            'combined_fefan_tablets' => ['Combined Fefan tablets', 0, 1000],
            'misoprostol_tablets' => ['Misoprostol tablets', 0, 100],
        ]);
        $pdo->beginTransaction();
        $existingPatientValue = $_POST['existing_patient_id'] ?? '';
        $existingPatientId = $existingPatientValue === ''
            ? null
            : filter_var($existingPatientValue, FILTER_VALIDATE_INT);
        $lmp = $_POST['lmp'] ?? '';
        $firstAncDate = $_POST['first_anc_date'] ?? '';
        $isValidDate = static function (string $date): bool {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
        };
        if (!$isValidDate($lmp) || !$isValidDate($firstAncDate)) {
            throw new InvalidArgumentException('Enter valid dates for the last menstrual period and first ANC visit.');
        }
        if ($lmp > $firstAncDate || $firstAncDate > $today) {
            throw new InvalidArgumentException('The first ANC date must be on or after the LMP and cannot be in the future.');
        }
        if ($existingPatientId === false) {
            throw new InvalidArgumentException('Invalid existing patient selection.');
        }
        $gravida = filter_var($_POST['gravida'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $parity = filter_var($_POST['parity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($gravida === false || $parity === false || $parity > $gravida) {
            throw new InvalidArgumentException('Enter a valid obstetric history: gravida must be at least one and parity cannot exceed gravida.');
        }

        if ($existingPatientId !== null) {
            if ($existingPatientId < 1) {
                throw new InvalidArgumentException('Invalid existing patient selection.');
            }
            $lockPatient = $pdo->prepare('SELECT id FROM patients WHERE id = :id FOR UPDATE');
            $lockPatient->execute([':id' => $existingPatientId]);
            $patient_id = $lockPatient->fetchColumn();
            if (!$patient_id) {
                throw new InvalidArgumentException('The selected patient was not found.');
            }
            $activeCheck = $pdo->prepare("SELECT 1 FROM pregnancy_episodes WHERE patient_id = :id AND status = 'active' LIMIT 1");
            $activeCheck->execute([':id' => $patient_id]);
            if ($activeCheck->fetchColumn()) {
                throw new InvalidArgumentException('This patient already has an active pregnancy.');
            }
            $lastEndStmt = $pdo->prepare(
                "SELECT MAX(COALESCE(dr.delivery_datetime, pe.closed_date))
                 FROM pregnancy_episodes pe
                 LEFT JOIN delivery_records dr ON dr.pregnancy_episode_id = pe.id
                 WHERE pe.patient_id = :id"
            );
            $lastEndStmt->execute([':id' => $patient_id]);
            $lastPregnancyEnd = $lastEndStmt->fetchColumn();
            if ($lastPregnancyEnd && $lmp <= substr($lastPregnancyEnd, 0, 10)) {
                throw new InvalidArgumentException('The new LMP must be after the end date of the patient’s previous recorded pregnancy.');
            }
        } else {
            if (trim($_POST['surname'] ?? '') === '' || trim($_POST['first_name'] ?? '') === '') {
                throw new InvalidArgumentException('Enter the mother’s surname and first name.');
            }
            $stmt = $pdo->prepare("INSERT INTO patients (nin, surname, first_name, age, client_category, phone, district, sub_county, parish, village, created_by)
                                  VALUES (:nin, :surname, :first_name, :age, :category, :phone, :district, :sub_county, :parish, :village, :created_by)");
            $stmt->execute([
                ':nin' => emptyToNull($_POST['nin'] ?? null),
                ':surname' => trim($_POST['surname'] ?? ''),
                ':first_name' => trim($_POST['first_name'] ?? ''),
                ':age' => !empty($_POST['age']) ? $_POST['age'] : null,
                ':category' => $_POST['client_category'] ?? 'N',
                ':phone' => emptyToNull($_POST['phone'] ?? null),
                ':district' => emptyToNull($_POST['district'] ?? null),
                ':sub_county' => emptyToNull($_POST['sub_county'] ?? null),
                ':parish' => emptyToNull($_POST['parish'] ?? null),
                ':village' => emptyToNull($_POST['village'] ?? null),
                ':created_by' => currentUserId()
            ]);
            $patient_id = $pdo->lastInsertId();
        }

        $edd = calculateEDD($lmp);
        $booking_ga = calculateGestationalAge($lmp, $firstAncDate);
        $booking_timing = $booking_ga !== null && $booking_ga <= 12 ? 'early' : 'late';
        
        // Insert pregnancy episode
        $stmt = $pdo->prepare("INSERT INTO pregnancy_episodes (patient_id, client_number, gravida, parity, lmp, edd, booking_ga_weeks, booking_timing, status, created_by) 
                              VALUES (:patient_id, :client_number, :gravida, :parity, :lmp, :edd, :booking_ga, :booking_timing, 'active', :created_by)");
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':client_number' => $client_number,
            ':gravida' => $gravida,
            ':parity' => $parity,
            ':lmp' => $lmp,
            ':edd' => $edd,
            ':booking_ga' => $booking_ga,
            ':booking_timing' => $booking_timing,
            ':created_by' => currentUserId()
        ]);
        $episode_id = $pdo->lastInsertId();
        
        // Get MUAC color
        $muac_cm = !empty($_POST['muac_cm']) ? floatval($_POST['muac_cm']) : null;
        $muac_color = $muac_cm ? getMUACColor($muac_cm) : 'ND';
        
        // Insert first ANC visit
        $serial_no = getNextSerialNo($pdo, 'anc_visits', 'visit_date');
        
        $stmt = $pdo->prepare("INSERT INTO anc_visits (
            pregnancy_episode_id, serial_no, visit_number, visit_date, next_visit_date, gestational_age_weeks,
            weight_kg, height_cm, muac_cm, muac_color, inr_number,
            bp_systolic, bp_diastolic, diagnosis, complications, tt_dose_received,
            woman_hiv_code, hivst_kit_given, partner_age, partner_result_code, partner_tfv_code,
            syphilis_result, hepatitis_b_result, llin_given, mebendazole_status,
            iron_folate_tablets, combined_fefan_tablets, misoprostol_tablets, recorded_by
        ) VALUES (
            :episode_id, :serial_no, 1, :first_anc_date, :next_visit, :ga,
            :weight, :height, :muac, :muac_color, :inr,
            :bp_sys, :bp_dias, :diagnosis, :complications, :tt,
            :hiv_code, :hivst_kit, :partner_age, :partner_result, :partner_tfv,
            :syphilis, :hep_b, :llin, :mebenda,
            :iron, :fefan, :misoprostol, :recorded_by
        )");
        
        $stmt->execute([
            ':episode_id' => $episode_id,
            ':serial_no' => $serial_no,
            ':first_anc_date' => $firstAncDate,
            ':next_visit' => emptyToNull($_POST['next_visit_date'] ?? null),
            ':ga' => $booking_ga,
            ':weight' => !empty($_POST['weight_kg']) ? $_POST['weight_kg'] : null,
            ':height' => !empty($_POST['height_cm']) ? $_POST['height_cm'] : null,
            ':muac' => $muac_cm,
            ':muac_color' => $muac_color,
            ':inr' => emptyToNull($_POST['inr_number'] ?? null),
            ':bp_sys' => !empty($_POST['bp_systolic']) ? $_POST['bp_systolic'] : null,
            ':bp_dias' => !empty($_POST['bp_diastolic']) ? $_POST['bp_diastolic'] : null,
            ':diagnosis' => emptyToNull($_POST['diagnosis'] ?? null),
            ':complications' => emptyToNull($_POST['complications'] ?? null),
            ':tt' => !empty($_POST['tt_dose']) ? $_POST['tt_dose'] : null,
            ':hiv_code' => emptyToNull($_POST['woman_hiv_code'] ?? null),
            ':hivst_kit' => formBoolean($_POST, 'hivst_kit_given'),
            ':partner_age' => !empty($_POST['partner_age']) ? $_POST['partner_age'] : null,
            ':partner_result' => emptyToNull($_POST['partner_result_code'] ?? null),
            ':partner_tfv' => emptyToNull($_POST['partner_tfv_code'] ?? null),
            ':syphilis' => emptyToNull($_POST['syphilis_result'] ?? null),
            ':hep_b' => emptyToNull($_POST['hepatitis_b_result'] ?? null),
            ':llin' => formBoolean($_POST, 'llin_given'),
            ':mebenda' => emptyToNull($_POST['mebendazole_status'] ?? null),
            ':iron' => !empty($_POST['iron_folate_tablets']) ? $_POST['iron_folate_tablets'] : null,
            ':fefan' => !empty($_POST['combined_fefan_tablets']) ? $_POST['combined_fefan_tablets'] : null,
            ':misoprostol' => !empty($_POST['misoprostol_tablets']) ? $_POST['misoprostol_tablets'] : null,
            ':recorded_by' => currentUserId()
        ]);
        
        if ($existingPatientId === null) {
            logAction($pdo, currentUserId(), 'CREATE_PATIENT', 'patients', $patient_id, 'Registered new patient');
        }
        logAction($pdo, currentUserId(), 'CREATE_PREGNANCY_EPISODE', 'pregnancy_episodes', $episode_id, "Registered pregnancy episode with client number: $client_number");
        $pdo->commit();
        
        $success = ($existingPatientId === null ? 'Patient registered' : 'New pregnancy registered for existing patient')
            . "! Client Number: $client_number";
        
        echo "<script>setTimeout(function() { window.location.href = 'patient_profile.php?id=$patient_id'; }, 2000);</script>";
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } else {
            error_log('Patient registration failed: ' . $e->getMessage());
            $error = 'Could not complete the registration. Please review the details and try again.';
        }
    }
}
?>

<style>
    /* FORM CONTAINER - NO SCROLLING, NATURAL FLOW */
    .form-wrapper {
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
        margin-bottom: 20px;
    }
    
    .client-badge {
        background: rgba(255, 255, 255, 0.15);
        border-radius: 12px;
        padding: 12px 20px;
        display: inline-block;
    }
    
    .client-badge .label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.7;
    }
    
    .client-badge .number {
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 1px;
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
    
    /* FORM SECTIONS */
    .form-section {
        padding: 28px 32px;
        border-bottom: 1px solid var(--border);
    }
    
    .section-title {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
    }
    
    .section-icon {
        width: 44px;
        height: 44px;
        background: #176f83;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
    }
    
    .section-title h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }
    
    .section-title p {
        font-size: 13px;
        color: var(--text-muted);
        margin: 4px 0 0 0;
    }
    
    /* FORM GRID - RESPONSIVE BUT NO SCROLLING */
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
        padding: 12px 28px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
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
        padding: 12px 24px;
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
        .form-group.full-width {
            grid-column: span 2;
        }
        .form-section {
            padding: 20px 24px;
        }
        .form-header {
            padding: 20px 24px;
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

<div class="form-wrapper">
    <div class="form-card">
        <!-- Header -->
        <div class="form-header">
            <h1><i class="fas fa-plus-circle"></i> <?php echo $selected_patient ? 'New Pregnancy Episode' : 'New ANC Registration'; ?></h1>
            <p><?php echo $selected_patient ? 'Create a new pregnancy record for this existing patient. Her demographic record will be retained.' : 'Register a pregnant mother for Antenatal Care at Lubaga Hospital'; ?></p>
            <div class="client-badge">
                <div class="label"><i class="fas fa-id-card"></i> NEW PREGNANCY CLIENT NUMBER</div>
                <div class="number"><?php echo $client_number; ?></div>
            </div>
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
        
        <form method="POST">
            <?php echo csrfField(); ?>
            <?php if ($selected_patient): ?>
                <input type="hidden" name="existing_patient_id" value="<?php echo (int) $selected_patient['id']; ?>">
                <div class="form-section">
                    <div class="section-title">
                        <div class="section-icon"><i class="fas fa-user-check"></i></div>
                        <div><h3>Existing Patient</h3><p>This pregnancy will be added to her existing longitudinal record.</p></div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Name</label><input value="<?php echo htmlspecialchars($selected_patient['surname'] . ' ' . $selected_patient['first_name'], ENT_QUOTES, 'UTF-8'); ?>" disabled></div>
                        <div class="form-group"><label>NIN</label><input value="<?php echo htmlspecialchars($selected_patient['nin'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>" disabled></div>
                        <div class="form-group"><label>Phone</label><input value="<?php echo htmlspecialchars($selected_patient['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>" disabled></div>
                        <div class="form-group"><label>Recorded pregnancy episodes</label><input value="<?php echo (int) $selected_patient['episode_count']; ?>" disabled></div>
                        <?php if ($selected_patient['last_pregnancy_end']): ?>
                            <div class="form-group"><label>Last recorded pregnancy end</label><input value="<?php echo htmlspecialchars(formatDate($selected_patient['last_pregnancy_end']), ENT_QUOTES, 'UTF-8'); ?>" disabled></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-hint">Confirm gravida and parity from the mother’s complete obstetric history; the system count may not include pregnancies managed elsewhere.</div>
                </div>
            <?php else: ?>
            <!-- SECTION 1: PATIENT DEMOGRAPHICS -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-user"></i></div>
                    <div>
                        <h3>Patient Demographics</h3>
                        <p>Basic personal information of the mother</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>National ID (NIN)</label>
                        <input type="text" name="nin" placeholder="e.g., CMN123456789">
                    </div>
                    <div class="form-group">
                        <label>Surname <span class="required">*</span></label>
                        <input type="text" name="surname" placeholder="Last name" required>
                    </div>
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" placeholder="First name" required>
                    </div>
                    <div class="form-group">
                        <label>Age (years)</label>
                        <input type="number" name="age" min="10" max="60" placeholder="e.g., 28">
                    </div>
                    <div class="form-group">
                        <label>Client Category</label>
                        <select name="client_category">
                            <option value="N">🇺🇬 National (Ugandan)</option>
                            <option value="F">🌍 Foreigner</option>
                            <option value="R">🏕️ Refugee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="0772123456">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> For SMS reminders</div>
                    </div>
                </div>
            </div>
            
            <!-- SECTION 2: ADDRESS -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-location-dot"></i></div>
                    <div>
                        <h3>Address Information</h3>
                        <p>Physical location for community follow-up</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>District</label>
                        <input type="text" name="district" placeholder="e.g., Kampala">
                    </div>
                    <div class="form-group">
                        <label>Sub-County</label>
                        <input type="text" name="sub_county" placeholder="e.g., Central">
                    </div>
                    <div class="form-group">
                        <label>Parish</label>
                        <input type="text" name="parish" placeholder="e.g., Namirembe">
                    </div>
                    <div class="form-group">
                        <label>Village</label>
                        <input type="text" name="village" placeholder="e.g., Kisenyi">
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- SECTION 3: PREGNANCY INFORMATION -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-fetus"></i></div>
                    <div>
                        <h3>Pregnancy Information</h3>
                        <p>Obstetric history and current pregnancy</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Gravida <span class="required">*</span></label>
                        <input type="number" name="gravida" min="1" placeholder="Total pregnancies" required>
                    </div>
                    <div class="form-group">
                        <label>Parity <span class="required">*</span></label>
                        <input type="number" name="parity" min="0" placeholder="Deliveries >7 months" required>
                    </div>
                    <div class="form-group half-width">
                        <label>Last Menstrual Period (LMP) <span class="required">*</span></label>
                        <input type="date" name="lmp" id="lmp" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <div class="form-hint"><i class="fas fa-calendar"></i> Estimated due date is calculated as LMP + 280 days. Confirm dates clinically.</div>
                    </div>
                    <div class="form-group half-width">
                        <label>First ANC Visit Date <span class="required">*</span></label>
                        <input type="date" name="first_anc_date" id="first_anc_date" value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <div class="form-hint">Enter the actual visit date, including when recording a historical visit.</div>
                    </div>
                    <div class="form-group half-width" id="pregnancy_date_prediction" aria-live="polite"></div>
                </div>
            </div>
            
            <!-- SECTION 4: FIRST ANC ASSESSMENT -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-stethoscope"></i></div>
                    <div>
                        <h3>First ANC Assessment</h3>
                        <p>Clinical measurements and initial diagnosis</p>
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
                    <div class="form-group half-width">
                        <label>Initial Diagnosis</label>
                        <input type="text" name="diagnosis" placeholder="e.g., Normal Pregnancy (NP)">
                    </div>
                    <div class="form-group half-width">
                        <label>Complications / Risk Factors</label>
                        <textarea name="complications" rows="2" placeholder="List any complications or 'Nil'"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- SECTION 5: HIV / eMTCT -->
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
                </div>
            </div>
            
            <!-- SECTION 6: PROPHYLAXIS -->
            <div class="form-section">
                <div class="section-title">
                    <div class="section-icon"><i class="fas fa-tablets"></i></div>
                    <div>
                        <h3>Prophylaxis & Treatments</h3>
                        <p>Preventive medications and interventions</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>TT Dose Received</label>
                        <select name="tt_dose">
                            <option value="">None</option>
                            <option value="1">Dose 1</option>
                            <option value="2">Dose 2</option>
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
                        <input type="number" name="iron_folate_tablets" min="0" max="1000" placeholder="Number of tablets">
                    </div>
                    <div class="form-group">
                        <label>Combined Fefan Tablets</label>
                        <input type="number" name="combined_fefan_tablets" min="0" max="1000" placeholder="Number of tablets">
                    </div>
                    <div class="form-group">
                        <label>Misoprostol Tablets</label>
                        <input type="number" name="misoprostol_tablets" min="0" max="100" placeholder="From 34 weeks">
                    </div>
                    <div class="form-group">
                        <label>Next Visit Date</label>
                        <input type="date" name="next_visit_date">
                    </div>
                </div>
            </div>
            
            <!-- BUTTONS -->
            <div class="form-actions">
                <button type="reset" class="btn-reset"><i class="fas fa-undo"></i> Reset</button>
                <button type="submit" class="btn-submit" <?php echo $selected_patient_has_active_episode ? 'disabled' : ''; ?>><i class="fas fa-save"></i> <?php echo $selected_patient ? 'Register New Pregnancy' : 'Register Patient'; ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    const lmpInput = document.getElementById('lmp');
    const firstAncInput = document.getElementById('first_anc_date');
    const prediction = document.getElementById('pregnancy_date_prediction');

    function updatePregnancyDatePrediction() {
        prediction.replaceChildren();
        if (!lmpInput.value || !firstAncInput.value || lmpInput.value > firstAncInput.value) return;

        const lmp = new Date(`${lmpInput.value}T00:00:00Z`);
        const visit = new Date(`${firstAncInput.value}T00:00:00Z`);
        const days = Math.floor((visit - lmp) / 86400000);
        const due = new Date(lmp);
        due.setUTCDate(due.getUTCDate() + 280);
        const result = document.createElement('div');
        result.className = 'form-hint';
        result.textContent = `Estimated due date: ${due.toISOString().slice(0, 10)} · Gestational age at first visit: ${Math.floor(days / 7)} weeks ${days % 7} days`;
        prediction.append(result);
    }

    lmpInput.addEventListener('change', updatePregnancyDatePrediction);
    firstAncInput.addEventListener('change', updatePregnancyDatePrediction);
    updatePregnancyDatePrediction();
</script>

<?php require_once 'footer.php'; ?>