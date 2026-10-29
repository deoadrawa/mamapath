<?php

require_once 'config.php';
requireLogin();

$error = '';
$success = isset($_GET['saved']) ? 'Child health record saved.' : '';
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $action = $_POST['action'] ?? 'create_visit';
        if ($action === 'record_immunization') {
            $babyId = filter_var($_POST['immunization_baby_id'] ?? null, FILTER_VALIDATE_INT);
            $visitId = empty($_POST['child_health_visit_id']) ? null : filter_var($_POST['child_health_visit_id'], FILTER_VALIDATE_INT);
            $vaccineName = trim($_POST['vaccine_name'] ?? '');
            $doseLabel = trim($_POST['dose_label'] ?? '');
            $eventDate = $_POST['event_date'] ?? '';
            $immunizationStatus = $_POST['immunization_status'] ?? '';
            $lotNumber = trim($_POST['lot_number'] ?? '');
            $immunizationNotes = trim($_POST['immunization_notes'] ?? '');
            $allowedImmunizationStatuses = ['given', 'not_given', 'missed', 'deferred', 'unknown'];

            if ($babyId === false || $babyId < 1 || ($visitId !== null && ($visitId === false || $visitId < 1)) || $vaccineName === '' || strlen($vaccineName) > 120 || strlen($doseLabel) > 50 || strlen($lotNumber) > 100 || strlen($immunizationNotes) > 255) {
                throw new InvalidArgumentException('Choose a newborn, enter the vaccine and keep event details within the allowed length.');
            }
            if (!in_array($immunizationStatus, $allowedImmunizationStatuses, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate) || !checkdate((int) substr($eventDate, 5, 2), (int) substr($eventDate, 8, 2), (int) substr($eventDate, 0, 4)) || $eventDate > $today) {
                throw new InvalidArgumentException('Choose a valid immunization status and event date.');
            }
            $babyCheck = $pdo->prepare(
                'SELECT b.id, DATE(d.delivery_datetime) AS birth_date
                 FROM babies b JOIN delivery_records d ON d.id = b.delivery_record_id
                 WHERE b.id = :id'
            );
            $babyCheck->execute([':id' => $babyId]);
            $baby = $babyCheck->fetch();
            if (!$baby || !$baby['birth_date'] || $eventDate < $baby['birth_date']) {
                throw new InvalidArgumentException('The newborn or immunization event date is invalid.');
            }
            if ((int) (new DateTimeImmutable($baby['birth_date']))->diff(new DateTimeImmutable($eventDate))->format('%a') > 730) {
                throw new InvalidArgumentException('Immunization events in this pathway are limited to 730 days after birth.');
            }
            if ($visitId !== null) {
                $visitCheck = $pdo->prepare('SELECT 1 FROM child_health_visits WHERE id = :id AND baby_id = :baby_id AND visit_date = :event_date');
                $visitCheck->execute([':id' => $visitId, ':baby_id' => $babyId, ':event_date' => $eventDate]);
                if (!$visitCheck->fetchColumn()) {
                    throw new InvalidArgumentException('The linked encounter must belong to this newborn and have the same date.');
                }
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO child_immunizations (
                    baby_id, child_health_visit_id, vaccine_name, dose_label, event_date,
                    status, lot_number, notes, recorded_by
                 ) VALUES (
                    :baby_id, :visit_id, :vaccine_name, :dose_label, :event_date,
                    :status, :lot_number, :notes, :user_id
                 )'
            );
            $stmt->execute([
                ':baby_id' => $babyId,
                ':visit_id' => $visitId,
                ':vaccine_name' => $vaccineName,
                ':dose_label' => $doseLabel === '' ? null : $doseLabel,
                ':event_date' => $eventDate,
                ':status' => $immunizationStatus,
                ':lot_number' => $lotNumber === '' ? null : $lotNumber,
                ':notes' => $immunizationNotes === '' ? null : $immunizationNotes,
                ':user_id' => currentUserId(),
            ]);
            logAction($pdo, currentUserId(), 'CREATE_CHILD_IMMUNIZATION', 'child_immunizations', $pdo->lastInsertId(), 'Child immunization event recorded');
            $pdo->commit();
            header('Location: child_followup.php?saved=1&baby_id=' . $babyId);
            exit;
        }
        if ($action !== 'create_visit') {
            throw new InvalidArgumentException('Unknown child health action.');
        }
        $babyId = filter_var($_POST['baby_id'] ?? null, FILTER_VALIDATE_INT);
        $visitDate = $_POST['visit_date'] ?? '';
        $feeding = trim($_POST['feeding_status'] ?? '');
        $concerns = trim($_POST['developmental_concerns'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($babyId === false || $babyId < 1) {
            throw new InvalidArgumentException('Choose a newborn.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) || !checkdate((int) substr($visitDate, 5, 2), (int) substr($visitDate, 8, 2), (int) substr($visitDate, 0, 4)) || $visitDate > $today) {
            throw new InvalidArgumentException('Enter a valid encounter date that is not in the future.');
        }
        foreach ([
            'weight_kg' => [0.2, 100, 'Weight'],
            'length_cm' => [10, 250, 'Length/height'],
            'head_circumference_cm' => [10, 100, 'Head circumference'],
        ] as $field => [$minimum, $maximum, $label]) {
            $raw = trim($_POST[$field] ?? '');
            if ($raw !== '' && (!is_numeric($raw) || (float) $raw < $minimum || (float) $raw > $maximum)) {
                throw new InvalidArgumentException($label . ' is outside the accepted entry range.');
            }
            ${$field} = $raw === '' ? null : (float) $raw;
        }
        if (strlen($feeding) > 100 || strlen($concerns) > 4000 || strlen($notes) > 4000) {
            throw new InvalidArgumentException('One or more child health notes are too long.');
        }

        $babyCheck = $pdo->prepare(
            'SELECT b.id, d.delivery_datetime
             FROM babies b JOIN delivery_records d ON d.id = b.delivery_record_id
             WHERE b.id = :id'
        );
        $babyCheck->execute([':id' => $babyId]);
        $baby = $babyCheck->fetch();
        if (!$baby || empty($baby['delivery_datetime'])) {
            throw new InvalidArgumentException('The selected newborn or birth date was not found.');
        }
        $birthDate = substr($baby['delivery_datetime'], 0, 10);
        if ($visitDate < $birthDate) {
            throw new InvalidArgumentException('The encounter date cannot be before the newborn’s birth date.');
        }
        $ageDays = (int) (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($visitDate))->format('%a');
        if ($ageDays > 730) {
            throw new InvalidArgumentException('This child health pathway records visits through 730 days after birth. Continue follow-up through the appropriate service after that age.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO child_health_visits (
                baby_id, visit_date, weight_kg, length_cm, head_circumference_cm,
                feeding_status, developmental_concerns, notes, recorded_by
             ) VALUES (
                :baby_id, :visit_date, :weight_kg, :length_cm, :head_circumference_cm,
                :feeding_status, :developmental_concerns, :notes, :user_id
             )'
        );
        $stmt->execute([
            ':baby_id' => $babyId,
            ':visit_date' => $visitDate,
            ':weight_kg' => $weight_kg,
            ':length_cm' => $length_cm,
            ':head_circumference_cm' => $head_circumference_cm,
            ':feeding_status' => $feeding === '' ? null : $feeding,
            ':developmental_concerns' => $concerns === '' ? null : $concerns,
            ':notes' => $notes === '' ? null : $notes,
            ':user_id' => currentUserId(),
        ]);
        logAction($pdo, currentUserId(), 'CREATE_CHILD_HEALTH_VISIT', 'child_health_visits', $pdo->lastInsertId(), 'Child health encounter recorded');
        $pdo->commit();
        header('Location: child_followup.php?saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save the child health encounter. Please try again.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Child health request failed: ' . $e->getMessage());
        }
    }
}

$babies = $pdo->query(
    "SELECT b.id, b.birth_order, d.delivery_datetime, p.surname, p.first_name, pe.client_number,
            DATEDIFF(CURDATE(), DATE(d.delivery_datetime)) AS age_days
     FROM babies b
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     WHERE d.delivery_datetime IS NOT NULL
       AND DATE(d.delivery_datetime) <= CURDATE()
       AND DATEDIFF(CURDATE(), DATE(d.delivery_datetime)) BETWEEN 0 AND 730
     ORDER BY d.delivery_datetime DESC, p.surname, p.first_name
     LIMIT 1000"
)->fetchAll();
$selectedBabyId = filter_input(INPUT_GET, 'baby_id', FILTER_VALIDATE_INT);
$visits = $pdo->query(
    'SELECT v.*, b.birth_order, d.delivery_datetime, p.surname, p.first_name, pe.client_number,
            DATEDIFF(v.visit_date, DATE(d.delivery_datetime)) AS age_at_visit_days
     FROM child_health_visits v
     JOIN babies b ON b.id = v.baby_id
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     ORDER BY v.visit_date DESC, v.id DESC LIMIT 300'
)->fetchAll();
$immunizations = $pdo->query(
    'SELECT i.*, b.birth_order, p.surname, p.first_name, pe.client_number
     FROM child_immunizations i
     JOIN babies b ON b.id = i.baby_id
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     ORDER BY i.event_date DESC, i.id DESC LIMIT 300'
)->fetchAll();
$childVisitOptions = $pdo->query(
    'SELECT v.id, v.baby_id, v.visit_date, b.birth_order, p.surname, p.first_name
     FROM child_health_visits v
     JOIN babies b ON b.id = v.baby_id
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     ORDER BY v.visit_date DESC, v.id DESC LIMIT 300'
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
    .muted-copy { color: var(--text-secondary); font-size: 12px; }
    @media (max-width: 900px) { .continuity-grid { grid-template-columns: 1fr; } }
</style>
<div class="continuity-page">
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="continuity-grid">
        <section class="continuity-card">
            <h2>Child health encounter</h2>
            <p class="muted-copy">Record encounters for each newborn separately through 730 days after birth (approximately the child portion of the first 1,000 days from conception). This form does not infer a schedule or interpret growth.</p>
            <form method="POST" class="care-form">
                <?= csrfField() ?><input type="hidden" name="action" value="create_visit">
                <div><label for="baby_id">Newborn</label><select name="baby_id" id="baby_id" required><option value="">Select newborn</option><?php foreach ($babies as $baby): ?><option value="<?= (int) $baby['id'] ?>" <?= (int) $selectedBabyId === (int) $baby['id'] ? 'selected' : '' ?>><?= htmlspecialchars($baby['surname'] . ' ' . $baby['first_name'] . ' · Baby ' . $baby['birth_order'] . ' · ' . formatDate($baby['delivery_datetime']) . ' · ' . (int) $baby['age_days'] . ' days') ?></option><?php endforeach; ?></select></div>
                <div><label for="visit_date">Encounter date</label><input type="date" name="visit_date" id="visit_date" value="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($today) ?>" required></div>
                <div><label for="weight_kg">Weight (kg)</label><input type="number" step="0.01" min="0.2" max="100" name="weight_kg" id="weight_kg"></div>
                <div><label for="length_cm">Length / height (cm)</label><input type="number" step="0.1" min="10" max="250" name="length_cm" id="length_cm"></div>
                <div><label for="head_circumference_cm">Head circumference (cm)</label><input type="number" step="0.1" min="10" max="100" name="head_circumference_cm" id="head_circumference_cm"></div>
                <div><label for="feeding_status">Feeding status / observation</label><input type="text" maxlength="100" name="feeding_status" id="feeding_status"></div>
                <div><label for="developmental_concerns">Developmental concern / action</label><textarea name="developmental_concerns" id="developmental_concerns" maxlength="4000"></textarea></div>
                <div><label for="notes">Other notes</label><textarea name="notes" id="notes" maxlength="4000"></textarea></div>
                <button type="submit" class="btn btn-primary">Save child encounter</button>
            </form>
        </section>
        <section class="continuity-card">
            <h2>Child follow-up timeline</h2>
            <h3>Record an immunization event</h3>
            <p class="muted-copy">Use the vaccine name and dose label used by the facility's current approved schedule. No vaccine timing is automatically inferred.</p>
            <form method="POST" class="care-form" style="margin-bottom:20px">
                <?= csrfField() ?><input type="hidden" name="action" value="record_immunization">
                <div><label for="immunization_baby_id">Newborn</label><select name="immunization_baby_id" id="immunization_baby_id" required><option value="">Select newborn</option><?php foreach ($babies as $baby): ?><option value="<?= (int) $baby['id'] ?>" <?= (int) $selectedBabyId === (int) $baby['id'] ? 'selected' : '' ?>><?= htmlspecialchars($baby['surname'] . ' ' . $baby['first_name'] . ' · Baby ' . $baby['birth_order']) ?></option><?php endforeach; ?></select></div>
                <div><label for="child_health_visit_id">Link to same-day child encounter (optional)</label><select name="child_health_visit_id" id="child_health_visit_id"><option value="">No linked encounter</option><?php foreach ($childVisitOptions as $visitOption): ?><option value="<?= (int) $visitOption['id'] ?>" data-baby="<?= (int) $visitOption['baby_id'] ?>" data-date="<?= htmlspecialchars($visitOption['visit_date']) ?>"><?= htmlspecialchars($visitOption['surname'] . ' ' . $visitOption['first_name'] . ' · Baby ' . $visitOption['birth_order'] . ' · ' . formatDate($visitOption['visit_date'])) ?></option><?php endforeach; ?></select></div>
                <div><label for="vaccine_name">Vaccine / product name</label><input type="text" name="vaccine_name" id="vaccine_name" maxlength="120" required></div>
                <div><label for="dose_label">Dose label (optional)</label><input type="text" name="dose_label" id="dose_label" maxlength="50"></div>
                <div><label for="event_date">Event date</label><input type="date" name="event_date" id="event_date" value="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($today) ?>" required></div>
                <div><label for="immunization_status">Status</label><select name="immunization_status" id="immunization_status" required><option value="given">Given</option><option value="not_given">Not given</option><option value="missed">Missed</option><option value="deferred">Deferred</option><option value="unknown">Unknown</option></select></div>
                <div><label for="lot_number">Lot number (optional)</label><input type="text" name="lot_number" id="lot_number" maxlength="100"></div>
                <div><label for="immunization_notes">Notes (optional)</label><input type="text" name="immunization_notes" id="immunization_notes" maxlength="255"></div>
                <button type="submit" class="btn btn-primary">Save immunization event</button>
            </form>
            <?php if (!$visits): ?><p class="muted-copy">No child health encounters have been recorded.</p><?php else: ?>
                <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Date / child</th><th>Age</th><th>Growth measures</th><th>Care observations</th></tr></thead><tbody>
                <?php foreach ($visits as $visit): ?>
                    <tr>
                        <td><?= formatDate($visit['visit_date']) ?><br><?= htmlspecialchars($visit['surname'] . ' ' . $visit['first_name'] . ' · Baby ' . $visit['birth_order']) ?><br><span class="muted-copy"><?= htmlspecialchars($visit['client_number']) ?></span></td>
                        <td><?= (int) $visit['age_at_visit_days'] ?> days</td>
                        <td><?= $visit['weight_kg'] !== null ? htmlspecialchars($visit['weight_kg'] . ' kg') : '—' ?><br><?= $visit['length_cm'] !== null ? htmlspecialchars($visit['length_cm'] . ' cm') : '—' ?><br><?= $visit['head_circumference_cm'] !== null ? htmlspecialchars($visit['head_circumference_cm'] . ' cm head') : '—' ?></td>
                        <td><?= $visit['feeding_status'] ? htmlspecialchars($visit['feeding_status']) . '<br>' : '' ?><?= $visit['developmental_concerns'] ? 'Concern: ' . nl2br(htmlspecialchars($visit['developmental_concerns'])) . '<br>' : '' ?><?= $visit['notes'] ? nl2br(htmlspecialchars($visit['notes'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
            <h3 style="margin-top:22px">Immunization history</h3>
            <?php if (!$immunizations): ?><p class="muted-copy">No immunization events have been recorded.</p><?php else: ?>
                <div style="overflow-x:auto"><table class="record-table"><thead><tr><th>Date / child</th><th>Vaccine / dose</th><th>Status</th><th>Lot / notes</th></tr></thead><tbody>
                <?php foreach ($immunizations as $immunization): ?><tr><td><?= formatDate($immunization['event_date']) ?><br><?= htmlspecialchars($immunization['surname'] . ' ' . $immunization['first_name'] . ' · Baby ' . $immunization['birth_order']) ?></td><td><?= htmlspecialchars($immunization['vaccine_name']) ?><br><span class="muted-copy"><?= htmlspecialchars($immunization['dose_label'] ?? '—') ?></span></td><td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $immunization['status']))) ?></td><td><?= htmlspecialchars($immunization['lot_number'] ?? '—') ?><br><?= htmlspecialchars($immunization['notes'] ?? '') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
    const immunizationBaby = document.getElementById('immunization_baby_id');
    const immunizationVisit = document.getElementById('child_health_visit_id');
    const immunizationDate = document.getElementById('event_date');
    immunizationBaby.addEventListener('change', () => {
        for (const option of immunizationVisit.options) option.hidden = Boolean(option.dataset.baby && option.dataset.baby !== immunizationBaby.value);
        if (immunizationVisit.selectedOptions[0]?.dataset.baby !== immunizationBaby.value) immunizationVisit.value = '';
    });
    immunizationVisit.addEventListener('change', () => {
        const option = immunizationVisit.selectedOptions[0];
        if (option.dataset.date) immunizationDate.value = option.dataset.date;
    });
    immunizationBaby.dispatchEvent(new Event('change'));
</script>
<?php require_once 'footer.php'; ?>
