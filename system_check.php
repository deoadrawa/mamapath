<?php
require_once 'config.php';
requireRole('admin');

$requiredSchema = [
    'patients' => ['id', 'surname', 'first_name', 'client_category', 'created_by'],
    'pregnancy_episodes' => ['id', 'patient_id', 'lmp', 'edd', 'status', 'created_by', 'updated_at'],
    'anc_visits' => ['id', 'pregnancy_episode_id', 'visit_number', 'next_visit_date', 'recorded_by', 'updated_at'],
    'delivery_records' => ['id', 'pregnancy_episode_id', 'delivery_datetime', 'number_of_babies', 'recorded_by'],
    'postnatal_visits' => ['id', 'pregnancy_episode_id', 'visit_date', 'baby_id', 'recorded_by'],
    'babies' => ['id', 'delivery_record_id', 'birth_order'],
    'appointments' => ['id', 'patient_id', 'appointment_type', 'scheduled_date', 'status'],
    'appointment_types' => ['code', 'label'],
    'screening_catalog' => ['code', 'name', 'is_active'],
    'screening_results' => ['id', 'patient_id', 'screening_code', 'performed_at', 'result_status'],
    'referrals' => ['id', 'patient_id', 'referral_date', 'destination', 'status', 'followup_date'],
    'child_health_visits' => ['id', 'baby_id', 'visit_date'],
    'child_immunizations' => ['id', 'baby_id', 'vaccine_name', 'event_date'],
    'commodities' => ['id', 'code', 'name'],
    'commodity_transactions' => ['id', 'commodity_id', 'transaction_type', 'quantity_delta', 'transaction_date'],
    'staffing_records' => ['id', 'cadre', 'month_start', 'planned_fte', 'available_fte'],
    'users' => ['id', 'username', 'password_hash', 'role', 'is_active'],
    'system_logs' => ['id', 'user_id', 'action', 'created_at'],
];

$tableNames = array_keys($requiredSchema);
$placeholders = implode(',', array_fill(0, count($tableNames), '?'));
$columnsStatement = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
);
$columnsStatement->execute($tableNames);
$availableColumns = [];
foreach ($columnsStatement->fetchAll() as $column) {
    $availableColumns[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
}

$schemaResults = [];
$missingCount = 0;
foreach ($requiredSchema as $table => $columns) {
    $present = $availableColumns[$table] ?? [];
    $missing = array_values(array_diff($columns, $present));
    if ($missing !== []) {
        $missingCount++;
    }
    $schemaResults[] = [
        'table' => $table,
        'missing' => $missing,
    ];
}

$dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$isMariaDb = stripos($dbVersion, 'mariadb') !== false;
$versionCore = '0';
if ($isMariaDb && preg_match('/(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $dbVersion, $versionMatch)) {
    $versionCore = $versionMatch[1];
} elseif (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $dbVersion, $versionMatch)) {
    $versionCore = $versionMatch[1];
}
$supportedVersion = $isMariaDb
    ? version_compare($versionCore, '10.5', '>=')
    : version_compare($versionCore, '8.0', '>=');
$overallHealthy = $missingCount === 0 && $supportedVersion;
?>
<?php require_once 'nav.php'; ?>

<section class="system-check-page">
    <div class="system-check-heading">
        <div>
            <h1>System Check</h1>
            <p>Verify that this deployment has the database structure required by the current modules.</p>
        </div>
        <span class="system-check-status <?= $overallHealthy ? 'is-healthy' : 'has-issues' ?>">
            <i class="fas <?= $overallHealthy ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= $overallHealthy ? 'Ready' : 'Action needed' ?>
        </span>
    </div>

    <div class="system-check-summary">
        <article class="system-check-card">
            <span>Database engine</span>
            <strong><?= htmlspecialchars($isMariaDb ? 'MariaDB' : 'MySQL') ?></strong>
            <small><?= htmlspecialchars($dbVersion) ?></small>
        </article>
        <article class="system-check-card">
            <span>Schema checks</span>
            <strong><?= count($schemaResults) - $missingCount ?> / <?= count($schemaResults) ?> passed</strong>
            <small>Tables and required columns</small>
        </article>
        <article class="system-check-card">
            <span>PHP database driver</span>
            <strong><?= extension_loaded('pdo_mysql') ? 'Available' : 'Missing' ?></strong>
            <small>PDO MySQL extension</small>
        </article>
    </div>

    <?php if (!$overallHealthy): ?>
        <div class="system-check-notice" role="alert">
            <strong>Some modules may fail to load.</strong>
            The database version or schema does not match the requirements. Do not rerun the full upgrade file;
            use the missing items below to identify what needs review. Ask your hosting administrator to check
            the PHP error log for the exact cause of any HTTP 500 response.
        </div>
    <?php else: ?>
        <div class="system-check-notice is-ok" role="status">
            The required tables and columns are present. This does not replace checking the server error log
            when a page still returns HTTP 500.
        </div>
    <?php endif; ?>

    <div class="system-check-table-wrap">
        <table class="system-check-table">
            <thead>
                <tr>
                    <th scope="col">Module table</th>
                    <th scope="col">Status</th>
                    <th scope="col">Missing columns</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schemaResults as $result): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($result['table']) ?></code></td>
                        <td>
                            <?php if ($result['missing'] === []): ?>
                                <span class="system-check-pill is-healthy">Ready</span>
                            <?php else: ?>
                                <span class="system-check-pill has-issues">Incomplete</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $result['missing'] === [] ? '—' : htmlspecialchars(implode(', ', $result['missing'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="system-check-footnote">
        This check reads schema metadata only. It does not display patient records, database credentials, or clinical data.
    </p>
</section>

<style>
    .system-check-page { max-width: 1180px; margin: 0 auto; color: var(--text-primary); }
    .system-check-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 20px; }
    .system-check-heading h1 { margin: 0 0 6px; font-size: 22px; }
    .system-check-heading p, .system-check-footnote { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.5; }
    .system-check-status { display: inline-flex; align-items: center; gap: 7px; padding: 8px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .system-check-status.is-healthy, .system-check-pill.is-healthy { background: var(--success-light); color: var(--success); }
    .system-check-status.has-issues, .system-check-pill.has-issues { background: var(--warning-light); color: var(--warning); }
    .system-check-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
    .system-check-card { display: flex; flex-direction: column; gap: 5px; min-width: 0; padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--card-bg); box-shadow: var(--shadow-card); }
    .system-check-card span, .system-check-card small { color: var(--text-secondary); font-size: 11px; }
    .system-check-card strong { overflow-wrap: anywhere; font-size: 17px; }
    .system-check-notice { margin: 0 0 16px; padding: 13px 15px; border: 1px solid color-mix(in srgb, var(--warning) 24%, transparent); border-radius: 9px; background: var(--warning-light); color: var(--warning); font-size: 13px; line-height: 1.5; }
    .system-check-notice.is-ok { border-color: color-mix(in srgb, var(--success) 22%, transparent); background: var(--success-light); color: var(--success); }
    .system-check-notice strong { display: block; margin-bottom: 3px; }
    .system-check-table-wrap { overflow: auto; border: 1px solid var(--border); border-radius: 11px; background: var(--card-bg); }
    .system-check-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .system-check-table th, .system-check-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
    .system-check-table th { background: var(--surface-subtle); color: var(--text-secondary); font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
    .system-check-table tbody tr:last-child td { border-bottom: 0; }
    .system-check-table code { color: var(--text-primary); }
    .system-check-pill { display: inline-flex; padding: 4px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .system-check-footnote { margin-top: 12px; font-size: 11px; }
    @media (max-width: 640px) {
        .system-check-heading { flex-direction: column; }
        .system-check-summary { grid-template-columns: 1fr; gap: 9px; }
    }
</style>

<?php require_once 'footer.php'; ?>
