<?php

require_once 'config.php';
requireLogin();

$error = '';
$success = isset($_GET['saved']) ? 'Planning data saved.' : '';
$today = date('Y-m-d');
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int) substr($month, 5, 2), 1, (int) substr($month, 0, 4))) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $action = $_POST['action'] ?? '';

        if ($action === 'create_commodity') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $reorder = $_POST['reorder_level'] ?? '';
            $leadTime = filter_var($_POST['lead_time_days'] ?? null, FILTER_VALIDATE_INT);
            if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code) || $name === '' || strlen($name) > 120 || $unit === '' || strlen($unit) > 30) {
                throw new InvalidArgumentException('Enter a unique code, commodity name, and stock unit.');
            }
            if (!is_numeric($reorder) || (float) $reorder < 0 || !is_finite((float) $reorder) || $leadTime === false || $leadTime < 0 || $leadTime > 365) {
                throw new InvalidArgumentException('Enter a non-negative reorder level and lead time of 0–365 days.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO commodities (code, name, unit, reorder_level, lead_time_days)
                 VALUES (:code, :name, :unit, :reorder_level, :lead_time_days)'
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':unit' => $unit,
                ':reorder_level' => $reorder,
                ':lead_time_days' => $leadTime,
            ]);
            logAction($pdo, currentUserId(), 'CREATE_COMMODITY', 'commodities', $pdo->lastInsertId(), 'Commodity added to the planning register');
            $pdo->commit();
            header('Location: resource_planning.php?saved=1&month=' . urlencode($month));
            exit;
        }

        if ($action === 'record_transaction') {
            $commodityId = filter_var($_POST['commodity_id'] ?? null, FILTER_VALIDATE_INT);
            $type = $_POST['transaction_type'] ?? '';
            $date = $_POST['transaction_date'] ?? '';
            $quantityRaw = trim($_POST['quantity'] ?? '');
            $reference = trim($_POST['reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if ($commodityId === false || $commodityId < 1 || !in_array($type, ['receipt', 'issue', 'adjustment'], true)) {
                throw new InvalidArgumentException('Choose a valid commodity and transaction type.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) || $date > $today) {
                throw new InvalidArgumentException('Enter a valid stock transaction date that is not in the future.');
            }
            if (!is_numeric($quantityRaw) || !is_finite((float) $quantityRaw) || (float) $quantityRaw == 0 || strlen($reference) > 100 || strlen($notes) > 255) {
                throw new InvalidArgumentException('Enter a non-zero quantity and keep the reference and notes within the allowed length.');
            }
            if ($type === 'adjustment' && $notes === '') {
                throw new InvalidArgumentException('Explain the reason for a stock adjustment in the notes.');
            }
            $quantity = (float) $quantityRaw;
            if ($type !== 'adjustment' && $quantity < 0) {
                throw new InvalidArgumentException('Use a positive quantity for receipts and issues; only adjustments accept a signed quantity.');
            }
            if ($type === 'issue') {
                $quantity = -abs($quantity);
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM commodities WHERE id = :id AND is_active = 1 FOR UPDATE');
            $lock->execute([':id' => $commodityId]);
            if (!$lock->fetchColumn()) {
                throw new InvalidArgumentException('The selected commodity is not active.');
            }
            $balanceStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity_delta), 0)
                 FROM commodity_transactions
                 WHERE commodity_id = :id AND transaction_date <= :transaction_date'
            );
            $balanceStmt->execute([':id' => $commodityId, ':transaction_date' => $date]);
            $balance = (float) $balanceStmt->fetchColumn();
            if ($balance + $quantity < 0) {
                throw new InvalidArgumentException('This transaction would make the recorded stock balance negative on that date.');
            }
            // A backdated entry must also keep every later running balance non-negative.
            $laterStmt = $pdo->prepare(
                'SELECT transaction_date, SUM(quantity_delta) AS delta
                 FROM commodity_transactions
                 WHERE commodity_id = :id AND transaction_date > :transaction_date
                 GROUP BY transaction_date ORDER BY transaction_date'
            );
            $laterStmt->execute([':id' => $commodityId, ':transaction_date' => $date]);
            $running = $balance + $quantity;
            foreach ($laterStmt->fetchAll() as $later) {
                $running += (float) $later['delta'];
                if ($running < 0) {
                    throw new InvalidArgumentException('This transaction would make the stock balance negative on ' . $later['transaction_date'] . '.');
                }
            }
            $stmt = $pdo->prepare(
                'INSERT INTO commodity_transactions (
                    commodity_id, transaction_date, transaction_type, quantity_delta,
                    reference, notes, recorded_by
                 ) VALUES (:commodity_id, :transaction_date, :type, :quantity, :reference, :notes, :user_id)'
            );
            $stmt->execute([
                ':commodity_id' => $commodityId,
                ':transaction_date' => $date,
                ':type' => $type,
                ':quantity' => $quantity,
                ':reference' => $reference === '' ? null : $reference,
                ':notes' => $notes === '' ? null : $notes,
                ':user_id' => currentUserId(),
            ]);
            logAction($pdo, currentUserId(), 'CREATE_COMMODITY_TRANSACTION', 'commodity_transactions', $pdo->lastInsertId(), 'Stock transaction recorded');
            $pdo->commit();
            header('Location: resource_planning.php?saved=1&month=' . urlencode($month));
            exit;
        }

        if ($action === 'save_staffing') {
            $cadre = trim($_POST['cadre'] ?? '');
            $plannedFte = $_POST['planned_fte'] ?? '';
            $availableFte = $_POST['available_fte'] ?? '';
            $hoursRaw = trim($_POST['available_hours'] ?? '');
            $source = trim($_POST['source'] ?? '');
            $notes = trim($_POST['staffing_notes'] ?? '');
            if ($cadre === '' || strlen($cadre) > 80 || !is_numeric($plannedFte) || !is_numeric($availableFte) || (float) $plannedFte < 0 || (float) $availableFte < 0 || (float) $plannedFte > 999 || (float) $availableFte > 999) {
                throw new InvalidArgumentException('Enter a cadre and valid planned and available FTE values (0–999).');
            }
            if (($hoursRaw !== '' && (!is_numeric($hoursRaw) || (float) $hoursRaw < 0 || (float) $hoursRaw > 100000)) || strlen($source) > 150 || strlen($notes) > 255) {
                throw new InvalidArgumentException('Enter valid available hours and keep staffing notes within the allowed length.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO staffing_records (
                    month_start, cadre, planned_fte, available_fte, available_hours,
                    source, notes, recorded_by
                 ) VALUES (:month_start, :cadre, :planned_fte, :available_fte, :available_hours, :source, :notes, :user_id)
                 ON DUPLICATE KEY UPDATE
                    planned_fte = VALUES(planned_fte),
                    available_fte = VALUES(available_fte),
                    available_hours = VALUES(available_hours),
                    source = VALUES(source),
                    notes = VALUES(notes),
                    recorded_by = VALUES(recorded_by)'
            );
            $stmt->execute([
                ':month_start' => $monthStart,
                ':cadre' => $cadre,
                ':planned_fte' => $plannedFte,
                ':available_fte' => $availableFte,
                ':available_hours' => $hoursRaw === '' ? null : $hoursRaw,
                ':source' => $source === '' ? null : $source,
                ':notes' => $notes === '' ? null : $notes,
                ':user_id' => currentUserId(),
            ]);
            logAction($pdo, currentUserId(), 'SAVE_STAFFING_RECORD', 'staffing_records', null, 'Monthly workforce capacity saved');
            $pdo->commit();
            header('Location: resource_planning.php?saved=1&month=' . urlencode($month));
            exit;
        }
        throw new InvalidArgumentException('Unknown planning action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save planning data. Please try again.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Resource planning request failed: ' . $e->getMessage());
        }
    }
}

$commodities = $pdo->query(
    "SELECT c.id, c.code, c.name, c.unit, c.reorder_level, c.lead_time_days,
            COALESCE(SUM(t.quantity_delta), 0) AS on_hand,
            COALESCE(-SUM(CASE WHEN t.transaction_type = 'issue'
                         AND t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND t.transaction_date <= CURDATE()
                         THEN t.quantity_delta ELSE 0 END) /
                     GREATEST(1, LEAST(90, DATEDIFF(CURDATE(), COALESCE(MIN(t.transaction_date), DATE(c.created_at))) + 1)), 0) AS daily_issue_rate,
            GREATEST(1, LEAST(90, DATEDIFF(CURDATE(), COALESCE(MIN(t.transaction_date), DATE(c.created_at))) + 1)) AS observation_days
     FROM commodities c
     LEFT JOIN commodity_transactions t ON t.commodity_id = c.id
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY c.name"
)->fetchAll();
$stockHistory = $pdo->query(
    'SELECT t.transaction_date, t.transaction_type, t.quantity_delta, t.reference, t.notes,
            c.name AS commodity_name, c.unit, u.full_name AS recorded_by_name
     FROM commodity_transactions t
     JOIN commodities c ON c.id = t.commodity_id
     LEFT JOIN users u ON u.id = t.recorded_by
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 100'
)->fetchAll();
$staffingStmt = $pdo->prepare('SELECT * FROM staffing_records WHERE month_start = :month_start ORDER BY cadre');
$staffingStmt->execute([':month_start' => $monthStart]);
$staffing = $staffingStmt->fetchAll();
$workloadStmt = $pdo->prepare(
    "SELECT u.role,
            SUM(service.anc_count) AS anc_count,
            SUM(service.delivery_count) AS delivery_count,
            SUM(service.pnc_count) AS pnc_count,
            SUM(service.child_count) AS child_count,
            SUM(service.screening_count) AS screening_count,
            SUM(service.referral_count) AS referral_count
     FROM (
        SELECT recorded_by AS user_id, COUNT(*) AS anc_count, 0 AS delivery_count, 0 AS pnc_count, 0 AS child_count, 0 AS screening_count, 0 AS referral_count
        FROM anc_visits WHERE visit_date >= :anc_start AND visit_date < :anc_end AND recorded_by IS NOT NULL GROUP BY recorded_by
        UNION ALL
        SELECT recorded_by, 0, COUNT(*), 0, 0, 0, 0 FROM delivery_records
        WHERE admission_datetime >= :delivery_start AND admission_datetime < :delivery_end AND recorded_by IS NOT NULL GROUP BY recorded_by
        UNION ALL
        SELECT recorded_by, 0, 0, COUNT(*), 0, 0, 0 FROM postnatal_visits
        WHERE visit_date >= :pnc_start AND visit_date < :pnc_end AND recorded_by IS NOT NULL GROUP BY recorded_by
        UNION ALL
        SELECT recorded_by, 0, 0, 0, COUNT(*), 0, 0 FROM child_health_visits
        WHERE visit_date >= :child_start AND visit_date < :child_end AND recorded_by IS NOT NULL GROUP BY recorded_by
        UNION ALL
        SELECT recorded_by, 0, 0, 0, 0, COUNT(*), 0 FROM screening_results
        WHERE performed_at >= :screening_start AND performed_at < :screening_end AND recorded_by IS NOT NULL GROUP BY recorded_by
        UNION ALL
        SELECT recorded_by, 0, 0, 0, 0, 0, COUNT(*) FROM referrals
        WHERE referral_date >= :referral_start AND referral_date < :referral_end AND recorded_by IS NOT NULL GROUP BY recorded_by
     ) service
     JOIN users u ON u.id = service.user_id
     GROUP BY u.role
     ORDER BY u.role"
);
$workloadStmt->execute([
    ':anc_start' => $monthStart, ':anc_end' => $monthEnd,
    ':delivery_start' => $monthStart, ':delivery_end' => $monthEnd,
    ':pnc_start' => $monthStart, ':pnc_end' => $monthEnd,
    ':child_start' => $monthStart, ':child_end' => $monthEnd,
    ':screening_start' => $monthStart, ':screening_end' => $monthEnd,
    ':referral_start' => $monthStart, ':referral_end' => $monthEnd,
]);
$workload = $workloadStmt->fetchAll();
?>
<?php require_once 'nav.php'; ?>
<style>
    .planning-page { max-width: 1500px; margin: 0 auto; }
    .planning-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 22px; margin-bottom: 20px; }
    .planning-card h2 { font-size: 18px; margin-bottom: 16px; }
    .planning-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
    .care-form { display: grid; gap: 12px; }
    .care-form label { display: block; color: var(--text-secondary); font-size: 13px; font-weight: 600; margin-bottom: 5px; }
    .care-form input, .care-form select, .care-form textarea { width: 100%; }
    .record-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .record-table th, .record-table td { border-bottom: 1px solid var(--border); padding: 10px 8px; text-align: left; vertical-align: top; }
    .record-table th { color: var(--text-secondary); }
    .muted-copy { color: var(--text-secondary); font-size: 12px; }
    .month-filter { display: flex; gap: 10px; align-items: end; margin-bottom: 18px; }
    .month-filter label { display: grid; gap: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600; }
    @media (max-width: 900px) { .planning-grid { grid-template-columns: 1fr; } }
</style>
<div class="planning-page">
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <section class="planning-card">
        <form method="GET" class="month-filter"><label for="month">Workload month<input type="month" name="month" id="month" value="<?= htmlspecialchars($month) ?>"></label><button class="btn btn-secondary" type="submit">View month</button></form>
        <h2>Service activity by staff cadre · <?= htmlspecialchars(date('F Y', strtotime($monthStart))) ?></h2>
        <p class="muted-copy">Counts are recorded encounters, not a quality or productivity score. A staff role may cover more than one service type.</p>
        <?php if (!$workload): ?><p class="muted-copy">No attributable service records were found for this month.</p><?php else: ?>
            <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Staff cadre</th><th>ANC</th><th>Delivery</th><th>PNC</th><th>Child</th><th>Screening</th><th>Referrals</th></tr></thead><tbody>
            <?php foreach ($workload as $row): ?>
                <tr><td><?= htmlspecialchars(getUserRoleLabel($row['role'])) ?></td><td><?= (int) $row['anc_count'] ?></td><td><?= (int) $row['delivery_count'] ?></td><td><?= (int) $row['pnc_count'] ?></td><td><?= (int) $row['child_count'] ?></td><td><?= (int) $row['screening_count'] ?></td><td><?= (int) $row['referral_count'] ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <div class="planning-grid">
        <section class="planning-card">
            <h2>Commodity stock and demand signals</h2>
            <p class="muted-copy">On-hand stock is calculated from the movement ledger. The issue rate divides recorded issues from the last 90 days by the observed ledger period (up to 90 days), including days with no issue. A stock-out estimate is shown only when issue history exists. These are planning indicators, not purchase orders.</p>
            <?php if (!$commodities): ?><p class="muted-copy">Add local commodity items to begin recording stock.</p><?php else: ?>
                <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Commodity</th><th>On hand</th><th>Issue / day*</th><th>History window</th><th>Estimated days</th><th>Reorder point</th><th>Planning signal</th></tr></thead><tbody>
                <?php foreach ($commodities as $item):
                    $dailyRate = (float) $item['daily_issue_rate'];
                    $onHand = (float) $item['on_hand'];
                    $daysStock = $dailyRate > 0 ? $onHand / $dailyRate : null;
                    $suggested = max(0, (float) $item['reorder_level'] + ($dailyRate * (int) $item['lead_time_days']) - $onHand);
                    $low = $onHand <= (float) $item['reorder_level'] || ($daysStock !== null && $daysStock <= (int) $item['lead_time_days']);
                ?>
                    <tr><td><?= htmlspecialchars($item['name']) ?><br><span class="muted-copy"><?= htmlspecialchars($item['code']) ?> · <?= htmlspecialchars($item['unit']) ?></span></td><td><?= htmlspecialchars($item['on_hand']) ?></td><td><?= $dailyRate > 0 ? number_format($dailyRate, 2) : 'No history' ?></td><td><?= (int) $item['observation_days'] ?> days</td><td><?= $daysStock !== null ? number_format($daysStock, 1) : '—' ?></td><td><?= htmlspecialchars($item['reorder_level']) ?><br><span class="muted-copy">Lead time <?= (int) $item['lead_time_days'] ?> days</span></td><td><?= $low ? '<strong>Review stock</strong>' : 'No current signal' ?><?php if ($dailyRate > 0): ?><br><span class="muted-copy">Suggested amount to reach reorder + lead-time use: <?= number_format($suggested, 2) ?> <?= htmlspecialchars($item['unit']) ?></span><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
            <p class="muted-copy">*Based on the last 90 days of recorded issues, including zeros where no issue is recorded. Confirm stock physically and review local procurement rules before acting.</p>
        </section>
        <section class="planning-card">
            <h2>Record a stock movement</h2>
            <form method="POST" class="care-form">
                <?= csrfField() ?><input type="hidden" name="action" value="record_transaction">
                <div><label for="commodity_id">Commodity</label><select name="commodity_id" id="commodity_id" required><option value="">Select item</option><?php foreach ($commodities as $item): ?><option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars($item['name'] . ' (' . $item['unit'] . ')') ?></option><?php endforeach; ?></select></div>
                <div><label for="transaction_type">Movement type</label><select name="transaction_type" id="transaction_type" required><option value="receipt">Receipt / stock in</option><option value="issue">Issue / stock out</option><option value="adjustment">Adjustment (signed quantity)</option></select></div>
                <div><label for="transaction_date">Movement date</label><input type="date" name="transaction_date" id="transaction_date" value="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($today) ?>" required></div>
                <div><label for="quantity">Quantity</label><input type="number" step="0.01" name="quantity" id="quantity" required></div>
                <div><label for="reference">Reference (optional)</label><input type="text" name="reference" id="reference" maxlength="100"></div>
                <div><label for="notes">Notes (optional)</label><input type="text" name="notes" id="notes" maxlength="255"></div>
                <button type="submit" class="btn btn-primary">Record movement</button>
            </form>
        </section>
    </div>
    <section class="planning-card">
        <h2>Recent stock ledger</h2>
        <?php if (!$stockHistory): ?><p class="muted-copy">No stock movements have been recorded.</p><?php else: ?>
            <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Date</th><th>Commodity</th><th>Movement</th><th>Reference / notes</th><th>Recorded by</th></tr></thead><tbody>
            <?php foreach ($stockHistory as $movement): ?>
                <tr><td><?= formatDate($movement['transaction_date']) ?></td><td><?= htmlspecialchars($movement['commodity_name']) ?></td><td><?= htmlspecialchars(ucfirst($movement['transaction_type'])) ?> · <?= htmlspecialchars($movement['quantity_delta'] . ' ' . $movement['unit']) ?></td><td><?= htmlspecialchars($movement['reference'] ?? '—') ?><br><?= htmlspecialchars($movement['notes'] ?? '') ?></td><td><?= htmlspecialchars($movement['recorded_by_name'] ?? 'Former staff') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <div class="planning-grid">
        <section class="planning-card">
            <h2>Register commodity</h2>
            <form method="POST" class="care-form">
                <?= csrfField() ?><input type="hidden" name="action" value="create_commodity">
                <div><label for="code">Item code</label><input type="text" name="code" id="code" maxlength="30" pattern="[A-Za-z0-9_-]{2,30}" required></div>
                <div><label for="name">Name</label><input type="text" name="name" id="name" maxlength="120" required></div>
                <div><label for="unit">Stock unit</label><input type="text" name="unit" id="unit" maxlength="30" placeholder="e.g. packs, tablets, tests" required></div>
                <div><label for="reorder_level">Reorder level in stock units</label><input type="number" step="0.01" min="0" name="reorder_level" id="reorder_level" value="0" required></div>
                <div><label for="lead_time_days">Supply lead time (days)</label><input type="number" min="0" max="365" name="lead_time_days" id="lead_time_days" value="0" required></div>
                <button type="submit" class="btn btn-primary">Add commodity</button>
            </form>
        </section>
        <section class="planning-card">
            <h2>Monthly staffing capacity · <?= htmlspecialchars(date('F Y', strtotime($monthStart))) ?></h2>
            <p class="muted-copy">Record facility-level planned and available staffing by cadre, with source notes for auditability.</p>
            <form method="POST" class="care-form">
                <?= csrfField() ?><input type="hidden" name="action" value="save_staffing">
                <div><label for="cadre">Cadre / team</label><input type="text" name="cadre" id="cadre" maxlength="80" placeholder="e.g. Midwives, Community health workers" required></div>
                <div><label for="planned_fte">Planned staffing (FTE)</label><input type="number" step="0.01" min="0" max="999" name="planned_fte" id="planned_fte" required></div>
                <div><label for="available_fte">Available staffing (FTE)</label><input type="number" step="0.01" min="0" max="999" name="available_fte" id="available_fte" required></div>
                <div><label for="available_hours">Available service hours (optional)</label><input type="number" step="0.01" min="0" name="available_hours" id="available_hours"></div>
                <div><label for="source">Data source (optional)</label><input type="text" name="source" id="source" maxlength="150"></div>
                <div><label for="staffing_notes">Notes (optional)</label><input type="text" name="staffing_notes" id="staffing_notes" maxlength="255"></div>
                <button type="submit" class="btn btn-primary">Save capacity</button>
            </form>
            <?php if ($staffing): ?><div style="overflow-x:auto;margin-top:18px"><table class="record-table"><thead><tr><th>Cadre</th><th>Planned FTE</th><th>Available FTE</th><th>Hours</th></tr></thead><tbody><?php foreach ($staffing as $record): ?><tr><td><?= htmlspecialchars($record['cadre']) ?></td><td><?= htmlspecialchars($record['planned_fte']) ?></td><td><?= htmlspecialchars($record['available_fte']) ?></td><td><?= $record['available_hours'] !== null ? htmlspecialchars($record['available_hours']) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
    </div>
</div>
<?php require_once 'footer.php'; ?>
