<?php

require_once 'config.php';
requireLogin();

$error = '';
$success = isset($_GET['saved']) ? 'Screening record saved.' : '';
$today = date('Y-m-d');
$selectedPatientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
$selectedEpisodeId = filter_input(INPUT_GET, 'episode_id', FILTER_VALIDATE_INT);
$selectedBabyId = filter_input(INPUT_GET, 'baby_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
        $episodeId = empty($_POST['pregnancy_episode_id']) ? null : filter_var($_POST['pregnancy_episode_id'], FILTER_VALIDATE_INT);
        $babyId = empty($_POST['baby_id']) ? null : filter_var($_POST['baby_id'], FILTER_VALIDATE_INT);
        $screeningCode = $_POST['screening_code'] ?? '';
        $performedAt = $_POST['performed_at'] ?? '';
        $status = $_POST['result_status'] ?? '';
        $method = trim($_POST['method'] ?? '');
        $numericRaw = trim($_POST['numeric_value'] ?? '');
        $unit = trim($_POST['value_unit'] ?? '');
        $resultText = trim($_POST['result_text'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $allowedStatuses = ['pending', 'normal', 'abnormal', 'positive', 'negative', 'inconclusive'];

        if ($patientId === false || $patientId < 1 || ($episodeId !== null && ($episodeId === false || $episodeId < 1)) || ($babyId !== null && ($babyId === false || $babyId < 1))) {
            throw new InvalidArgumentException('Choose a valid patient and care record.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $performedAt) || !checkdate((int) substr($performedAt, 5, 2), (int) substr($performedAt, 8, 2), (int) substr($performedAt, 0, 4)) || $performedAt > $today) {
            throw new InvalidArgumentException('Enter a valid screening date that is not in the future.');
        }
        if (!in_array($status, $allowedStatuses, true) || strlen($method) > 100 || strlen($resultText) > 255 || strlen($unit) > 30 || strlen($notes) > 4000) {
            throw new InvalidArgumentException('Choose a valid result status and keep result details within the allowed length.');
        }

        $catalogStmt = $pdo->prepare('SELECT value_kind, default_unit, population FROM screening_catalog WHERE code = :code AND is_active = 1');
        $catalogStmt->execute([':code' => $screeningCode]);
        $catalog = $catalogStmt->fetch();
        if (!$catalog) {
            throw new InvalidArgumentException('Choose an active screening type.');
        }
        if (($babyId !== null && $catalog['population'] === 'maternal') || ($babyId === null && $catalog['population'] === 'newborn')) {
            throw new InvalidArgumentException('The screening type and selected care recipient do not match.');
        }
        $numericValue = null;
        if ($numericRaw !== '') {
            if (!is_numeric($numericRaw) || !is_finite((float) $numericRaw)) {
                throw new InvalidArgumentException('Enter a valid numeric result.');
            }
            $numericValue = (float) $numericRaw;
        }
        if ($catalog['value_kind'] === 'numeric' && $status !== 'pending' && $numericValue === null && $resultText === '') {
            throw new InvalidArgumentException('Enter a numeric value or text result, or mark the test pending.');
        }
        if ($catalog['value_kind'] !== 'numeric' && $status !== 'pending' && $resultText === '') {
            throw new InvalidArgumentException('Enter the observed result, or mark the test pending.');
        }
        if ($unit === '') {
            $unit = $catalog['default_unit'] ?? '';
        }
        if ($numericValue !== null && $unit === '') {
            throw new InvalidArgumentException('Enter the unit used for the numeric result.');
        }

        $patientCheck = $pdo->prepare('SELECT 1 FROM patients WHERE id = :id');
        $patientCheck->execute([':id' => $patientId]);
        if (!$patientCheck->fetchColumn()) {
            throw new InvalidArgumentException('The selected patient was not found.');
        }
        if ($episodeId !== null) {
            $episodeCheck = $pdo->prepare('SELECT 1 FROM pregnancy_episodes WHERE id = :id AND patient_id = :patient_id');
            $episodeCheck->execute([':id' => $episodeId, ':patient_id' => $patientId]);
            if (!$episodeCheck->fetchColumn()) {
                throw new InvalidArgumentException('The selected pregnancy episode does not belong to this patient.');
            }
        }
        if ($babyId !== null) {
            $babyCheck = $pdo->prepare(
                'SELECT pe.id AS episode_id FROM babies b
                 JOIN delivery_records d ON d.id = b.delivery_record_id
                 JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
                 WHERE b.id = :baby_id AND pe.patient_id = :patient_id'
            );
            $babyCheck->execute([':baby_id' => $babyId, ':patient_id' => $patientId]);
            $babyEpisodeId = $babyCheck->fetchColumn();
            if (!$babyEpisodeId) {
                throw new InvalidArgumentException('The selected newborn does not belong to this patient.');
            }
            if ($episodeId !== null && (int) $episodeId !== (int) $babyEpisodeId) {
                throw new InvalidArgumentException('The newborn and pregnancy episode do not match.');
            }
            $episodeId = (int) $babyEpisodeId;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO screening_results (
                patient_id, pregnancy_episode_id, baby_id, screening_code, performed_at,
                result_status, method, numeric_value, value_unit, result_text, notes, recorded_by
             ) VALUES (
                :patient_id, :episode_id, :baby_id, :screening_code, :performed_at,
                :result_status, :method, :numeric_value, :value_unit, :result_text, :notes, :user_id
             )'
        );
        $stmt->execute([
            ':patient_id' => $patientId,
            ':episode_id' => $episodeId,
            ':baby_id' => $babyId,
            ':screening_code' => $screeningCode,
            ':performed_at' => $performedAt,
            ':result_status' => $status,
            ':method' => $method === '' ? null : $method,
            ':numeric_value' => $numericValue,
            ':value_unit' => $unit === '' ? null : $unit,
            ':result_text' => $resultText === '' ? null : $resultText,
            ':notes' => $notes === '' ? null : $notes,
            ':user_id' => currentUserId(),
        ]);
        logAction($pdo, currentUserId(), 'CREATE_SCREENING', 'screening_results', $pdo->lastInsertId(), 'Screening result recorded');
        $pdo->commit();
        header('Location: screenings.php?saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save the screening result. Please try again.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Screening request failed: ' . $e->getMessage());
        }
    }
}

$patients = $pdo->query('SELECT id, surname, first_name FROM patients ORDER BY surname, first_name LIMIT 2000')->fetchAll();
$episodes = $pdo->query(
    'SELECT pe.id, pe.patient_id, pe.client_number, pe.status
     FROM pregnancy_episodes pe ORDER BY pe.created_at DESC LIMIT 2000'
)->fetchAll();
$babies = $pdo->query(
    'SELECT b.id, pe.patient_id, pe.id AS episode_id, b.birth_order, d.delivery_datetime, p.surname, p.first_name
     FROM babies b JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     ORDER BY d.delivery_datetime DESC LIMIT 1000'
)->fetchAll();
$catalog = $pdo->query('SELECT code, name, population, value_kind, default_unit FROM screening_catalog WHERE is_active = 1 ORDER BY name')->fetchAll();
$results = $pdo->query(
    'SELECT s.*, c.name AS screening_name, p.surname, p.first_name, pe.client_number, b.birth_order
     FROM screening_results s
     JOIN screening_catalog c ON c.code = s.screening_code
     JOIN patients p ON p.id = s.patient_id
     LEFT JOIN pregnancy_episodes pe ON pe.id = s.pregnancy_episode_id
     LEFT JOIN babies b ON b.id = s.baby_id
     ORDER BY s.performed_at DESC, s.id DESC LIMIT 300'
)->fetchAll();
$summary = $pdo->query(
    "SELECT
        SUM(result_status IN ('abnormal', 'positive')) AS needs_followup,
        SUM(result_status = 'pending') AS pending,
        COUNT(*) AS total
     FROM screening_results
     WHERE performed_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
)->fetch() ?: ['needs_followup' => 0, 'pending' => 0, 'total' => 0];
?>
<?php require_once 'nav.php'; ?>
<style>
    .continuity-page { max-width: 1500px; margin: 0 auto; }
    .continuity-grid { display: grid; grid-template-columns: minmax(300px, 380px) minmax(0, 1fr); gap: 20px; align-items: start; }
    .continuity-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 22px; }
    .continuity-card h2 { font-size: 18px; margin-bottom: 16px; }
    .care-form { display: grid; gap: 12px; }
    .care-form label { display: block; color: var(--text-secondary); font-size: 13px; font-weight: 600; margin-bottom: 5px; }
    .care-form input, .care-form select, .care-form textarea { width: 100%; }
    .care-form textarea { min-height: 72px; }
    .record-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .record-table th, .record-table td { border-bottom: 1px solid var(--border); padding: 10px 8px; text-align: left; vertical-align: top; }
    .record-table th { color: var(--text-secondary); }
    .metric-row { display: flex; gap: 12px; margin-bottom: 16px; }
    .metric { flex: 1; background: var(--background); border-radius: 10px; padding: 12px; }
    .metric strong { display: block; font-size: 20px; }
    .muted-copy { color: var(--text-secondary); font-size: 12px; }
    @media (max-width: 900px) { .continuity-grid { grid-template-columns: 1fr; } }
</style>
<div class="continuity-page">
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="continuity-grid">
        <section class="continuity-card">
            <h2>Record screening</h2>
            <p class="muted-copy">Capture the test, method/result as reported, date, and care episode. Results are stored as observations; the system does not diagnose or apply thresholds.</p>
            <form method="POST" class="care-form">
                <?= csrfField() ?>
                <div><label for="patient_id">Mother / patient</label><select name="patient_id" id="patient_id" required><option value="">Select patient</option><?php foreach ($patients as $patient): ?><option value="<?= (int) $patient['id'] ?>" <?= (int) $selectedPatientId === (int) $patient['id'] ? 'selected' : '' ?>><?= htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']) ?> (<?= (int) $patient['id'] ?>)</option><?php endforeach; ?></select></div>
                <div><label for="pregnancy_episode_id">Pregnancy episode (optional)</label><select name="pregnancy_episode_id" id="pregnancy_episode_id"><option value="">Not linked to an episode</option><?php foreach ($episodes as $episode): ?><option value="<?= (int) $episode['id'] ?>" data-patient="<?= (int) $episode['patient_id'] ?>" <?= (int) $selectedEpisodeId === (int) $episode['id'] ? 'selected' : '' ?>><?= htmlspecialchars($episode['client_number'] . ' · ' . $episode['status']) ?></option><?php endforeach; ?></select></div>
                <div><label for="baby_id">Newborn (optional)</label><select name="baby_id" id="baby_id"><option value="">Not linked to a newborn</option><?php foreach ($babies as $baby): ?><option value="<?= (int) $baby['id'] ?>" data-patient="<?= (int) $baby['patient_id'] ?>" data-episode="<?= (int) $baby['episode_id'] ?>" <?= (int) $selectedBabyId === (int) $baby['id'] ? 'selected' : '' ?>><?= htmlspecialchars($baby['surname'] . ' ' . $baby['first_name'] . ' · Baby ' . $baby['birth_order'] . ' · ' . formatDate($baby['delivery_datetime'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="screening_code">Screening type</label><select name="screening_code" id="screening_code" required><?php foreach ($catalog as $item): ?><option value="<?= htmlspecialchars($item['code']) ?>" data-unit="<?= htmlspecialchars($item['default_unit'] ?? '') ?>"><?= htmlspecialchars($item['name'] . ' · ' . ucfirst($item['population'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="performed_at">Test date</label><input type="date" name="performed_at" id="performed_at" value="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($today) ?>" required></div>
                <div><label for="result_status">Recorded result status</label><select name="result_status" id="result_status" required><option value="pending">Pending</option><option value="normal">Normal</option><option value="abnormal">Abnormal</option><option value="positive">Positive</option><option value="negative">Negative</option><option value="inconclusive">Inconclusive</option></select></div>
                <div><label for="numeric_value">Numeric result (if applicable)</label><input type="number" step="0.001" name="numeric_value" id="numeric_value" inputmode="decimal"></div>
                <div><label for="value_unit">Unit</label><input type="text" name="value_unit" id="value_unit" maxlength="30"></div>
                <div><label for="method">Method (optional)</label><input type="text" name="method" id="method" maxlength="100"></div>
                <div><label for="result_text">Observed result</label><input type="text" name="result_text" id="result_text" maxlength="255"></div>
                <div><label for="notes">Notes</label><textarea name="notes" id="notes" maxlength="4000"></textarea></div>
                <button type="submit" class="btn btn-primary">Save screening</button>
            </form>
        </section>
        <section class="continuity-card">
            <h2>Recent screening activity</h2>
            <div class="metric-row">
                <div class="metric"><span class="muted-copy">Last 90 days</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></div>
                <div class="metric"><span class="muted-copy">Pending</span><strong><?= (int) ($summary['pending'] ?? 0) ?></strong></div>
                <div class="metric"><span class="muted-copy">Abnormal / positive</span><strong><?= (int) ($summary['needs_followup'] ?? 0) ?></strong></div>
            </div>
            <p class="muted-copy">Counts indicate recorded results only and do not determine diagnosis or urgency. Review according to the approved facility protocol.</p>
            <?php if (!$results): ?><p class="muted-copy">No screening results have been recorded.</p><?php else: ?>
                <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Date / patient</th><th>Test</th><th>Recorded result</th><th>Linked episode</th></tr></thead><tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?= formatDate($result['performed_at']) ?><br><?= htmlspecialchars($result['surname'] . ' ' . $result['first_name']) ?></td>
                        <td><?= htmlspecialchars($result['screening_name']) ?><br><span class="muted-copy"><?= $result['baby_id'] ? 'Newborn ' . (int) $result['birth_order'] : ($result['client_number'] ? htmlspecialchars($result['client_number']) : 'Patient') ?></span></td>
                        <td><?= htmlspecialchars(ucfirst($result['result_status'])) ?><?php if ($result['numeric_value'] !== null): ?><br><?= htmlspecialchars($result['numeric_value'] . ' ' . ($result['value_unit'] ?? '')) ?><?php endif; ?><?php if ($result['result_text']): ?><br><?= htmlspecialchars($result['result_text']) ?><?php endif; ?><?php if ($result['method']): ?><br><span class="muted-copy">Method: <?= htmlspecialchars($result['method']) ?></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($result['client_number'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
    const patientSelector = document.getElementById('patient_id');
    for (const selector of [document.getElementById('pregnancy_episode_id'), document.getElementById('baby_id')]) {
        selector.addEventListener('change', () => {
            const option = selector.options[selector.selectedIndex];
            if (option.dataset.patient) patientSelector.value = option.dataset.patient;
            if (option.dataset.episode) document.getElementById('pregnancy_episode_id').value = option.dataset.episode;
        });
        patientSelector.addEventListener('change', () => {
            for (const option of selector.options) option.hidden = Boolean(option.dataset.patient && option.dataset.patient !== patientSelector.value);
            if (selector.selectedOptions[0]?.dataset.patient && selector.selectedOptions[0].dataset.patient !== patientSelector.value) selector.value = '';
        });
        patientSelector.dispatchEvent(new Event('change'));
    }
    const screeningSelector = document.getElementById('screening_code');
    const unitInput = document.getElementById('value_unit');
    screeningSelector.addEventListener('change', () => { unitInput.value = screeningSelector.selectedOptions[0].dataset.unit || ''; });
</script>
<?php require_once 'footer.php'; ?>
