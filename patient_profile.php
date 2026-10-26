<?php
// ============================================
// FILE: patient_profile.php
// PURPOSE: Complete patient journey timeline with ALL records
// Includes: Demographics, All Pregnancy Episodes, ANC Visits, Delivery, Postnatal
// Color-coded for easy visibility - COMPACT STATS ON ONE LINE
// ============================================

require_once 'config.php';
require_once 'nav.php';
requireLogin();

$patient_id = $_GET['id'] ?? 0;
if (!$patient_id) {
    header('Location: patients.php');
    exit;
}

// Get patient details
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
$stmt->execute([':id' => $patient_id]);
$patient = $stmt->fetch();
if (!$patient) {
    header('Location: patients.php');
    exit;
}

// Get all pregnancy episodes
$stmt = $pdo->prepare("SELECT * FROM pregnancy_episodes WHERE patient_id = :id ORDER BY created_at DESC");
$stmt->execute([':id' => $patient_id]);
$episodes = $stmt->fetchAll();

// Get active episode
$active_episode = null;
$delivered_episodes = [];
foreach ($episodes as $ep) {
    if ($ep['status'] == 'active') {
        $active_episode = $ep;
    } else {
        $delivered_episodes[] = $ep;
    }
}

// Get data for the most recent episode (or active if exists)
$current_episode = $active_episode ?? ($episodes[0] ?? null);
$anc_visits = [];
$delivery = null;
$postnatal = [];

if ($current_episode) {
    $stmt = $pdo->prepare("SELECT * FROM anc_visits WHERE pregnancy_episode_id = :id ORDER BY visit_number ASC");
    $stmt->execute([':id' => $current_episode['id']]);
    $anc_visits = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT * FROM delivery_records WHERE pregnancy_episode_id = :id");
    $stmt->execute([':id' => $current_episode['id']]);
    $delivery = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM postnatal_visits WHERE pregnancy_episode_id = :id ORDER BY visit_date ASC");
    $stmt->execute([':id' => $current_episode['id']]);
    $postnatal = $stmt->fetchAll();
}

// Calculate summary statistics
$total_anc_visits = count($anc_visits);
$total_anc_visits_completed = $anc_visits ? max(array_column($anc_visits, 'visit_number')) : 0;
$last_anc_date = $anc_visits ? max(array_column($anc_visits, 'visit_date')) : null;
$tt_completed = $anc_visits ? max(array_filter(array_column($anc_visits, 'tt_dose_received'))) : 0;
$iptp_doses = array_unique(array_filter(array_column($anc_visits, 'iptp_dose')));
$total_iptp = count($iptp_doses);
?>

<style>
    /* Profile Header */
    .profile-header {
        background: linear-gradient(135deg, #0a2b44 0%, #0d3b5e 100%);
        border-radius: 20px;
        padding: 20px 24px;
        margin-bottom: 20px;
        color: white;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .profile-info h1 {
        font-size: 22px;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .profile-info h1 i {
        color: #42a5f5;
        font-size: 24px;
    }
    
    .profile-details {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 10px;
    }
    
    .profile-detail-item {
        font-size: 12px;
        background: rgba(255,255,255,0.12);
        padding: 4px 12px;
        border-radius: 20px;
    }
    
    .profile-detail-item strong {
        color: #90caf9;
    }
    
    .quick-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: flex-start;
    }
    
    .quick-btn {
        background: rgba(255,255,255,0.15);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .quick-btn:hover {
        background: rgba(255,255,255,0.25);
        transform: translateY(-1px);
    }
    
    .quick-btn-primary { background: #1e88e5; }
    .quick-btn-primary:hover { background: #1565c0; }
    .quick-btn-success { background: #43a047; }
    .quick-btn-warning { background: #fb8c00; }
    
    /* COMPACT STATS TILES - ALL ON ONE LINE */
    .stats-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
        justify-content: space-between;
    }
    
    .stat-tile {
        flex: 1;
        min-width: 90px;
        background: var(--card-bg);
        border-radius: 14px;
        border: 1px solid var(--border);
        padding: 10px 8px;
        text-align: center;
        transition: all 0.2s;
    }
    
    .stat-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .stat-tile-icon {
        font-size: 18px;
        margin-bottom: 4px;
    }
    
    .stat-tile-value {
        font-size: 18px;
        font-weight: 700;
        line-height: 1.2;
    }
    
    .stat-tile-label {
        font-size: 9px;
        color: var(--text-secondary);
        margin-top: 3px;
        white-space: nowrap;
    }
    
    /* Timeline Cards */
    .timeline-card {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        margin-bottom: 20px;
        overflow: hidden;
    }
    
    .timeline-header {
        padding: 14px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .timeline-header h3 {
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .timeline-header h3 i {
        color: #1e88e5;
    }
    
    .status-badge {
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .status-active {
        background: #e8f5e9;
        color: #2e7d64;
    }
    
    .status-delivered {
        background: #e3f2fd;
        color: #1565c0;
    }
    
    .status-pending {
        background: #fff3e0;
        color: #e65100;
    }
    
    .timeline-content {
        padding: 16px 20px;
    }
    
    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 12px;
    }
    
    .info-item {
        background: var(--bg-body);
        border-radius: 10px;
        padding: 10px 14px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .info-label {
        font-weight: 600;
        font-size: 11px;
        color: var(--text-secondary);
    }
    
    .info-value {
        font-size: 13px;
        font-weight: 500;
    }
    
    /* Color-coded values */
    .value-positive { color: #2e7d64; font-weight: 700; }
    .value-warning { color: #e65100; font-weight: 700; }
    .value-danger { color: #c62828; font-weight: 700; }
    .value-info { color: #1565c0; font-weight: 700; }
    
    /* Tables */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    
    .data-table th, .data-table td {
        padding: 10px 8px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }
    
    .data-table th {
        background: var(--bg-body);
        font-weight: 600;
        color: var(--text-secondary);
    }
    
    .data-table tr:hover {
        background: var(--bg-body);
    }
    
    /* MUAC Colors */
    .muac-R { background: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
    .muac-Y { background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
    .muac-G { background: #e8f5e9; color: #2e7d64; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
    
    /* HIV Status Colors */
    .hiv-positive { background: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
    .hiv-negative { background: #e8f5e9; color: #2e7d64; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 11px; display: inline-block; }
    
    /* BP Colors */
    .bp-normal { color: #2e7d64; font-weight: 600; }
    .bp-high { color: #c62828; font-weight: 600; }
    
    .section-title {
        font-size: 14px;
        font-weight: 700;
        margin: 16px 0 12px 0;
        padding-left: 10px;
        border-left: 3px solid #1e88e5;
        color: var(--text-primary);
    }
    
    @media (max-width: 900px) {
        .stats-row { flex-wrap: wrap; }
        .stat-tile { min-width: 100px; }
        .info-grid { grid-template-columns: 1fr; }
        .data-table { display: block; overflow-x: auto; }
        .profile-header { flex-direction: column; }
    }
</style>

<!-- Profile Header -->
<div class="profile-header">
    <div class="profile-info">
        <h1>
            <i class="fas fa-user-circle"></i> 
            <?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?>
            <?php if ($active_episode): ?>
                <span class="status-badge status-active" style="background: rgba(255,255,255,0.2); color: white;">
                    <i class="fas fa-circle"></i> Active
                </span>
            <?php endif; ?>
        </h1>
        <div class="profile-details">
            <div class="profile-detail-item"><strong>NIN:</strong> <?php echo htmlspecialchars($patient['nin'] ?? '—'); ?></div>
            <div class="profile-detail-item"><strong>Age:</strong> <?php echo $patient['age']; ?> yrs</div>
            <div class="profile-detail-item"><strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone'] ?? '—'); ?></div>
            <div class="profile-detail-item"><strong>Category:</strong> <?php echo $patient['client_category']; ?></div>
            <div class="profile-detail-item"><strong>Location:</strong> <?php echo htmlspecialchars($patient['village'] ?? '—'); ?></div>
        </div>
    </div>
    <div class="quick-actions">
        <?php if ($active_episode): ?>
            <a href="anc_visit.php?episode_id=<?php echo $active_episode['id']; ?>" class="quick-btn quick-btn-primary"><i class="fas fa-notes-medical"></i> ANC</a>
            <a href="delivery_admission.php?episode_id=<?php echo $active_episode['id']; ?>" class="quick-btn quick-btn-success"><i class="fas fa-birthday-cake"></i> Delivery</a>
        <?php else: ?>
            <a href="register_patient.php" class="quick-btn quick-btn-primary"><i class="fas fa-plus"></i> New Pregnancy</a>
        <?php endif; ?>
        <a href="postnatal_visit.php" class="quick-btn quick-btn-warning"><i class="fas fa-hand-holding-heart"></i> PNC</a>
        <a href="patients.php" class="quick-btn"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- COMPACT STATS TILES - ALL ON ONE LINE -->
<div class="stats-row">
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-calendar-check" style="color: #1e88e5;"></i></div>
        <div class="stat-tile-value"><?php echo $total_anc_visits; ?></div>
        <div class="stat-tile-label">ANC Visits</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-chart-line" style="color: #43a047;"></i></div>
        <div class="stat-tile-value"><?php echo $total_anc_visits_completed; ?></div>
        <div class="stat-tile-label">Completed</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-syringe" style="color: #fb8c00;"></i></div>
        <div class="stat-tile-value"><?php echo $tt_completed ?: '0'; ?></div>
        <div class="stat-tile-label">TT Doses</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-tablets" style="color: #8e24aa;"></i></div>
        <div class="stat-tile-value"><?php echo $total_iptp; ?></div>
        <div class="stat-tile-label">IPTp Doses</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-baby" style="color: #00897b;"></i></div>
        <div class="stat-tile-value"><?php echo $delivery ? '1' : '0'; ?></div>
        <div class="stat-tile-label">Deliveries</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-hand-holding-heart" style="color: #26c6da;"></i></div>
        <div class="stat-tile-value"><?php echo count($postnatal); ?></div>
        <div class="stat-tile-label">PNC Visits</div>
    </div>
    <?php if ($active_episode && $current_episode): ?>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-calendar-alt" style="color: #e91e63;"></i></div>
        <div class="stat-tile-value"><?php 
            $ga = calculateGestationalAge($current_episode['lmp'], date('Y-m-d'));
            echo $ga ?: '—';
        ?></div>
        <div class="stat-tile-label">Current GA (wks)</div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon"><i class="fas fa-calendar-plus" style="color: #ff5722;"></i></div>
        <div class="stat-tile-value"><?php 
            $days_to_edd = $current_episode['edd'] ? ceil((strtotime($current_episode['edd']) - strtotime(date('Y-m-d'))) / 86400) : 0;
            echo $days_to_edd > 0 ? $days_to_edd : ($days_to_edd == 0 ? 'Today' : 'Overdue');
        ?></div>
        <div class="stat-tile-label">Days to EDD</div>
    </div>
    <?php endif; ?>
</div>

<!-- Current Pregnancy Details -->
<?php if ($current_episode): ?>
<div class="timeline-card">
    <div class="timeline-header">
        <h3><i class="fas fa-fetus"></i> Pregnancy Details</h3>
        <span class="status-badge <?php echo $current_episode['status'] == 'active' ? 'status-active' : 'status-delivered'; ?>">
            <i class="fas fa-circle"></i> <?php echo ucfirst($current_episode['status']); ?>
        </span>
    </div>
    <div class="timeline-content">
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Client Number:</span><span class="info-value value-info"><?php echo $current_episode['client_number']; ?></span></div>
            <div class="info-item"><span class="info-label">Gravida / Parity:</span><span class="info-value"><?php echo $current_episode['gravida']; ?> / <?php echo $current_episode['parity']; ?></span></div>
            <div class="info-item"><span class="info-label">LMP:</span><span class="info-value"><?php echo formatDate($current_episode['lmp']); ?></span></div>
            <div class="info-item"><span class="info-label">EDD:</span><span class="info-value value-info"><?php echo formatDate($current_episode['edd']); ?></span></div>
            <div class="info-item"><span class="info-label">Booking GA:</span><span class="info-value <?php echo ($current_episode['booking_ga_weeks'] ?? 0) <= 12 ? 'value-positive' : 'value-warning'; ?>"><?php echo $current_episode['booking_ga_weeks'] ?? '—'; ?> weeks</span></div>
            <div class="info-item"><span class="info-label">Booking Timing:</span><span class="info-value <?php echo $current_episode['booking_timing'] == 'early' ? 'value-positive' : 'value-warning'; ?>"><?php echo $current_episode['booking_timing'] == 'early' ? '✅ Early (≤12 wks)' : '⚠️ Late (>12 wks)'; ?></span></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ANC Visits Details -->
<div class="timeline-card">
    <div class="timeline-header">
        <h3><i class="fas fa-notes-medical"></i> ANC Visit History</h3>
        <span class="status-badge status-active"><?php echo count($anc_visits); ?> visit(s)</span>
    </div>
    <div class="timeline-content">
        <?php if (count($anc_visits) > 0): ?>
            <table class="data-table">
                <thead>
                    <tr><th>#</th><th>Date</th><th>GA</th><th>Wt(kg)</th><th>BP</th><th>MUAC</th><th>TT</th><th>IPTp</th><th>HIV</th><th>Diagnosis</th><th>Complications</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($anc_visits as $visit): ?>
                    <tr>
                        <td><strong><?php echo $visit['visit_number']; ?></strong></td>
                        <td><?php echo formatDate($visit['visit_date']); ?></td>
                        <td><?php echo $visit['gestational_age_weeks']; ?></td>
                        <td><?php echo $visit['weight_kg']; ?></td>
                        <td class="<?php echo (($visit['bp_systolic'] ?? 0) > 140 || ($visit['bp_diastolic'] ?? 0) > 90) ? 'bp-high' : 'bp-normal'; ?>"><?php echo $visit['bp_systolic'] . '/' . $visit['bp_diastolic']; ?></td>
                        <td><span class="muac-<?php echo $visit['muac_color']; ?>"><?php echo $visit['muac_cm'] . ' (' . $visit['muac_color'] . ')'; ?></span></td>
                        <td><?php echo $visit['tt_dose_received'] ?? '-'; ?></td>
                        <td><?php echo $visit['iptp_dose'] ?? '-'; ?></td>
                        <td><?php 
                            $hiv_codes = ['TRR', 'TRRK', 'TRR+'];
                            if (in_array($visit['woman_hiv_code'], $hiv_codes)) {
                                echo '<span class="hiv-positive">' . ($visit['woman_hiv_code'] ?? '-') . '</span>';
                            } elseif ($visit['woman_hiv_code']) {
                                echo '<span class="hiv-negative">' . $visit['woman_hiv_code'] . '</span>';
                            } else { echo '-'; }
                        ?></td>
                        <td><?php echo htmlspecialchars(substr($visit['diagnosis'] ?? '', 0, 25)); ?></td>
                        <td class="<?php echo !empty($visit['complications']) && $visit['complications'] != 'Nil' ? 'value-danger' : ''; ?>"><?php echo htmlspecialchars(substr($visit['complications'] ?? '', 0, 25) ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 20px;"><i class="fas fa-notes-medical" style="font-size: 32px; opacity: 0.3;"></i><br>No ANC visits recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Record -->
<?php if ($delivery): ?>
<div class="timeline-card">
    <div class="timeline-header">
        <h3><i class="fas fa-birthday-cake"></i> Delivery & Maternity Record</h3>
        <span class="status-badge status-delivered"><i class="fas fa-check-circle"></i> Delivered</span>
    </div>
    <div class="timeline-content">
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Admission:</span><span class="info-value"><?php echo formatDateTime($delivery['admission_datetime']); ?></span></div>
            <div class="info-item"><span class="info-label">IPD Number:</span><span class="info-value value-info"><?php echo $delivery['ipd_no']; ?></span></div>
            <div class="info-item"><span class="info-label">Delivery Date:</span><span class="info-value"><?php echo formatDateTime($delivery['delivery_datetime']); ?></span></div>
            <div class="info-item"><span class="info-label">Mode:</span><span class="info-value"><?php echo str_replace('_', ' ', $delivery['mode_of_delivery']); ?></span></div>
            <div class="info-item"><span class="info-label">Gestation:</span><span class="info-value"><?php echo $delivery['gestation_weeks']; ?> wks (<?php echo $delivery['term_preterm'] == 'T' ? 'Term' : 'Pre-term'; ?>)</span></div>
            <div class="info-item"><span class="info-label">Baby:</span><span class="info-value"><?php echo $delivery['baby_sex'] == 'M' ? '👶 Male' : '👶 Female'; ?>, <?php echo $delivery['baby_weight_kg']; ?> kg</span></div>
            <div class="info-item"><span class="info-label">Apgar:</span><span class="info-value"><?php echo $delivery['apgar_1min'] . '/' . $delivery['apgar_5min']; ?></span></div>
            <div class="info-item"><span class="info-label">Skin to Skin:</span><span class="info-value"><?php echo $delivery['skin_to_skin'] ? '✅ Yes' : '❌ No'; ?></span></div>
            <div class="info-item"><span class="info-label">Breastfed 1hr:</span><span class="info-value"><?php echo $delivery['breastfed_1hr'] ? '✅ Yes' : '❌ No'; ?></span></div>
            <div class="info-item"><span class="info-label">Baby Condition:</span><span class="info-value <?php echo in_array($delivery['baby_condition_at_discharge'], ['MSB','FSB','NND']) ? 'value-danger' : 'value-positive'; ?>"><?php echo $delivery['baby_condition_at_discharge']; ?></span></div>
            <div class="info-item"><span class="info-label">Postpartum FP:</span><span class="info-value"><?php echo $delivery['postpartum_fp_code'] ?? '—'; ?></span></div>
        </div>
        <?php if ($delivery['management_complication']): ?>
            <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Complications</div>
            <div class="info-item" style="background: #ffebee;"><span class="info-label">Management:</span><span class="info-value value-danger"><?php echo htmlspecialchars($delivery['management_complication']); ?></span></div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
    <?php if ($active_episode): ?>
    <div class="timeline-card">
        <div class="timeline-header"><h3><i class="fas fa-birthday-cake"></i> Delivery Record</h3><span class="status-badge status-pending">Pending</span></div>
        <div class="timeline-content"><p style="color: var(--text-secondary); text-align: center; padding: 15px;"><i class="fas fa-hourglass-half" style="font-size: 32px; opacity: 0.3;"></i><br>Delivery not yet recorded.</p></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Postnatal Visits -->
<div class="timeline-card">
    <div class="timeline-header">
        <h3><i class="fas fa-hand-holding-heart"></i> Postnatal Care Visits</h3>
        <span><?php echo count($postnatal); ?> visit(s)</span>
    </div>
    <div class="timeline-content">
        <?php if (count($postnatal) > 0): ?>
            <table class="data-table">
                <thead><tr><th>Timing</th><th>Date</th><th>Mother Wt</th><th>Breast</th><th>Cervix</th><th>FP</th><th>Baby Wt</th><th>Feeding</th><th>DBS</th><th>Immunization</th></tr></thead>
                <tbody>
                    <?php foreach ($postnatal as $pn): ?>
                    <tr>
                        <td><strong><?php echo str_replace('_', ' ', $pn['visit_timing']); ?></strong></td>
                        <td><?php echo formatDate($pn['visit_date']); ?></td>
                        <td><?php echo $pn['mother_weight_kg']; ?> kg</td>
                        <td><?php echo ['FOM'=>'✅ Normal','SS'=>'⚠️ Abnormal','ND'=>'—'][$pn['breast_status']] ?? '-'; ?></td>
                        <td><?php echo ['FOM'=>'✅ Normal','SS'=>'⚠️ Suspicious','ND'=>'—'][$pn['cervix_status']] ?? '-'; ?></td>
                        <td><?php echo $pn['fp_method_code'] ?? '-'; ?></td>
                        <td class="<?php echo ($pn['baby_weight_kg'] ?? 0) < 2.5 ? 'value-warning' : 'value-positive'; ?>"><?php echo $pn['baby_weight_kg']; ?> kg</td>
                        <td><?php echo str_replace('_', ' ', $pn['infant_feeding_option'] ?? '-'); ?></td>
                        <td class="<?php echo $pn['dbs_test_result'] == 'positive' ? 'value-danger' : ($pn['dbs_test_result'] == 'negative' ? 'value-positive' : 'value-warning'); ?>"><?php echo ucfirst($pn['dbs_test_result'] ?? 'pending'); ?></td>
                        <td><?php echo htmlspecialchars(substr($pn['immunization_status'] ?? '', 0, 25)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: var(--text-secondary); text-align: center; padding: 20px;"><i class="fas fa-hand-holding-heart" style="font-size: 32px; opacity: 0.3;"></i><br>No postnatal visits recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Previous Pregnancies -->
<?php if (count($delivered_episodes) > 1 || ($delivered_episodes && !$active_episode)): ?>
<div class="timeline-card">
    <div class="timeline-header"><h3><i class="fas fa-history"></i> Previous Pregnancy History</h3><span><?php echo count($delivered_episodes); ?> previous</span></div>
    <div class="timeline-content">
        <table class="data-table">
            <thead><tr><th>Client Number</th><th>G/P</th><th>LMP</th><th>EDD</th><th>Booking GA</th><th>Status</th><th>Closed Date</th></tr></thead>
            <tbody>
                <?php foreach ($delivered_episodes as $ep): ?>
                <tr>
                    <td><strong><?php echo $ep['client_number']; ?></strong></td>
                    <td><?php echo $ep['gravida']; ?>/<?php echo $ep['parity']; ?></td>
                    <td><?php echo formatDate($ep['lmp']); ?></td>
                    <td><?php echo formatDate($ep['edd']); ?></td>
                    <td><?php echo $ep['booking_ga_weeks']; ?> wks</td>
                    <td><span class="status-badge status-delivered"><?php echo ucfirst($ep['status']); ?></span></td>
                    <td><?php echo formatDate($ep['closed_date']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>