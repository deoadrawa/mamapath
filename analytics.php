<?php
// ============================================
// FILE: analytics.php
// PURPOSE: Strategic analytics hub and report viewer with shared filters,
// period comparison, charts, data tables and CSV export.
// ============================================

require_once 'config.php';
requireLogin();
require_once 'analytics_lib.php';
require_once 'analytics_reports.php';

$h = static fn($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$categories = analyticsCategories();
$reports = analyticsReports();
$filters = analyticsFilters($_GET);
$reportId = (string) ($_GET['r'] ?? '');
$report = $reports[$reportId] ?? null;

if ($reportId !== '' && $report === null) {
    http_response_code(404);
}
if ($report !== null && !analyticsCanView($report)) {
    http_response_code(403);
    $report = null;
    $forbidden = true;
}

$results = [];
$elapsedMs = 0;
if ($report !== null) {
    $started = microtime(true);
    $results = analyticsEvalReport($pdo, $report, $filters);
    $elapsedMs = (int) round((microtime(true) - $started) * 1000);
}

if ($report !== null && ($_GET['export'] ?? '') === 'csv') {
    $name = preg_replace('/[^a-z0-9_]+/i', '_', $reportId);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analytics_' . $name . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $csvSafe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, [$report['title']]);
    fputcsv($out, ['Period', $filters['from'] . ' to ' . $filters['to']]);
    if ($filters['cfrom']) {
        fputcsv($out, ['Compared with', $filters['cfrom'] . ' to ' . $filters['cto']]);
    }
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    foreach ($results as $res) {
        if ($res['kind'] === 'kpis') {
            foreach ($res['items'] as $item) {
                fputcsv($out, array_map($csvSafe, [$item['label'], $item['value'], $item['sub'], $item['delta']['text'] ?? '']));
            }
            fputcsv($out, []);
        } elseif ($res['kind'] === 'chart') {
            fputcsv($out, [$res['title']]);
            fputcsv($out, array_map($csvSafe, $res['table']['head']));
            foreach ($res['table']['rows'] as $row) {
                fputcsv($out, array_map($csvSafe, $row));
            }
            fputcsv($out, []);
        }
    }
    fclose($out);
    exit;
}

$subCounties = $pdo->query("SELECT DISTINCT sub_county FROM patients WHERE sub_county IS NOT NULL AND sub_county <> '' ORDER BY sub_county LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
$villages = $pdo->query("SELECT DISTINCT village FROM patients WHERE village IS NOT NULL AND village <> '' ORDER BY village LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN);
$canSeeStaff = hasRole(['admin', 'staff_admin', 'manager']);
$staffList = $canSeeStaff ? $pdo->query('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll() : [];

$sources = analyticsSources();
$usesPatientFilters = false;
if ($report !== null) {
    foreach ($report['blocks'] as $b) {
        if (isset($b['source']) && ($sources[$b['source']]['patient'] ?? true) !== false) {
            $usesPatientFilters = true;
        }
    }
}
$patientFilterSet = $filters['age'] !== '' || $filters['category'] !== '' || $filters['subcounty'] !== '' || $filters['village'] !== '';

$fmtDate = static fn(string $d): string => date('d M Y', strtotime($d));
$chartData = [];
foreach ($results as $res) {
    if ($res['kind'] === 'chart' && $res['type'] !== 'none') {
        $chartData[$res['id']] = ['type' => $res['type'], 'stacked' => $res['stacked'], 'fmt' => $res['fmt'], 'labels' => $res['labels'], 'datasets' => $res['datasets']];
    }
}
$baseQuery = analyticsFilterQuery($filters);
$pageTitle = $report['title'] ?? 'Strategic Analytics';
?>
<?php require_once 'nav.php'; ?>
<link rel="stylesheet" href="analytics.css?v=2">
<section class="an-page">
    <header class="an-head">
        <div>
            <?php if ($report): ?>
                <a class="an-back" href="analytics.php?<?php echo $h($baseQuery); ?>"><i class="fas fa-arrow-left"></i> All reports</a>
                <h1><i class="fas <?php echo $h($report['icon']); ?>"></i> <?php echo $h($report['title']); ?></h1>
                <p><?php echo $h($report['desc']); ?></p>
            <?php else: ?>
                <h1><i class="fas fa-compass"></i> Strategic Analytics</h1>
                <p><?php echo count($reports); ?> reports with trends, comparisons and filters. Pick a period, then open any report. Your filters carry across reports.</p>
            <?php endif; ?>
        </div>
        <?php if ($report): ?>
            <div class="an-actions">
                <a class="an-btn" href="analytics.php?<?php echo $h(analyticsFilterQuery($filters, ['r' => $reportId, 'export' => 'csv'])); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
                <button type="button" class="an-btn an-btn-pdf" id="anPdf" data-title="<?php echo $h($report['title']); ?>" data-file="analytics_<?php echo $h($reportId); ?>_<?php echo date('Y-m-d'); ?>.pdf" data-user="<?php echo $h($_SESSION['username'] ?? ''); ?>"><i class="fas fa-file-pdf"></i> Download PDF</button>
                <button type="button" class="an-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        <?php endif; ?>
    </header>

    <form class="an-filters" method="get" action="analytics.php" id="anFilters">
        <?php if ($reportId !== ''): ?><input type="hidden" name="r" value="<?php echo $h($reportId); ?>"><?php endif; ?>
        <label>Period
            <select name="preset" id="anPreset">
                <?php foreach (analyticsPresetList() as $key => $label): ?>
                    <option value="<?php echo $h($key); ?>" <?php echo $filters['preset'] === $key ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>From <input type="date" name="date_from" id="anFrom" value="<?php echo $h($filters['from']); ?>"></label>
        <label>To <input type="date" name="date_to" id="anTo" value="<?php echo $h($filters['to']); ?>"></label>
        <label>Group by
            <select name="group">
                <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter'] as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $filters['group'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Compare with
            <select name="compare">
                <option value="none" <?php echo $filters['compare'] === 'none' ? 'selected' : ''; ?>>No comparison</option>
                <option value="prev_period" <?php echo $filters['compare'] === 'prev_period' ? 'selected' : ''; ?>>Previous period</option>
                <option value="prev_year" <?php echo $filters['compare'] === 'prev_year' ? 'selected' : ''; ?>>Same period last year</option>
            </select>
        </label>
        <label>Age group
            <select name="age">
                <option value="">All ages</option>
                <?php foreach (ANALYTICS_AGE_BANDS as $band): ?>
                    <option value="<?php echo $h($band); ?>" <?php echo $filters['age'] === $band ? 'selected' : ''; ?>><?php echo $h($band); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Client category
            <select name="category">
                <option value="">All</option>
                <?php foreach (['N' => 'National', 'R' => 'Refugee', 'F' => 'Foreigner'] as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $filters['category'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Sub-county
            <select name="subcounty" data-no-search>
                <option value="">All</option>
                <?php foreach ($subCounties as $v): ?>
                    <option value="<?php echo $h($v); ?>" <?php echo $filters['subcounty'] === $v ? 'selected' : ''; ?>><?php echo $h($v); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Village
            <input type="text" name="village" list="anVillages" value="<?php echo $h($filters['village']); ?>" placeholder="All villages" autocomplete="off">
            <datalist id="anVillages">
                <?php foreach ($villages as $v): ?><option value="<?php echo $h($v); ?>"><?php endforeach; ?>
            </datalist>
        </label>
        <?php if ($canSeeStaff): ?>
            <label>Recorded by
                <select name="staff">
                    <option value="0">All staff</option>
                    <?php foreach ($staffList as $u): ?>
                        <option value="<?php echo (int) $u['id']; ?>" <?php echo $filters['staff'] === (int) $u['id'] ? 'selected' : ''; ?>><?php echo $h($u['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <div class="an-filter-actions">
            <button type="submit" class="an-btn an-btn-primary"><i class="fas fa-filter"></i> Apply</button>
            <a class="an-btn" href="analytics.php<?php echo $reportId !== '' ? '?r=' . $h(urlencode($reportId)) : ''; ?>">Reset</a>
        </div>
    </form>

    <p class="an-context">
        <i class="fas fa-calendar"></i> <?php echo $h($fmtDate($filters['from']) . ' – ' . $fmtDate($filters['to'])); ?>
        <?php if ($filters['cfrom']): ?> · compared with <?php echo $h($fmtDate($filters['cfrom']) . ' – ' . $fmtDate($filters['cto'])); ?><?php endif; ?>
        <?php if ($filters['group_note']): ?> · <?php echo $h($filters['group_note']); ?><?php endif; ?>
        <?php if ($report && $patientFilterSet && !$usesPatientFilters): ?> · <strong>Age, category and location filters do not apply to this report.</strong><?php endif; ?>
    </p>

<?php if (isset($forbidden)): ?>
    <div class="an-note an-note-warn"><i class="fas fa-lock"></i> This report is limited to managers and administrators.</div>
<?php elseif ($reportId !== '' && $report === null): ?>
    <div class="an-note an-note-warn"><i class="fas fa-circle-question"></i> That report does not exist. <a href="analytics.php">Back to all reports</a>.</div>
<?php elseif ($report === null): ?>
    <div class="an-search">
        <i class="fas fa-search"></i>
        <input type="search" id="anSearch" placeholder="Search reports (e.g. HIV, caesarean, referrals)…" autocomplete="off" aria-label="Search reports">
    </div>
    <?php foreach ($categories as $catKey => [$catName, $catIcon, $catTone, $catDesc]): ?>
        <?php $catReports = array_filter($reports, static fn($rep) => $rep['cat'] === $catKey && analyticsCanView($rep)); ?>
        <?php if (!$catReports) continue; ?>
        <section class="an-cat tone-<?php echo $h($catTone); ?>" data-cat>
            <h2><span class="an-cat-icon"><i class="fas <?php echo $h($catIcon); ?>"></i></span> <?php echo $h($catName); ?> <small><?php echo count($catReports); ?></small></h2>
            <p class="an-cat-desc"><?php echo $h($catDesc); ?></p>
            <div class="an-cards">
                <?php foreach ($catReports as $id => $rep): ?>
                    <a class="an-card" href="analytics.php?<?php echo $h(analyticsFilterQuery($filters, ['r' => $id])); ?>" data-search="<?php echo $h(strtolower($rep['title'] . ' ' . $rep['desc'] . ' ' . $catName)); ?>">
                        <span class="an-card-icon"><i class="fas <?php echo $h($rep['icon']); ?>"></i></span>
                        <span class="an-card-body"><strong><?php echo $h($rep['title']); ?></strong><span><?php echo $h($rep['desc']); ?></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <p class="an-empty" id="anEmpty" hidden>No reports match your search.</p>
<?php else: ?>
    <?php $tone = $categories[$report['cat']][2]; ?>
    <div class="an-report tone-<?php echo $h($tone); ?>">
    <?php $grid = false; ?>
    <?php foreach ($results as $res): ?>
        <?php if ($res['kind'] === 'kpis'): ?>
            <?php if ($grid) { echo '</div>'; $grid = false; } ?>
            <?php if ($res['title'] !== ''): ?><h2 class="an-section"><?php echo $h($res['title']); ?></h2><?php endif; ?>
            <div class="an-kpis">
                <?php foreach ($res['items'] as $item): ?>
                    <div class="an-kpi">
                        <span class="an-kpi-label"><?php echo $h($item['label']); ?></span>
                        <span class="an-kpi-value"><?php echo $h($item['value']); ?></span>
                        <span class="an-kpi-foot">
                            <?php if ($item['sub'] !== ''): ?><span class="an-kpi-sub"><?php echo $h($item['sub']); ?></span><?php endif; ?>
                            <?php if ($item['delta']): ?>
                                <span class="an-delta an-<?php echo $h($item['delta']['tone']); ?>">
                                    <i class="fas <?php echo $item['delta']['dir'] === 'up' ? 'fa-arrow-up' : ($item['delta']['dir'] === 'down' ? 'fa-arrow-down' : 'fa-minus'); ?>"></i>
                                    <?php echo $h($item['delta']['text']); ?>
                                </span>
                                <span class="an-kpi-prev">was <?php echo $h(analyticsCell($item['cmp'], $item['fmt'], false)); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($res['kind'] === 'section'): ?>
            <?php if ($grid) { echo '</div>'; $grid = false; } ?>
            <h2 class="an-section"><?php echo $h($res['title']); ?></h2>
        <?php elseif ($res['kind'] === 'note'): ?>
            <?php if ($grid) { echo '</div>'; $grid = false; } ?>
            <div class="an-note"><i class="fas fa-circle-info"></i> <?php echo $h($res['text']); ?></div>
        <?php elseif ($res['kind'] === 'error'): ?>
            <?php if (!$grid) { echo '<div class="an-grid">'; $grid = true; } ?>
            <article class="an-chart an-chart-error"><h3><?php echo $h($res['title']); ?></h3><p><i class="fas fa-triangle-exclamation"></i> This section could not be calculated. Please tell the system administrator.</p></article>
        <?php else: ?>
            <?php if (!$grid) { echo '<div class="an-grid">'; $grid = true; } ?>
            <?php $wide = count($res['table']['head']) > 5 || in_array($res['type'], ['line', 'bar'], true) && count($res['labels']) > 14; ?>
            <article class="an-chart<?php echo $wide ? ' an-wide' : ''; ?>">
                <h3><?php echo $h($res['title']); ?></h3>
                <?php if ($res['note'] !== ''): ?><p class="an-chart-note"><?php echo $h($res['note']); ?></p><?php endif; ?>
                <?php if ($res['type'] !== 'none'): ?>
                    <?php $isH = $res['type'] === 'hbar'; $height = $isH ? max(180, count($res['labels']) * (count($res['datasets']) > 1 ? 44 : 30) + 70) : 280; ?>
                    <div class="an-canvas" style="height:<?php echo (int) $height; ?>px"><canvas id="chart-<?php echo $h($res['id']); ?>" data-chart-id="<?php echo $h($res['id']); ?>" role="img" aria-label="<?php echo $h($res['title']); ?>"></canvas></div>
                <?php endif; ?>
                <div class="an-table-wrap">
                    <table class="an-table">
                        <thead><tr><?php foreach ($res['table']['head'] as $col): ?><th><?php echo $h($col); ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php if (!$res['table']['rows']): ?><tr><td colspan="<?php echo count($res['table']['head']); ?>" class="an-none">No data for this selection</td></tr><?php endif; ?>
                            <?php foreach ($res['table']['rows'] as $row): ?>
                                <tr><?php foreach ($row as $i => $cell): ?><<?php echo $i === 0 ? 'th scope="row"' : 'td'; ?>><?php echo $h($cell); ?></<?php echo $i === 0 ? 'th' : 'td'; ?>><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($grid) echo '</div>'; ?>
    </div>
    <p class="an-foot"><i class="fas fa-stopwatch"></i> Calculated in <?php echo (int) $elapsedMs; ?> ms. Partial first and last periods may show lower counts. Rates show the numerator and denominator in the table.</p>
    <script type="application/json" id="anData"><?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); ?></script>
    <script src="assets/vendor/chart.umd.js"></script>
    <script src="assets/vendor/html2canvas.min.js"></script>
    <script src="assets/vendor/jspdf.umd.min.js"></script>
<?php endif; ?>
</section>
<script src="assets/analytics.js?v=2"></script>
<script src="assets/analytics-pdf.js?v=3"></script>
<?php require_once 'footer.php'; ?>
