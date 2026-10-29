<?php
// ============================================
// FILE: analytics_lib.php
// PURPOSE: Declarative analytics engine. Reports are lists of blocks
// (kpis, trends, breakdowns, metrics, ranked tables) evaluated against one
// shared filter set, optionally compared with an earlier period.
// All SQL comes from the fixed registry; user input is only ever bound.
// ============================================

const ANALYTICS_COLORS = ['#1E40AF', '#10B981', '#F59E0B', '#EF4444', '#7C3AED', '#0D9488', '#DB2777', '#0EA5E9', '#64748B'];
const ANALYTICS_CMP_COLOR = '#94A3B8';
const ANALYTICS_AGE_BANDS = ['<20', '20-24', '25-34', '35+'];

function analyticsPresetList(): array
{
    return [
        'last_30' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_3m' => 'Last 3 months',
        'last_6m' => 'Last 6 months',
        'last_12m' => 'Last 12 months',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'custom' => 'Custom dates',
    ];
}

function analyticsDateValid($date): bool
{
    return is_string($date)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
}

function analyticsFilters(array $in): array
{
    $today = new DateTimeImmutable('today');
    $presets = analyticsPresetList();
    $preset = isset($in['preset'], $presets[$in['preset']]) ? $in['preset'] : 'last_12m';

    $from = $today->modify('first day of -11 months');
    $to = $today;
    switch ($preset) {
        case 'last_30':
            $from = $today->modify('-29 days');
            break;
        case 'this_month':
            $from = $today->modify('first day of this month');
            break;
        case 'last_month':
            $from = $today->modify('first day of last month');
            $to = $today->modify('last day of last month');
            break;
        case 'last_3m':
            $from = $today->modify('first day of -2 months');
            break;
        case 'last_6m':
            $from = $today->modify('first day of -5 months');
            break;
        case 'this_year':
            $from = $today->setDate((int) $today->format('Y'), 1, 1);
            break;
        case 'last_year':
            $year = (int) $today->format('Y') - 1;
            $from = $today->setDate($year, 1, 1);
            $to = $today->setDate($year, 12, 31);
            break;
        case 'custom':
            if (analyticsDateValid($in['date_from'] ?? null) && analyticsDateValid($in['date_to'] ?? null)
                && $in['date_from'] <= $in['date_to']) {
                $from = new DateTimeImmutable($in['date_from']);
                $to = new DateTimeImmutable($in['date_to']);
            } else {
                $preset = 'last_12m';
            }
            break;
    }
    if ($from->diff($to)->days > 3660) {
        $from = $to->modify('-3660 days');
    }
    $span = $from->diff($to)->days + 1;

    $group = $in['group'] ?? 'month';
    if (!in_array($group, ['day', 'week', 'month', 'quarter'], true)) {
        $group = 'month';
    }
    $groupNote = '';
    if ($group === 'day' && $span > 120) {
        $group = 'week';
        $groupNote = 'Daily grouping is limited to 120 days; showing weekly.';
    }
    if ($group === 'week' && $span > 800) {
        $group = 'month';
        $groupNote = 'Weekly grouping is limited to about 2 years; showing monthly.';
    }

    $compare = $in['compare'] ?? 'prev_period';
    if (!in_array($compare, ['none', 'prev_period', 'prev_year'], true)) {
        $compare = 'prev_period';
    }
    $cfrom = $cto = null;
    if ($compare === 'prev_period') {
        $cto = $from->modify('-1 day');
        $cfrom = $cto->modify('-' . ($span - 1) . ' days');
    } elseif ($compare === 'prev_year') {
        $cfrom = $from->modify('-1 year');
        $cto = $to->modify('-1 year');
    }

    $age = $in['age'] ?? '';
    if (!in_array($age, ANALYTICS_AGE_BANDS, true)) {
        $age = '';
    }
    $category = $in['category'] ?? '';
    if (!in_array($category, ['F', 'N', 'R'], true)) {
        $category = '';
    }
    $clip = static fn($v): string => mb_substr(trim((string) ($v ?? '')), 0, 100);

    return [
        'preset' => $preset,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'span' => $span,
        'group' => $group,
        'group_note' => $groupNote,
        'compare' => $compare,
        'cfrom' => $cfrom ? $cfrom->format('Y-m-d') : null,
        'cto' => $cto ? $cto->format('Y-m-d') : null,
        'age' => $age,
        'category' => $category,
        'subcounty' => $clip($in['subcounty'] ?? ''),
        'village' => $clip($in['village'] ?? ''),
        'staff' => max(0, (int) ($in['staff'] ?? 0)),
    ];
}

function analyticsFilterQuery(array $f, array $extra = []): string
{
    $q = [
        'preset' => $f['preset'], 'date_from' => $f['from'], 'date_to' => $f['to'],
        'group' => $f['group'], 'compare' => $f['compare'], 'age' => $f['age'],
        'category' => $f['category'], 'subcounty' => $f['subcounty'],
        'village' => $f['village'], 'staff' => $f['staff'] ?: '',
    ];
    return http_build_query(array_filter(array_merge($q, $extra), static fn($v) => $v !== '' && $v !== null));
}

function analyticsPeriods(array $f): array
{
    $periods = ['cur' => ['from' => $f['from'], 'to' => $f['to']]];
    if ($f['cfrom']) {
        $periods['cmp'] = ['from' => $f['cfrom'], 'to' => $f['cto']];
    }
    return $periods;
}

function analyticsSources(): array
{
    $pe = ' JOIN pregnancy_episodes pe ON pe.id = %s JOIN patients p ON p.id = pe.patient_id';
    $ep = static fn(string $col): string => sprintf($pe, $col);
    $babyJoin = ' JOIN babies b ON b.id = %s JOIN delivery_records dr ON dr.id = b.delivery_record_id'
        . ' JOIN pregnancy_episodes pe ON pe.id = dr.pregnancy_episode_id JOIN patients p ON p.id = pe.patient_id';

    return [
        'patients' => ['from' => 'patients p', 'date' => 'p.created_at', 'dt' => 'ts', 'staff' => 'p.created_by'],
        'episodes' => ['from' => 'pregnancy_episodes pe JOIN patients p ON p.id = pe.patient_id', 'date' => 'pe.created_at', 'dt' => 'ts', 'staff' => 'pe.created_by'],
        'anc_cycle' => [
            'from' => 'pregnancy_episodes pe JOIN patients p ON p.id = pe.patient_id LEFT JOIN (SELECT pregnancy_episode_id, COUNT(*) AS visits FROM anc_visits GROUP BY pregnancy_episode_id) ac ON ac.pregnancy_episode_id = pe.id',
            'date' => 'pe.edd', 'dt' => 'date', 'staff' => null,
        ],
        'anc' => ['from' => 'anc_visits av' . $ep('av.pregnancy_episode_id'), 'date' => 'av.visit_date', 'dt' => 'date', 'staff' => 'av.recorded_by'],
        'delivery' => ['from' => 'delivery_records dr' . $ep('dr.pregnancy_episode_id'), 'date' => 'dr.delivery_datetime', 'dt' => 'ts', 'staff' => 'dr.recorded_by'],
        'baby' => ['from' => 'delivery_records dr' . $ep('dr.pregnancy_episode_id') . ' LEFT JOIN babies b ON b.delivery_record_id = dr.id', 'date' => 'dr.delivery_datetime', 'dt' => 'ts', 'staff' => 'dr.recorded_by'],
        'pnc' => ['from' => 'postnatal_visits pv' . $ep('pv.pregnancy_episode_id'), 'date' => 'pv.visit_date', 'dt' => 'date', 'staff' => 'pv.recorded_by'],
        'referral' => ['from' => 'referrals r JOIN patients p ON p.id = r.patient_id', 'date' => 'r.referral_date', 'dt' => 'date', 'staff' => 'r.recorded_by'],
        'screening' => ['from' => 'screening_results sr JOIN patients p ON p.id = sr.patient_id', 'date' => 'sr.performed_at', 'dt' => 'date', 'staff' => 'sr.recorded_by'],
        'appointment' => ['from' => 'appointments a JOIN patients p ON p.id = a.patient_id', 'date' => 'a.scheduled_date', 'dt' => 'date', 'staff' => 'a.created_by'],
        'child_visit' => ['from' => 'child_health_visits cv' . sprintf($babyJoin, 'cv.baby_id'), 'date' => 'cv.visit_date', 'dt' => 'date', 'staff' => 'cv.recorded_by'],
        'immunization' => ['from' => 'child_immunizations ci' . sprintf($babyJoin, 'ci.baby_id'), 'date' => 'ci.event_date', 'dt' => 'date', 'staff' => 'ci.recorded_by'],
        'commodity_tx' => ['from' => 'commodity_transactions ct JOIN commodities c ON c.id = ct.commodity_id', 'date' => 'ct.transaction_date', 'dt' => 'date', 'staff' => 'ct.recorded_by', 'patient' => false],
        'staffing' => ['from' => 'staffing_records sf', 'date' => 'sf.month_start', 'dt' => 'date', 'staff' => 'sf.recorded_by', 'patient' => false],
        'logs' => ['from' => 'system_logs l LEFT JOIN users u ON u.id = l.user_id', 'date' => 'l.created_at', 'dt' => 'ts', 'staff' => 'l.user_id', 'patient' => false],
    ];
}

function analyticsScope(array $src, array $f, array $period, string $extra = ''): array
{
    $where = [];
    $params = [];
    if (($src['dt'] ?? 'date') === 'date') {
        $where[] = "{$src['date']} BETWEEN ? AND ?";
        $params[] = $period['from'];
        $params[] = $period['to'];
    } else {
        $where[] = "{$src['date']} >= ? AND {$src['date']} < ?";
        $params[] = $period['from'] . ' 00:00:00';
        $params[] = (new DateTimeImmutable($period['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if (($src['patient'] ?? true) !== false) {
        $ageSql = ['<20' => 'p.age < 20', '20-24' => 'p.age BETWEEN 20 AND 24', '25-34' => 'p.age BETWEEN 25 AND 34', '35+' => 'p.age >= 35'];
        if ($f['age'] !== '') {
            $where[] = $ageSql[$f['age']];
        }
        if ($f['category'] !== '') {
            $where[] = 'p.client_category = ?';
            $params[] = $f['category'];
        }
        if ($f['subcounty'] !== '') {
            $where[] = 'p.sub_county = ?';
            $params[] = $f['subcounty'];
        }
        if ($f['village'] !== '') {
            $where[] = 'p.village = ?';
            $params[] = $f['village'];
        }
    }
    if (!empty($src['staff']) && $f['staff'] > 0) {
        $where[] = $src['staff'] . ' = ?';
        $params[] = $f['staff'];
    }
    if ($extra !== '') {
        $where[] = '(' . $extra . ')';
    }
    return [implode(' AND ', $where), $params];
}

function analyticsRun(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function analyticsBucketExpr(string $col, string $group): string
{
    switch ($group) {
        case 'day':
            return "DATE_FORMAT($col, '%Y-%m-%d')";
        case 'week':
            return "DATE_FORMAT($col, '%x-W%v')";
        case 'quarter':
            return "CONCAT(YEAR($col), '-Q', QUARTER($col))";
        default:
            return "DATE_FORMAT($col, '%Y-%m')";
    }
}

function analyticsBuckets(string $from, string $to, string $group): array
{
    $end = new DateTimeImmutable($to);
    $cursor = new DateTimeImmutable($from);
    $buckets = [];
    if ($group === 'week') {
        $cursor = $cursor->modify('monday this week');
        if ($cursor > new DateTimeImmutable($from)) {
            $cursor = $cursor->modify('-7 days');
        }
    } elseif ($group === 'month') {
        $cursor = $cursor->modify('first day of this month');
    } elseif ($group === 'quarter') {
        $month = (int) $cursor->format('n');
        $cursor = $cursor->setDate((int) $cursor->format('Y'), $month - (($month - 1) % 3), 1);
    }
    while ($cursor <= $end && count($buckets) < 400) {
        switch ($group) {
            case 'day':
                $buckets[] = ['key' => $cursor->format('Y-m-d'), 'label' => $cursor->format('d M')];
                $cursor = $cursor->modify('+1 day');
                break;
            case 'week':
                $buckets[] = ['key' => $cursor->format('o-\WW'), 'label' => 'Wk ' . $cursor->format('W') . ' (' . $cursor->format('d M') . ')'];
                $cursor = $cursor->modify('+7 days');
                break;
            case 'quarter':
                $quarter = (int) ceil((int) $cursor->format('n') / 3);
                $buckets[] = ['key' => $cursor->format('Y') . '-Q' . $quarter, 'label' => 'Q' . $quarter . ' ' . $cursor->format('Y')];
                $cursor = $cursor->modify('+3 months');
                break;
            default:
                $buckets[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
                $cursor = $cursor->modify('+1 month');
        }
    }
    return $buckets;
}

function analyticsFmt($value, string $fmt = 'int'): string
{
    if ($value === null) {
        return '–';
    }
    switch ($fmt) {
        case 'pct':
            return number_format((float) $value, 1) . '%';
        case 'dec':
            return number_format((float) $value, 1);
        case 'dec2':
            return number_format((float) $value, 2);
        case 'days':
            return number_format((float) $value, 1) . ' d';
        default:
            return number_format((float) $value);
    }
}

function analyticsDelta($cur, $cmp, string $fmt, ?string $good = null): array
{
    if ($cur === null || $cmp === null) {
        return ['text' => '–', 'dir' => 'flat', 'tone' => 'flat'];
    }
    if ($fmt === 'pct') {
        $diff = $cur - $cmp;
        $text = sprintf('%+.1f pp', $diff);
    } elseif (abs($cmp) < 1e-9) {
        $diff = $cur;
        $text = abs($cur) < 1e-9 ? '0%' : 'new';
    } else {
        $diff = $cur - $cmp;
        $text = sprintf('%+.0f%%', $diff / abs($cmp) * 100);
    }
    $dir = abs($diff) < 1e-9 ? 'flat' : ($diff > 0 ? 'up' : 'down');
    $tone = 'flat';
    if ($dir !== 'flat' && $good !== null) {
        $tone = $dir === $good ? 'good' : 'bad';
    }
    return ['text' => $text, 'dir' => $dir, 'tone' => $tone];
}

function analyticsCell(?array $cell, string $fmt, bool $showFraction = true): string
{
    if ($cell === null) {
        return '–';
    }
    $text = analyticsFmt($cell['v'], $fmt);
    if ($showFraction && isset($cell['d']) && $cell['d'] !== null) {
        $text .= ' (' . number_format((float) $cell['n']) . '/' . number_format((float) $cell['d']) . ')';
    }
    return $text;
}

// Builds one measurement from a result row: ratios use n_i/d_i, plain counts n_i.
function analyticsMeasure(?array $row, int $i, array $spec, string $fmt): array
{
    $isRatio = isset($spec['num']);
    $n = $row !== null && $row["n$i"] !== null ? (float) $row["n$i"] : 0.0;
    if (!$isRatio) {
        return ['v' => $n, 'n' => $n, 'd' => null];
    }
    $d = $row !== null && $row["d$i"] !== null ? (float) $row["d$i"] : 0.0;
    $scale = $spec['scale'] ?? ($fmt === 'pct' ? 100 : 1);
    return ['v' => $d > 0 ? $n / $d * $scale : null, 'n' => $n, 'd' => $d];
}

function analyticsSelectList(array $specs, string $prefix = ''): array
{
    $sel = [];
    foreach ($specs as $i => $s) {
        if (isset($s['num'])) {
            $sel[] = "{$s['num']} AS {$prefix}n$i";
            $sel[] = "{$s['den']} AS {$prefix}d$i";
        } else {
            $sel[] = "{$s['expr']} AS {$prefix}n$i";
        }
    }
    return $sel;
}

function analyticsLabelMap(PDO $pdo, array $b): array
{
    $map = $b['labels'] ?? [];
    if (!empty($b['labels_sql'])) {
        foreach ($pdo->query($b['labels_sql'])->fetchAll(PDO::FETCH_NUM) as $row) {
            $map[(string) $row[0]] = (string) $row[1];
        }
    }
    return $map;
}

function analyticsFromClause(array $src, array $b): string
{
    return $src['from'] . ($b['join'] ?? '');
}

function analyticsEvalKpis(PDO $pdo, array $b, array $f): array
{
    $src = analyticsSources()[$b['source']];
    $items = $b['items'];
    $values = [];
    foreach (analyticsPeriods($f) as $key => $period) {
        [$where, $params] = analyticsScope($src, $f, $period, $b['where'] ?? '');
        $sql = 'SELECT ' . implode(', ', analyticsSelectList($items)) . ' FROM ' . analyticsFromClause($src, $b) . ' WHERE ' . $where;
        $row = analyticsRun($pdo, $sql, $params)[0] ?? null;
        foreach ($items as $i => $item) {
            $values[$key][$i] = analyticsMeasure($row, $i, $item, $item['fmt'] ?? 'int');
        }
    }
    $out = [];
    foreach ($items as $i => $item) {
        $fmt = $item['fmt'] ?? 'int';
        $cur = $values['cur'][$i];
        $cmp = $values['cmp'][$i] ?? null;
        $out[] = [
            'label' => $item['label'],
            'fmt' => $fmt,
            'cur' => $cur,
            'cmp' => $cmp,
            'value' => analyticsCell($cur, $fmt, false),
            'sub' => $cur['d'] !== null ? number_format($cur['n']) . ' of ' . number_format($cur['d']) : ($item['hint'] ?? ''),
            'delta' => $cmp !== null ? analyticsDelta($cur['v'], $cmp['v'], $fmt, $item['good'] ?? null) : null,
            'icon' => $item['icon'] ?? null,
            'tone' => $item['tone'] ?? null,
        ];
    }
    return ['kind' => 'kpis', 'title' => $b['title'] ?? '', 'items' => $out];
}

function analyticsEvalTrend(PDO $pdo, array $b, array $f, string $id): array
{
    $src = analyticsSources()[$b['source']];
    $series = $b['series'];
    $fmt = $b['fmt'] ?? 'int';
    $cols = [];
    foreach (analyticsPeriods($f) as $key => $period) {
        $buckets = analyticsBuckets($period['from'], $period['to'], $f['group']);
        $sel = array_merge([analyticsBucketExpr($src['date'], $f['group']) . ' AS bk'], analyticsSelectList($series));
        [$where, $params] = analyticsScope($src, $f, $period, $b['where'] ?? '');
        $rows = analyticsRun($pdo, 'SELECT ' . implode(', ', $sel) . ' FROM ' . analyticsFromClause($src, $b) . " WHERE $where GROUP BY bk", $params);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['bk']] = $r;
        }
        $cols[$key] = ['buckets' => $buckets, 'data' => []];
        foreach ($series as $i => $s) {
            foreach ($buckets as $bk) {
                $cols[$key]['data'][$i][] = analyticsMeasure($byKey[$bk['key']] ?? null, $i, $s, $fmt);
            }
        }
    }

    $labels = array_column($cols['cur']['buckets'], 'label');
    $datasets = [];
    $head = ['Period'];
    if (isset($cols['cmp'])) {
        $head[] = 'Compared with';
    }
    foreach ($series as $i => $s) {
        $color = $s['color'] ?? ANALYTICS_COLORS[$i % count(ANALYTICS_COLORS)];
        $datasets[] = ['label' => $s['label'], 'data' => array_column($cols['cur']['data'][$i], 'v'), 'color' => $color, 'cmp' => false];
        $head[] = $s['label'];
        if (isset($cols['cmp'])) {
            $cmpVals = array_column($cols['cmp']['data'][$i], 'v');
            $cmpVals = array_pad(array_slice($cmpVals, 0, count($labels)), count($labels), null);
            $datasets[] = ['label' => $s['label'] . ' (previous)', 'data' => $cmpVals, 'color' => ANALYTICS_CMP_COLOR, 'cmp' => true];
            $head[] = $s['label'] . ' (previous)';
        }
    }
    $rows = [];
    foreach ($cols['cur']['buckets'] as $idx => $bk) {
        $row = [$bk['label']];
        if (isset($cols['cmp'])) {
            $row[] = $cols['cmp']['buckets'][$idx]['label'] ?? '–';
        }
        foreach ($series as $i => $s) {
            $row[] = analyticsCell($cols['cur']['data'][$i][$idx], $fmt);
            if (isset($cols['cmp'])) {
                $row[] = isset($cols['cmp']['data'][$i][$idx]) ? analyticsCell($cols['cmp']['data'][$i][$idx], $fmt) : '–';
            }
        }
        $rows[] = $row;
    }
    return [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? '',
        'type' => $b['ctype'] ?? 'line', 'stacked' => !empty($b['stacked']), 'fmt' => $fmt,
        'labels' => $labels, 'datasets' => $datasets, 'table' => ['head' => $head, 'rows' => $rows],
    ];
}

function analyticsEvalTrendBy(PDO $pdo, array $b, array $f, string $id): array
{
    $src = analyticsSources()[$b['source']];
    $buckets = analyticsBuckets($f['from'], $f['to'], $f['group']);
    [$where, $params] = analyticsScope($src, $f, ['from' => $f['from'], 'to' => $f['to']], $b['where'] ?? '');
    $rows = analyticsRun(
        $pdo,
        'SELECT ' . analyticsBucketExpr($src['date'], $f['group']) . " AS bk, {$b['dim']} AS dm, {$b['expr']} AS v FROM "
        . analyticsFromClause($src, $b) . " WHERE $where GROUP BY bk, dm",
        $params
    );
    $labelMap = analyticsLabelMap($pdo, $b);
    $totals = [];
    $grid = [];
    foreach ($rows as $r) {
        $dm = $r['dm'] === null || $r['dm'] === '' ? 'Not recorded' : ($labelMap[(string) $r['dm']] ?? (string) $r['dm']);
        $grid[$dm][$r['bk']] = (float) $r['v'];
        $totals[$dm] = ($totals[$dm] ?? 0) + (float) $r['v'];
    }
    arsort($totals);
    $dims = array_slice(array_keys($totals), 0, $b['limit'] ?? 7);
    $datasets = [];
    $head = ['Period'];
    foreach ($dims as $i => $dm) {
        $data = [];
        foreach ($buckets as $bk) {
            $data[] = $grid[$dm][$bk['key']] ?? 0;
        }
        $datasets[] = ['label' => $dm, 'data' => $data, 'color' => ANALYTICS_COLORS[$i % count(ANALYTICS_COLORS)], 'cmp' => false];
        $head[] = $dm;
    }
    $tableRows = [];
    foreach ($buckets as $idx => $bk) {
        $row = [$bk['label']];
        foreach ($datasets as $ds) {
            $row[] = analyticsFmt($ds['data'][$idx], 'int');
        }
        $tableRows[] = $row;
    }
    return [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? 'Comparison period is not drawn on category trends.',
        'type' => $b['ctype'] ?? 'bar', 'stacked' => true, 'fmt' => 'int',
        'labels' => array_column($buckets, 'label'), 'datasets' => $datasets, 'table' => ['head' => $head, 'rows' => $tableRows],
    ];
}

function analyticsEvalBreakdown(PDO $pdo, array $b, array $f, string $id): array
{
    $src = analyticsSources()[$b['source']];
    $fmt = $b['fmt'] ?? 'int';
    $spec = isset($b['num']) ? ['num' => $b['num'], 'den' => $b['den'], 'scale' => $b['scale'] ?? null] : ['expr' => $b['expr']];
    $maps = [];
    foreach (analyticsPeriods($f) as $key => $period) {
        [$where, $params] = analyticsScope($src, $f, $period, $b['where'] ?? '');
        $sel = array_merge(["{$b['dim']} AS dm"], analyticsSelectList([$spec]));
        $rows = analyticsRun($pdo, 'SELECT ' . implode(', ', $sel) . ' FROM ' . analyticsFromClause($src, $b) . " WHERE $where GROUP BY dm", $params);
        foreach ($rows as $r) {
            $maps[$key][$r['dm'] === null ? '' : (string) $r['dm']] = analyticsMeasure($r, 0, $spec, $fmt);
        }
    }
    $maps += ['cur' => [], 'cmp' => []];

    $keys = array_unique(array_merge(array_keys($maps['cur']), array_keys($maps['cmp'])));
    $keys = array_map('strval', $keys);
    $score = static fn(string $k): float => (float) ($maps['cur'][$k]['v'] ?? -1);
    if (!empty($b['fixed'])) {
        $fixed = array_map('strval', $b['fixed']);
        $rest = array_values(array_diff($keys, $fixed));
        usort($rest, static fn($a, $c) => $score($c) <=> $score($a));
        $keys = array_merge($fixed, $rest);
    } elseif (($b['order'] ?? 'value') === 'label') {
        sort($keys, SORT_NATURAL);
    } else {
        usort($keys, static fn($a, $c) => $score($c) <=> $score($a) ?: strcmp($a, $c));
    }
    $limit = $b['limit'] ?? 12;
    if ($limit > 0) {
        $keys = array_slice($keys, 0, $limit);
    }

    $labelMap = analyticsLabelMap($pdo, $b);
    $hasCmp = isset($f['cfrom']);
    $labels = [];
    $cur = [];
    $cmp = [];
    $rows = [];
    $total = 0.0;
    foreach ($keys as $k) {
        $total += (float) ($maps['cur'][$k]['v'] ?? 0);
    }
    $isCount = !isset($b['num']);
    foreach ($keys as $k) {
        $label = $k === '' ? 'Not recorded' : ($labelMap[$k] ?? $k);
        $c = $maps['cur'][$k] ?? ($isCount ? ['v' => 0.0, 'n' => 0.0, 'd' => null] : ['v' => null, 'n' => 0.0, 'd' => 0.0]);
        $p = $maps['cmp'][$k] ?? ($isCount ? ['v' => 0.0, 'n' => 0.0, 'd' => null] : ['v' => null, 'n' => 0.0, 'd' => 0.0]);
        $labels[] = $label;
        $cur[] = $c['v'];
        $cmp[] = $p['v'];
        $row = [$label, analyticsCell($c, $fmt)];
        if ($isCount && $fmt === 'int') {
            $row[] = $total > 0 ? number_format($c['v'] / $total * 100, 1) . '%' : '–';
        }
        if ($hasCmp) {
            $row[] = analyticsCell($p, $fmt);
            $row[] = analyticsDelta($c['v'], $p['v'], $fmt)['text'];
        }
        $rows[] = $row;
    }
    $head = [$b['dim_label'] ?? 'Category', 'Current'];
    if ($isCount && $fmt === 'int') {
        $head[] = 'Share';
    }
    if ($hasCmp) {
        $head[] = 'Previous';
        $head[] = 'Change';
    }
    $datasets = [['label' => $b['series_label'] ?? 'Current', 'data' => $cur, 'color' => $b['color'] ?? ANALYTICS_COLORS[0], 'cmp' => false]];
    if ($hasCmp) {
        $datasets[] = ['label' => 'Previous period', 'data' => $cmp, 'color' => ANALYTICS_CMP_COLOR, 'cmp' => true];
    }
    $type = $b['ctype'] ?? 'bar';
    if ($type === 'doughnut' && $hasCmp) {
        $type = 'bar';
    }
    return [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? '',
        'type' => $type, 'stacked' => false, 'fmt' => $fmt,
        'labels' => $labels, 'datasets' => $datasets, 'table' => ['head' => $head, 'rows' => $rows],
    ];
}

function analyticsEvalMetrics(PDO $pdo, array $b, array $f, string $id): array
{
    $src = analyticsSources()[$b['source']];
    $items = $b['items'];
    $default = $b['fmt'] ?? 'pct';
    $values = [];
    foreach (analyticsPeriods($f) as $key => $period) {
        [$where, $params] = analyticsScope($src, $f, $period, $b['where'] ?? '');
        $row = analyticsRun($pdo, 'SELECT ' . implode(', ', analyticsSelectList($items)) . ' FROM ' . analyticsFromClause($src, $b) . " WHERE $where", $params)[0] ?? null;
        foreach ($items as $i => $item) {
            $values[$key][$i] = analyticsMeasure($row, $i, $item, $item['fmt'] ?? $default);
        }
    }
    $hasCmp = isset($values['cmp']);
    $labels = array_column($items, 'label');
    $head = ['Indicator', 'Current'];
    if ($hasCmp) {
        $head[] = 'Previous';
        $head[] = 'Change';
    }
    $rows = [];
    foreach ($items as $i => $item) {
        $fmt = $item['fmt'] ?? $default;
        $row = [$item['label'], analyticsCell($values['cur'][$i], $fmt)];
        if ($hasCmp) {
            $row[] = analyticsCell($values['cmp'][$i], $fmt);
            $row[] = analyticsDelta($values['cur'][$i]['v'], $values['cmp'][$i]['v'], $fmt)['text'];
        }
        $rows[] = $row;
    }
    $datasets = [['label' => 'Current', 'data' => array_column($values['cur'], 'v'), 'color' => $b['color'] ?? ANALYTICS_COLORS[0], 'cmp' => false]];
    if ($hasCmp) {
        $datasets[] = ['label' => 'Previous period', 'data' => array_column($values['cmp'], 'v'), 'color' => ANALYTICS_CMP_COLOR, 'cmp' => true];
    }
    return [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? '',
        'type' => $b['ctype'] ?? 'hbar', 'stacked' => false, 'fmt' => $default,
        'labels' => $labels, 'datasets' => $datasets, 'table' => ['head' => $head, 'rows' => $rows],
    ];
}

function analyticsEvalRank(PDO $pdo, array $b, array $f, string $id): array
{
    $src = analyticsSources()[$b['source']];
    $cols = $b['cols'];
    [$where, $params] = analyticsScope($src, $f, ['from' => $f['from'], 'to' => $f['to']], $b['where'] ?? '');
    $sel = array_merge(["{$b['dim']} AS dm"], analyticsSelectList($cols));
    $rows = analyticsRun($pdo, 'SELECT ' . implode(', ', $sel) . ' FROM ' . analyticsFromClause($src, $b) . " WHERE $where GROUP BY dm", $params);
    $labelMap = analyticsLabelMap($pdo, $b);
    $items = [];
    foreach ($rows as $r) {
        $key = $r['dm'] === null ? '' : (string) $r['dm'];
        $cells = [];
        foreach ($cols as $i => $c) {
            $cells[$i] = analyticsMeasure($r, $i, $c, $c['fmt'] ?? 'int');
        }
        $items[] = ['label' => $key === '' ? 'Not recorded' : ($labelMap[$key] ?? $key), 'cells' => $cells];
    }
    $sortCol = $b['sort'] ?? 0;
    usort($items, static fn($a, $c) => ($c['cells'][$sortCol]['v'] ?? -1) <=> ($a['cells'][$sortCol]['v'] ?? -1) ?: strcmp($a['label'], $c['label']));
    $limit = $b['limit'] ?? 15;
    if ($limit > 0) {
        $items = array_slice($items, 0, $limit);
    }
    $head = [$b['dim_label'] ?? 'Group'];
    foreach ($cols as $c) {
        $head[] = $c['label'];
    }
    $tableRows = [];
    foreach ($items as $it) {
        $row = [$it['label']];
        foreach ($cols as $i => $c) {
            $row[] = analyticsCell($it['cells'][$i], $c['fmt'] ?? 'int');
        }
        $tableRows[] = $row;
    }
    $out = [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? '',
        'type' => ($b['chart'] ?? true) ? 'hbar' : 'none', 'stacked' => false, 'fmt' => $cols[$sortCol]['fmt'] ?? 'int',
        'labels' => array_column($items, 'label'),
        'datasets' => [['label' => $cols[$sortCol]['label'], 'data' => array_map(static fn($it) => $it['cells'][$sortCol]['v'], $items), 'color' => $b['color'] ?? ANALYTICS_COLORS[0], 'cmp' => false]],
        'table' => ['head' => $head, 'rows' => $tableRows],
    ];
    return $out;
}

function analyticsEvalReport(PDO $pdo, array $report, array $f): array
{
    $results = [];
    $n = 0;
    foreach ($report['blocks'] as $b) {
        $n++;
        $id = 'c' . $n;
        try {
        switch ($b['type']) {
            case 'kpis':
                $results[] = analyticsEvalKpis($pdo, $b, $f);
                break;
            case 'trend':
                $results[] = analyticsEvalTrend($pdo, $b, $f, $id);
                break;
            case 'trendby':
                $results[] = analyticsEvalTrendBy($pdo, $b, $f, $id);
                break;
            case 'breakdown':
                $results[] = analyticsEvalBreakdown($pdo, $b, $f, $id);
                break;
            case 'metrics':
                $results[] = analyticsEvalMetrics($pdo, $b, $f, $id);
                break;
            case 'rank':
                $results[] = analyticsEvalRank($pdo, $b, $f, $id);
                break;
            case 'kpichart':
                $results[] = analyticsKpiChart($results, $b, $f, $id);
                break;
            case 'custom':
                foreach ($b['run']($pdo, $f, $id) as $extra) {
                    $results[] = $extra;
                }
                break;
            case 'note':
                $results[] = ['kind' => 'note', 'text' => $b['text']];
                break;
            case 'section':
                $results[] = ['kind' => 'section', 'title' => $b['title']];
                break;
        }
        } catch (Throwable $e) {
            error_log('analytics block failed: ' . $e->getMessage());
            $results[] = ['kind' => 'error', 'title' => $b['title'] ?? 'Section'];
        }
    }
    return $results;
}

function analyticsKpiChart(array $results, array $b, array $f, string $id): array
{
    $labels = [];
    $cur = [];
    $cmp = [];
    $rows = [];
    $hasCmp = isset($f['cfrom']);
    foreach ($results as $r) {
        if ($r['kind'] !== 'kpis') {
            continue;
        }
        foreach ($r['items'] as $item) {
            if ($item['fmt'] !== 'int') {
                continue;
            }
            $labels[] = $item['label'];
            $cur[] = $item['cur']['v'];
            $cmp[] = $item['cmp']['v'] ?? null;
            $row = [$item['label'], analyticsFmt($item['cur']['v'])];
            if ($hasCmp) {
                $row[] = analyticsFmt($item['cmp']['v'] ?? null);
                $row[] = $item['delta']['text'] ?? '–';
            }
            $rows[] = $row;
        }
    }
    $datasets = [['label' => 'Current', 'data' => $cur, 'color' => ANALYTICS_COLORS[0], 'cmp' => false]];
    $head = ['Indicator', 'Current'];
    if ($hasCmp) {
        $datasets[] = ['label' => 'Previous period', 'data' => $cmp, 'color' => ANALYTICS_CMP_COLOR, 'cmp' => true];
        $head[] = 'Previous';
        $head[] = 'Change';
    }
    return [
        'kind' => 'chart', 'id' => $id, 'title' => $b['title'], 'note' => $b['note'] ?? '',
        'type' => 'hbar', 'stacked' => false, 'fmt' => 'int',
        'labels' => $labels, 'datasets' => $datasets, 'table' => ['head' => $head, 'rows' => $rows],
    ];
}

function analyticsCanView(array $report): bool
{
    return empty($report['roles']) || hasRole($report['roles']);
}
