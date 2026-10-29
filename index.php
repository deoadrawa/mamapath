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
$stmt = $pdo->query("SELECT COUNT(DISTINCT pe.id) FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id WHERE av.woman_hiv_code IN ('TRR', 'TRRK', 'TRR+') ");
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
                     FROM (
                        SELECT b.baby_weight_kg FROM babies b
                        UNION ALL
                        SELECT dr.baby_weight_kg FROM delivery_records dr
                        WHERE NOT EXISTS (SELECT 1 FROM babies b2 WHERE b2.delivery_record_id = dr.id)
                     ) w
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


<!-- Welcome / priorities -->
<section class="dash-hero">
    <div class="dash-hero-text">
        <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['user_fullname'] ?? $_SESSION['username'] ?? 'Colleague', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p><i class="fas fa-calendar-day"></i> <?php echo date('l, d F Y'); ?> &middot; Maternal &amp; Child Health</p>
    </div>
    <div class="dash-chips">
        <a class="dash-chip tone-danger" href="#follow-up"><i class="fas fa-triangle-exclamation"></i><strong><?php echo count($overdue_patients); ?></strong> Overdue</a>
        <a class="dash-chip tone-warning" href="#follow-up"><i class="fas fa-calendar-check"></i><strong><?php echo count($upcoming_anc); ?></strong> ANC due</a>
        <a class="dash-chip tone-info" href="#follow-up"><i class="fas fa-baby"></i><strong><?php echo count($upcoming_deliveries); ?></strong> Expected deliveries</a>
        <a class="dash-chip tone-success" href="#follow-up"><i class="fas fa-hand-holding-medical"></i><strong><?php echo count($recent_deliveries); ?></strong> PNC follow-up</a>
    </div>
</section>

<!-- Quick actions -->
<div class="dash-actions">
    <a class="dash-action tone-primary" href="register_patient.php"><i class="fas fa-user-plus"></i> New ANC</a>
    <a class="dash-action tone-info" href="anc_visit.php"><i class="fas fa-notes-medical"></i> ANC Visit</a>
    <a class="dash-action tone-pink" href="delivery_admission.php"><i class="fas fa-baby"></i> Delivery</a>
    <a class="dash-action tone-success" href="postnatal_visit.php"><i class="fas fa-hand-holding-medical"></i> Postnatal</a>
    <a class="dash-action tone-teal" href="appointments.php"><i class="fas fa-calendar-check"></i> Appointments</a>
    <a class="dash-action tone-purple" href="patients.php"><i class="fas fa-users"></i> Patients</a>
    <a class="dash-action tone-warning" href="reports.php"><i class="fas fa-chart-column"></i> Reports</a>
</div>

<!-- Key indicators -->
<div class="dash-kpi-groups">
    <section class="dash-kpi-group span-12">
        <h3 class="dash-section"><i class="fas fa-stethoscope"></i> Antenatal care</h3>
        <div class="stats-row">
            <a class="stat-tile tone-primary" href="patients.php"><span class="stat-tile-icon"><i class="fas fa-baby"></i></span><span class="stat-tile-value"><?php echo $stats['active_pregnancies']; ?></span><span class="stat-tile-label">Active pregnancies</span></a>
            <a class="stat-tile tone-info" href="patients.php"><span class="stat-tile-icon"><i class="fas fa-calendar-plus"></i></span><span class="stat-tile-value"><?php echo $stats['new_anc_month']; ?></span><span class="stat-tile-label">New ANC this month</span></a>
            <a class="stat-tile tone-teal" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-clock"></i></span><span class="stat-tile-value"><?php echo $stats['early_booking_rate']; ?>%</span><span class="stat-tile-label">Early booking rate</span></a>
            <a class="stat-tile tone-purple" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-chart-column"></i></span><span class="stat-tile-value"><?php echo $stats['avg_anc_visits']; ?></span><span class="stat-tile-label">Average ANC visits</span></a>
            <a class="stat-tile tone-primary" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-notes-medical"></i></span><span class="stat-tile-value"><?php echo number_format($stats['total_anc_visits']); ?></span><span class="stat-tile-label">Total ANC visits</span></a>
        </div>
    </section>
    <section class="dash-kpi-group span-7">
        <h3 class="dash-section"><i class="fas fa-baby-carriage"></i> Delivery &amp; postnatal</h3>
        <div class="stats-row">
            <a class="stat-tile tone-pink" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-baby"></i></span><span class="stat-tile-value"><?php echo $stats['deliveries_month']; ?></span><span class="stat-tile-label">Deliveries this month</span></a>
            <a class="stat-tile tone-warning" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-scissors"></i></span><span class="stat-tile-value"><?php echo $stats['cs_rate']; ?>%</span><span class="stat-tile-label">C-section rate</span></a>
            <a class="stat-tile tone-success" href="postnatal_visit.php"><span class="stat-tile-icon"><i class="fas fa-hand-holding-medical"></i></span><span class="stat-tile-value"><?php echo $stats['pnc_6week_rate']; ?>%</span><span class="stat-tile-label">PNC 6-week coverage</span></a>
        </div>
    </section>
    <section class="dash-kpi-group span-5">
        <h3 class="dash-section"><i class="fas fa-shield-virus"></i> Safety &amp; risk</h3>
        <div class="stats-row">
            <a class="stat-tile tone-danger" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-virus"></i></span><span class="stat-tile-value"><?php echo $stats['hiv_on_art']; ?></span><span class="stat-tile-label">HIV+ mothers</span></a>
            <a class="stat-tile tone-danger" href="reports.php"><span class="stat-tile-icon"><i class="fas fa-triangle-exclamation"></i></span><span class="stat-tile-value"><?php echo $stats['stillbirth_rate']; ?>%</span><span class="stat-tile-label">Stillbirth rate</span></a>
        </div>
    </section>
</div>

<!-- Follow-up, in order of urgency -->
<h3 class="dash-section dash-section-lg" id="follow-up"><i class="fas fa-list-check"></i> Priority follow-up</h3>
<div class="followup-grid">
    <!-- Overdue Patients (Missed Appointments) -->
    <div class="followup-card tone-danger">
        <div class="followup-header">
            <h4><span class="dash-icon"><i class="fas fa-exclamation-triangle"></i></span> Overdue / Missed Visits</h4>
            <span class="followup-badge"><?php echo count($overdue_patients); ?> &middot; Needs follow-up</span>
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
                        <td><span class="urgent-badge"><?php echo htmlspecialchars(formatDate($patient['next_due_date']) . ' (' . (int) $patient['days_overdue'] . ' days overdue)', ENT_QUOTES, 'UTF-8'); ?></span></td>
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


    <!-- Upcoming ANC Visits (Due this week) -->
    <div class="followup-card tone-warning">
        <div class="followup-header">
            <h4><span class="dash-icon"><i class="fas fa-calendar-week"></i></span> Upcoming ANC Visits</h4>
            <span class="followup-badge"><?php echo count($upcoming_anc); ?> &middot; Scheduled date, or needs scheduling</span>
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
                        <td><span class="<?php echo $patient['days_to_next'] !== null && $patient['days_to_next'] < 0 ? 'urgent-badge' : 'warning-badge'; ?>">
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
                        </span></td>
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
    <div class="followup-card tone-info">
        <div class="followup-header">
            <h4><span class="dash-icon"><i class="fas fa-birthday-cake"></i></span> Expected Deliveries</h4>
            <span class="followup-badge"><?php echo count($upcoming_deliveries); ?> &middot; EDD overdue up to 14 days through next 30 days</span>
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
                        <td><span class="<?php echo $patient['days_to_edd'] <= 7 ? 'urgent-badge' : 'warning-badge'; ?>">
                            <?php echo $patient['days_to_edd'] < 0 ? 'EDD passed by ' . abs((int) $patient['days_to_edd']) . ' days' : (int) $patient['days_to_edd'] . ' days'; ?>
                        </span></td>
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

    <!-- Recent Deliveries (Need PNC Follow-up) -->
    <div class="followup-card tone-success">
        <div class="followup-header">
            <h4><span class="dash-icon"><i class="fas fa-hand-holding-heart"></i></span> Recent Deliveries</h4>
            <span class="followup-badge"><?php echo count($recent_deliveries); ?> &middot; PNC follow-up needed</span>
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

<h3 class="dash-section dash-section-lg"><i class="fas fa-chart-line"></i> Analytics &amp; trends</h3>
<h4 class="dash-subsection"><i class="fas fa-stethoscope"></i> Antenatal care analytics</h4>
<div class="charts-grid">
    <div class="chart-card tone-primary"><h3><span class="dash-icon"><i class="fas fa-chart-line"></i></span> ANC Visits Trend (6 Months)</h3><canvas id="ancTrendChart"></canvas></div>
    <div class="chart-card tone-teal"><h3><span class="dash-icon"><i class="fas fa-calendar-check"></i></span> First ANC Booking Timing</h3><canvas id="bookingChart"></canvas></div>
    <div class="chart-card tone-info"><h3><span class="dash-icon"><i class="fas fa-syringe"></i></span> TT Doses Coverage</h3><canvas id="ttChart"></canvas></div>
    <div class="chart-card tone-purple"><h3><span class="dash-icon"><i class="fas fa-tablets"></i></span> IPTp Doses (Malaria)</h3><canvas id="iptpChart"></canvas></div>
    <div class="chart-card tone-success"><h3><span class="dash-icon"><i class="fas fa-apple-alt"></i></span> Nutrition Status (MUAC)</h3><canvas id="muacChart"></canvas></div>
</div>

<h4 class="dash-subsection"><i class="fas fa-baby-carriage"></i> Delivery &amp; newborn analytics</h4>
<div class="charts-grid">
    <div class="chart-card tone-pink"><h3><span class="dash-icon"><i class="fas fa-chart-pie"></i></span> Delivery Modes</h3><canvas id="deliveryModesChart"></canvas></div>
    <div class="chart-card tone-warning"><h3><span class="dash-icon"><i class="fas fa-weight-scale"></i></span> Birth Weight Classification</h3><canvas id="lbwChart"></canvas></div>
    <div class="chart-card tone-success"><h3><span class="dash-icon"><i class="fas fa-child"></i></span> Postnatal Visit Timing</h3><canvas id="pncTimingChart"></canvas></div>
</div>

<h4 class="dash-subsection"><i class="fas fa-shield-virus"></i> Risk, infection &amp; referral analytics</h4>
<div class="charts-grid">
    <div class="chart-card tone-danger"><h3><span class="dash-icon"><i class="fas fa-exclamation-triangle"></i></span> Top 5 Risk Factors</h3><canvas id="complicationsChart"></canvas></div>
    <div class="chart-card tone-danger"><h3><span class="dash-icon"><i class="fas fa-heartbeat"></i></span> HIV Status Distribution</h3><canvas id="hivChart"></canvas></div>
    <div class="chart-card tone-purple"><h3><span class="dash-icon"><i class="fas fa-shield-virus"></i></span> eMTCT Risk Assessment</h3><canvas id="emtctRiskChart"></canvas></div>
    <div class="chart-card tone-info"><h3><span class="dash-icon"><i class="fas fa-share-from-square"></i></span> Referral Types</h3><canvas id="referralChart"></canvas></div>
</div>

<script src="assets/vendor/chart.umd.js"></script>
<script>
function createClinicalChart(canvasId, configuration) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;

    canvas.setAttribute('aria-hidden', 'true');
    const chart = new Chart(canvas, configuration);
    const summary = document.createElement('div');
    summary.className = 'chart-data-summary';
    summary.setAttribute('role', 'region');
    summary.setAttribute('aria-label', `${canvas.closest('.chart-card')?.querySelector('h3')?.textContent.trim() || 'Chart'} data table`);

    const table = document.createElement('table');
    table.className = 'chart-summary-table';
    const caption = document.createElement('caption');
    caption.textContent = 'Data summary';
    table.append(caption);

    const datasets = configuration.data.datasets || [];
    const headers = ['Category', ...datasets.map(dataset => dataset.label || 'Count')];
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    headers.forEach((label, index) => {
        const cell = document.createElement('th');
        cell.scope = 'col';
        cell.textContent = label;
        headerRow.append(cell);
    });
    thead.append(headerRow);
    table.append(thead);

    const tbody = document.createElement('tbody');
    const labels = configuration.data.labels || [];
    if (labels.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = headers.length;
        cell.textContent = 'No data available';
        row.append(cell);
        tbody.append(row);
    } else {
        labels.forEach((label, rowIndex) => {
            const row = document.createElement('tr');
            const category = document.createElement('th');
            category.scope = 'row';
            category.textContent = String(label ?? 'Not recorded');
            row.append(category);
            datasets.forEach(dataset => {
                const cell = document.createElement('td');
                cell.textContent = String(dataset.data?.[rowIndex] ?? '—');
                row.append(cell);
            });
            tbody.append(row);
        });
    }
    table.append(tbody);
    summary.append(table);
    canvas.insertAdjacentElement('afterend', summary);
    return chart;
}

// Chart 1: ANC Trend
createClinicalChart('ancTrendChart', {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($anc_trend, 'month')); ?>,
        datasets: [{ label: 'ANC visits', data: <?php echo json_encode(array_column($anc_trend, 'visits')); ?>, borderColor: '#1E40AF', backgroundColor: '#1E40AF', fill: false, tension: 0 }]
    },
    options: { responsive: true, maintainAspectRatio: true }
});

// Chart 2: Delivery Modes
createClinicalChart('deliveryModesChart', {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($delivery_modes, 'mode_of_delivery')); ?>, datasets: [{ label: 'Deliveries', data: <?php echo json_encode(array_column($delivery_modes, 'count')); ?>, backgroundColor: ['#1E40AF','#10B981','#F59E0B','#0F766E','#EF4444','#64748B'] }] },
    options: { responsive: true }
});

// Chart 3: MUAC
createClinicalChart('muacChart', {
    type: 'doughnut',
    data: { labels: <?php echo json_encode(array_column($muac_data, 'muac_color')); ?>, datasets: [{ label: 'Records', data: <?php echo json_encode(array_column($muac_data, 'count')); ?>, backgroundColor: ['#EF4444','#F59E0B','#10B981'] }] },
    options: { responsive: true }
});

// Chart 4: Complications
createClinicalChart('complicationsChart', {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($complications, 'complications')); ?>, datasets: [{ label: 'Cases', data: <?php echo json_encode(array_column($complications, 'count')); ?>, backgroundColor: '#F59E0B' }] },
    options: { responsive: true, indexAxis: 'y' }
});

// Chart 5: TT Coverage
createClinicalChart('ttChart', {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($tt_coverage, 'tt_dose_received')); ?>, datasets: [{ label: 'Mothers', data: <?php echo json_encode(array_column($tt_coverage, 'mothers')); ?>, backgroundColor: '#1E40AF' }] },
    options: { responsive: true }
});

// Chart 6: IPTp
createClinicalChart('iptpChart', {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($iptp_data, 'iptp_dose')); ?>, datasets: [{ label: 'Mothers', data: <?php echo json_encode(array_column($iptp_data, 'mothers')); ?>, backgroundColor: '#10B981' }] },
    options: { responsive: true }
});

// Chart 7: Booking Timing
createClinicalChart('bookingChart', {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($booking_timing, 'booking_timing')); ?>, datasets: [{ label: 'Bookings', data: <?php echo json_encode(array_column($booking_timing, 'count')); ?>, backgroundColor: ['#10B981','#EF4444'] }] },
    options: { responsive: true }
});

// Chart 8: HIV Status
createClinicalChart('hivChart', {
    type: 'doughnut',
    data: { labels: <?php echo json_encode(array_column($hiv_status, 'status')); ?>, datasets: [{ label: 'Records', data: <?php echo json_encode(array_column($hiv_status, 'count')); ?>, backgroundColor: ['#10B981','#EF4444','#64748B'] }] },
    options: { responsive: true }
});

// Chart 9: PNC Timing
createClinicalChart('pncTimingChart', {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($pnc_timing, 'visit_timing')); ?>, datasets: [{ label: 'Visits', data: <?php echo json_encode(array_column($pnc_timing, 'visits')); ?>, backgroundColor: '#0F766E' }] },
    options: { responsive: true }
});

// Chart 10: LBW
createClinicalChart('lbwChart', {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($lbw_data, 'category')); ?>, datasets: [{ label: 'Births', data: <?php echo json_encode(array_column($lbw_data, 'count')); ?>, backgroundColor: ['#EF4444','#10B981'] }] },
    options: { responsive: true }
});

// Chart 11: Referral Types
createClinicalChart('referralChart', {
    type: 'bar',
    data: { labels: <?php echo json_encode(array_column($referral_types, 'referral_type')); ?>, datasets: [{ label: 'Referrals', data: <?php echo json_encode(array_column($referral_types, 'count')); ?>, backgroundColor: '#F59E0B' }] },
    options: { responsive: true }
});

// Chart 12: eMTCT Risk
createClinicalChart('emtctRiskChart', {
    type: 'pie',
    data: { labels: <?php echo json_encode(array_column($emtct_risk, 'emtct_risk')); ?>, datasets: [{ label: 'Records', data: <?php echo json_encode(array_column($emtct_risk, 'count')); ?>, backgroundColor: ['#EF4444','#10B981'] }] },
    options: { responsive: true }
});
</script>

<?php require_once 'footer.php'; ?>