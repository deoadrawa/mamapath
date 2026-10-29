<?php
// ============================================
// FILE: reports.php
// PURPOSE: Complete Reports Hub - Following System Design Pattern
// ============================================

require_once 'config.php';
requireLogin();

// ============================================
// HANDLE EXPORT - MUST BE BEFORE ANY OUTPUT
// ============================================
$export_report = $_GET['export_report'] ?? '';
$export_tab = $_GET['export_tab'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$exportTabs = ['executive_summary', 'anc_register', 'delivery_register', 'pnc_register', 'patient_list'];
$excelCell = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$validExportDate = static function (string $date): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
};

if ($export_report === 'excel') {
    if (
        !in_array($export_tab, $exportTabs, true)
        || !$validExportDate($date_from)
        || !$validExportDate($date_to)
        || $date_from > $date_to
    ) {
        http_response_code(400);
        exit('Invalid report request.');
    }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="report_' . $export_tab . '_' . date('Y-m-d') . '.xls"');
    
    echo "<html><body>";
    echo "<h2>Lubaga Hospital MCH System - " . $excelCell(ucwords(str_replace('_', ' ', $export_tab))) . "</h2>";
    echo "<p>Period: " . $excelCell($date_from) . " to " . $excelCell($date_to) . "</p>";
    echo "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";
    echo "<table border='1'>";
    
    switch ($export_tab) {
        case 'executive_summary':
            $total_patients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
            $active = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE status = 'active'")->fetchColumn();
            $anc_total = $pdo->query("SELECT COUNT(*) FROM anc_visits")->fetchColumn();
            $deliveries = $pdo->query("SELECT COUNT(*) FROM delivery_records")->fetchColumn();
            $pnc_total = $pdo->query("SELECT COUNT(*) FROM postnatal_visits")->fetchColumn();
            $hiv = $pdo->query("SELECT COUNT(DISTINCT pe.id) FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id WHERE av.woman_hiv_code IN ('TRR','TRRK','TRR+')")->fetchColumn();
            $screenings = $pdo->prepare("SELECT COUNT(*) FROM screening_results WHERE performed_at BETWEEN ? AND ?");
            $screenings->execute([$date_from, $date_to]);
            $referrals = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referral_date BETWEEN ? AND ?");
            $referrals->execute([$date_from, $date_to]);
            $referralsCompleted = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE status = 'completed' AND completed_date BETWEEN ? AND ?");
            $referralsCompleted->execute([$date_from, $date_to]);
            $childVisits = $pdo->prepare("SELECT COUNT(*) FROM child_health_visits WHERE visit_date BETWEEN ? AND ?");
            $childVisits->execute([$date_from, $date_to]);
            echo "<tr><th>Metric</th><th>Value</th></tr>";
            echo "<tr><td>Total Patients</td><td>" . $excelCell($total_patients) . "</td></tr>";
            echo "<tr><td>Active Pregnancies</td><td>" . $excelCell($active) . "</td></tr>";
            echo "<tr><td>Total ANC Visits</td><td>" . $excelCell($anc_total) . "</td></tr>";
            echo "<tr><td>Total Deliveries</td><td>" . $excelCell($deliveries) . "</td></tr>";
            echo "<tr><td>Total PNC Visits</td><td>" . $excelCell($pnc_total) . "</td></tr>";
            echo "<tr><td>HIV Positive Mothers</td><td>" . $excelCell($hiv) . "</td></tr>";
            echo "<tr><td>Screenings Recorded (Selected Period)</td><td>" . $excelCell($screenings->fetchColumn()) . "</td></tr>";
            echo "<tr><td>Referrals Initiated (Selected Period)</td><td>" . $excelCell($referrals->fetchColumn()) . "</td></tr>";
            echo "<tr><td>Referrals Completed (Selected Period)</td><td>" . $excelCell($referralsCompleted->fetchColumn()) . "</td></tr>";
            echo "<tr><td>Child Health Encounters (Selected Period)</td><td>" . $excelCell($childVisits->fetchColumn()) . "</td></tr>";
            echo "</table>";
            break;
        case 'anc_register':
            $anc = $pdo->prepare("SELECT pe.client_number, CONCAT(p.surname,' ',p.first_name) as name, p.age, p.phone, p.village, av.visit_date, av.gestational_age_weeks, av.weight_kg, CONCAT(av.bp_systolic,'/',av.bp_diastolic) as bp, av.woman_hiv_code, av.tt_dose_received, av.diagnosis FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id JOIN patients p ON pe.patient_id = p.id WHERE av.visit_date BETWEEN ? AND ? ORDER BY av.visit_date DESC");
            $anc->execute([$date_from, $date_to]);
            echo "<tr><th>Client #</th><th>Name</th><th>Age</th><th>Phone</th><th>Village</th><th>Date</th><th>GA</th><th>Weight</th><th>BP</th><th>HIV</th><th>TT</th><th>Diagnosis</th></tr>";
            foreach ($anc->fetchAll() as $r) {
                echo '<tr><td>' . $excelCell($r['client_number']) . '</td><td>' . $excelCell($r['name']) . '</td><td>' . $excelCell($r['age']) . '</td><td>' . $excelCell($r['phone']) . '</td><td>' . $excelCell($r['village']) . '</td><td>' . $excelCell($r['visit_date']) . '</td><td>' . $excelCell($r['gestational_age_weeks']) . '</td><td>' . $excelCell($r['weight_kg']) . '</td><td>' . $excelCell($r['bp']) . '</td><td>' . $excelCell($r['woman_hiv_code']) . '</td><td>' . $excelCell($r['tt_dose_received']) . '</td><td>' . $excelCell($r['diagnosis']) . '</td></tr>';
            }
            break;
        case 'delivery_register':
            $del = $pdo->prepare(
                "SELECT dr.ipd_no, dr.delivery_datetime, CONCAT(p.surname,' ',p.first_name) AS mother,
                        dr.mode_of_delivery, b.birth_order,
                        IF(b.id IS NULL, dr.baby_sex, b.baby_sex) AS baby_sex,
                        IF(b.id IS NULL, dr.baby_weight_kg, b.baby_weight_kg) AS baby_weight_kg,
                        IF(b.id IS NULL, dr.baby_condition_at_discharge, b.condition_at_discharge) AS outcome
                 FROM delivery_records dr
                 JOIN pregnancy_episodes pe ON dr.pregnancy_episode_id = pe.id
                 JOIN patients p ON pe.patient_id = p.id
                 LEFT JOIN babies b ON b.delivery_record_id = dr.id
                 WHERE dr.delivery_datetime BETWEEN ? AND ?
                 ORDER BY dr.delivery_datetime DESC, b.birth_order"
            );
            $del->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
            echo "<tr><th>IPD #</th><th>Date</th><th>Mother</th><th>Mode</th><th>Baby</th><th>Sex</th><th>Weight</th><th>Outcome</th></tr>";
            foreach ($del->fetchAll() as $r) {
                echo '<tr><td>' . $excelCell($r['ipd_no']) . '</td><td>' . $excelCell($r['delivery_datetime']) . '</td><td>' . $excelCell($r['mother']) . '</td><td>' . $excelCell($r['mode_of_delivery']) . '</td><td>' . $excelCell($r['birth_order'] ? 'Baby ' . $r['birth_order'] : 'Baby') . '</td><td>' . $excelCell($r['baby_sex']) . '</td><td>' . $excelCell($r['baby_weight_kg']) . '</td><td>' . $excelCell($r['outcome']) . '</td></tr>';
            }
            break;
        case 'pnc_register':
            $pnc = $pdo->prepare("SELECT pe.client_number, CONCAT(p.surname,' ',p.first_name) as mother, pv.visit_date, pv.visit_timing, pv.mother_weight_kg, pv.baby_weight_kg, pv.dbs_test_result, b.birth_order FROM postnatal_visits pv JOIN pregnancy_episodes pe ON pv.pregnancy_episode_id = pe.id JOIN patients p ON pe.patient_id = p.id LEFT JOIN babies b ON b.id = pv.baby_id WHERE pv.visit_date BETWEEN ? AND ? ORDER BY pv.visit_date DESC");
            $pnc->execute([$date_from, $date_to]);
            echo "<tr><th>Client #</th><th>Mother</th><th>Date</th><th>Timing</th><th>Baby</th><th>Mother Wt</th><th>Baby Wt</th><th>DBS</th></tr>";
            foreach ($pnc->fetchAll() as $r) {
                echo '<tr><td>' . $excelCell($r['client_number']) . '</td><td>' . $excelCell($r['mother']) . '</td><td>' . $excelCell($r['visit_date']) . '</td><td>' . $excelCell($r['visit_timing']) . '</td><td>' . $excelCell($r['birth_order'] ? 'Baby ' . $r['birth_order'] : '') . '</td><td>' . $excelCell($r['mother_weight_kg']) . '</td><td>' . $excelCell($r['baby_weight_kg']) . '</td><td>' . $excelCell($r['dbs_test_result']) . '</td></tr>';
            }
            break;
        case 'patient_list':
            $patients = $pdo->query("SELECT p.surname, p.first_name, p.nin, p.age, p.phone, p.village, pe.client_number FROM patients p LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active' ORDER BY p.surname")->fetchAll();
            echo "<tr><th>Client #</th><th>Name</th><th>NIN</th><th>Age</th><th>Phone</th><th>Village</th></tr>";
            foreach ($patients as $p) {
                echo '<tr><td>' . $excelCell($p['client_number']) . '</td><td>' . $excelCell($p['surname'] . ' ' . $p['first_name']) . '</td><td>' . $excelCell($p['nin']) . '</td><td>' . $excelCell($p['age']) . '</td><td>' . $excelCell($p['phone']) . '</td><td>' . $excelCell($p['village']) . '</td></tr>';
            }
            break;
    }
    echo "</table></body></html>";
    exit;
}

// Regular page variables
$active_tab = $_GET['tab'] ?? 'executive_summary';
$patient_id = $_GET['patient_id'] ?? 0;

// Helper functions
function formatDateDMY($date) {
    if (empty($date) || $date == '0000-00-00') return '-';
    return date('d M Y', strtotime($date));
}

function formatDateTimeDMY($datetime) {
    if (empty($datetime)) return '-';
    return date('d M Y H:i', strtotime($datetime));
}

// Get summary stats
$total_patients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
$active_pregnancies = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE status = 'active'")->fetchColumn();
$total_anc = $pdo->query("SELECT COUNT(*) FROM anc_visits")->fetchColumn();
$total_deliveries = $pdo->query("SELECT COUNT(*) FROM delivery_records")->fetchColumn();
$total_pnc = $pdo->query("SELECT COUNT(*) FROM postnatal_visits")->fetchColumn();
$hiv_positive = $pdo->query("SELECT COUNT(DISTINCT pe.id) FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id WHERE av.woman_hiv_code IN ('TRR','TRRK','TRR+')")->fetchColumn();
$continuityStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM screening_results WHERE performed_at BETWEEN :screen_start AND :screen_end) AS screenings,
        (SELECT COUNT(*) FROM referrals WHERE referral_date BETWEEN :ref_start AND :ref_end) AS referrals,
        (SELECT COUNT(*) FROM referrals WHERE status = 'completed' AND completed_date BETWEEN :completed_start AND :completed_end) AS referrals_completed,
        (SELECT COUNT(*) FROM child_health_visits WHERE visit_date BETWEEN :child_start AND :child_end) AS child_visits,
        (SELECT COUNT(*) FROM referrals WHERE followup_date < CURDATE() AND status IN ('initiated', 'accepted', 'not_completed')) AS overdue_referrals"
);
$continuityStmt->execute([
    ':screen_start' => $date_from, ':screen_end' => $date_to,
    ':ref_start' => $date_from, ':ref_end' => $date_to,
    ':completed_start' => $date_from, ':completed_end' => $date_to,
    ':child_start' => $date_from, ':child_end' => $date_to,
]);
$continuityMetrics = $continuityStmt->fetch() ?: [
    'screenings' => 0, 'referrals' => 0, 'referrals_completed' => 0,
    'child_visits' => 0, 'overdue_referrals' => 0,
];

$patients_list = $pdo->query("SELECT p.id, p.surname, p.first_name, pe.client_number FROM patients p LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active' ORDER BY p.surname LIMIT 200")->fetchAll();
?>
<?php require_once 'nav.php'; ?>

<style>
    /* Reports Page Styles - Following System Design */
    .reports-container {
        max-width: 100%;
        margin: 0;
        padding: 0;
    }
    
    /* Stats Row - Same as Dashboard */
    .stats-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 20px;
        justify-content: space-between;
    }
    
    .stat-tile {
        flex: 1;
        min-width: 85px;
        background: var(--card-bg);
        border-radius: 12px;
        border: 1px solid var(--border);
        padding: 8px 6px;
        text-align: center;
        transition: all 0.2s;
    }
    
    .stat-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }
    
    .stat-tile-icon {
        font-size: 16px;
        margin-bottom: 4px;
    }
    
    .stat-tile-value {
        font-size: 18px;
        font-weight: 700;
        line-height: 1.2;
    }
    
    .stat-tile-label {
        font-size: 8px;
        color: var(--text-secondary);
        margin-top: 3px;
        white-space: nowrap;
    }
    
    /* Tabs - 3 Rows Styled Like Nav */
    .tabs-wrapper {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        margin-bottom: 20px;
        overflow: hidden;
    }
    
    .tabs-row {
        display: flex;
        flex-wrap: wrap;
        border-bottom: 1px solid var(--border);
        padding: 4px 8px;
    }
    
    .tabs-row:last-child {
        border-bottom: none;
    }
    
    .tab-btn {
        padding: 8px 16px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        color: var(--text-secondary);
        transition: all 0.2s;
        background: transparent;
        border: none;
        border-radius: 10px;
        margin: 2px;
    }
    
    .tab-btn:hover {
        background: var(--bg-body);
        color: var(--text-primary);
    }
    
    .tab-btn.active {
        background: var(--primary);
        color: white;
    }
    
    /* Filter Bar - Same as Dashboard Quick Actions */
    .filter-bar-modern {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        padding: 15px 20px;
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: flex-end;
        justify-content: space-between;
    }
    
    .filter-group-modern {
        display: flex;
        flex-direction: column;
    }
    
    .filter-group-modern label {
        font-size: 10px;
        font-weight: 600;
        margin-bottom: 5px;
        color: var(--text-secondary);
        text-transform: uppercase;
    }
    
    .filter-group-modern input {
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
        background: var(--bg-body);
        color: var(--text-primary);
    }
    
    .btn-modern {
        padding: 8px 20px;
        border-radius: 30px;
        border: none;
        cursor: pointer;
        font-weight: 500;
        font-size: 12px;
        transition: all 0.2s;
    }
    
    .btn-primary-modern {
        background: var(--primary);
        color: white;
    }
    
    .btn-primary-modern:hover {
        background: var(--primary-dark);
    }
    
    .btn-secondary-modern {
        background: var(--teal);
        color: white;
    }
    
    .btn-secondary-modern:hover {
        background: var(--teal);
        opacity: 0.9;
    }
    
    .btn-print-modern {
        background: #475569;
        color: white;
    }
    
    /* Report Content */
    .report-content-modern {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        padding: 20px;
    }
    
    .report-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--border);
    }
    
    .report-title-modern {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-primary);
    }
    
    .report-subtitle-modern {
        font-size: 12px;
        color: var(--text-secondary);
        margin-top: 5px;
    }
    
    .action-buttons {
        display: flex;
        gap: 10px;
    }
    
    /* Tables */
    .table-wrapper {
        overflow-x: auto;
        margin-top: 15px;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    
    .data-table-modern {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        min-width: 600px;
    }
    
    .data-table-modern th,
    .data-table-modern td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid var(--border);
        border-right: 1px solid var(--border);
        vertical-align: top;
    }
    
    .data-table-modern th {
        background: var(--bg-body);
        font-weight: 600;
        color: var(--text-primary);
        position: sticky;
        top: 0;
        font-size: 11px;
    }
    
    .data-table-modern td:last-child,
    .data-table-modern th:last-child {
        border-right: none;
    }
    
    .data-table-modern tr:hover {
        background: var(--bg-body);
    }
    
    /* Sub-table for individual patient */
    .sub-table-modern {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
        background: var(--bg-body);
        border-radius: 8px;
    }
    
    .sub-table-modern th,
    .sub-table-modern td {
        padding: 5px 8px;
        border: 1px solid var(--border);
    }
    
    /* Badges */
    .badge-success {
        background: #e8f5e9;
        color: #2e7d64;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        display: inline-block;
    }
    
    .badge-danger {
        background: #ffebee;
        color: #c62828;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        display: inline-block;
    }
    
    .badge-warning {
        background: #fff3e0;
        color: #e65100;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        display: inline-block;
    }
    
    .badge-info {
        background: #e3f2fd;
        color: #1565c0;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        display: inline-block;
    }
    
    /* Patient Selector */
    .patient-selector-modern {
        background: var(--bg-body);
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
    }
    
    .patient-selector-modern select {
        padding: 8px 16px;
        min-width: 300px;
        margin: 0 10px;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: var(--card-bg);
        color: var(--text-primary);
    }
    
    /* Insights Box */
    .insights-box-modern {
        background: #e3f2fd;
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 20px;
        border-left: 4px solid var(--primary);
    }
    
    .insights-list-modern {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 8px;
    }
    
    .insight-tag-modern {
        background: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        color: var(--text-primary);
    }
    
    /* Stats Grid for Demographics */
    .stats-grid-modern {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    
    .stat-card-modern {
        background: var(--bg-body);
        border-radius: 12px;
        padding: 12px;
        text-align: center;
        border: 1px solid var(--border);
    }
    
    .stat-card-value-modern {
        font-size: 22px;
        font-weight: 700;
        color: var(--primary);
    }
    
    .stat-card-label-modern {
        font-size: 10px;
        color: var(--text-secondary);
        margin-top: 5px;
    }
    
    /* Print Styles */
    @media print {
        .stats-row,
        .tabs-wrapper,
        .filter-bar-modern,
        .action-buttons,
        .patient-selector-modern form button,
        .btn-modern {
            display: none;
        }
        
        .report-content-modern {
            padding: 0;
            margin: 0;
        }
        
        .data-table-modern th {
            background: #e2e8f0 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
    
    @media (max-width: 768px) {
        .stats-row {
            flex-wrap: wrap;
        }
        
        .stat-tile {
            min-width: 90px;
        }
        
        .filter-bar-modern {
            flex-direction: column;
            align-items: stretch;
        }
        
        .report-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .tabs-row {
            overflow-x: auto;
            flex-wrap: nowrap;
        }
    }
</style>

<div class="reports-container">
    <!-- Stats Row - Same as Dashboard -->
    <div class="stats-row">
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-users"></i></div>
            <div class="stat-tile-value"><?php echo number_format($total_patients); ?></div>
            <div class="stat-tile-label">Total Patients</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-fetus"></i></div>
            <div class="stat-tile-value"><?php echo $active_pregnancies; ?></div>
            <div class="stat-tile-label">Active Pregnancies</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-notes-medical"></i></div>
            <div class="stat-tile-value"><?php echo number_format($total_anc); ?></div>
            <div class="stat-tile-label">ANC Visits</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-birthday-cake"></i></div>
            <div class="stat-tile-value"><?php echo number_format($total_deliveries); ?></div>
            <div class="stat-tile-label">Deliveries</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-hand-holding-heart"></i></div>
            <div class="stat-tile-value"><?php echo number_format($total_pnc); ?></div>
            <div class="stat-tile-label">PNC Visits</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-icon"><i class="fas fa-heartbeat"></i></div>
            <div class="stat-tile-value"><?php echo $hiv_positive; ?></div>
            <div class="stat-tile-label">HIV+ Mothers</div>
        </div>
    </div>
    
    <!-- Tabs - 3 Rows -->
    <div class="tabs-wrapper">
        <div class="tabs-row">
            <button class="tab-btn <?php echo $active_tab == 'executive_summary' ? 'active' : ''; ?>" onclick="switchTab('executive_summary')">📊 Executive Summary</button>
            <button class="tab-btn <?php echo $active_tab == 'patient_list' ? 'active' : ''; ?>" onclick="switchTab('patient_list')">👥 Patient Registry</button>
            <button class="tab-btn <?php echo $active_tab == 'patient_demographics' ? 'active' : ''; ?>" onclick="switchTab('patient_demographics')">📈 Demographics</button>
            <button class="tab-btn <?php echo $active_tab == 'individual_patient' ? 'active' : ''; ?>" onclick="switchTab('individual_patient')">👤 Individual Patient</button>
        </div>
        <div class="tabs-row">
            <button class="tab-btn <?php echo $active_tab == 'anc_register' ? 'active' : ''; ?>" onclick="switchTab('anc_register')">📋 ANC Register</button>
            <button class="tab-btn <?php echo $active_tab == 'high_risk' ? 'active' : ''; ?>" onclick="switchTab('high_risk')">⚠️ High Risk</button>
            <button class="tab-btn <?php echo $active_tab == 'delivery_register' ? 'active' : ''; ?>" onclick="switchTab('delivery_register')">🤱 Delivery Register</button>
            <button class="tab-btn <?php echo $active_tab == 'perinatal_outcomes' ? 'active' : ''; ?>" onclick="switchTab('perinatal_outcomes')">👶 Perinatal Outcomes</button>
        </div>
        <div class="tabs-row">
            <button class="tab-btn <?php echo $active_tab == 'pnc_register' ? 'active' : ''; ?>" onclick="switchTab('pnc_register')">💚 PNC Register</button>
            <button class="tab-btn <?php echo $active_tab == 'fp_uptake' ? 'active' : ''; ?>" onclick="switchTab('fp_uptake')">👪 Family Planning</button>
            <button class="tab-btn <?php echo $active_tab == 'appointments' ? 'active' : ''; ?>" onclick="switchTab('appointments')">📅 Appointments</button>
            <button class="tab-btn <?php echo $active_tab == 'missed_visits' ? 'active' : ''; ?>" onclick="switchTab('missed_visits')">⚠️ Missed Appointments</button>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar-modern">
        <form method="GET" id="filterForm" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="tab" id="activeTab" value="<?php echo $active_tab; ?>">
            <div class="filter-group-modern">
                <label>📅 Date From</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            <div class="filter-group-modern">
                <label>📅 Date To</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            <button type="submit" class="btn-modern btn-primary-modern"><i class="fas fa-filter"></i> Apply Filter</button>
        </form>
        <div style="display: flex; gap: 10px;">
            <button class="btn-modern btn-print-modern" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="btn-modern btn-secondary-modern" onclick="exportReport()"><i class="fas fa-file-excel"></i> Export Excel</button>
        </div>
    </div>
    
    <!-- Report Content -->
    <div class="report-content-modern">
        <?php if ($active_tab == 'executive_summary'): ?>
            <?php
            $first_anc = $pdo->query("SELECT COUNT(DISTINCT pregnancy_episode_id) FROM anc_visits WHERE visit_number = 1")->fetchColumn();
            $cs = $pdo->query("SELECT COUNT(CASE WHEN mode_of_delivery = 'caesarean_section' THEN 1 END) FROM delivery_records")->fetchColumn();
            $cs_rate = $total_deliveries > 0 ? round($cs / $total_deliveries * 100, 1) : 0;
            $booking = $pdo->query("SELECT COUNT(CASE WHEN booking_timing = 'early' THEN 1 END) as early, COUNT(*) as total FROM pregnancy_episodes WHERE booking_timing IS NOT NULL")->fetch();
            $early_rate = ($booking['total'] ?? 0) > 0 ? round($booking['early'] / $booking['total'] * 100, 1) : 0;
            $muac = $pdo->query("SELECT muac_color, COUNT(*) as count FROM anc_visits WHERE muac_color IS NOT NULL GROUP BY muac_color")->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Executive Summary Report</div>
                    <div class="report-subtitle-modern">Key Performance Indicators - <?php echo date('d M Y H:i:s'); ?></div>
                </div>
                <div class="action-buttons">
                    <button class="btn-modern btn-print-modern" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
            
            <div class="insights-box-modern">
                <div><strong>📈 Key Insights</strong></div>
                <div class="insights-list-modern">
                    <span class="insight-tag-modern">📊 Total enrolled: <?php echo number_format($total_patients); ?> patients</span>
                    <span class="insight-tag-modern">👶 Active pregnancies: <?php echo $active_pregnancies; ?></span>
                    <span class="insight-tag-modern">📅 Early booking rate: <?php echo $early_rate; ?>%</span>
                    <span class="insight-tag-modern">🏥 C-section rate: <?php echo $cs_rate; ?>%</span>
                    <span class="insight-tag-modern">🩺 HIV+ mothers: <?php echo $hiv_positive; ?></span>
                </div>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td>Total Patients</td><td><strong><?php echo number_format($total_patients); ?></strong></td></tr>
                        <tr><td>Active Pregnancies</td><td><strong><?php echo $active_pregnancies; ?></strong></td></tr>
                        <tr><td>Total ANC Visits (All Time)</td><td><strong><?php echo number_format($total_anc); ?></strong></td></tr>
                        <tr><td>First ANC Visits (Unique)</td><td><strong><?php echo $first_anc; ?></strong></td></tr>
                        <tr><td>Total Deliveries</td><td><strong><?php echo number_format($total_deliveries); ?></strong></td></tr>
                        <tr><td>C-Section Deliveries</td><td><strong><?php echo $cs; ?> (<?php echo $cs_rate; ?>%)</strong></td></tr>
                        <tr><td>Total PNC Visits</td><td><strong><?php echo number_format($total_pnc); ?></strong></td></tr>
                        <tr><td>HIV Positive Mothers</td><td><strong><?php echo $hiv_positive; ?></strong></td></tr>
                        <tr><td>Early Booking Rate (&lt;12 weeks)</td><td><strong><?php echo $early_rate; ?>%</strong></td></tr>
                        <tr><td>Screening records (selected period)</td><td><strong><?php echo (int) $continuityMetrics['screenings']; ?></strong></td></tr>
                        <tr><td>Referrals recorded (selected period)</td><td><strong><?php echo (int) $continuityMetrics['referrals']; ?></strong></td></tr>
                        <tr><td>Referrals completed in period</td><td><strong><?php echo (int) $continuityMetrics['referrals_completed']; ?></strong></td></tr>
                        <tr><td>Child health encounters (selected period)</td><td><strong><?php echo (int) $continuityMetrics['child_visits']; ?></strong></td></tr>
                        <tr><td>Referrals past planned follow-up</td><td><strong><?php echo (int) $continuityMetrics['overdue_referrals']; ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted" style="margin-top:14px">Continuity metrics count records, not clinical outcomes. Use the <a href="screenings.php">screening register</a>, <a href="referrals.php">referral tracking</a>, <a href="child_followup.php">child follow-up</a>, and <a href="resource_planning.php">resource planning</a> pages to review the underlying data.</p>
            
            <?php if (!empty($muac)): ?>
            <div class="table-wrapper" style="margin-top: 20px;">
                <table class="data-table-modern">
                    <thead><tr><th>MUAC Status</th><th>Count</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <?php foreach ($muac as $m): ?>
                        <tr>
                            <td><?php echo $m['muac_color'] == 'R' ? '🔴 Red' : ($m['muac_color'] == 'Y' ? '🟡 Yellow' : '🟢 Green'); ?></td>
                            <td><?php echo $m['count']; ?></td>
                            <td><?php echo $m['muac_color'] == 'R' ? 'Severe Acute Malnutrition' : ($m['muac_color'] == 'Y' ? 'Moderate Acute Malnutrition' : 'Normal'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
        <?php elseif ($active_tab == 'patient_list'): ?>
            <?php
            $patients_data = $pdo->query("SELECT p.id, p.surname, p.first_name, p.nin, p.age, p.phone, p.village, p.district, p.sub_county, p.parish, p.client_category, pe.client_number, pe.status, DATE_FORMAT(p.created_at, '%d %b %Y') as reg_date FROM patients p LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active' ORDER BY p.created_at DESC")->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Patient Registry</div>
                    <div class="report-subtitle-modern">All registered patients - Total: <?php echo count($patients_data); ?></div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead>
                        <tr><th>Client #</th><th>Name</th><th>NIN</th><th>Age</th><th>Phone</th><th>Village</th><th>Parish</th><th>District</th><th>Category</th><th>Status</th><th>Registered</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients_data as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['client_number'] ?? '—'); ?></td>
                            <td><strong><?php echo htmlspecialchars($p['surname'] . ' ' . $p['first_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['nin'] ?? '—'); ?></td>
                            <td><?php echo $p['age']; ?></td>
                            <td><?php echo htmlspecialchars($p['phone'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['village'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['parish'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($p['district'] ?? '—'); ?></td>
                            <td><?php echo $p['client_category']; ?></td>
                            <td><?php echo $p['status'] == 'active' ? '<span class="badge-success">Active Pregnancy</span>' : '<span class="badge-warning">No Active Pregnancy</span>'; ?></td>
                            <td><?php echo $p['reg_date']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'patient_demographics'): ?>
            <?php
            $age_dist = $pdo->query("SELECT CASE WHEN age < 18 THEN 'Under 18' WHEN age BETWEEN 18 AND 24 THEN '18-24' WHEN age BETWEEN 25 AND 34 THEN '25-34' WHEN age BETWEEN 35 AND 44 THEN '35-44' WHEN age >= 45 THEN '45+' ELSE 'Unknown' END as age_group, COUNT(*) as count FROM patients WHERE age IS NOT NULL GROUP BY age_group ORDER BY MIN(age)")->fetchAll();
            $district_dist = $pdo->query("SELECT district, COUNT(*) as count FROM patients WHERE district IS NOT NULL GROUP BY district ORDER BY count DESC LIMIT 10")->fetchAll();
            $category_dist = $pdo->query("SELECT client_category, COUNT(*) as count FROM patients GROUP BY client_category")->fetchAll();
            $total_age = array_sum(array_column($age_dist, 'count'));
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Patient Demographics</div>
                    <div class="report-subtitle-modern">Age distribution, location & category analysis</div>
                </div>
            </div>
            
            <div class="stats-grid-modern">
                <?php foreach ($age_dist as $a): ?>
                <div class="stat-card-modern">
                    <div class="stat-card-value-modern"><?php echo $a['count']; ?></div>
                    <div class="stat-card-label-modern"><?php echo $a['age_group']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Age Group</th><th>Count</th><th>Percentage</th></tr></thead>
                    <tbody>
                        <?php foreach ($age_dist as $a): ?>
                        <tr>
                            <td><?php echo $a['age_group']; ?></td>
                            <td><?php echo $a['count']; ?></td>
                            <td><?php echo $total_age > 0 ? round($a['count']/$total_age*100,1) : 0; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="table-wrapper" style="margin-top: 20px;">
                <table class="data-table-modern">
                    <thead><tr><th>Category</th><th>Count</th><th>Percentage</th></tr></thead>
                    <tbody>
                        <?php 
                        $total_cat = array_sum(array_column($category_dist, 'count'));
                        foreach ($category_dist as $c): ?>
                        <tr>
                            <td><?php echo $c['client_category'] == 'N' ? '🇺🇬 National' : ($c['client_category'] == 'F' ? '🌍 Foreigner' : '🏕️ Refugee'); ?></td>
                            <td><?php echo $c['count']; ?></td>
                            <td><?php echo $total_cat > 0 ? round($c['count']/$total_cat*100,1) : 0; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="table-wrapper" style="margin-top: 20px;">
                <table class="data-table-modern">
                    <thead><tr><th>District</th><th>Patient Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($district_dist as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['district']); ?></td>
                            <td><?php echo $d['count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'individual_patient'): ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Individual Patient Report</div>
                    <div class="report-subtitle-modern">Complete medical history of a single patient</div>
                </div>
            </div>
            
            <div class="patient-selector-modern">
                <form method="GET" style="display: inline;">
                    <input type="hidden" name="tab" value="individual_patient">
                    <input type="hidden" name="date_from" value="<?php echo $date_from; ?>">
                    <input type="hidden" name="date_to" value="<?php echo $date_to; ?>">
                    <select name="patient_id" required>
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patients_list as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $patient_id == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($p['client_number'] ?? 'No client') . ' - ' . $p['surname'] . ' ' . $p['first_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-modern btn-primary-modern">Load Patient</button>
                </form>
            </div>
            
            <?php if ($patient_id > 0): 
                $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch();
                if ($patient):
                $preg_stmt = $pdo->prepare("SELECT * FROM pregnancy_episodes WHERE patient_id = ? ORDER BY created_at DESC");
                $preg_stmt->execute([$patient_id]);
                $pregnancies = $preg_stmt->fetchAll();
            ?>
                <div class="table-wrapper">
                    <table class="data-table-modern">
                        <tr style="background: var(--bg-body);"><td colspan="2"><strong>📋 PERSONAL INFORMATION</strong></td></tr>
                        <tr><td style="width:30%"><strong>Full Name</strong></td><td><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></td></tr>
                        <tr><td><strong>NIN</strong></td><td><?php echo htmlspecialchars($patient['nin'] ?? '—'); ?></td></tr>
                        <tr><td><strong>Age</strong></td><td><?php echo $patient['age']; ?> years</td></tr>
                        <tr><td><strong>Phone</strong></td><td><?php echo htmlspecialchars($patient['phone'] ?? '—'); ?></td></tr>
                        <tr><td><strong>Client Category</strong></td><td><?php echo $patient['client_category'] == 'N' ? 'National' : ($patient['client_category'] == 'F' ? 'Foreigner' : 'Refugee'); ?></td></tr>
                        <tr><td><strong>Address</strong></td><td><?php echo htmlspecialchars($patient['village'] ?? ''); ?>, <?php echo htmlspecialchars($patient['parish'] ?? ''); ?>, <?php echo htmlspecialchars($patient['sub_county'] ?? ''); ?>, <?php echo htmlspecialchars($patient['district'] ?? ''); ?></td></tr>
                        <tr><td><strong>Registered</strong></td><td><?php echo formatDateDMY($patient['created_at']); ?></td></tr>
                        
                        <?php foreach ($pregnancies as $ep): 
                            $anc_visits = $pdo->prepare("SELECT * FROM anc_visits WHERE pregnancy_episode_id = ? ORDER BY visit_number ASC");
                            $anc_visits->execute([$ep['id']]);
                            $anc_visits_data = $anc_visits->fetchAll();
                            $delivery = $pdo->prepare("SELECT * FROM delivery_records WHERE pregnancy_episode_id = ?");
                            $delivery->execute([$ep['id']]);
                            $delivery_data = $delivery->fetch();
                            $babies = [];
                            if ($delivery_data) {
                                $baby_stmt = $pdo->prepare('SELECT * FROM babies WHERE delivery_record_id = ? ORDER BY birth_order');
                                $baby_stmt->execute([$delivery_data['id']]);
                                $babies = $baby_stmt->fetchAll();
                            }
                        ?>
                            <tr style="background: var(--bg-body);"><td colspan="2"><strong>🤰 PREGNANCY: <?php echo $ep['client_number']; ?> (<?php echo ucfirst($ep['status']); ?>)</strong></td></tr>
                            <tr><td><strong>Gravida / Parity</strong></td><td><?php echo $ep['gravida']; ?> / <?php echo $ep['parity']; ?></td></tr>
                            <tr><td><strong>LMP / EDD</strong></td><td><?php echo formatDateDMY($ep['lmp']); ?> / <?php echo formatDateDMY($ep['edd']); ?></td></tr>
                            <tr><td><strong>Booking GA</strong></td><td><?php echo $ep['booking_ga_weeks']; ?> weeks (<?php echo ucfirst($ep['booking_timing']); ?> booking)</td></tr>
                            
                            <?php if ($anc_visits_data): ?>
                            <tr><td colspan="2"><strong>📝 ANC Visits (<?php echo count($anc_visits_data); ?> visits)</strong></td></tr>
                            <tr><td colspan="2">
                                <table class="sub-table-modern">
                                    <thead><tr><th>#</th><th>Date</th><th>GA</th><th>Weight</th><th>BP</th><th>MUAC</th><th>HIV Code</th><th>TT</th><th>IPTp</th><th>Diagnosis</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($anc_visits_data as $v): ?>
                                        <tr>
                                            <td><?php echo $v['visit_number']; ?></td>
                                            <td><?php echo formatDateDMY($v['visit_date']); ?></td>
                                            <td><?php echo $v['gestational_age_weeks']; ?> wks</td>
                                            <td><?php echo $v['weight_kg']; ?> kg</td>
                                            <td><?php echo $v['bp_systolic'] . '/' . $v['bp_diastolic']; ?></td>
                                            <td><?php echo $v['muac_cm'] . ' (' . $v['muac_color'] . ')'; ?></td>
                                            <td><?php echo $v['woman_hiv_code'] ?? '-'; ?></td>
                                            <td><?php echo $v['tt_dose_received'] ?? '-'; ?></td>
                                            <td><?php echo $v['iptp_dose'] ?? '-'; ?></td>
                                            <td><?php echo htmlspecialchars(substr($v['diagnosis'] ?? '-', 0, 30)); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td></tr>
                            <?php endif; ?>
                            
                            <?php if ($delivery_data): ?>
                            <tr style="background: var(--bg-body);"><td colspan="2"><strong>🤱 Delivery Details</strong></td></tr>
                            <tr><td><strong>Admission No / IPD</strong></td><td><?php echo $delivery_data['admission_no'] . ' / ' . $delivery_data['ipd_no']; ?></td></tr>
                            <tr><td><strong>Delivery Date</strong></td><td><?php echo formatDateTimeDMY($delivery_data['delivery_datetime']); ?></td></tr>
                            <tr><td><strong>Mode of Delivery</strong></td><td><?php echo str_replace('_', ' ', $delivery_data['mode_of_delivery']); ?></td></tr>
                            <tr><td><strong>Term/Preterm</strong></td><td><?php echo $delivery_data['term_preterm'] == 'T' ? 'Term (≥37 weeks)' : 'Preterm (<37 weeks)'; ?> (<?php echo $delivery_data['gestation_weeks']; ?> weeks)</td></tr>
                            <?php if ($babies): foreach ($babies as $baby): ?>
                                <tr><td><strong>Baby <?php echo (int) $baby['birth_order']; ?> Sex / Weight</strong></td><td><?php echo $baby['baby_sex'] === 'M' ? 'Male' : 'Female'; ?> / <?php echo htmlspecialchars($baby['baby_weight_kg'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> kg</td></tr>
                                <tr><td><strong>Baby <?php echo (int) $baby['birth_order']; ?> Apgar Score</strong></td><td><?php echo htmlspecialchars(($baby['apgar_1min'] ?? '—') . ' / ' . ($baby['apgar_5min'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?> (1/5 min)</td></tr>
                                <tr><td><strong>Baby <?php echo (int) $baby['birth_order']; ?> Outcome</strong></td><td><?php echo htmlspecialchars($baby['condition_at_discharge'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
                            <?php endforeach; else: ?>
                                <tr><td><strong>Baby Sex / Weight</strong></td><td><?php echo $delivery_data['baby_sex'] == 'M' ? 'Male' : 'Female'; ?> / <?php echo $delivery_data['baby_weight_kg']; ?> kg</td></tr>
                                <tr><td><strong>Apgar Score</strong></td><td><?php echo $delivery_data['apgar_1min'] . ' / ' . $delivery_data['apgar_5min']; ?> (1/5 min)</td></tr>
                                <tr><td><strong>Baby Outcome</strong></td><td><?php
                                    $outcome = $delivery_data['baby_condition_at_discharge'];
                                    if ($outcome === 'AL') echo '<span class="badge-success">Alive</span>';
                                    elseif ($outcome === 'MSB') echo '<span class="badge-danger">Macerated Still Birth</span>';
                                    elseif ($outcome === 'FSB') echo '<span class="badge-danger">Fresh Still Birth</span>';
                                    elseif ($outcome === 'NND') echo '<span class="badge-danger">Neonatal Death</span>';
                                    else echo htmlspecialchars($outcome ?? '—', ENT_QUOTES, 'UTF-8');
                                ?></td></tr>
                            <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; endif; ?>
            
        <?php elseif ($active_tab == 'anc_register'): ?>
            <?php
            $anc_stmt = $pdo->prepare("SELECT pe.client_number, CONCAT(p.surname, ' ', p.first_name) as patient_name, p.age, p.phone, p.village, av.visit_number, DATE_FORMAT(av.visit_date, '%d %b %Y') as visit_date, av.gestational_age_weeks, av.weight_kg, av.height_cm, CONCAT(av.muac_cm, 'cm (', av.muac_color, ')') as muac, CONCAT(av.bp_systolic, '/', av.bp_diastolic) as bp, av.woman_hiv_code as hiv, av.tt_dose_received as tt, av.iptp_dose, IF(av.llin_given=1,'Yes','No') as llin, av.iron_folate_tablets, av.diagnosis, av.complications FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id JOIN patients p ON pe.patient_id = p.id WHERE av.visit_date BETWEEN ? AND ? ORDER BY av.visit_date DESC LIMIT 1000");
            $anc_stmt->execute([$date_from, $date_to]);
            $anc_data = $anc_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">ANC Register</div>
                    <div class="report-subtitle-modern">Period: <?php echo formatDateDMY($date_from); ?> to <?php echo formatDateDMY($date_to); ?> - Total: <?php echo count($anc_data); ?> visits</div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead>
                        <tr><th>Client #</th><th>Name</th><th>Age</th><th>Phone</th><th>Village</th><th>#</th><th>Date</th><th>GA</th><th>Wt</th><th>MUAC</th><th>BP</th><th>HIV</th><th>TT</th><th>IPTp</th><th>LLIN</th><th>Iron</th><th>Diagnosis</th><th>Complications</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($anc_data as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['patient_name']); ?></strong></td>
                            <td><?php echo $r['age']; ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo $r['visit_number']; ?></td>
                            <td><?php echo $r['visit_date']; ?></td>
                            <td><?php echo $r['gestational_age_weeks']; ?>w</td>
                            <td><?php echo $r['weight_kg']; ?>kg</td>
                            <td><?php echo $r['muac']; ?></td>
                            <td><?php echo $r['bp']; ?></td>
                            <td><?php echo $r['hiv'] ? '<span class="badge-warning">' . $r['hiv'] . '</span>' : '-'; ?></td>
                            <td><?php echo $r['tt'] ?? '-'; ?></td>
                            <td><?php echo $r['iptp_dose'] ?? '-'; ?></td>
                            <td><?php echo $r['llin']; ?></td>
                            <td><?php echo $r['iron_folate_tablets'] ?? '-'; ?></td>
                            <td><?php echo htmlspecialchars(substr($r['diagnosis'] ?? '-', 0, 20)); ?></td>
                            <td class="<?php echo !empty($r['complications']) && $r['complications'] != 'Nil' ? 'badge-danger' : ''; ?>"><?php echo htmlspecialchars(substr($r['complications'] ?? '-', 0, 20)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'high_risk'): ?>
            <?php
            $high_stmt = $pdo->prepare("SELECT pe.client_number, CONCAT(p.surname, ' ', p.first_name) as patient_name, p.age, p.phone, p.village, av.complications, av.diagnosis, DATE_FORMAT(av.visit_date, '%d %b %Y') as visit_date, av.woman_hiv_code, av.gestational_age_weeks FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id JOIN patients p ON pe.patient_id = p.id WHERE (av.complications IS NOT NULL AND av.complications NOT IN ('Nil','None','')) AND av.visit_date BETWEEN ? AND ? ORDER BY av.visit_date DESC");
            $high_stmt->execute([$date_from, $date_to]);
            $high_risk_data = $high_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">High Risk Pregnancies</div>
                    <div class="report-subtitle-modern">Patients with complications - Total: <?php echo count($high_risk_data); ?></div>
                </div>
            </div>
            <?php if (count($high_risk_data) > 0): ?>
            <div class="insights-box-modern" style="background:#ffebee; border-left-color:#c62828;">
                <div><strong>⚠️ Action Required</strong></div>
                <div class="insights-list-modern">
                    <span class="insight-tag-modern">🚨 <?php echo count($high_risk_data); ?> high risk patients identified</span>
                    <span class="insight-tag-modern">🩺 Close monitoring required</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Client #</th><th>Name</th><th>Age</th><th>Phone</th><th>Village</th><th>GA</th><th>HIV</th><th>Complication</th><th>Diagnosis</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($high_risk_data as $r): ?>
                        <tr style="background:#ffebee;">
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['patient_name']); ?></strong></td>
                            <td><?php echo $r['age']; ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo $r['gestational_age_weeks']; ?>w</td>
                            <td><?php echo $r['woman_hiv_code'] ?? '-'; ?></td>
                            <td class="badge-danger"><?php echo htmlspecialchars(substr($r['complications'], 0, 35)); ?></td>
                            <td><?php echo htmlspecialchars($r['diagnosis'] ?? '-'); ?></td>
                            <td><?php echo $r['visit_date']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'delivery_register'): ?>
            <?php
            $del_stmt = $pdo->prepare(
                "SELECT dr.ipd_no, DATE_FORMAT(dr.delivery_datetime, '%d %b %Y %H:%i') AS delivery_date,
                        CONCAT(p.surname, ' ', p.first_name) AS mother_name, p.age, p.phone, p.village,
                        pe.client_number, dr.mode_of_delivery, b.birth_order,
                        IF(b.id IS NULL, dr.baby_sex, b.baby_sex) AS baby_sex,
                        IF(b.id IS NULL, dr.baby_weight_kg, b.baby_weight_kg) AS baby_weight_kg,
                        IF(b.id IS NULL, CONCAT(dr.apgar_1min, '/', dr.apgar_5min), CONCAT(b.apgar_1min, '/', b.apgar_5min)) AS apgar,
                        IF(b.id IS NULL, dr.baby_condition_at_discharge, b.condition_at_discharge) AS outcome
                 FROM delivery_records dr
                 JOIN pregnancy_episodes pe ON dr.pregnancy_episode_id = pe.id
                 JOIN patients p ON pe.patient_id = p.id
                 LEFT JOIN babies b ON b.delivery_record_id = dr.id
                 WHERE dr.delivery_datetime BETWEEN ? AND ?
                 ORDER BY dr.delivery_datetime DESC, b.birth_order"
            );
            $del_stmt->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
            $delivery_data = $del_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Delivery Register</div>
                    <div class="report-subtitle-modern">Period: <?php echo formatDateDMY($date_from); ?> to <?php echo formatDateDMY($date_to); ?> - Total: <?php echo count($delivery_data); ?> newborn(s)</div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>IPD #</th><th>Date</th><th>Mother</th><th>Age</th><th>Phone</th><th>Village</th><th>Client #</th><th>Mode</th><th>Baby</th><th>Apgar</th><th>Outcome</th></tr></thead>
                    <tbody>
                        <?php foreach ($delivery_data as $r): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['ipd_no']); ?></strong></td>
                            <td><?php echo $r['delivery_date']; ?></td>
                            <td><?php echo htmlspecialchars($r['mother_name']); ?></td>
                            <td><?php echo $r['age']; ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><?php echo str_replace('_', ' ', $r['mode_of_delivery']); ?></td>
                            <td><?php echo $r['birth_order'] ? 'Baby ' . (int) $r['birth_order'] . ' · ' : ''; ?><?php echo htmlspecialchars($r['baby_sex'] . ' (' . ($r['baby_weight_kg'] ?? '—') . ' kg)', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $r['apgar']; ?></td>
                            <td class="<?php echo $r['outcome'] != 'AL' ? 'badge-danger' : 'badge-success'; ?>"><?php echo $r['outcome']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'perinatal_outcomes'): ?>
            <?php
            $out_stmt = $pdo->prepare(
                "SELECT outcome, COUNT(*) AS count
                 FROM (
                    SELECT b.condition_at_discharge AS outcome
                    FROM babies b
                    JOIN delivery_records dr ON dr.id = b.delivery_record_id
                    WHERE dr.delivery_datetime BETWEEN ? AND ?
                    UNION ALL
                    SELECT dr.baby_condition_at_discharge AS outcome
                    FROM delivery_records dr
                    WHERE dr.delivery_datetime BETWEEN ? AND ?
                      AND NOT EXISTS (
                          SELECT 1 FROM babies b WHERE b.delivery_record_id = dr.id
                      )
                 ) outcomes
                 GROUP BY outcome"
            );
            $out_stmt->execute([
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59',
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59',
            ]);
            $outcome_data = $out_stmt->fetchAll();
            $total_outcomes = array_sum(array_column($outcome_data, 'count'));
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Perinatal Outcomes</div>
                    <div class="report-subtitle-modern">Period: <?php echo formatDateDMY($date_from); ?> to <?php echo formatDateDMY($date_to); ?></div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Outcome</th><th>Count</th><th>Percentage</th></tr></thead>
                    <tbody>
                        <?php foreach ($outcome_data as $o): ?>
                        <tr>
                            <td><?php echo $o['outcome']; ?></td>
                            <td><strong><?php echo $o['count']; ?></strong></td>
                            <td><?php echo $total_outcomes > 0 ? round($o['count']/$total_outcomes*100,1) : 0; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'pnc_register'): ?>
            <?php
            $pnc_stmt = $pdo->prepare("SELECT pe.client_number, CONCAT(p.surname, ' ', p.first_name) as mother_name, p.age, p.phone, p.village, DATE_FORMAT(pv.visit_date, '%d %b %Y') as visit_date, pv.visit_timing, pv.mother_weight_kg, pv.baby_weight_kg, pv.dbs_test_result FROM postnatal_visits pv JOIN pregnancy_episodes pe ON pv.pregnancy_episode_id = pe.id JOIN patients p ON pe.patient_id = p.id WHERE pv.visit_date BETWEEN ? AND ? ORDER BY pv.visit_date DESC");
            $pnc_stmt->execute([$date_from, $date_to]);
            $pnc_data = $pnc_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Postnatal Care Register</div>
                    <div class="report-subtitle-modern">Period: <?php echo formatDateDMY($date_from); ?> to <?php echo formatDateDMY($date_to); ?> - Total: <?php echo count($pnc_data); ?> visits</div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Client #</th><th>Mother</th><th>Age</th><th>Phone</th><th>Village</th><th>Date</th><th>Timing</th><th>Mother Wt</th><th>Baby Wt</th><th>DBS</th></tr></thead>
                    <tbody>
                        <?php foreach ($pnc_data as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['mother_name']); ?></strong></td>
                            <td><?php echo $r['age']; ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo $r['visit_date']; ?></td>
                            <td><?php echo str_replace('_', ' ', $r['visit_timing']); ?></td>
                            <td><?php echo $r['mother_weight_kg']; ?>kg</td>
                            <td><?php echo $r['baby_weight_kg']; ?>kg</td>
                            <td class="<?php echo $r['dbs_test_result'] == 'positive' ? 'badge-danger' : ($r['dbs_test_result'] == 'negative' ? 'badge-success' : ''); ?>"><?php echo ucfirst($r['dbs_test_result'] ?? 'pending'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'fp_uptake'): ?>
            <?php
            $fp_stmt = $pdo->prepare("SELECT pv.fp_method_code, fm.method_name, COUNT(*) as count FROM postnatal_visits pv LEFT JOIN fp_methods fm ON pv.fp_method_code = fm.code WHERE pv.fp_method_code IS NOT NULL AND pv.fp_method_code != 14 AND pv.visit_date BETWEEN ? AND ? GROUP BY pv.fp_method_code ORDER BY count DESC");
            $fp_stmt->execute([$date_from, $date_to]);
            $fp_data = $fp_stmt->fetchAll();
            $total_fp = array_sum(array_column($fp_data, 'count'));
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Family Planning Uptake</div>
                    <div class="report-subtitle-modern">Post-delivery method distribution - Total: <?php echo $total_fp; ?> mothers</div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Code</th><th>FP Method</th><th>Mothers</th><th>Percentage</th></tr></thead>
                    <tbody>
                        <?php foreach ($fp_data as $f): ?>
                        <tr>
                            <td><?php echo $f['fp_method_code']; ?></td>
                            <td><?php echo htmlspecialchars($f['method_name'] ?? 'Other'); ?></td>
                            <td><strong><?php echo $f['count']; ?></strong></td>
                            <td><?php echo $total_fp > 0 ? round($f['count']/$total_fp*100,1) : 0; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'appointments'): ?>
            <?php
            $app_stmt = $pdo->prepare(
                "SELECT pe.client_number, CONCAT(p.surname, ' ', p.first_name) AS patient_name,
                        p.phone, p.village, a.scheduled_date, a.scheduled_time,
                        at.label AS appointment_type
                 FROM appointments a
                 JOIN patients p ON p.id = a.patient_id
                 LEFT JOIN pregnancy_episodes pe ON pe.id = a.pregnancy_episode_id
                 JOIN appointment_types at ON at.code = a.appointment_type
                 WHERE a.status = 'scheduled' AND a.scheduled_date BETWEEN ? AND ?
                 ORDER BY a.scheduled_date, a.scheduled_time, p.surname"
            );
            $app_stmt->execute([$date_from, $date_to]);
            $appointment_data = $app_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Upcoming Appointments</div>
                    <div class="report-subtitle-modern">Scheduled appointments in selected period - Total: <?php echo count($appointment_data); ?></div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Client #</th><th>Name</th><th>Phone</th><th>Village</th><th>Appointment</th><th>Date / Time</th></tr></thead>
                    <tbody>
                        <?php foreach ($appointment_data as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['patient_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['appointment_type']); ?></td>
                            <td><?php echo htmlspecialchars(formatDate($r['scheduled_date']) . ($r['scheduled_time'] ? ' ' . substr($r['scheduled_time'], 0, 5) : '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif ($active_tab == 'missed_visits'): ?>
            <?php
            $miss_stmt = $pdo->prepare(
                "SELECT pe.client_number, CONCAT(p.surname, ' ', p.first_name) AS patient_name,
                        p.phone, p.village, a.scheduled_date, a.scheduled_time,
                        at.label AS appointment_type, DATEDIFF(CURDATE(), a.scheduled_date) AS days_overdue
                 FROM appointments a
                 JOIN patients p ON p.id = a.patient_id
                 LEFT JOIN pregnancy_episodes pe ON pe.id = a.pregnancy_episode_id
                 JOIN appointment_types at ON at.code = a.appointment_type
                 WHERE a.status = 'missed' AND a.scheduled_date BETWEEN ? AND ?
                 ORDER BY a.scheduled_date"
            );
            $miss_stmt->execute([$date_from, $date_to]);
            $missed_data = $miss_stmt->fetchAll();
            ?>
            <div class="report-header">
                <div>
                    <div class="report-title-modern">Missed Appointments</div>
                    <div class="report-subtitle-modern">Appointments marked missed in selected period - Total: <?php echo count($missed_data); ?></div>
                </div>
            </div>
            <?php if (count($missed_data) > 0): ?>
            <div class="insights-box-modern" style="background:#ffebee; border-left-color:#c62828;">
                <div><strong>🚨 Urgent Follow-up Required</strong></div>
                <div class="insights-list-modern">
                    <span class="insight-tag-modern">⚠️ <?php echo count($missed_data); ?> appointments were marked missed</span>
                    <span class="insight-tag-modern">📞 Immediate contact recommended</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="table-wrapper">
                <table class="data-table-modern">
                    <thead><tr><th>Client #</th><th>Name</th><th>Phone</th><th>Village</th><th>Appointment</th><th>Scheduled</th><th>Days overdue</th></tr></thead>
                    <tbody>
                        <?php foreach ($missed_data as $r): ?>
                        <tr style="background:#ffebee;">
                            <td><?php echo htmlspecialchars($r['client_number']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['patient_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['village'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['appointment_type']); ?></td>
                            <td><?php echo htmlspecialchars(formatDate($r['scheduled_date']) . ($r['scheduled_time'] ? ' ' . substr($r['scheduled_time'], 0, 5) : '')); ?></td>
                            <td class="badge-danger"><?php echo $r['days_overdue']; ?> days overdue</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function switchTab(tabName) {
    document.getElementById('activeTab').value = tabName;
    document.getElementById('filterForm').submit();
}

function exportReport() {
    var currentTab = document.getElementById('activeTab').value;
    var dateFrom = document.querySelector('input[name="date_from"]').value;
    var dateTo = document.querySelector('input[name="date_to"]').value;
    window.location.href = 'reports.php?export_report=excel&export_tab=' + currentTab + '&date_from=' + dateFrom + '&date_to=' + dateTo;
}
</script>

<?php require_once 'footer.php'; ?>