<?php
// ============================================
// FILE: search_global.php
// PURPOSE: Advanced Global Search - FULLY WORKING
// ============================================

require_once 'config.php';
requireLogin();

$query = trim($_GET['q'] ?? '');
$search_type = $_GET['type'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$results = [];
$total_results = 0;
$max_category_total = 0;

// Only search if there's a query
if (strlen($query) >= 2) {
    $search_term = "%$query%";
    
    // ============================================
    // PATIENTS SEARCH
    // ============================================
    if ($search_type == 'all' || $search_type == 'patients') {
        // Count query
        $count_sql = "SELECT COUNT(*) as total FROM patients p 
                      WHERE p.surname LIKE ? 
                         OR p.first_name LIKE ? 
                         OR p.nin LIKE ? 
                         OR p.phone LIKE ? 
                         OR p.village LIKE ?";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
        $patient_total = $count_stmt->fetchColumn();
        
        // Data query
        if ($patient_total > 0) {
            $sql = "SELECT 
                        p.id, 
                        CONCAT(p.surname, ' ', p.first_name) as name, 
                        'patient' as type, 
                        p.nin as reference, 
                        p.phone, 
                        p.village, 
                        p.district, 
                        p.age,
                        NULL as client_number,
                        NULL as pregnancy_status,
                        NULL as edd,
                        NULL as visit_date,
                        NULL as diagnosis,
                        NULL as complications,
                        NULL as woman_hiv_code,
                        NULL as gestational_age_weeks,
                        NULL as delivery_datetime,
                        NULL as mode_of_delivery,
                        NULL as baby_sex,
                        NULL as baby_weight_kg,
                        NULL as baby_condition_at_discharge,
                        NULL as ipd_no,
                        NULL as visit_timing,
                        NULL as mother_weight_kg,
                        NULL as dbs_test_result,
                        NULL as patient_id
                    FROM patients p 
                    WHERE p.surname LIKE ? 
                       OR p.first_name LIKE ? 
                       OR p.nin LIKE ? 
                       OR p.phone LIKE ? 
                       OR p.village LIKE ?
                    ORDER BY p.surname ASC
                    LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
            $patient_results = $stmt->fetchAll();
            
            // Get active pregnancy info for each patient
            foreach ($patient_results as &$patient) {
                $ep_sql = "SELECT client_number, status, edd FROM pregnancy_episodes 
                          WHERE patient_id = ? AND status = 'active' LIMIT 1";
                $ep_stmt = $pdo->prepare($ep_sql);
                $ep_stmt->execute([$patient['id']]);
                $episode = $ep_stmt->fetch();
                if ($episode) {
                    $patient['client_number'] = $episode['client_number'];
                    $patient['pregnancy_status'] = $episode['status'];
                    $patient['edd'] = $episode['edd'];
                }
            }
            
            $results = array_merge($results, $patient_results);
            $total_results += $patient_total;
            $max_category_total = max($max_category_total, (int) $patient_total);
        }
    }
    
    // ============================================
    // ANC VISITS SEARCH
    // ============================================
    if ($search_type == 'all' || $search_type == 'anc') {
        $count_sql = "SELECT COUNT(av.id) as total 
                      FROM anc_visits av 
                      INNER JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id 
                      INNER JOIN patients p ON pe.patient_id = p.id 
                      WHERE p.surname LIKE ? 
                         OR p.first_name LIKE ? 
                         OR av.diagnosis LIKE ? 
                         OR av.complications LIKE ? 
                         OR av.woman_hiv_code LIKE ?";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
        $anc_total = $count_stmt->fetchColumn();
        
        if ($anc_total > 0) {
            $sql = "SELECT 
                        av.id, 
                        CONCAT(p.surname, ' ', p.first_name, ' (ANC #', av.visit_number, ')') as name, 
                        'anc' as type, 
                        DATE_FORMAT(av.visit_date, '%d %b %Y') as reference,
                        p.id as patient_id,
                        av.visit_date,
                        av.gestational_age_weeks,
                        av.diagnosis, 
                        av.complications, 
                        av.woman_hiv_code,
                        NULL as nin,
                        NULL as phone,
                        NULL as village,
                        NULL as age,
                        NULL as client_number,
                        NULL as pregnancy_status,
                        NULL as edd,
                        NULL as delivery_datetime,
                        NULL as mode_of_delivery,
                        NULL as baby_sex,
                        NULL as baby_weight_kg,
                        NULL as baby_condition_at_discharge,
                        NULL as ipd_no,
                        NULL as visit_timing,
                        NULL as mother_weight_kg,
                        NULL as dbs_test_result
                    FROM anc_visits av 
                    INNER JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id 
                    INNER JOIN patients p ON pe.patient_id = p.id 
                    WHERE p.surname LIKE ? 
                       OR p.first_name LIKE ? 
                       OR av.diagnosis LIKE ? 
                       OR av.complications LIKE ? 
                       OR av.woman_hiv_code LIKE ?
                    ORDER BY av.visit_date DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$search_term, $search_term, $search_term, $search_term, $search_term]);
            $anc_results = $stmt->fetchAll();
            $results = array_merge($results, $anc_results);
            $total_results += $anc_total;
            $max_category_total = max($max_category_total, (int) $anc_total);
        }
    }
    
    // ============================================
    // DELIVERIES SEARCH
    // ============================================
    if ($search_type == 'all' || $search_type == 'delivery') {
        $count_sql = "SELECT COUNT(dr.id) as total 
                      FROM delivery_records dr 
                      INNER JOIN pregnancy_episodes pe ON dr.pregnancy_episode_id = pe.id 
                      INNER JOIN patients p ON pe.patient_id = p.id 
                      WHERE p.surname LIKE ? 
                         OR p.first_name LIKE ? 
                         OR dr.ipd_no LIKE ? 
                         OR dr.mode_of_delivery LIKE ?";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term]);
        $delivery_total = $count_stmt->fetchColumn();
        
        if ($delivery_total > 0) {
            $sql = "SELECT 
                        dr.id, 
                        CONCAT(p.surname, ' ', p.first_name, ' (Delivery)') as name, 
                        'delivery' as type, 
                        dr.ipd_no as reference,
                        p.id as patient_id,
                        dr.delivery_datetime, 
                        dr.mode_of_delivery, 
                        dr.baby_sex, 
                        dr.baby_weight_kg, 
                        dr.baby_condition_at_discharge,
                        NULL as nin,
                        NULL as phone,
                        NULL as village,
                        NULL as age,
                        NULL as client_number,
                        NULL as pregnancy_status,
                        NULL as edd,
                        NULL as visit_date,
                        NULL as gestational_age_weeks,
                        NULL as diagnosis,
                        NULL as complications,
                        NULL as woman_hiv_code,
                        NULL as visit_timing,
                        NULL as mother_weight_kg,
                        NULL as dbs_test_result
                    FROM delivery_records dr 
                    INNER JOIN pregnancy_episodes pe ON dr.pregnancy_episode_id = pe.id 
                    INNER JOIN patients p ON pe.patient_id = p.id 
                    WHERE p.surname LIKE ? 
                       OR p.first_name LIKE ? 
                       OR dr.ipd_no LIKE ? 
                       OR dr.mode_of_delivery LIKE ?
                    ORDER BY dr.delivery_datetime DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$search_term, $search_term, $search_term, $search_term]);
            $delivery_results = $stmt->fetchAll();
            $results = array_merge($results, $delivery_results);
            $total_results += $delivery_total;
            $max_category_total = max($max_category_total, (int) $delivery_total);
        }
    }
    
    // ============================================
    // POSTNATAL SEARCH
    // ============================================
    if ($search_type == 'all' || $search_type == 'postnatal') {
        $count_sql = "SELECT COUNT(pv.id) as total 
                      FROM postnatal_visits pv 
                      INNER JOIN pregnancy_episodes pe ON pv.pregnancy_episode_id = pe.id 
                      INNER JOIN patients p ON pe.patient_id = p.id 
                      WHERE p.surname LIKE ? 
                         OR p.first_name LIKE ? 
                         OR pv.visit_timing LIKE ? 
                         OR pv.dbs_test_result LIKE ?";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$search_term, $search_term, $search_term, $search_term]);
        $postnatal_total = $count_stmt->fetchColumn();
        
        if ($postnatal_total > 0) {
            $sql = "SELECT 
                        pv.id, 
                        CONCAT(p.surname, ' ', p.first_name, ' (PNC)') as name, 
                        'postnatal' as type, 
                        pv.visit_timing as reference,
                        p.id as patient_id,
                        pv.visit_date, 
                        pv.visit_timing, 
                        pv.mother_weight_kg, 
                        pv.baby_weight_kg, 
                        pv.dbs_test_result,
                        NULL as nin,
                        NULL as phone,
                        NULL as village,
                        NULL as age,
                        NULL as client_number,
                        NULL as pregnancy_status,
                        NULL as edd,
                        NULL as gestational_age_weeks,
                        NULL as diagnosis,
                        NULL as complications,
                        NULL as woman_hiv_code,
                        NULL as delivery_datetime,
                        NULL as mode_of_delivery,
                        NULL as baby_sex,
                        NULL as baby_condition_at_discharge,
                        NULL as ipd_no
                    FROM postnatal_visits pv 
                    INNER JOIN pregnancy_episodes pe ON pv.pregnancy_episode_id = pe.id 
                    INNER JOIN patients p ON pe.patient_id = p.id 
                    WHERE p.surname LIKE ? 
                       OR p.first_name LIKE ? 
                       OR pv.visit_timing LIKE ? 
                       OR pv.dbs_test_result LIKE ?
                    ORDER BY pv.visit_date DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$search_term, $search_term, $search_term, $search_term]);
            $postnatal_results = $stmt->fetchAll();
            $results = array_merge($results, $postnatal_results);
            $total_results += $postnatal_total;
            $max_category_total = max($max_category_total, (int) $postnatal_total);
        }
    }
}

$total_pages = max(1, (int) ceil(($search_type === 'all' ? $max_category_total : $total_results) / $limit));

// Helper functions if not defined in config.php
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (!$date || $date == '0000-00-00') return '—';
        return date('d M Y', strtotime($date));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime) {
        if (!$datetime) return '—';
        return date('d M Y H:i', strtotime($datetime));
    }
}

$rawPatientContextId = $_GET['patient_id'] ?? null;
$patientContextId = is_scalar($rawPatientContextId)
    ? filter_var($rawPatientContextId, FILTER_VALIDATE_INT)
    : false;
$patientContext = $patientContextId !== false && $patientContextId > 0
    ? getPatientContextSummary($pdo, $patientContextId)
    : null;
$contextCloseParams = $_GET;
unset($contextCloseParams['patient_id']);
$contextCloseUrl = 'search_global.php' . ($contextCloseParams ? '?' . http_build_query($contextCloseParams) : '');

require_once 'nav.php';
?>

<!-- Add custom styles that won't interfere with existing system layout -->
<style>
    /* Search page specific styles - scoped to avoid conflicts */
    .search-container {
        padding: 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .search-card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        margin-bottom: 20px;
        border: 1px solid #e2e8f0;
    }
    
    .search-header {
        background: #173c50;
        padding: 20px 24px;
        color: white;
        border-radius: 12px 12px 0 0;
    }
    
    .search-header h2 {
        font-size: 20px;
        margin: 0 0 8px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .search-header p {
        font-size: 13px;
        margin: 0;
        opacity: 0.85;
    }
    
    .search-form {
        padding: 20px 24px;
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .search-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .search-input-group {
        flex: 2;
        min-width: 250px;
    }
    
    .search-input-group input {
        width: 100%;
        padding: 10px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        background: #ffffff;
        color: #1e293b;
        transition: all 0.2s;
    }
    
    .search-input-group input:focus {
        outline: none;
        border-color: #1e88e5;
        box-shadow: 0 0 0 3px rgba(30,136,229,0.1);
    }
    
    .search-type-group {
        min-width: 160px;
    }
    
    .search-type-group select {
        width: 100%;
        padding: 10px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 8px;
        background: #ffffff;
        color: #1e293b;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-search {
        background: #1e88e5;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    
    .btn-search:hover {
        background: #1565c0;
    }
    
    .btn-reset {
        background: #64748b;
    }
    
    .btn-reset:hover {
        background: #475569;
    }
    
    .quick-filters {
        padding: 12px 24px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .quick-filter-label {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
    }
    
    .quick-filter-btn {
        background: none;
        border: 1px solid #e2e8f0;
        padding: 4px 12px;
        border-radius: 20px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.2s;
        color: #1e293b;
    }
    
    .quick-filter-btn:hover {
        background: #1e88e5;
        border-color: #1e88e5;
        color: white;
    }
    
    .results-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        padding: 16px 24px;
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .results-count {
        font-size: 13px;
        color: #64748b;
    }
    
    .results-count strong {
        color: #1e88e5;
        font-size: 16px;
    }
    
    .results-filter-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .filter-chip {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .filter-chip:hover, .filter-chip.active {
        background: #1e88e5;
        border-color: #1e88e5;
        color: white;
    }
    
    .results-grid {
        padding: 20px 24px;
        background: #ffffff;
    }
    
    .result-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
    }
    
    .result-card:hover {
        transform: translateX(4px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border-color: #1e88e5;
    }
    
    .result-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    
    .result-name {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
    }
    
    .result-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .badge-patient { background: #e3f2fd; color: #1565c0; }
    .badge-anc { background: #e8f5e9; color: #2e7d64; }
    .badge-delivery { background: #fff3e0; color: #e65100; }
    .badge-postnatal { background: #f3e5f5; color: #7b1fa2; }
    
    .result-details {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin: 12px 0;
        font-size: 12px;
    }
    
    .result-detail {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #64748b;
    }
    
    .result-detail i {
        width: 14px;
        font-size: 12px;
        color: #1e88e5;
    }
    
    .result-action {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 12px;
    }
    
    .action-link {
        background: #1e88e5;
        color: white;
        text-decoration: none;
        padding: 5px 14px;
        border-radius: 6px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    
    .action-link:hover {
        background: #1565c0;
        text-decoration: none;
        color: white;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        padding: 20px 24px;
        border-top: 1px solid #e2e8f0;
        flex-wrap: wrap;
        background: #ffffff;
    }
    
    .pagination a, .pagination span {
        padding: 6px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: #1e293b;
        border: 1px solid #e2e8f0;
        font-size: 13px;
        transition: all 0.2s;
    }
    
    .pagination a:hover {
        background: #1e88e5;
        border-color: #1e88e5;
        color: white;
    }
    
    .pagination .active {
        background: #1e88e5;
        color: white;
        border-color: #1e88e5;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 24px;
        color: #64748b;
        background: #ffffff;
    }
    
    .empty-state i {
        font-size: 48px;
        opacity: 0.3;
        margin-bottom: 16px;
    }
    
    .empty-state h3 {
        margin-bottom: 8px;
        font-size: 18px;
        font-weight: 600;
    }
    
    .search-tips {
        background: #f0f9ff;
        padding: 16px 24px;
        border-radius: 0 0 12px 12px;
        border-top: 1px solid #e2e8f0;
    }
    
    .search-tips ul {
        margin-top: 8px;
        margin-left: 20px;
        font-size: 12px;
        color: #64748b;
    }
    
    .search-tips li {
        margin: 4px 0;
    }
    
    @media (max-width: 768px) {
        .search-container {
            padding: 12px;
        }
        .search-header, .search-form, .quick-filters, .results-header, .results-grid, .pagination {
            padding-left: 16px;
            padding-right: 16px;
        }
        .result-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }
        .result-details {
            flex-direction: column;
            gap: 8px;
        }
        .search-row {
            flex-direction: column;
        }
        .search-input-group, .search-type-group, .btn-search {
            width: 100%;
        }
    }
</style>

<div class="search-container<?php echo $patientContext ? ' has-context' : ''; ?>">
    <div class="search-card">
        <div class="search-header">
            <h2><i class="fas fa-search"></i> Advanced Global Search</h2>
            <p>Search across patients, ANC visits, deliveries, and postnatal records</p>
        </div>
        
        <div class="search-form">
            <form method="GET" action="">
                <div class="search-row">
                    <div class="search-input-group">
                        <input type="text" name="q" placeholder="Search by name, NIN, phone, diagnosis, IPD number, village..." 
                               value="<?php echo htmlspecialchars($query); ?>" autofocus>
                    </div>
                    <div class="search-type-group">
                        <select name="type">
                            <option value="all" <?php echo $search_type == 'all' ? 'selected' : ''; ?>>📁 All Modules</option>
                            <option value="patients" <?php echo $search_type == 'patients' ? 'selected' : ''; ?>>👤 Patients Only</option>
                            <option value="anc" <?php echo $search_type == 'anc' ? 'selected' : ''; ?>>📋 ANC Visits</option>
                            <option value="delivery" <?php echo $search_type == 'delivery' ? 'selected' : ''; ?>>🤱 Deliveries</option>
                            <option value="postnatal" <?php echo $search_type == 'postnatal' ? 'selected' : ''; ?>>👶 Postnatal</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($query)): ?>
                        <a href="search_global.php" class="btn-search btn-reset"><i class="fas fa-undo"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <div class="quick-filters">
            <span class="quick-filter-label"><i class="fas fa-bolt"></i> Quick Filters:</span>
            <button class="quick-filter-btn" onclick="quickSearch('')">All Records</button>
            <button class="quick-filter-btn" onclick="quickSearch('NABUKENYA')">NABUKENYA</button>
            <button class="quick-filter-btn" onclick="quickSearch('077')">Phone: 077</button>
            <button class="quick-filter-btn" onclick="quickSearch('KAMPALA')">KAMPALA</button>
            <button class="quick-filter-btn" onclick="quickSearch('TR')">HIV: TR</button>
        </div>
        
        <?php if (!empty($query)): ?>
            <div class="results-header">
                <div class="results-count">
                    <i class="fas fa-chart-column"></i> Found <strong><?php echo number_format($total_results); ?></strong> result(s) for 
                    "<strong><?php echo htmlspecialchars($query); ?></strong>"
                </div>
                <div class="results-filter-buttons">
                    <span style="font-size: 11px; color: #64748b;">Filter results:</span>
                    <button class="filter-chip" onclick="filterResults('all')">All</button>
                    <button class="filter-chip" onclick="filterResults('patient')">Patients</button>
                    <button class="filter-chip" onclick="filterResults('anc')">ANC</button>
                    <button class="filter-chip" onclick="filterResults('delivery')">Delivery</button>
                    <button class="filter-chip" onclick="filterResults('postnatal')">Postnatal</button>
                </div>
            </div>
            
            <div class="results-grid" id="resultsGrid">
                <?php if (count($results) > 0): ?>
                    <?php foreach ($results as $result): ?>
                        <div class="result-card" data-type="<?php echo $result['type']; ?>">
                            <div class="result-header">
                                <div class="result-name">
                                    <?php echo htmlspecialchars($result['name']); ?>
                                </div>
                                <div class="result-type-badge badge-<?php echo $result['type']; ?>">
                                    <i class="fas <?php 
                                        echo $result['type'] == 'patient' ? 'fa-user' : 
                                            ($result['type'] == 'anc' ? 'fa-notes-medical' : 
                                            ($result['type'] == 'delivery' ? 'fa-birthday-cake' : 'fa-hand-holding-heart')); 
                                    ?>"></i>
                                    <?php 
                                        if ($result['type'] == 'patient') echo 'PATIENT';
                                        elseif ($result['type'] == 'anc') echo 'ANC VISIT';
                                        elseif ($result['type'] == 'delivery') echo 'DELIVERY';
                                        else echo 'POSTNATAL';
                                    ?>
                                </div>
                            </div>
                            
                            <div class="result-details">
                                <?php if ($result['type'] == 'patient'): ?>
                                    <div class="result-detail"><i class="fas fa-id-card"></i> NIN: <?php echo htmlspecialchars($result['reference'] ?? '—'); ?></div>
                                    <div class="result-detail"><i class="fas fa-phone"></i> Phone: <?php echo htmlspecialchars($result['phone'] ?? '—'); ?></div>
                                    <div class="result-detail"><i class="fas fa-map-marker-alt"></i> Village: <?php echo htmlspecialchars($result['village'] ?? '—'); ?></div>
                                    <div class="result-detail"><i class="fas fa-calendar"></i> Age: <?php echo $result['age'] ?? '—'; ?> years</div>
                                    <?php if (!empty($result['client_number'])): ?>
                                        <div class="result-detail"><i class="fas fa-hashtag"></i> Client: <?php echo htmlspecialchars($result['client_number']); ?></div>
                                    <?php endif; ?>
                                    <?php if (($result['pregnancy_status'] ?? '') == 'active' && !empty($result['edd'])): ?>
                                        <div class="result-detail"><i class="fas fa-baby"></i> EDD: <?php echo formatDate($result['edd']); ?></div>
                                    <?php endif; ?>
                                    
                                <?php elseif ($result['type'] == 'anc'): ?>
                                    <div class="result-detail"><i class="fas fa-calendar-check"></i> Visit Date: <?php echo formatDate($result['visit_date']); ?></div>
                                    <div class="result-detail"><i class="fas fa-chart-line"></i> GA: <?php echo $result['gestational_age_weeks'] ?? '—'; ?> weeks</div>
                                    <div class="result-detail"><i class="fas fa-stethoscope"></i> Diagnosis: <?php echo htmlspecialchars(substr($result['diagnosis'] ?? '—', 0, 35)); ?></div>
                                    <?php if (!empty($result['woman_hiv_code'])): ?>
                                        <div class="result-detail"><i class="fas fa-heartbeat"></i> HIV Code: <?php echo htmlspecialchars($result['woman_hiv_code']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($result['complications']) && $result['complications'] != 'Nil' && $result['complications'] != 'None'): ?>
                                        <div class="result-detail"><i class="fas fa-exclamation-triangle" style="color: #e65100;"></i> Risk: <?php echo htmlspecialchars(substr($result['complications'], 0, 30)); ?></div>
                                    <?php endif; ?>
                                    
                                <?php elseif ($result['type'] == 'delivery'): ?>
                                    <div class="result-detail"><i class="fas fa-calendar-check"></i> Delivery Date: <?php echo formatDateTime($result['delivery_datetime']); ?></div>
                                    <div class="result-detail"><i class="fas fa-procedures"></i> Mode: <?php echo str_replace('_', ' ', $result['mode_of_delivery'] ?? '—'); ?></div>
                                    <div class="result-detail"><i class="fas fa-baby"></i> Baby: <?php echo ($result['baby_sex'] ?? '') == 'M' ? 'Male' : 'Female'; ?>, <?php echo $result['baby_weight_kg'] ?? '—'; ?> kg</div>
                                    <div class="result-detail"><i class="fas fa-tag"></i> IPD: <?php echo htmlspecialchars($result['reference'] ?? '—'); ?></div>
                                    
                                <?php elseif ($result['type'] == 'postnatal'): ?>
                                    <div class="result-detail"><i class="fas fa-calendar-check"></i> Visit Date: <?php echo formatDate($result['visit_date']); ?></div>
                                    <div class="result-detail"><i class="fas fa-clock"></i> Timing: <?php echo str_replace('_', ' ', $result['visit_timing'] ?? '—'); ?></div>
                                    <div class="result-detail"><i class="fas fa-weight-scale"></i> Baby Weight: <?php echo $result['baby_weight_kg'] ?? '—'; ?> kg</div>
                                    <div class="result-detail"><i class="fas fa-flask"></i> DBS: <?php echo ucfirst($result['dbs_test_result'] ?? 'pending'); ?></div>
                                    <div class="result-detail"><i class="fas fa-female"></i> Mother Weight: <?php echo $result['mother_weight_kg'] ?? '—'; ?> kg</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="result-action">
                                <?php if ($result['type'] == 'patient'): ?>
                                    <?php $previewParams = array_merge($_GET, ['patient_id' => (int) $result['id']]); ?>
                                    <a href="search_global.php?<?php echo htmlspecialchars(http_build_query($previewParams), ENT_QUOTES, 'UTF-8'); ?>" class="action-link context-action">
                                        <i class="fas fa-id-card"></i> Quick context
                                    </a>
                                    <a href="patient_profile.php?id=<?php echo $result['id']; ?>" class="action-link">
                                        <i class="fas fa-eye"></i> View Full Profile
                                    </a>
                                    <?php if (!empty($result['phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($result['phone']); ?>" class="action-link" style="background: #2e7d64;">
                                            <i class="fas fa-phone"></i> Call
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="patient_profile.php?id=<?php echo $result['patient_id']; ?>" class="action-link">
                                        <i class="fas fa-eye"></i> View Patient Record
                                    </a>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No results found</h3>
                        <p>We couldn't find any matches for "<strong><?php echo htmlspecialchars($query); ?></strong>"</p>
                        <p style="font-size: 13px; margin-top: 8px;">Try checking your spelling or using different keywords</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $search_type; ?>&page=<?php echo $page-1; ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>
                
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $search_type; ?>&page=<?php echo $i; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $search_type; ?>&page=<?php echo $page+1; ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="search-tips">
                <i class="fas fa-lightbulb" style="color: #1565c0;"></i> <strong>Search Tips:</strong>
                <ul>
                    <li>Search by <strong>patient name</strong> (first or last) - e.g., "NABUKENYA" or "JANE"</li>
                    <li>Search by <strong>National ID (NIN)</strong> - e.g., "CM12345678"</li>
                    <li>Search by <strong>phone number</strong> - e.g., "0775668896"</li>
                    <li>Search by <strong>village or district</strong> - e.g., "KIBOWA" or "KAMPALA"</li>
                    <li>Search by <strong>diagnosis</strong> - e.g., "Normal" or "Abdominal Disorder"</li>
                    <li>Search by <strong>client number</strong> - e.g., "001/2026"</li>
                </ul>
            </div>
            
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h3>Start Searching</h3>
                <p>Enter a search term above to find patients, ANC visits, deliveries, or postnatal records</p>
                <div style="margin-top: 20px; display: flex; flex-wrap: wrap; justify-content: center; gap: 10px;">
                    <button class="quick-filter-btn" onclick="quickSearch('NABUKENYA')">🔍 NABUKENYA</button>
                    <button class="quick-filter-btn" onclick="quickSearch('077')">🔍 077</button>
                    <button class="quick-filter-btn" onclick="quickSearch('Abdominal')">🔍 Abdominal</button>
                    <button class="quick-filter-btn" onclick="quickSearch('001/2026')">🔍 001/2026</button>
                </div>
            </div>
            
            <div class="search-tips">
                <i class="fas fa-lightbulb" style="color: #1565c0;"></i> <strong>What can you search for?</strong>
                <ul>
                    <li><strong>Patients:</strong> Name, NIN, Phone number, Village, District, Client number</li>
                    <li><strong>ANC Visits:</strong> Patient name, Diagnosis, Complications, HIV codes</li>
                    <li><strong>Deliveries:</strong> Patient name, IPD number, Mode of delivery</li>
                    <li><strong>Postnatal:</strong> Patient name, Visit timing, DBS results</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($patientContext): ?>
        <aside class="patient-context" aria-labelledby="patient-context-title">
            <div class="patient-context-header">
                <div>
                    <p class="eyebrow">Selected patient</p>
                    <h2 id="patient-context-title"><?php echo htmlspecialchars($patientContext['surname'] . ' ' . $patientContext['first_name']); ?></h2>
                    <p><?php echo htmlspecialchars($patientContext['client_number'] ?? 'No client number'); ?></p>
                </div>
                <a class="context-close" href="<?php echo htmlspecialchars($contextCloseUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Close patient context" title="Close patient context"><i class="fas fa-times"></i></a>
            </div>
            <dl class="patient-context-details">
                <div><dt>Age</dt><dd><?php echo htmlspecialchars((string) ($patientContext['age'] ?? 'Not recorded')); ?><?php echo $patientContext['age'] !== null ? ' years' : ''; ?></dd></div>
                <div><dt>Phone</dt><dd><?php echo htmlspecialchars($patientContext['phone'] ?? 'Not recorded'); ?></dd></div>
                <div><dt>Location</dt><dd><?php echo htmlspecialchars(implode(', ', array_filter([$patientContext['village'] ?? '', $patientContext['district'] ?? ''])) ?: 'Not recorded'); ?></dd></div>
                <div><dt>Pregnancy</dt><dd><?php echo htmlspecialchars(ucfirst($patientContext['pregnancy_status'] ?? 'No recorded pregnancy')); ?></dd></div>
                <?php if ($patientContext['episode_id']): ?>
                    <div><dt>Gravida / parity</dt><dd><?php echo htmlspecialchars(($patientContext['gravida'] ?? '—') . ' / ' . ($patientContext['parity'] ?? '—')); ?></dd></div>
                    <div><dt>LMP</dt><dd><?php echo htmlspecialchars(formatDate($patientContext['lmp'])); ?></dd></div>
                    <div><dt>Estimated delivery</dt><dd><?php echo htmlspecialchars(formatDate($patientContext['edd'])); ?></dd></div>
                    <div><dt>ANC visits</dt><dd><?php echo number_format((int) $patientContext['anc_visit_count']); ?><?php if ($patientContext['last_anc_date']): ?> · Last: <?php echo htmlspecialchars(formatDate($patientContext['last_anc_date'])); ?><?php endif; ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="patient-context-actions">
                <a class="btn btn-primary" href="patient_profile.php?id=<?php echo (int) $patientContext['id']; ?>"><i class="fas fa-user"></i> Open full profile</a>
                <?php if (!empty($patientContext['episode_id']) && $patientContext['pregnancy_status'] === 'active'): ?>
                    <a class="btn btn-secondary" href="anc_visit.php?episode_id=<?php echo (int) $patientContext['episode_id']; ?>"><i class="fas fa-notes-medical"></i> Record ANC visit</a>
                <?php endif; ?>
            </div>
        </aside>
        <a class="patient-context-backdrop" href="<?php echo htmlspecialchars($contextCloseUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Close patient context"></a>
    <?php endif; ?>
</div>

<script>
    function quickSearch(term) {
        const input = document.querySelector('input[name="q"]');
        if (input) {
            input.value = term;
            document.querySelector('form').submit();
        }
    }
    
    function filterResults(type) {
        const cards = document.querySelectorAll('.result-card');
        cards.forEach(card => {
            if (type === 'all') {
                card.style.display = 'block';
            } else {
                if (card.dataset.type === type) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            }
        });
        
        const chips = document.querySelectorAll('.filter-chip');
        chips.forEach(chip => {
            chip.classList.remove('active');
            if ((type === 'all' && chip.innerText.trim() === 'All') || 
                (chip.innerText.toLowerCase() === type)) {
                chip.classList.add('active');
            }
        });
    }
    
    // Initialize filter chips if there are results
    document.addEventListener('DOMContentLoaded', function() {
        const activeFilter = document.querySelector('.filter-chip.active');
        if (!activeFilter && document.querySelector('.filter-chip')) {
            document.querySelector('.filter-chip').classList.add('active');
        }
    });
</script>

<?php require_once 'footer.php'; ?>