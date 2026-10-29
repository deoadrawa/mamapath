<?php

require_once 'config.php';
requireRole('admin');

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$actionFilter = trim($_GET['action'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 50;

$validDate = static function (string $date): bool {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
};
if (!$validDate($dateFrom) || !$validDate($dateTo) || $dateFrom > $dateTo) {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');
}

$where = ['l.created_at >= :date_start AND l.created_at < :date_end'];
$params = [
    ':date_start' => $dateFrom . ' 00:00:00',
    ':date_end' => date('Y-m-d', strtotime($dateTo . ' +1 day')) . ' 00:00:00',
];
if ($actionFilter !== '') {
    $where[] = 'l.action LIKE :action';
    $params[':action'] = '%' . $actionFilter . '%';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs l WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$stmt = $pdo->prepare(
    "SELECT l.created_at, l.action, l.table_name, l.record_id, l.details,
            l.ip_address, u.username, u.full_name
     FROM system_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE $whereSql
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
?>
<?php require_once 'nav.php'; ?>
<style>
    .audit-page { max-width: 1400px; margin: 0 auto; }
    .audit-card { margin-top: 18px; padding: 20px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; }
    .audit-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
    .audit-filter label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: var(--text-secondary); }
    .audit-filter input { min-width: 150px; padding: 9px 10px; color: var(--text-primary); background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; }
    .audit-filter button { padding: 10px 14px; border: 0; border-radius: 8px; color: white; background: var(--primary); font: inherit; font-weight: 600; }
    .audit-table-wrap { overflow-x: auto; }
    .audit-table { width: 100%; min-width: 850px; border-collapse: collapse; }
    .audit-table th, .audit-table td { padding: 11px 9px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .audit-table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
    .audit-pagination { display: flex; justify-content: space-between; gap: 12px; margin-top: 16px; }
    .audit-pagination a { color: var(--primary); }
    @media (max-width: 700px) { .audit-card { padding: 14px; } }
</style>

<section class="audit-page">
    <h1>Audit Log</h1>
    <p class="text-muted">Review recorded account and clinical-record actions.</p>
    <div class="audit-card">
        <form method="GET" class="audit-filter">
            <div><label for="date_from">From</label><input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div><label for="date_to">To</label><input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div><label for="action">Action</label><input id="action" name="action" value="<?php echo htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. CREATE_ANC"></div>
            <button type="submit">Filter</button>
        </form>
        <p><?php echo number_format($totalRows); ?> events</p>
        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead><tr><th>Date / time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6">No audit events found for this period.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(formatDateTime($log['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($log['full_name'] ?: $log['username'] ?: 'System', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim(($log['table_name'] ?? '') . ' #' . ($log['record_id'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($log['details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($log['ip_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="audit-pagination" aria-label="Audit log pages">
                <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                <span>
                    <?php if ($page > 1): ?><a href="?<?php echo http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'action' => $actionFilter, 'page' => $page - 1]); ?>">Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="?<?php echo http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'action' => $actionFilter, 'page' => $page + 1]); ?>">Next</a><?php endif; ?>
                </span>
            </nav>
        <?php endif; ?>
    </div>
</section>
<?php require_once 'footer.php'; ?>
