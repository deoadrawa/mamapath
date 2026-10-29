<?php

require_once 'config.php';
requireLogin();

$error = '';
$success = isset($_GET['saved']) ? 'Referral information saved.' : '';
$today = date('Y-m-d');
$selectedPatientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
$selectedEpisodeId = filter_input(INPUT_GET, 'episode_id', FILTER_VALIDATE_INT);
$selectedBabyId = filter_input(INPUT_GET, 'baby_id', FILTER_VALIDATE_INT);
$categories = [
    'danger_sign' => 'Danger sign',
    'abnormal_screening' => 'Abnormal screening',
    'specialist_review' => 'Specialist review',
    'emergency' => 'Emergency',
    'community_followup' => 'Community follow-up',
    'other' => 'Other',
];
$urgencies = ['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'];
$statuses = [
    'initiated' => 'Initiated',
    'accepted' => 'Accepted',
    'completed' => 'Completed',
    'not_completed' => 'Not completed',
    'declined' => 'Declined',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $action = $_POST['action'] ?? '';
        $referralId = filter_var($_POST['referral_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'create') {
            $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
            $episodeId = empty($_POST['pregnancy_episode_id']) ? null : filter_var($_POST['pregnancy_episode_id'], FILTER_VALIDATE_INT);
            $babyId = empty($_POST['baby_id']) ? null : filter_var($_POST['baby_id'], FILTER_VALIDATE_INT);
            $screeningResultId = empty($_POST['screening_result_id']) ? null : filter_var($_POST['screening_result_id'], FILTER_VALIDATE_INT);
            $referralDate = $_POST['referral_date'] ?? '';
            $followupDate = empty($_POST['followup_date']) ? null : $_POST['followup_date'];
            $category = $_POST['reason_category'] ?? '';
            $urgency = $_POST['urgency'] ?? '';
            $reason = trim($_POST['reason'] ?? '');
            $destination = trim($_POST['destination'] ?? '');

            if ($patientId === false || $patientId < 1 || ($episodeId !== null && ($episodeId === false || $episodeId < 1)) || ($babyId !== null && ($babyId === false || $babyId < 1)) || ($screeningResultId !== null && ($screeningResultId === false || $screeningResultId < 1))) {
                throw new InvalidArgumentException('Choose a valid patient and care record.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $referralDate) || !checkdate((int) substr($referralDate, 5, 2), (int) substr($referralDate, 8, 2), (int) substr($referralDate, 0, 4)) || $referralDate > $today) {
                throw new InvalidArgumentException('The referral date must be valid and cannot be in the future.');
            }
            if ($followupDate !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followupDate) || !checkdate((int) substr($followupDate, 5, 2), (int) substr($followupDate, 8, 2), (int) substr($followupDate, 0, 4)))) {
                throw new InvalidArgumentException('Enter a valid follow-up date.');
            }
            if ($followupDate !== null && $followupDate < $referralDate) {
                throw new InvalidArgumentException('The follow-up date cannot be before the referral date.');
            }
            if (!isset($categories[$category]) || !isset($urgencies[$urgency]) || $reason === '' || strlen($reason) > 255 || $destination === '' || strlen($destination) > 150) {
                throw new InvalidArgumentException('Enter the referral reason, destination, category, and urgency.');
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
            if ($screeningResultId !== null) {
                if ($category !== 'abnormal_screening') {
                    throw new InvalidArgumentException('A linked screening result requires the abnormal screening referral category.');
                }
                $screeningCheck = $pdo->prepare(
                    'SELECT patient_id, pregnancy_episode_id, baby_id
                     FROM screening_results WHERE id = :id'
                );
                $screeningCheck->execute([':id' => $screeningResultId]);
                $screening = $screeningCheck->fetch();
                if (!$screening || (int) $screening['patient_id'] !== (int) $patientId) {
                    throw new InvalidArgumentException('The selected screening result does not belong to this patient.');
                }
                if ($episodeId !== null && (int) ($screening['pregnancy_episode_id'] ?? 0) !== (int) $episodeId) {
                    throw new InvalidArgumentException('The selected screening result and pregnancy episode do not match.');
                }
                if ($babyId !== null && (int) ($screening['baby_id'] ?? 0) !== (int) $babyId) {
                    throw new InvalidArgumentException('The selected screening result and newborn do not match.');
                }
                $episodeId = $screening['pregnancy_episode_id'] ? (int) $screening['pregnancy_episode_id'] : null;
                $babyId = $screening['baby_id'] ? (int) $screening['baby_id'] : null;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO referrals (
                    patient_id, pregnancy_episode_id, baby_id, screening_result_id, referral_date, reason_category,
                    reason, urgency, destination, followup_date, recorded_by
                 ) VALUES (
                    :patient_id, :episode_id, :baby_id, :screening_result_id, :referral_date, :category,
                    :reason, :urgency, :destination, :followup_date, :user_id
                 )"
            );
            $stmt->execute([
                ':patient_id' => $patientId,
                ':episode_id' => $episodeId,
                ':baby_id' => $babyId,
                ':screening_result_id' => $screeningResultId,
                ':referral_date' => $referralDate,
                ':category' => $category,
                ':reason' => $reason,
                ':urgency' => $urgency,
                ':destination' => $destination,
                ':followup_date' => $followupDate,
                ':user_id' => currentUserId(),
            ]);
            logAction($pdo, currentUserId(), 'CREATE_REFERRAL', 'referrals', $pdo->lastInsertId(), 'Referral recorded');
            $pdo->commit();
            header('Location: referrals.php?saved=1');
            exit;
        }

        if ($action === 'update' && $referralId !== false && $referralId > 0) {
            $status = $_POST['status'] ?? '';
            $completedDate = empty($_POST['completed_date']) ? null : $_POST['completed_date'];
            $outcome = trim($_POST['outcome_notes'] ?? '');
            if (!isset($statuses[$status]) || strlen($outcome) > 4000) {
                throw new InvalidArgumentException('Choose a valid referral status and enter a shorter outcome note.');
            }
            if ($completedDate !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedDate) || !checkdate((int) substr($completedDate, 5, 2), (int) substr($completedDate, 8, 2), (int) substr($completedDate, 0, 4)) || $completedDate > $today)) {
                throw new InvalidArgumentException('Enter a valid completion date that is not in the future.');
            }
            if ($status === 'completed' && $completedDate === null) {
                throw new InvalidArgumentException('A completed referral needs its completion date.');
            }
            if ($status !== 'completed') {
                $completedDate = null;
            }
            $pdo->beginTransaction();
            $existingStmt = $pdo->prepare('SELECT referral_date FROM referrals WHERE id = :id FOR UPDATE');
            $existingStmt->execute([':id' => $referralId]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Referral not found.');
            }
            if ($completedDate !== null && $completedDate < $existing['referral_date']) {
                throw new InvalidArgumentException('The completion date cannot be before the referral date.');
            }
            $stmt = $pdo->prepare(
                'UPDATE referrals SET status = :status, completed_date = :completed_date, outcome_notes = :outcome
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $status,
                ':completed_date' => $completedDate,
                ':outcome' => $outcome === '' ? null : $outcome,
                ':id' => $referralId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Referral not found or no changes were made.');
            }
            logAction($pdo, currentUserId(), 'UPDATE_REFERRAL', 'referrals', $referralId, 'Referral status updated to ' . $status);
            $pdo->commit();
            header('Location: referrals.php?saved=1');
            exit;
        }
        throw new InvalidArgumentException('Unknown referral action.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save the referral. Please try again.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Referral request failed: ' . $e->getMessage());
        }
    }
}

$patients = $pdo->query(
    "SELECT id, surname, first_name FROM patients ORDER BY surname, first_name LIMIT 2000"
)->fetchAll();
$episodes = $pdo->query(
    "SELECT pe.id, pe.patient_id, pe.client_number, pe.status, pe.lmp
     FROM pregnancy_episodes pe
     ORDER BY pe.created_at DESC LIMIT 2000"
)->fetchAll();
$babies = $pdo->query(
    "SELECT b.id, pe.patient_id, pe.id AS episode_id, b.birth_order, d.delivery_datetime, pe.client_number,
            p.surname, p.first_name
     FROM babies b
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     ORDER BY d.delivery_datetime DESC LIMIT 1000"
)->fetchAll();
$screeningOptions = $pdo->query(
    "SELECT s.id, s.patient_id, s.pregnancy_episode_id, s.baby_id, s.performed_at,
            s.screening_code, s.result_status, c.name AS screening_name,
            p.surname, p.first_name
     FROM screening_results s
     JOIN screening_catalog c ON c.code = s.screening_code
     JOIN patients p ON p.id = s.patient_id
     WHERE s.result_status IN ('abnormal', 'positive', 'pending', 'inconclusive')
     ORDER BY s.performed_at DESC, s.id DESC LIMIT 500"
)->fetchAll();
$referrals = $pdo->query(
    "SELECT r.*, p.surname, p.first_name, pe.client_number, b.birth_order,
            c.name AS screening_name
     FROM referrals r
     JOIN patients p ON p.id = r.patient_id
     LEFT JOIN pregnancy_episodes pe ON pe.id = r.pregnancy_episode_id
     LEFT JOIN babies b ON b.id = r.baby_id
     LEFT JOIN screening_results s ON s.id = r.screening_result_id
     LEFT JOIN screening_catalog c ON c.code = s.screening_code
     ORDER BY FIELD(r.status, 'initiated', 'accepted', 'not_completed', 'declined', 'completed'),
              r.referral_date DESC, r.id DESC
     LIMIT 250"
)->fetchAll();
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
    .status-form { min-width: 190px; display: grid; gap: 6px; }
    .muted-copy { color: var(--text-secondary); font-size: 12px; }
    @media (max-width: 900px) { .continuity-grid { grid-template-columns: 1fr; } }
</style>
<div class="continuity-page">
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="continuity-grid">
        <section class="continuity-card">
            <h2>Record a referral</h2>
            <p class="muted-copy">Record the referral and its destination; follow-up status is updated when the outcome is known.</p>
            <form method="POST" class="care-form">
                <?= csrfField() ?><input type="hidden" name="action" value="create">
                <div><label for="patient_id">Mother / patient</label><select name="patient_id" id="patient_id" required><option value="">Select patient</option><?php foreach ($patients as $patient): ?><option value="<?= (int) $patient['id'] ?>" <?= (int) $selectedPatientId === (int) $patient['id'] ? 'selected' : '' ?>><?= htmlspecialchars($patient['surname'] . ' ' . $patient['first_name']) ?> (<?= (int) $patient['id'] ?>)</option><?php endforeach; ?></select></div>
                <div><label for="pregnancy_episode_id">Pregnancy episode (optional)</label><select name="pregnancy_episode_id" id="pregnancy_episode_id"><option value="">Not linked to an episode</option><?php foreach ($episodes as $episode): ?><option value="<?= (int) $episode['id'] ?>" data-patient="<?= (int) $episode['patient_id'] ?>" <?= (int) $selectedEpisodeId === (int) $episode['id'] ? 'selected' : '' ?>><?= htmlspecialchars($episode['client_number'] . ' · ' . $episode['status'] . ' · LMP ' . formatDate($episode['lmp'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="baby_id">Newborn (optional)</label><select name="baby_id" id="baby_id"><option value="">Not linked to a newborn</option><?php foreach ($babies as $baby): ?><option value="<?= (int) $baby['id'] ?>" data-patient="<?= (int) $baby['patient_id'] ?>" data-episode="<?= (int) $baby['episode_id'] ?>" <?= (int) $selectedBabyId === (int) $baby['id'] ? 'selected' : '' ?>><?= htmlspecialchars($baby['surname'] . ' ' . $baby['first_name'] . ' · Baby ' . $baby['birth_order'] . ' · ' . formatDate($baby['delivery_datetime'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="screening_result_id">Link abnormal / pending screening (optional)</label><select name="screening_result_id" id="screening_result_id"><option value="">No linked screening result</option><?php foreach ($screeningOptions as $screening): ?><option value="<?= (int) $screening['id'] ?>" data-patient="<?= (int) $screening['patient_id'] ?>" data-episode="<?= (int) ($screening['pregnancy_episode_id'] ?? 0) ?>" data-baby="<?= (int) ($screening['baby_id'] ?? 0) ?>"><?= htmlspecialchars($screening['surname'] . ' ' . $screening['first_name'] . ' · ' . $screening['screening_name'] . ' · ' . ucfirst($screening['result_status']) . ' · ' . formatDate($screening['performed_at'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="referral_date">Referral date</label><input type="date" name="referral_date" id="referral_date" value="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($today) ?>" required></div>
                <div><label for="reason_category">Referral category</label><select name="reason_category" id="reason_category" required><?php foreach ($categories as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                <div><label for="urgency">Urgency</label><select name="urgency" id="urgency" required><?php foreach ($urgencies as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                <div><label for="reason">Reason</label><input type="text" name="reason" id="reason" maxlength="255" required></div>
                <div><label for="destination">Destination / receiving facility</label><input type="text" name="destination" id="destination" maxlength="150" required></div>
                <div><label for="followup_date">Planned follow-up date (optional)</label><input type="date" name="followup_date" id="followup_date"></div>
                <button type="submit" class="btn btn-primary">Save referral</button>
            </form>
        </section>
        <section class="continuity-card">
            <h2>Referral tracking</h2>
            <?php if (!$referrals): ?><p class="muted-copy">No referrals have been recorded.</p><?php else: ?>
                <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Patient / linked record</th><th>Referral</th><th>Destination / urgency</th><th>Status and outcome</th></tr></thead><tbody>
                <?php foreach ($referrals as $referral): ?>
                    <tr>
                        <td><?= htmlspecialchars($referral['surname'] . ' ' . $referral['first_name']) ?><br><span class="muted-copy"><?= htmlspecialchars($referral['client_number'] ?? ($referral['baby_id'] ? 'Newborn ' . $referral['birth_order'] : 'Patient record')) ?></span></td>
                        <td><?= htmlspecialchars($categories[$referral['reason_category']] ?? $referral['reason_category']) ?><br><?= htmlspecialchars($referral['reason']) ?><?= $referral['screening_name'] ? '<br><span class="muted-copy">Linked test: ' . htmlspecialchars($referral['screening_name']) . '</span>' : '' ?><br><span class="muted-copy"><?= formatDate($referral['referral_date']) ?><?= $referral['followup_date'] ? ' · Follow-up ' . formatDate($referral['followup_date']) : '' ?></span></td>
                        <td><?= htmlspecialchars($referral['destination']) ?><br><span class="muted-copy"><?= htmlspecialchars(ucfirst($referral['urgency'])) ?></span></td>
                        <td>
                            <form method="POST" class="status-form">
                                <?= csrfField() ?><input type="hidden" name="action" value="update"><input type="hidden" name="referral_id" value="<?= (int) $referral['id'] ?>">
                                <select name="status"><?php foreach ($statuses as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>" <?= $referral['status'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select>
                                <input type="date" name="completed_date" value="<?= htmlspecialchars($referral['completed_date'] ?? '') ?>" max="<?= htmlspecialchars($today) ?>" aria-label="Completion date">
                                <input type="text" name="outcome_notes" value="<?= htmlspecialchars($referral['outcome_notes'] ?? '') ?>" maxlength="4000" placeholder="Outcome / follow-up note" aria-label="Outcome notes">
                                <button type="submit" class="btn btn-secondary">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
    const patientSelector = document.getElementById('patient_id');
    const episodeSelector = document.getElementById('pregnancy_episode_id');
    const babySelector = document.getElementById('baby_id');
    const screeningSelector = document.getElementById('screening_result_id');
    for (const selector of [episodeSelector, babySelector, screeningSelector]) {
        selector.addEventListener('change', () => {
            const option = selector.options[selector.selectedIndex];
            if (option.dataset.patient) patientSelector.value = option.dataset.patient;
            if (option.dataset.episode && option.dataset.episode !== '0') episodeSelector.value = option.dataset.episode;
            if (selector === screeningSelector) {
                if (option.dataset.baby && option.dataset.baby !== '0') babySelector.value = option.dataset.baby;
                else babySelector.value = '';
                document.getElementById('reason_category').value = 'abnormal_screening';
                patientSelector.dispatchEvent(new Event('change'));
            }
        });
    }
    patientSelector.addEventListener('change', () => {
        for (const selector of [episodeSelector, babySelector, screeningSelector]) {
            for (const option of selector.options) {
                option.hidden = Boolean(option.dataset.patient && option.dataset.patient !== patientSelector.value);
            }
            if (selector.selectedOptions[0]?.dataset.patient && selector.selectedOptions[0].dataset.patient !== patientSelector.value) selector.value = '';
        }
    });
    patientSelector.dispatchEvent(new Event('change'));
</script>
<?php require_once 'footer.php'; ?>
