<?php
// ============================================
// FILE: index.php
// PURPOSE: Main Dashboard with 12 Charts & Stats - OPTIMIZED
// Includes: Compact stats tiles, Upcoming visits, Expected deliveries
// FIXED: SQL errors with aggregate functions in HAVING/ORDER BY
// ============================================

require_once 'config.php';
requireLogin();
require_once 'nav.php';

// ============================================
// STATISTICS TILES
// ============================================

$stats = [];

// Active pregnancies
$stmt = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE status = 'active'");
$stats['active_pregnancies'] = $stmt->fetchColumn();

// New ANC this month
$stmt = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$stats['new_anc_month'] = $stmt->fetchColumn();

// Early booking rate
$stmt = $pdo->query("SELECT COUNT(CASE WHEN booking_timing = 'early' THEN 1 END) as early, COUNT(*) as total FROM pregnancy_episodes WHERE booking_timing IS NOT NULL");
$row = $stmt->fetch();
$stats['early_booking_rate'] = $row['total'] > 0 ? round(($row['early'] / $row['total']) * 100) : 0;

// Deliveries this month
$stmt = $pdo->query("SELECT COUNT(*) FROM delivery_records WHERE MONTH(delivery_datetime) = MONTH(CURDATE()) AND YEAR(delivery_datetime) = YEAR(CURDATE())");
$stats['deliveries_month'] = $stmt->fetchColumn();

// C-section rate
$stmt = $pdo->query("SELECT COUNT(CASE WHEN mode_of_delivery = 'caesarean_section' THEN 1 END) as cs, COUNT(*) as total FROM delivery_records");
$row = $stmt->fetch();
$stats['cs_rate'] = $row['total'] > 0 ? round(($row['cs'] / $row['total']) * 100) : 0;

// HIV+ mothers on ART
$stmt = $pdo->query("SELECT COUNT(DISTINCT pe.id) FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id WHERE av.woman_hiv_code IN ('TRR', 'TRRK', 'TRR+') AND av.emtct_risk = 'Low'");
$stats['hiv_on_art'] = $stmt->fetchColumn();

// PNC 6-week rate - FIXED: Use proper subquery
$stmt = $pdo->query("SELECT 
    COUNT(DISTINCT dr.pregnancy_episode_id) as delivered,
    COUNT(DISTINCT pv.pregnancy_episode_id) as pnc6 
FROM delivery_records dr 
LEFT JOIN postnatal_visits pv ON dr.pregnancy_episode_id = pv.pregnancy_episode_id AND pv.visit_timing = '6_weeks'");
$row = $stmt->fetch();
$stats['pnc_6week_rate'] = $row['delivered'] > 0 ? round(($row['pnc6'] / $row['delivered']) * 100) : 0;

// Total ANC visits all time
$stmt = $pdo->query("SELECT COUNT(*) FROM anc_visits");
$stats['total_anc_visits'] = $stmt->fetchColumn();

// Total deliveries
$stmt = $pdo->query("SELECT COUNT(*) FROM delivery_records");
$stats['total_deliveries'] = $stmt->fetchColumn();

// Total postnatal visits
$stmt = $pdo->query("SELECT COUNT(*) FROM postnatal_visits");
$stats['total_pnc'] = $stmt->fetchColumn();

// Average ANC visits per client
$stmt = $pdo->query("SELECT AVG(visit_count) FROM (SELECT pregnancy_episode_id, COUNT(*) as visit_count FROM anc_visits GROUP BY pregnancy_episode_id) as sub");
$stats['avg_anc_visits'] = round($stmt->fetchColumn(), 1);

// Stillbirth rate
$stmt = $pdo->query(
    "SELECT
        SUM(CASE
            WHEN b.id IS NULL THEN CASE WHEN dr.baby_condition_at_discharge IN ('MSB', 'FSB') THEN 1 ELSE 0 END
            WHEN b.condition_at_discharge IN ('MSB', 'FSB') THEN 1
            ELSE 0
        END) AS sb,
        COUNT(b.id) + SUM(CASE WHEN b.id IS NULL THEN 1 ELSE 0 END) AS total
     FROM delivery_records dr
     LEFT JOIN babies b ON b.delivery_record_id = dr.id"
);
$row = $stmt->fetch();
$stats['stillbirth_rate'] = $row['total'] > 0 ? round(($row['sb'] / $row['total']) * 100, 1) : 0;

// ============================================
// UPCOMING VISITS & DELIVERIES (FIXED SQL)
// ============================================

// Use the clinician-entered next visit date or an actual scheduled appointment;
// do not infer a clinical interval from the previous visit date.
$stmt = $pdo->query("
    SELECT 
        p.id, 
        pe.id as episode_id,
        p.surname, 
        p.first_name, 
        p.phone, 
        pe.client_number, 
        pe.edd,
        last_visits.last_visit_date as last_visit,
        COALESCE(scheduled_anc.scheduled_date, last_visits.next_visit_date) as next_due_date,
        DATEDIFF(COALESCE(scheduled_anc.scheduled_date, last_visits.next_visit_date), CURDATE()) as days_to_next,
        pe.lmp,
        pe.gravida, 
        pe.parity
    FROM patients p
    JOIN pregnancy_episodes pe ON p.id = pe.patient_id
    LEFT JOIN (
        SELECT av.pregnancy_episode_id, av.visit_date AS last_visit_date, av.next_visit_date
        FROM anc_visits av
        WHERE av.id = (
            SELECT latest.id FROM anc_visits latest
            WHERE latest.pregnancy_episode_id = av.pregnancy_episode_id
            ORDER BY latest.visit_date DESC, latest.id DESC
            LIMIT 1
        )
    ) last_visits ON last_visits.pregnancy_episode_id = pe.id
    LEFT JOIN (
        SELECT pregnancy_episode_id, MIN(scheduled_date) AS scheduled_date
        FROM appointments
        WHERE appointment_type = 'ANC' AND status = 'scheduled' AND scheduled_date >= CURDATE()
        GROUP BY pregnancy_episode_id
    ) scheduled_anc ON scheduled_anc.pregnancy_episode_id = pe.id
    WHERE pe.status = 'active'
    HAVING next_due_date IS NULL OR next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY next_due_date IS NULL, next_due_date
    LIMIT 10
");
$upcoming_anc = $stmt->fetchAll();

// Include pregnancies with an EDD in the recent past as well as the next 30 days.
$stmt = $pdo->query("
    SELECT 
        p.id, 
        p.surname, 
        p.first_name, 
        p.phone, 
        pe.client_number, 
        pe.edd,
        DATEDIFF(pe.edd, CURDATE()) as days_to_edd,
        pe.gravida, 
        pe.parity,
        (SELECT COUNT(*) FROM anc_visits WHERE pregnancy_episode_id = pe.id) as anc_visits_count
    FROM patients p
    JOIN pregnancy_episodes pe ON p.id = pe.patient_id
    WHERE pe.status = 'active' 
      AND pe.edd IS NOT NULL
      AND pe.edd BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY pe.edd ASC
    LIMIT 10
");
$upcoming_deliveries = $stmt->fetchAll();

// Only report missed follow-ups when a clinician-entered date or appointment is overdue.
$stmt = $pdo->query("
    SELECT 
        p.id, 
        pe.id as episode_id,
        p.surname, 
        p.first_name, 
        p.phone, 
        pe.client_number, 
        pe.edd,
        last_visits.last_visit_date as last_visit,
        COALESCE(scheduled_anc.scheduled_date, last_visits.next_visit_date) as next_due_date,
        DATEDIFF(CURDATE(), COALESCE(scheduled_anc.scheduled_date, last_visits.next_visit_date)) as days_overdue
    FROM patients p
    JOIN pregnancy_episodes pe ON p.id = pe.patient_id
    LEFT JOIN (
        SELECT av.pregnancy_episode_id, av.visit_date AS last_visit_date, av.next_visit_date
        FROM anc_visits av
        WHERE av.id = (
            SELECT latest.id FROM anc_visits latest
            WHERE latest.pregnancy_episode_id = av.pregnancy_episode_id
            ORDER BY latest.visit_date DESC, latest.id DESC
            LIMIT 1
        )
    ) last_visits ON last_visits.pregnancy_episode_id = pe.id
    LEFT JOIN (
        SELECT pregnancy_episode_id, MIN(scheduled_date) AS scheduled_date
        FROM appointments
        WHERE appointment_type = 'ANC' AND status = 'scheduled' AND scheduled_date >= CURDATE()
        GROUP BY pregnancy_episode_id
    ) scheduled_anc ON scheduled_anc.pregnancy_episode_id = pe.id
    WHERE pe.status = 'active'
      AND COALESCE(scheduled_anc.scheduled_date, last_visits.next_visit_date) < CURDATE()
    ORDER BY days_overdue DESC
    LIMIT 10
");
$overdue_patients = $stmt->fetchAll();

// Recent deliveries (last 7 days) for PNC follow-up
$stmt = $pdo->query("
    SELECT 
        p.id, 
        pe.id as episode_id,
        p.surname, 
        p.first_name, 
        p.phone, 
        pe.client_number,
        dr.delivery_datetime, 
        dr.baby_sex, 
        dr.baby_weight_kg,
        DATEDIFF(CURDATE(), dr.delivery_datetime) as days_postpartum,
        (SELECT COUNT(*) FROM postnatal_visits WHERE pregnancy_episode_id = pe.id) as pnc_visits
    FROM delivery_records dr
    JOIN pregnancy_episodes pe ON dr.pregnancy_episode_id = pe.id
    JOIN patients p ON pe.patient_id = p.id
    WHERE dr.delivery_datetime >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY dr.delivery_datetime DESC
    LIMIT 10
");
$recent_deliveries = $stmt->fetchAll();

// ============================================
// CHART DATA
// ============================================

// Chart 1: ANC Trend (Last 6 months)
$stmt = $pdo->query("SELECT DATE_FORMAT(visit_date, '%b') as month, COUNT(*) as visits 
                     FROM anc_visits 
                     WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                     GROUP BY MONTH(visit_date), DATE_FORMAT(visit_date, '%b')
                     ORDER BY MIN(visit_date)");
$anc_trend = $stmt->fetchAll();

// Chart 2: Delivery Modes
$stmt = $pdo->query("SELECT 
                        CASE mode_of_delivery
                            WHEN 'normal_vaginal' THEN 'Normal Vaginal'
                            WHEN 'caesarean_section' THEN 'C-Section'
                            ELSE mode_of_delivery
                        END as mode_of_delivery, 
                        COUNT(*) as count 
                     FROM delivery_records 
                     GROUP BY mode_of_delivery");
$delivery_modes = $stmt->fetchAll();

// Chart 3: MUAC Distribution
$stmt = $pdo->query("SELECT 
                        CASE muac_color
                            WHEN 'R' THEN 'Severe Malnutrition'
                            WHEN 'Y' THEN 'Moderate Malnutrition'
                            WHEN 'G' THEN 'Normal'
                            ELSE 'Unknown'
                        END as muac_color, 
                        COUNT(*) as count 
                     FROM anc_visits 
                     WHERE muac_color IN ('R','Y','G') 
                     GROUP BY muac_color");
$muac_data = $stmt->fetchAll();

// Chart 4: Top Complications
$stmt = $pdo->query("SELECT complications, COUNT(*) as count 
                     FROM anc_visits 
                     WHERE complications IS NOT NULL AND complications != 'Nil' AND complications != '' AND complications != 'None'
                     GROUP BY complications 
                     ORDER BY count DESC 
                     LIMIT 5");
$complications = $stmt->fetchAll();

// Chart 5: TT Dose Coverage
$stmt = $pdo->query("SELECT tt_dose_received, COUNT(DISTINCT pregnancy_episode_id) as mothers 
                     FROM anc_visits 
                     WHERE tt_dose_received IS NOT NULL 
                     GROUP BY tt_dose_received 
                     ORDER BY tt_dose_received");
$tt_coverage = $stmt->fetchAll();

// Chart 6: IPTp Coverage
$stmt = $pdo->query("SELECT iptp_dose, COUNT(DISTINCT pregnancy_episode_id) as mothers 
                     FROM anc_visits 
                     WHERE iptp_dose IS NOT NULL AND iptp_dose != ''
                     GROUP BY iptp_dose");
$iptp_data = $stmt->fetchAll();

// Chart 7: Booking Timing
$stmt = $pdo->query("SELECT booking_timing, COUNT(*) as count FROM pregnancy_episodes WHERE booking_timing IS NOT NULL GROUP BY booking_timing");
$booking_timing = $stmt->fetchAll();

// Chart 8: HIV Status
$stmt = $pdo->query("SELECT 
                        CASE 
                            WHEN woman_hiv_code IN ('TR','TRV','TRK','TR+') THEN 'HIV Negative'
                            WHEN woman_hiv_code IN ('TRR','TRRK','TRR+') THEN 'HIV Positive'
                            ELSE 'Unknown/Not Tested'
                        END as status,
                        COUNT(DISTINCT pregnancy_episode_id) as count
                     FROM anc_visits 
                     GROUP BY status");
$hiv_status = $stmt->fetchAll();

// Chart 9: Postnatal Visit Timing
$stmt = $pdo->query("SELECT 
                        CASE visit_timing
                            WHEN '24_hours' THEN '24 Hours'
                            WHEN '6_days' THEN '6 Days'
                            WHEN '6_weeks' THEN '6 Weeks'
                            WHEN '6_months' THEN '6 Months'
                            ELSE visit_timing
                        END as visit_timing,
                        COUNT(*) as visits 
                     FROM postnatal_visits 
                     GROUP BY visit_timing");
$pnc_timing = $stmt->fetchAll();

// Chart 10: Low Birth Weight
$stmt = $pdo->query("SELECT 
                        CASE WHEN baby_weight_kg < 2.5 THEN 'Low Birth Weight (<2.5kg)'
                             ELSE 'Normal Weight (≥2.5kg)'
                        END as category,
                        COUNT(*) as count
                     FROM delivery_records 
                     WHERE baby_weight_kg IS NOT NULL
                     GROUP BY category");
$lbw_data = $stmt->fetchAll();

// Chart 11: Referral Types
$stmt = $pdo->query("SELECT referral_type, COUNT(*) as count FROM anc_visits WHERE referral_type IS NOT NULL GROUP BY referral_type");
$referral_types = $stmt->fetchAll();

// Chart 12: eMTCT Risk
$stmt = $pdo->query("SELECT emtct_risk, COUNT(*) as count FROM anc_visits WHERE emtct_risk IS NOT NULL GROUP BY emtct_risk");
$emtct_risk = $stmt->fetchAll();

// Helper function for formatting dates (if not already defined in config.php)
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (!$date || $date == '0000-00-00') return '—';
        return date('d M Y', strtotime($date));
    }
}
?>

<!-- Rest of your HTML remains exactly the same from here -->
<style>
    /* COMPACT STATS ROW - ALL ON ONE LINE */
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
    
    /* Quick Actions */
    .quick-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .quick-btn {
        background: var(--primary);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s;
    }
    
    .quick-btn:hover {
        background: var(--primary-light);
    }
    
    /* Follow-up Sections */
    .followup-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .followup-card {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
    }
    
    .followup-header {
        padding: 12px 16px;
        background: var(--bg-body);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .followup-header h4 {
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .followup-badge {
        font-size: 11px;
        padding: 2px 10px;
        border-radius: 20px;
        background: var(--primary-soft);
        color: var(--primary);
    }
    
    .followup-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }
    
    .followup-table th, .followup-table td {
        padding: 10px 8px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }
    
    .followup-table th {
        background: var(--bg-body);
        font-weight: 600;
        color: var(--text-secondary);
    }
    
    .followup-table tr:hover {
        background: var(--bg-body);
    }
    
    .urgent-badge {
        background: #ffebee;
        color: #c62828;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
    }
    
    .warning-badge {
        background: #fff3e0;
        color: #e65100;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
    }
    
    .success-badge {
        background: #e8f5e9;
        color: #2e7d64;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
    }
    
    .view-link {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
    }
    
    .view-link:hover {
        text-decoration: underline;
    }
    
    .empty-message {
        text-align: center;
        padding: 20px;
        color: var(--text-secondary);
        font-size: 12px;
    }
    
    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }
    
    .chart-card {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border);
        padding: 14px;
    }
    
    .chart-card h3 {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 12px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .chart-card canvas {
        max-height: 200px;
    }
    
    @media (max-width: 1000px) {
        .stats-row { flex-wrap: wrap; }
        .stat-tile { min-width: 90px; }
        .followup-grid { grid-template-columns: 1fr; }
        .charts-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Quick Actions -->
<div class="quick-actions">
    <button class="quick-btn" onclick="location.href='register_patient.php'"><i class="fas fa-plus-circle"></i> New ANC</button>
    <button class="quick-btn" onclick="location.href='anc_visit.php'"><i class="fas fa-notes-medical"></i> ANC Visit</button>
    <button class="quick-btn" onclick="location.href='delivery_admission.php'"><i class="fas fa-birthday-cake"></i> Delivery</button>
    <button class="quick-btn" onclick="location.href='postnatal_visit.php'"><i class="fas fa-hand-holding-heart"></i> PNC</button>
    <button class="quick-btn" onclick="location.href='patients.php'"><i class="fas fa-users"></i> All Patients</button>
    <button class="quick-btn" onclick="location.href='reports.php'"><i class="fas fa-chart-bar"></i> Reports</button>
</div>

<!-- COMPACT STATS TILES - ALL ON ONE LINE -->
<div class="stats-row">
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-fetus"></i></div><div class="stat-tile-value"><?php echo $stats['active_pregnancies']; ?></div><div class="stat-tile-label">Active</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-calendar-plus"></i></div><div class="stat-tile-value"><?php echo $stats['new_anc_month']; ?></div><div class="stat-tile-label">New ANC (Month)</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-clock"></i></div><div class="stat-tile-value"><?php echo $stats['early_booking_rate']; ?>%</div><div class="stat-tile-label">Early Booking</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-baby"></i></div><div class="stat-tile-value"><?php echo $stats['deliveries_month']; ?></div><div class="stat-tile-label">Deliveries (Month)</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-chart-line"></i></div><div class="stat-tile-value"><?php echo $stats['cs_rate']; ?>%</div><div class="stat-tile-label">C-Section</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-heartbeat"></i></div><div class="stat-tile-value"><?php echo $stats['hiv_on_art']; ?></div><div class="stat-tile-label">HIV+ on ART</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-child"></i></div><div class="stat-tile-value"><?php echo $stats['pnc_6week_rate']; ?>%</div><div class="stat-tile-label">PNC 6-Week</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-chart-simple"></i></div><div class="stat-tile-value"><?php echo $stats['avg_anc_visits']; ?></div><div class="stat-tile-label">Avg ANC Visits</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-tile-value"><?php echo $stats['stillbirth_rate']; ?>%</div><div class="stat-tile-label">Stillbirth</div></div>
    <div class="stat-tile"><div class="stat-tile-icon"><i class="fas fa-chart-bar"></i></div><div class="stat-tile-value"><?php echo number_format($stats['total_anc_visits']); ?></div><div class="stat-tile-label">Total ANC</div></div>
</div>

<!-- FOLLOW-UP SECTIONS -->
<div class="followup-grid">
    <!-- Upcoming ANC Visits (Due this week) -->
    <div class="followup-card">
        <div class="followup-header">
            <h4><i class="fas fa-calendar-week" style="color: #1e88e5;"></i> Upcoming ANC Visits</h4>
            <span class="followup-badge">Scheduled date, or needs scheduling</span>
        </div>
        <?php if (count($upcoming_anc) > 0): ?>
            <table class="followup-table">
                <thead>
                    <tr><th>Client #</th><th>Patient</th><th>Last Visit</th><th>Next ANC Date</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming_anc as $patient): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['client_number']); ?></td>
                        <td><strong><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></strong></td>
                        <td><?php echo $patient['last_visit'] ? formatDate($patient['last_visit']) : 'No visits yet'; ?></td>
                        <td class="<?php echo $patient['days_to_next'] !== null && $patient['days_to_next'] < 0 ? 'urgent-badge' : 'warning-badge'; ?>">
                            <?php
                                if ($patient['next_due_date'] === null) {
                                    echo 'Needs scheduling';
                                } elseif ($patient['days_to_next'] < 0) {
                                    echo htmlspecialchars(formatDate($patient['next_due_date']) . ' (' . abs((int) $patient['days_to_next']) . ' days overdue)', ENT_QUOTES, 'UTF-8');
                                } elseif ((int) $patient['days_to_next'] === 0) {
                                    echo 'Today';
                                } else {
                                    echo htmlspecialchars(formatDate($patient['next_due_date']) . ' (in ' . (int) $patient['days_to_next'] . ' days)', ENT_QUOTES, 'UTF-8');
                                }
                            ?>
                        </td>
                        <td><a href="anc_visit.php?episode_id=<?php echo (int)$patient['episode_id']; ?>" class="view-link">Record Visit →</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-message"><i class="fas fa-check-circle"></i> All patients are up to date with ANC visits</div>
        <?php endif; ?>
    </div>

    <!-- Expected Deliveries (Next 30 days) -->
    <div class="followup-card">
        <div class="followup-header">
            <h4><i class="fas fa-birthday-cake" style="color: #fb8c00;"></i> Expected Deliveries</h4>
            <span class="followup-badge">EDD overdue up to 14 days through next 30 days</span>
        </div>
        <?php if (count($upcoming_deliveries) > 0): ?>
            <table class="followup-table">
                <thead>
                    <tr><th>Client #</th><th>Patient</th><th>EDD</th><th>Days to/from EDD</th><th>ANC Visits</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming_deliveries as $patient): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['client_number']); ?></td>
                        <td><strong><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></strong></td>
                        <td><?php echo formatDate($patient['edd']); ?></td>
                        <td class="<?php echo $patient['days_to_edd'] <= 7 ? 'urgent-badge' : 'warning-badge'; ?>">
                            <?php echo $patient['days_to_edd'] < 0 ? 'EDD passed by ' . abs((int) $patient['days_to_edd']) . ' days' : (int) $patient['days_to_edd'] . ' days'; ?>
                        </td>
                        <td><?php echo $patient['anc_visits_count']; ?></td>
                        <td><a href="patient_profile.php?id=<?php echo $patient['id']; ?>" class="view-link">View →</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-message"><i class="fas fa-calendar-alt"></i> No deliveries expected in next 30 days</div>
        <?php endif; ?>
    </div>
</div>

<div class="followup-grid">
    <!-- Overdue Patients (Missed Appointments) -->
    <div class="followup-card">
        <div class="followup-header">
            <h4><i class="fas fa-exclamation-triangle" style="color: #c62828;"></i> Overdue / Missed Visits</h4>
            <span class="followup-badge">Needs follow-up</span>
        </div>
        <?php if (count($overdue_patients) > 0): ?>
            <table class="followup-table">
                <thead>
                    <tr><th>Client #</th><th>Patient</th><th>Last Visit</th><th>Next ANC Due</th><th>Phone</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($overdue_patients as $patient): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['client_number']); ?></td>
                        <td><strong><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></strong></td>
                        <td><?php echo formatDate($patient['last_visit']); ?></td>
                        <td class="urgent-badge"><?php echo htmlspecialchars(formatDate($patient['next_due_date']) . ' (' . (int) $patient['days_overdue'] . ' days overdue)', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($patient['phone'] ?? '—'); ?></td>
                        <td><a href="anc_visit.php?episode_id=<?php echo (int)$patient['episode_id']; ?>" class="view-link">Record Visit →</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-message"><i class="fas fa-smile"></i> No overdue patients</div>
        <?php endif; ?>
    </div>

    <!-- Recent Deliveries (Need PNC Follow-up) -->
    <div class="followup-card">
        <div class="followup-header">
            <h4><i class="fas fa-hand-holding-heart" style="color: #2e7d64;"></i> Recent Deliveries</h4>
            <span class="followup-badge">PNC follow-up needed</span>
        </div>
        <?php if (count($recent_deliveries) > 0): ?>
            <table class="followup-table">
                <thead>
                    <tr><th>Client #</th><th>Mother</th><th>Delivery Date</th><th>Days Postpartum</th><th>Baby</th><th>PNC Visits</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_deliveries as $patient): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['client_number']); ?></td>
                        <td><strong><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></strong></td>
                        <td><?php echo formatDate($patient['delivery_datetime']); ?></td>
                        <td><?php echo $patient['days_postpartum']; ?> days</td>
                        <td><?php echo $patient['baby_sex'] == 'M' ? '👶 Male' : '👶 Female'; ?>, <?php echo $patient['baby_weight_kg']; ?>kg</td>
                        <td><?php echo $patient['pnc_visits']; ?></td>
                        <td><a href="postnatal_visit.php?episode_id=<?php echo (int)$patient['episode_id']; ?>" class="view-link">PNC →</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-message"><i class="fas fa-baby-carriage"></i> No recent deliveries in the last 7 days</div>
        <?php endif; ?>
    </div>
</div>

<!-- Charts Grid (12 Charts) -->
<div class="charts-grid">
    <!-- Chart 1: ANC Trend -->
    <div class="chart-card"><h3><i class="fas fa-chart-line"></i> ANC Visits Trend (6 Months)</h3><canvas id="ancTrendChart"></canvas></div>
    
    <!-- Chart 2: Delivery Modes -->
    <div class="chart-card"><h3><i class="fas fa-chart-pie"></i> Delivery Modes</h3><canvas id="deliveryModesChart"></canvas></div>
    
    <!-- Chart 3: MUAC Nutrition Status -->
    <div class="chart-card"><h3><i class="fas fa-apple-alt"></i> Nutrition Status (MUAC)</h3><canvas id="muacChart"></canvas></div>
    
    <!-- Chart 4: Top Complications -->
    <div class="chart-card"><h3><i class="fas fa-exclamation-triangle"></i> Top 5 Risk Factors</h3><canvas id="complicationsChart"></canvas></div>
    
    <!-- Chart 5: TT Immunization Coverage -->
    <div class="chart-card"><h3><i class="fas fa-syringe"></i> TT Doses Coverage</h3><canvas id="ttChart"></canvas></div>
    
    <!-- Chart 6: IPTp Coverage -->
    <div class="chart-card"><h3><i class="fas fa-tablets"></i> IPTp Doses (Malaria)</h3><canvas id="iptpChart"></canvas></div>
    
    <!-- Chart 7: Booking Timing -->
    <div class="chart-card"><h3><i class="fas fa-calendar-check"></i> First ANC Booking Timing</h3><canvas id="bookingChart"></canvas></div>
    
    <!-- Chart 8: HIV Status -->
    <div class="chart-card"><h3><i class="fas fa-heartbeat"></i> HIV Status Distribution</h3><canvas id="hivChart"></canvas></div>
    
    <!-- Chart 9: Postnatal Visit Timing -->
    <div class="chart-card"><h3><i class="fas fa-child"></i> Postnatal Visit Timing</h3><canvas id="pncTimingChart"></canvas></div>
    
    <!-- Chart 10: Low Birth Weight -->
    <div class="chart-card"><h3><i class="fas fa-weight-scale"></i> Birth Weight Classification</h3><canvas id="lbwChart"></canvas></div>
    
    <!-- Chart 11: Referral Types -->
    <div class="chart-card"><h3><i class="fas fa-referral"></i> Referral Types</h3><canvas id="referralChart"></canvas></div>
    
    <!-- Chart 12: eMTCT Risk -->
    <div class="chart-card"><h3><i class="fas fa-shield-virus"></i> eMTCT Risk Assessment</h3><canvas id="emtctRiskChart"></canvas></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart 1: ANC Trend
new Chart(document.getElementById('ancTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($anc_trend, 'month')); ?>,
        datasets: [{ label: 'ANC Visits', data: <?php echo json_encode(array_column($anc_trend, 'visits')); ?>, borderColor: '#1e88e5', backgroundColor: 'rgba(30,136,229,0.1)', fill: true, tension: 0.3 }]
    },
    options: { responsive: true, maintainAspectRatio: true }
});

// Chart 2: Delivery Modes
new Chart(document.getElementById('deliveryModesChart'), {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($delivery_modes, 'mode_of_delivery')); ?>, datasets: [{ data: <?php echo json_encode(array_column($delivery_modes, 'count')); ?>, backgroundColor: ['#1e88e5','#43a047','#fb8c00','#26c6da','#e53935','#8e24aa'] }] },
    options: { responsive: true }
});

// Chart 3: MUAC
new Chart(document.getElementById('muacChart'), {
    type: 'doughnut',
    data: { labels: <?php echo json_encode(array_column($muac_data, 'muac_color')); ?>, datasets: [{ data: <?php echo json_encode(array_column($muac_data, 'count')); ?>, backgroundColor: ['#dc3545','#ffc107','#28a745'] }] },
    options: { responsive: true }
});

// Chart 4: Complications
new Chart(document.getElementById('complicationsChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($complications, 'complications')); ?>, datasets: [{ label: 'Number of Cases', data: <?php echo json_encode(array_column($complications, 'count')); ?>, backgroundColor: '#fb8c00', borderRadius: 8 }] },
    options: { responsive: true, indexAxis: 'y' }
});

// Chart 5: TT Coverage
new Chart(document.getElementById('ttChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($tt_coverage, 'tt_dose_received')); ?>, datasets: [{ label: 'Mothers', data: <?php echo json_encode(array_column($tt_coverage, 'mothers')); ?>, backgroundColor: '#1e88e5' }] },
    options: { responsive: true }
});

// Chart 6: IPTp
new Chart(document.getElementById('iptpChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($iptp_data, 'iptp_dose')); ?>, datasets: [{ label: 'Mothers', data: <?php echo json_encode(array_column($iptp_data, 'mothers')); ?>, backgroundColor: '#43a047' }] },
    options: { responsive: true }
});

// Chart 7: Booking Timing
new Chart(document.getElementById('bookingChart'), {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($booking_timing, 'booking_timing')); ?>, datasets: [{ data: <?php echo json_encode(array_column($booking_timing, 'count')); ?>, backgroundColor: ['#28a745','#dc3545'] }] },
    options: { responsive: true }
});

// Chart 8: HIV Status
new Chart(document.getElementById('hivChart'), {
    type: 'doughnut',
    data: { labels: <?php echo json_encode(array_column($hiv_status, 'status')); ?>, datasets: [{ data: <?php echo json_encode(array_column($hiv_status, 'count')); ?>, backgroundColor: ['#28a745','#dc3545','#6c757d'] }] },
    options: { responsive: true }
});

// Chart 9: PNC Timing
new Chart(document.getElementById('pncTimingChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($pnc_timing, 'visit_timing')); ?>, datasets: [{ label: 'Visits', data: <?php echo json_encode(array_column($pnc_timing, 'visits')); ?>, backgroundColor: '#26c6da' }] },
    options: { responsive: true }
});

// Chart 10: LBW
new Chart(document.getElementById('lbwChart'), {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($lbw_data, 'category')); ?>, datasets: [{ data: <?php echo json_encode(array_column($lbw_data, 'count')); ?>, backgroundColor: ['#dc3545','#28a745'] }] },
    options: { responsive: true }
});

// Chart 11: Referral Types
new Chart(document.getElementById('referralChart'), {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($referral_types, 'referral_type')); ?>, datasets: [{ label: 'Referrals', data: <?php echo json_encode(array_column($referral_types, 'count')); ?>, backgroundColor: '#fb8c00' }] },
    options: { responsive: true }
});

// Chart 12: eMTCT Risk
new Chart(document.getElementById('emtctRiskChart'), {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($emtct_risk, 'emtct_risk')); ?>, datasets: [{ data: <?php echo json_encode(array_column($emtct_risk, 'count')); ?>, backgroundColor: ['#dc3545','#28a745'] }] },
    options: { responsive: true }
});
</script>

<?php require_once 'footer.php'; ?>