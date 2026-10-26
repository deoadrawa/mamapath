<?php
// ============================================
// FILE: patients.php
// PURPOSE: Complete patient registry - ONLY TABLE SCROLLS
// Filter bar, stats, pagination stay fixed
// ============================================

require_once 'config.php';
require_once 'nav.php';
requireLogin();

// Handle search and filters
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE clauses
$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(p.surname LIKE :search OR p.first_name LIKE :search OR p.nin LIKE :search OR p.phone LIKE :search OR pe.client_number LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($filter_status === 'active') {
    $where_clauses[] = "pe.status = 'active'";
} elseif ($filter_status === 'no_pregnancy') {
    $where_clauses[] = "pe.id IS NULL";
} elseif ($filter_status === 'overdue') {
    $where_clauses[] = "pe.status = 'active'";
    $where_clauses[] = "(SELECT MAX(visit_date) FROM anc_visits WHERE pregnancy_episode_id = pe.id) < DATE_SUB(CURDATE(), INTERVAL 35 DAY) OR (SELECT MAX(visit_date) FROM anc_visits WHERE pregnancy_episode_id = pe.id) IS NULL";
}

$where_sql = implode(" AND ", $where_clauses);

// Count total
$count_sql = "SELECT COUNT(DISTINCT p.id) as total 
              FROM patients p 
              LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active'
              WHERE $where_sql";
$stmt = $pdo->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_patients = $stmt->fetch()['total'];
$total_pages = ceil($total_patients / $limit);

// Build ORDER BY
$order_by = match($sort_by) {
    'name' => "p.surname $sort_order, p.first_name $sort_order",
    'client' => "pe.client_number $sort_order",
    'edd' => "pe.edd $sort_order",
    'last_anc' => "last_anc_date $sort_order",
    default => "p.created_at DESC"
};

// Fetch patients with all needed data
$sql = "SELECT 
            p.id,
            p.surname,
            p.first_name,
            p.age,
            p.nin,
            p.phone,
            p.village,
            p.district,
            pe.id as episode_id,
            pe.client_number,
            pe.status as pregnancy_status,
            pe.edd,
            pe.gravida,
            pe.parity,
            pe.lmp,
            (SELECT MAX(visit_date) FROM anc_visits WHERE pregnancy_episode_id = pe.id) as last_anc_date,
            (SELECT MAX(visit_number) FROM anc_visits WHERE pregnancy_episode_id = pe.id) as last_visit_number,
            (SELECT COUNT(*) FROM anc_visits WHERE pregnancy_episode_id = pe.id) as anc_count,
            (SELECT DATE_ADD(MAX(visit_date), INTERVAL 28 DAY) FROM anc_visits WHERE pregnancy_episode_id = pe.id) as next_anc_date,
            (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM anc_visits WHERE pregnancy_episode_id = pe.id AND complications IS NOT NULL AND complications != 'Nil' AND complications != '') as has_risk
        FROM patients p 
        LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active'
        WHERE $where_sql
        ORDER BY $order_by
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$patients = $stmt->fetchAll();

// Calculate summary stats
$active_count = 0;
$overdue_count = 0;
$delivering_soon_count = 0;

foreach ($patients as $p) {
    if ($p['pregnancy_status'] == 'active') {
        $active_count++;
        if ($p['edd']) {
            $days_to_edd = (strtotime($p['edd']) - strtotime(date('Y-m-d'))) / 86400;
            if ($days_to_edd >= 0 && $days_to_edd <= 14) {
                $delivering_soon_count++;
            }
        }
        if ($p['next_anc_date']) {
            $days_overdue = (strtotime(date('Y-m-d')) - strtotime($p['next_anc_date'])) / 86400;
            if ($days_overdue > 0) {
                $overdue_count++;
            }
        }
    }
}
?>

<style>
    /* ============================================ */
    /* PAGE CONTAINER - FIXED LAYOUT */
    /* ============================================ */
    .patients-container {
        background: var(--card-bg);
        border-radius: 20px;
        border: 1px solid var(--border);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    
    /* ============================================ */
    /* FILTER BAR - FIXED (NO SCROLL) */
    /* ============================================ */
    .filter-bar {
        padding: 20px 24px;
        background: linear-gradient(135deg, #0a2b44 0%, #0d3b5e 100%);
        border-bottom: 1px solid rgba(255,255,255,0.1);
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: flex-end;
        flex-shrink: 0;
        border-radius: 20px 20px 0 0;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-group label {
        font-size: 11px;
        font-weight: 600;
        color: rgba(255,255,255,0.7);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .filter-group input,
    .filter-group select {
        padding: 8px 12px;
        border: none;
        border-radius: 10px;
        background: rgba(255,255,255,0.15);
        color: white;
        font-size: 13px;
        min-width: 160px;
    }
    
    .filter-group input::placeholder {
        color: rgba(255,255,255,0.5);
    }
    
    .filter-group option {
        background: #0d3b5e;
        color: white;
    }
    
    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        background: rgba(255,255,255,0.25);
    }
    
    .btn {
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }
    
    .btn-primary {
        background: #1e88e5;
        color: white;
    }
    
    .btn-primary:hover {
        background: #1565c0;
        transform: translateY(-1px);
    }
    
    .btn-secondary {
        background: #64748b;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #475569;
    }
    
    .btn-success {
        background: #2e7d64;
        color: white;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-success:hover {
        background: #1b5e4a;
        transform: translateY(-1px);
    }
    
    /* ============================================ */
    /* STATS ROW - FIXED (NO SCROLL) */
    /* ============================================ */
    .stats-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 16px 24px;
        background: var(--card-bg);
        border-bottom: 1px solid var(--border);
        flex-shrink: 0;
    }
    
    .stat-badge {
        background: var(--bg-body);
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid var(--border);
    }
    
    .stat-badge i {
        font-size: 14px;
    }
    
    .stat-badge .total { color: #1e88e5; }
    .stat-badge .active { color: #2e7d64; }
    .stat-badge .overdue { color: #c62828; }
    .stat-badge .delivering { color: #e65100; }
    
    /* ============================================ */
    /* TABLE WRAPPER - ONLY THIS SCROLLS */
    /* ============================================ */
    .table-wrapper {
        overflow-x: auto;
        overflow-y: auto;
        max-height: calc(100vh - 320px);
        min-height: 400px;
        position: relative;
        flex: 1;
    }
    
    /* Custom scrollbar styling */
    .table-wrapper::-webkit-scrollbar {
        height: 8px;
        width: 8px;
    }
    
    .table-wrapper::-webkit-scrollbar-track {
        background: var(--border);
        border-radius: 10px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb {
        background: var(--primary);
        border-radius: 10px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb:hover {
        background: var(--primary-light);
    }
    
    /* ============================================ */
    /* PATIENT TABLE */
    /* ============================================ */
    .patient-table {
        min-width: 1400px;
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        table-layout: auto;
    }
    
    .patient-table th {
        text-align: left;
        padding: 14px 12px;
        background: #1a2a3a;
        font-weight: 600;
        font-size: 12px;
        color: white;
        border-bottom: 2px solid #2d4a6e;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .patient-table th i {
        margin-left: 5px;
        font-size: 10px;
    }
    
    .patient-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
        white-space: nowrap;
    }
    
    .patient-table tr:hover {
        background: var(--bg-body);
    }
    
    /* Column widths */
    .patient-table th:nth-child(1) { min-width: 110px; }
    .patient-table th:nth-child(2) { min-width: 200px; }
    .patient-table th:nth-child(3) { min-width: 50px; }
    .patient-table th:nth-child(4) { min-width: 60px; }
    .patient-table th:nth-child(5) { min-width: 160px; }
    .patient-table th:nth-child(6) { min-width: 120px; }
    .patient-table th:nth-child(7) { min-width: 130px; }
    .patient-table th:nth-child(8) { min-width: 130px; }
    .patient-table th:nth-child(9) { min-width: 130px; }
    .patient-table th:nth-child(10) { min-width: 100px; }
    .patient-table th:nth-child(11) { min-width: 160px; }
    
    /* ============================================ */
    /* BADGES */
    /* ============================================ */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
    }
    
    .badge-active {
        background: #e8f5e9;
        color: #2e7d64;
    }
    
    .badge-active i {
        color: #2e7d64;
    }
    
    .badge-inactive {
        background: #f1f5f9;
        color: #64748b;
    }
    
    .badge-urgent {
        background: #ffebee;
        color: #c62828;
    }
    
    .badge-urgent i {
        color: #c62828;
    }
    
    .badge-warning {
        background: #fff3e0;
        color: #e65100;
    }
    
    .badge-warning i {
        color: #e65100;
    }
    
    .badge-success {
        background: #e8f5e9;
        color: #2e7d64;
    }
    
    .badge-info {
        background: #e3f2fd;
        color: #1565c0;
    }
    
    .badge-risk {
        background: #ffebee;
        color: #c62828;
    }
    
    .badge-risk i {
        color: #c62828;
    }
    
    .badge-lowrisk {
        background: #e8f5e9;
        color: #2e7d64;
    }
    
    /* ============================================ */
    /* ACTION BUTTONS */
    /* ============================================ */
    .action-group {
        display: flex;
        gap: 6px;
        flex-wrap: nowrap;
    }
    
    .action-icon {
        width: 30px;
        height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--card-bg);
        color: var(--text-secondary);
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .action-icon:hover {
        background: #1e88e5;
        border-color: #1e88e5;
        color: white;
        transform: translateY(-2px);
    }
    
    /* ============================================ */
    /* PAGINATION - FIXED (NO SCROLL) */
    /* ============================================ */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 6px;
        padding: 16px 24px;
        border-top: 1px solid var(--border);
        flex-wrap: wrap;
        flex-shrink: 0;
        background: var(--card-bg);
    }
    
    .pagination a, .pagination span {
        padding: 6px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-primary);
        border: 1px solid var(--border);
        font-size: 12px;
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
    
    /* ============================================ */
    /* SORTABLE HEADER */
    /* ============================================ */
    .sortable {
        cursor: pointer;
        user-select: none;
        transition: color 0.2s;
    }
    
    .sortable:hover {
        color: #90caf9 !important;
    }
    
    /* ============================================ */
    /* EMPTY STATE */
    /* ============================================ */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-secondary);
    }
    
    .empty-state i {
        font-size: 48px;
        opacity: 0.3;
        margin-bottom: 16px;
    }
    
    /* ============================================ */
    /* SUBTEXT STYLING */
    /* ============================================ */
    .subtext {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 2px;
        white-space: nowrap;
    }
    
    .text-muted {
        color: var(--text-muted);
        font-size: 11px;
    }
    
    /* ============================================ */
    /* RESPONSIVE */
    /* ============================================ */
    @media (max-width: 768px) {
        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
        }
        .stats-row {
            flex-wrap: wrap;
        }
        .table-wrapper {
            max-height: calc(100vh - 400px);
        }
    }
</style>

<!-- FILTER BAR - FIXED (NO SCROLL) -->
<div class="patients-container">
    <div class="filter-bar">
        <form method="GET" style="display: contents;">
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" placeholder="Name, NIN, Client #, Phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-filter"></i> Status</label>
                <select name="status">
                    <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Patients</option>
                    <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active Pregnancy</option>
                    <option value="overdue" <?php echo $filter_status == 'overdue' ? 'selected' : ''; ?>>Overdue ANC Visit</option>
                    <option value="no_pregnancy" <?php echo $filter_status == 'no_pregnancy' ? 'selected' : ''; ?>>No Active Pregnancy</option>
                </select>
            </div>
            <input type="hidden" name="sort" value="<?php echo $sort_by; ?>">
            <input type="hidden" name="order" value="<?php echo $sort_order; ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
            <a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            <a href="register_patient.php" class="btn btn-success" style="margin-left: auto;"><i class="fas fa-plus"></i> New Patient</a>
        </form>
    </div>
    
    <!-- STATS ROW - FIXED (NO SCROLL) -->
    <div class="stats-row">
        <span class="stat-badge"><i class="fas fa-users" style="color: #1e88e5;"></i> Total: <?php echo $total_patients; ?></span>
        <span class="stat-badge"><i class="fas fa-fetus" style="color: #2e7d64;"></i> Active: <?php echo $active_count; ?></span>
        <span class="stat-badge"><i class="fas fa-exclamation-triangle" style="color: #c62828;"></i> Overdue ANC: <?php echo $overdue_count; ?></span>
        <span class="stat-badge"><i class="fas fa-birthday-cake" style="color: #e65100;"></i> Due ≤14d: <?php echo $delivering_soon_count; ?></span>
    </div>
    
    <!-- TABLE WRAPPER - ONLY THIS SCROLLS (HORIZONTAL & VERTICAL) -->
    <div class="table-wrapper">
        <table class="patient-table">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortBy('client')">
                        Client # 
                        <?php if ($sort_by == 'client'): ?>
                            <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                        <?php else: ?>
                            <i class="fas fa-sort"></i>
                        <?php endif; ?>
                    </th>
                    <th class="sortable" onclick="sortBy('name')">
                        Patient Name
                        <?php if ($sort_by == 'name'): ?>
                            <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                        <?php else: ?>
                            <i class="fas fa-sort"></i>
                        <?php endif; ?>
                    </th>
                    <th>Age</th>
                    <th>G/P</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th class="sortable" onclick="sortBy('last_anc')">
                        Last ANC
                        <?php if ($sort_by == 'last_anc'): ?>
                            <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                        <?php else: ?>
                            <i class="fas fa-sort"></i>
                        <?php endif; ?>
                    </th>
                    <th>Next ANC</th>
                    <th class="sortable" onclick="sortBy('edd')">
                        EDD
                        <?php if ($sort_by == 'edd'): ?>
                            <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                        <?php else: ?>
                            <i class="fas fa-sort"></i>
                        <?php endif; ?>
                    </th>
                    <th>Risk</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($patients) > 0): ?>
                    <?php foreach ($patients as $patient): 
                        $is_active = ($patient['pregnancy_status'] == 'active');
                        
                        // Calculate next ANC status
                        $next_anc_label = '';
                        $next_anc_class = '';
                        if ($is_active && $patient['next_anc_date']) {
                            $days = (strtotime($patient['next_anc_date']) - strtotime(date('Y-m-d'))) / 86400;
                            $next_anc_full_date = date('d M Y', strtotime($patient['next_anc_date']));
                            if ($days < 0) {
                                $next_anc_class = 'badge-urgent';
                                $next_anc_label = '<i class="fas fa-clock"></i> ' . abs(round($days)) . ' days overdue';
                            } elseif ($days <= 7) {
                                $next_anc_class = 'badge-warning';
                                $next_anc_label = '<i class="fas fa-hourglass-half"></i> Due in ' . round($days) . ' days';
                            } else {
                                $next_anc_class = 'badge-info';
                                $next_anc_label = '<i class="fas fa-calendar"></i> ' . $next_anc_full_date;
                            }
                        } elseif ($is_active) {
                            $next_anc_class = 'badge-warning';
                            $next_anc_label = '<i class="fas fa-exclamation-circle"></i> Schedule first visit';
                        } else {
                            $next_anc_label = '—';
                        }
                        
                        // Calculate EDD status
                        $edd_label = '';
                        $edd_class = '';
                        if ($is_active && $patient['edd']) {
                            $days = (strtotime($patient['edd']) - strtotime(date('Y-m-d'))) / 86400;
                            $edd_full_date = date('d M Y', strtotime($patient['edd']));
                            if ($days < 0) {
                                $edd_class = 'badge-urgent';
                                $edd_label = '<i class="fas fa-exclamation-triangle"></i> Overdue by ' . abs(round($days)) . ' days';
                            } elseif ($days <= 7) {
                                $edd_class = 'badge-urgent';
                                $edd_label = '<i class="fas fa-bell"></i> Due in ' . round($days) . ' days';
                            } elseif ($days <= 30) {
                                $edd_class = 'badge-warning';
                                $edd_label = '<i class="fas fa-hourglass-half"></i> Due in ' . round($days) . ' days';
                            } else {
                                $edd_class = 'badge-info';
                                $edd_label = '<i class="fas fa-calendar"></i> ' . $edd_full_date;
                            }
                        } elseif ($is_active) {
                            $edd_label = '<i class="fas fa-edit"></i> Not calculated';
                        } else {
                            $edd_label = '—';
                        }
                        
                        // Format last ANC date
                        $last_anc_display = '—';
                        if ($patient['last_anc_date']) {
                            $last_anc_display = date('d M Y', strtotime($patient['last_anc_date']));
                            if ($patient['last_visit_number']) {
                                $last_anc_display .= ' <span class="text-muted">(#' . $patient['last_visit_number'] . ')</span>';
                            }
                        }
                    ?>
                    <tr style="<?php echo ($is_active && $patient['has_risk']) ? 'border-left: 3px solid #c62828;' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($patient['client_number'] ?? '—'); ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']); ?></strong>
                            <div class="subtext"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($patient['nin'] ?? '—'); ?></div>
                        </td>
                        <td><?php echo $patient['age'] ?? '—'; ?> <span class="text-muted">yrs</span></td>
                        <td><span class="badge badge-info" style="background: #e3f2fd; padding: 2px 8px;"><?php echo ($patient['gravida'] ?? '0') . ' / ' . ($patient['parity'] ?? '0'); ?></span></td>
                        <td>
                            <?php if (!empty($patient['phone'])): ?>
                                <i class="fas fa-phone" style="color: #2e7d64;"></i> <?php echo htmlspecialchars($patient['phone']); ?>
                                <div class="subtext"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($patient['village'] ?? ''); ?></div>
                            <?php else: ?>
                                —
                                <?php if (!empty($patient['village'])): ?>
                                    <div class="subtext"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($patient['village']); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_active): ?>
                                <span class="badge badge-active"><i class="fas fa-circle"></i> Active Pregnancy</span>
                            <?php else: ?>
                                <span class="badge badge-inactive"><i class="fas fa-circle"></i> No Pregnancy</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $last_anc_display; ?></td>
                        <td><span class="badge <?php echo $next_anc_class; ?>"><?php echo $next_anc_label; ?></span></td>
                        <td><span class="badge <?php echo $edd_class; ?>"><?php echo $edd_label; ?></span></td>
                        <td>
                            <?php if ($is_active && $patient['has_risk']): ?>
                                <span class="badge badge-risk"><i class="fas fa-exclamation-triangle"></i> High Risk</span>
                            <?php elseif ($is_active): ?>
                                <span class="badge badge-lowrisk"><i class="fas fa-check-circle"></i> Low Risk</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="action-group">
                            <a href="patient_profile.php?id=<?php echo $patient['id']; ?>" class="action-icon" title="View Full Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($is_active): ?>
                                <a href="anc_visit.php?episode_id=<?php echo $patient['episode_id']; ?>" class="action-icon" title="Record ANC Visit">
                                    <i class="fas fa-notes-medical"></i>
                                </a>
                                <a href="delivery_admission.php?episode_id=<?php echo $patient['episode_id']; ?>" class="action-icon" title="Record Delivery">
                                    <i class="fas fa-birthday-cake"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($patient['phone'])): ?>
                                <a href="tel:<?php echo $patient['phone']; ?>" class="action-icon" title="Call Patient">
                                    <i class="fas fa-phone"></i>
                                </a>
                            <?php endif; ?>
                            <a href="patient_profile.php?id=<?php echo $patient['id']; ?>&print=1" class="action-icon" title="Print Summary">
                                <i class="fas fa-print"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="empty-state">
                            <i class="fas fa-search"></i>
                            <p>No patients found matching your criteria</p>
                            <a href="register_patient.php" class="btn btn-success" style="margin-top: 10px; display: inline-flex;">Register New Patient</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- PAGINATION - FIXED (NO SCROLL) -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
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
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function sortBy(field) {
    let currentSort = '<?php echo $sort_by; ?>';
    let currentOrder = '<?php echo $sort_order; ?>';
    let newOrder = (currentSort === field && currentOrder === 'ASC') ? 'DESC' : 'ASC';
    
    let url = new URL(window.location.href);
    url.searchParams.set('sort', field);
    url.searchParams.set('order', newOrder);
    window.location.href = url.toString();
}
</script>

<?php require_once 'footer.php'; ?>