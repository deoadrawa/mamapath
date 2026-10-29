<?php

require_once 'config.php';
requireLogin();

$error = '';
$success = isset($_GET['saved']) ? 'Appointment changes saved.' : '';
$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? $today;
$date_to = $_GET['date_to'] ?? $today;
$status_filter = $_GET['status'] ?? 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
            $episodeId = empty($_POST['pregnancy_episode_id'])
                ? null
                : filter_var($_POST['pregnancy_episode_id'], FILTER_VALIDATE_INT);
            $babyId = empty($_POST['baby_id']) ? null : filter_var($_POST['baby_id'], FILTER_VALIDATE_INT);
            $type = $_POST['appointment_type'] ?? '';
            $scheduledDate = $_POST['scheduled_date'] ?? '';
            $scheduledTime = empty($_POST['scheduled_time']) ? null : $_POST['scheduled_time'];

            if ($patientId === false || $patientId < 1 || ($episodeId !== null && ($episodeId === false || $episodeId < 1)) || ($babyId !== null && ($babyId === false || $babyId < 1))) {
                throw new InvalidArgumentException('Choose a valid patient and pregnancy episode.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate) || !checkdate(
                (int) substr($scheduledDate, 5, 2),
                (int) substr($scheduledDate, 8, 2),
                (int) substr($scheduledDate, 0, 4)
            )) {
                throw new InvalidArgumentException('Enter a valid appointment date.');
            }
            if ($scheduledTime !== null && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $scheduledTime)) {
                throw new InvalidArgumentException('Enter a valid appointment time.');
            }

            $typeCheck = $pdo->prepare('SELECT 1 FROM appointment_types WHERE code = :code');
            $typeCheck->execute([':code' => $type]);
            if (!$typeCheck->fetchColumn()) {
                throw new InvalidArgumentException('Choose a valid appointment type.');
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
                    throw new InvalidArgumentException('The selected episode does not belong to this patient.');
                }
            }
            if ($babyId !== null) {
                $babyCheck = $pdo->prepare(
                    'SELECT pe.id AS episode_id, pe.patient_id
                     FROM babies b
                     JOIN delivery_records d ON d.id = b.delivery_record_id
                     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
                     WHERE b.id = :baby_id'
                );
                $babyCheck->execute([':baby_id' => $babyId]);
                $baby = $babyCheck->fetch();
                if (!$baby || (int) $baby['patient_id'] !== (int) $patientId) {
                    throw new InvalidArgumentException('The selected newborn does not belong to this patient.');
                }
                if ($episodeId !== null && (int) $baby['episode_id'] !== (int) $episodeId) {
                    throw new InvalidArgumentException('The newborn and pregnancy episode do not match.');
                }
                $episodeId = (int) $baby['episode_id'];
            }
            if ($type === 'CHILD' && $babyId === null) {
                throw new InvalidArgumentException('A child health appointment must be linked to a newborn.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO appointments (
                    patient_id, pregnancy_episode_id, baby_id, appointment_type, scheduled_date,
                    scheduled_time, notes, created_by, updated_by
                 ) VALUES (
                    :patient_id, :episode_id, :baby_id, :type, :scheduled_date,
                    :scheduled_time, :notes, :user_id, :user_id
                 )"
            );
            $stmt->execute([
                ':patient_id' => $patientId,
                ':episode_id' => $episodeId,
                ':baby_id' => $babyId,
                ':type' => $type,
                ':scheduled_date' => $scheduledDate,
                ':scheduled_time' => $scheduledTime,
                ':notes' => emptyToNull(trim($_POST['notes'] ?? '')),
                ':user_id' => currentUserId(),
            ]);
            logAction($pdo, currentUserId(), 'CREATE_APPOINTMENT', 'appointments', $pdo->lastInsertId(), 'Appointment scheduled');
            $pdo->commit();
            header('Location: appointments.php?saved=1');
            exit;
        } elseif ($action === 'update_status') {
            $appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? '';
            $allowedStatuses = ['completed', 'missed', 'cancelled'];
            if ($appointmentId === false || $appointmentId < 1 || !in_array($status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Choose a valid appointment and status.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE appointments SET status = :status, updated_by = :user_id
                 WHERE id = :id AND status = 'scheduled'"
            );
            $stmt->execute([
                ':status' => $status,
                ':user_id' => currentUserId(),
                ':id' => $appointmentId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('That appointment is no longer scheduled.');
            }
            logAction($pdo, currentUserId(), 'UPDATE_APPOINTMENT', 'appointments', $appointmentId, 'Appointment status changed to ' . $status);
            $pdo->commit();
            header('Location: appointments.php?saved=1');
            exit;
        } else {
            throw new InvalidArgumentException('Unknown appointment action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Could not save the appointment. Please try again.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Appointment request failed: ' . $e->getMessage());
        }
    }
}

$types = $pdo->query('SELECT code, label FROM appointment_types ORDER BY label')->fetchAll();
$patients = $pdo->query(
    "SELECT p.id AS patient_id, p.surname, p.first_name, pe.id AS episode_id, pe.client_number
     FROM patients p
     LEFT JOIN pregnancy_episodes pe
       ON pe.patient_id = p.id AND pe.status IN ('active', 'delivered')
     ORDER BY p.surname, p.first_name, pe.created_at DESC"
)->fetchAll();
$babies = $pdo->query(
    "SELECT b.id, pe.patient_id, d.pregnancy_episode_id, b.birth_order, d.delivery_datetime,
            p.surname, p.first_name
     FROM babies b
     JOIN delivery_records d ON d.id = b.delivery_record_id
     JOIN pregnancy_episodes pe ON pe.id = d.pregnancy_episode_id
     JOIN patients p ON p.id = pe.patient_id
     WHERE d.delivery_datetime IS NOT NULL AND DATE(d.delivery_datetime) <= CURDATE()
       AND DATEDIFF(CURDATE(), DATE(d.delivery_datetime)) BETWEEN 0 AND 730
     ORDER BY d.delivery_datetime DESC LIMIT 1000"
)->fetchAll();

$fromValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) && checkdate((int) substr($date_from, 5, 2), (int) substr($date_from, 8, 2), (int) substr($date_from, 0, 4));
$toValid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) && checkdate((int) substr($date_to, 5, 2), (int) substr($date_to, 8, 2), (int) substr($date_to, 0, 4));
if (!$fromValid || !$toValid || $date_from > $date_to) {
    $date_from = $today;
    $date_to = $today;
}

$statusOptions = ['all', 'scheduled', 'completed', 'missed', 'cancelled'];
if (!in_array($status_filter, $statusOptions, true)) {
    $status_filter = 'all';
}

$sql = "SELECT a.id, a.patient_id, a.pregnancy_episode_id, a.baby_id, a.appointment_type,
               a.scheduled_date, a.scheduled_time, a.status, a.notes,
               p.surname, p.first_name, p.phone, pe.client_number, b.birth_order, at.label AS type_label
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN pregnancy_episodes pe ON pe.id = a.pregnancy_episode_id
        LEFT JOIN babies b ON b.id = a.baby_id
        JOIN appointment_types at ON at.code = a.appointment_type
        WHERE a.scheduled_date BETWEEN :date_from AND :date_to";
$params = [':date_from' => $date_from, ':date_to' => $date_to];
if ($status_filter !== 'all') {
    $sql .= ' AND a.status = :status';
    $params[':status'] = $status_filter;
}
$sql .= " ORDER BY a.scheduled_date, a.scheduled_time, p.surname, p.first_name LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();
?>
<?php require_once 'nav.php'; ?>
<style>
    .appointments-page { max-width: 1400px; margin: 0 auto; }
    .appointments-grid { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 20px; align-items: start; }
    .appointment-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 22px; }
    .appointment-card h2 { font-size: 18px; margin-bottom: 16px; }
    .appointment-form { display: grid; gap: 14px; }
    .appointment-form label, .filter-form label { display: block; color: var(--text-secondary); font-size: 13px; font-weight: 600; margin-bottom: 6px; }
    .appointment-form input, .appointment-form select, .appointment-form textarea, .filter-form input, .filter-form select {
        width: 100%; padding: 10px 12px; color: var(--text-primary); background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; font: inherit;
    }
    .appointment-form button, .filter-form button, .status-form button { border: 0; border-radius: 8px; padding: 10px 14px; color: #fff; background: var(--primary); font: inherit; font-weight: 600; cursor: pointer; }
    .filter-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin-bottom: 16px; }
    .filter-form > div { min-width: 130px; }
    .appointment-table-wrap { overflow-x: auto; }
    .appointment-table { width: 100%; border-collapse: collapse; min-width: 680px; }
    .appointment-table th, .appointment-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .appointment-table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
    .status-form { display: flex; gap: 6px; align-items: center; }
    .status-form select { min-width: 125px; padding: 7px; color: var(--text-primary); background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 20px; background: var(--info-light); font-size: 12px; }
    .notice { margin-bottom: 16px; padding: 12px 14px; border-radius: 8px; }
    .notice.error { color: #8a1c1c; background: #ffebee; }
    .notice.success { color: #1b5e20; background: #e8f5e9; }
    @media (max-width: 900px) { .appointments-grid { grid-template-columns: 1fr; } }
</style>

<section class="appointments-page">
    <h1>Appointments</h1>
    <p class="text-muted">Schedule and track ANC, delivery, and postnatal follow-up.</p>
    <?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="appointments-grid">
        <section class="appointment-card">
            <h2>Schedule an appointment</h2>
            <form method="POST" class="appointment-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <div>
                    <label for="patient_id">Patient</label>
                    <select id="patient_id" name="patient_id" required>
                        <option value="">Select patient</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?php echo (int) $patient['patient_id']; ?>" data-episode="<?php echo (int) ($patient['episode_id'] ?? 0); ?>">
                                <?php echo htmlspecialchars($patient['surname'] . ', ' . $patient['first_name'] . ($patient['client_number'] ? ' · ' . $patient['client_number'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="pregnancy_episode_id" id="pregnancy_episode_id">
                <div>
                    <label for="baby_id">Newborn (required for child health)</label>
                    <select id="baby_id" name="baby_id">
                        <option value="">Not linked to a newborn</option>
                        <?php foreach ($babies as $baby): ?>
                            <option value="<?php echo (int) $baby['id']; ?>" data-patient="<?php echo (int) $baby['patient_id']; ?>" data-episode="<?php echo (int) $baby['pregnancy_episode_id']; ?>">
                                <?php echo htmlspecialchars($baby['surname'] . ', ' . $baby['first_name'] . ' · Baby ' . $baby['birth_order'] . ' · ' . formatDate($baby['delivery_datetime']), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="appointment_type">Appointment type</label>
                    <select id="appointment_type" name="appointment_type" required>
                        <option value="">Select type</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['code'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($type['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="scheduled_date">Date</label>
                    <input id="scheduled_date" type="date" name="scheduled_date" min="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="scheduled_time">Time (optional)</label>
                    <input id="scheduled_time" type="time" name="scheduled_time">
                </div>
                <div>
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>
                </div>
                <button type="submit">Schedule appointment</button>
            </form>
        </section>

        <section class="appointment-card">
            <h2>Appointment list</h2>
            <form method="GET" class="filter-form">
                <div><label for="date_from">From</label><input id="date_from" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="date_to">To</label><input id="date_to" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $status_filter === $option ? 'selected' : ''; ?>><?php echo ucfirst($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Filter</button>
            </form>
            <div class="appointment-table-wrap">
                <table class="appointment-table">
                    <thead><tr><th>Date / time</th><th>Patient</th><th>Type / newborn</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$appointments): ?>
                        <tr><td colspan="5">No appointments found for this period.</td></tr>
                    <?php else: foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(formatDate($appointment['scheduled_date']) . ($appointment['scheduled_time'] ? ' ' . substr($appointment['scheduled_time'], 0, 5) : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($appointment['surname'] . ', ' . $appointment['first_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($appointment['client_number']): ?><br><small><?php echo htmlspecialchars($appointment['client_number'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($appointment['type_label'], ENT_QUOTES, 'UTF-8'); ?><?php if ($appointment['baby_id']): ?><br><small>Baby <?php echo (int) $appointment['birth_order']; ?></small><?php endif; ?></td>
                            <td><span class="status-badge"><?php echo htmlspecialchars(ucfirst($appointment['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <?php if ($appointment['status'] === 'scheduled'): ?>
                                    <form method="POST" class="status-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                        <select name="status" aria-label="Update appointment status">
                                            <option value="completed">Completed</option>
                                            <option value="missed">Missed</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                        <button type="submit">Save</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
<script>
document.getElementById('patient_id').addEventListener('change', function () {
    const option = this.selectedOptions[0];
    document.getElementById('pregnancy_episode_id').value = option?.dataset.episode || '';
    document.getElementById('baby_id').value = '';
});
const babySelector = document.getElementById('baby_id');
const typeSelector = document.getElementById('appointment_type');
babySelector.addEventListener('change', function () {
    const option = this.selectedOptions[0];
    if (option?.dataset.patient) {
        document.getElementById('patient_id').value = option.dataset.patient;
        document.getElementById('pregnancy_episode_id').value = option.dataset.episode;
    }
});
typeSelector.addEventListener('change', function () {
    babySelector.required = this.value === 'CHILD';
});
babySelector.required = typeSelector.value === 'CHILD';
</script>
<?php require_once 'footer.php'; ?>
