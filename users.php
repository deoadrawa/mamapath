<?php

require_once 'config.php';
requireRole('admin');

$error = '';
$success = isset($_GET['saved']) ? 'Staff account changes saved.' : '';
$roles = ['staff_admin', 'doctor', 'midwife', 'nurse', 'receptionist', 'manager', 'community_health_worker', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCSRF();
        $action = $_POST['action'] ?? '';
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'save') {
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $jobTitle = trim($_POST['job_title'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $role = $_POST['role'] ?? '';
            $password = $_POST['password'] ?? '';

            if ($fullName === '' || !in_array($role, $roles, true)) {
                throw new InvalidArgumentException('Enter the staff name and choose a valid role.');
            }
            if ($email !== '' && !validateEmail($email)) {
                throw new InvalidArgumentException('Enter a valid email address.');
            }
            if ($phone !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $phone)) {
                throw new InvalidArgumentException('Enter a valid phone number.');
            }

            if ($userId === false || $userId === null) {
                if ($username === '' || strlen($username) > 50 || strlen($password) < 12) {
                    throw new InvalidArgumentException('New accounts need a username and a password of at least 12 characters.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, full_name, role, job_title, phone, email, created_by, updated_by)
                     VALUES (:username, :password_hash, :full_name, :role, :job_title, :phone, :email, :user_id, :user_id)"
                );
                $stmt->execute([
                    ':username' => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':full_name' => $fullName,
                    ':role' => $role,
                    ':job_title' => emptyToNull($jobTitle),
                    ':phone' => emptyToNull($phone),
                    ':email' => emptyToNull($email),
                    ':user_id' => currentUserId(),
                ]);
                logAction($pdo, currentUserId(), 'CREATE_USER', 'users', $pdo->lastInsertId(), 'Staff account created');
                $pdo->commit();
            } else {
                $pdo->beginTransaction();
                $targetStmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
                $targetStmt->execute([':id' => $userId]);
                $target = $targetStmt->fetch();
                if (!$target) {
                    throw new InvalidArgumentException('Staff account not found.');
                }
                if ((int) $target['id'] === (int) currentUserId() && ($role !== 'admin' || (int) $target['is_active'] !== 1)) {
                    throw new InvalidArgumentException('You cannot change your own administrator access.');
                }
                if (
                    (int) $target['is_active'] === 1
                    && normalizeUserRole($target['role']) === 'admin'
                    && $role !== 'admin'
                ) {
                    $otherAdmins = $pdo->prepare(
                        "SELECT COUNT(*) FROM users
                         WHERE id <> :id AND is_active = 1 AND role IN ('admin', 'super_admin')"
                    );
                    $otherAdmins->execute([':id' => $userId]);
                    if ((int) $otherAdmins->fetchColumn() === 0) {
                        throw new InvalidArgumentException('At least one active administrator account must remain.');
                    }
                }

                $sql = 'UPDATE users SET full_name = :full_name, role = :role, job_title = :job_title, phone = :phone, email = :email, updated_by = :user_id';
                $params = [
                    ':full_name' => $fullName,
                    ':role' => $role,
                    ':job_title' => emptyToNull($jobTitle),
                    ':phone' => emptyToNull($phone),
                    ':email' => emptyToNull($email),
                    ':user_id' => currentUserId(),
                    ':id' => $userId,
                ];
                if ($password !== '') {
                    if (strlen($password) < 12) {
                        throw new InvalidArgumentException('A replacement password must be at least 12 characters.');
                    }
                    $sql .= ', password_hash = :password_hash';
                    $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                logAction($pdo, currentUserId(), 'UPDATE_USER', 'users', $userId, 'Staff account updated');
                $pdo->commit();
                $success = 'Staff account updated.';
            }
        } elseif ($action === 'toggle_active') {
            if ($userId === false || $userId === null || (int) $userId === (int) currentUserId()) {
                throw new InvalidArgumentException('You cannot deactivate your own account.');
            }
            $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($active, [0, 1], true)) {
                throw new InvalidArgumentException('Choose a valid account status.');
            }
            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
            $targetStmt->execute([':id' => $userId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                throw new InvalidArgumentException('Staff account not found.');
            }
            if (
                $active === 0
                && (int) $target['is_active'] === 1
                && normalizeUserRole($target['role']) === 'admin'
            ) {
                $otherAdmins = $pdo->prepare(
                    "SELECT COUNT(*) FROM users
                     WHERE id <> :id AND is_active = 1 AND role IN ('admin', 'super_admin')"
                );
                $otherAdmins->execute([':id' => $userId]);
                if ((int) $otherAdmins->fetchColumn() === 0) {
                    throw new InvalidArgumentException('At least one active administrator account must remain.');
                }
            }
            $stmt = $pdo->prepare('UPDATE users SET is_active = :active, updated_by = :user_id WHERE id = :id');
            $stmt->execute([':active' => $active, ':user_id' => currentUserId(), ':id' => $userId]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Staff account not found or unchanged.');
            }
            logAction($pdo, currentUserId(), $active ? 'ACTIVATE_USER' : 'DEACTIVATE_USER', 'users', $userId, 'Staff account status changed');
            $pdo->commit();
            $success = $active ? 'Staff account activated.' : 'Staff account deactivated.';
        } else {
            throw new InvalidArgumentException('Unknown staff action.');
        }
        header('Location: users.php?saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Could not save the staff account. Check that the username and email are not already in use.';
        if (!$e instanceof InvalidArgumentException) {
            error_log('Staff account request failed: ' . $e->getMessage());
        }
    }
}

$users = $pdo->query(
    'SELECT id, username, full_name, role, job_title, phone, email, is_active, last_login
     FROM users ORDER BY is_active DESC, full_name'
)->fetchAll();
?>
<?php require_once 'nav.php'; ?>
<style>
    .users-page { max-width: 1400px; margin: 0 auto; }
    .users-layout { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 20px; align-items: start; }
    .users-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 22px; }
    .users-card h2 { font-size: 18px; margin-bottom: 16px; }
    .user-form { display: grid; gap: 12px; }
    .user-form label { display: block; color: var(--text-secondary); font-size: 13px; font-weight: 600; margin-bottom: 5px; }
    .user-form input, .user-form select { width: 100%; padding: 9px 10px; color: var(--text-primary); background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; font: inherit; }
    .user-form button, .user-action button { border: 0; border-radius: 8px; padding: 9px 12px; color: #fff; background: var(--primary); font: inherit; font-weight: 600; cursor: pointer; }
    .users-table-wrap { overflow-x: auto; }
    .users-table { width: 100%; border-collapse: collapse; min-width: 780px; }
    .users-table th, .users-table td { padding: 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
    .users-table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
    .user-edit { display: grid; grid-template-columns: repeat(3, minmax(120px, 1fr)); gap: 8px; }
    .user-edit input, .user-edit select { width: 100%; padding: 7px; color: var(--text-primary); background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px; }
    .user-action { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .user-action .deactivate { background: var(--danger); }
    .notice { margin-bottom: 16px; padding: 12px 14px; border-radius: 8px; }
    .notice.error { color: #8a1c1c; background: #ffebee; }
    .notice.success { color: #1b5e20; background: #e8f5e9; }
    @media (max-width: 900px) { .users-layout { grid-template-columns: 1fr; } .user-edit { grid-template-columns: 1fr 1fr; } }
</style>

<section class="users-page">
    <h1>Staff Accounts</h1>
    <p class="text-muted">Create staff accounts, update roles, and disable access when staff leave.</p>
    <?php if ($error !== ''): ?><div class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="notice success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <div class="users-layout">
        <section class="users-card">
            <h2>Create a staff account</h2>
            <form method="POST" class="user-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <div><label for="username">Username</label><input id="username" name="username" maxlength="50" required></div>
                <div><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="150" required></div>
                <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="150"></div>
                <div><label for="job_title">Job title</label><input id="job_title" name="job_title" maxlength="80"></div>
                <div><label for="phone">Phone</label><input id="phone" name="phone" maxlength="20"></div>
                <div><label for="role">Role</label><select id="role" name="role" required><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"><?php echo htmlspecialchars(getUserRoleLabel($role), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                <div><label for="password">Temporary password</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required></div>
                <button type="submit">Create account</button>
            </form>
        </section>
        <section class="users-card">
            <h2>Accounts (<span id="userCount"><?php echo count($users); ?></span> of <?php echo count($users); ?>)</h2>
            <input type="search" id="userFilter" placeholder="Search by name, username, role or phone" aria-label="Search accounts" autocomplete="off" style="width:100%;min-height:40px;margin-bottom:12px;padding:0 12px;border:1px solid #CBD5E1;border-radius:6px;box-sizing:border-box">
            <div class="users-table-wrap">
                <table class="users-table">
                    <thead><tr><th>Username</th><th>Account details</th><th>Last login</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr data-user-row="<?php echo htmlspecialchars(strtolower($user['username'] . ' ' . $user['full_name'] . ' ' . $user['role'] . ' ' . $user['phone'] . ' ' . $user['job_title']), ENT_QUOTES, 'UTF-8'); ?>">
                            <td><strong><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td>
                                <form method="POST">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="save">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                    <div class="user-edit">
                                        <input name="full_name" aria-label="Full name" value="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="role" aria-label="Role"><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>" <?php echo normalizeUserRole($user['role']) === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(getUserRoleLabel($role), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                                        <input name="email" type="email" aria-label="Email" value="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Email">
                                        <input name="job_title" aria-label="Job title" value="<?php echo htmlspecialchars($user['job_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Job title">
                                        <input name="phone" aria-label="Phone" value="<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Phone">
                                        <input name="password" type="password" minlength="12" autocomplete="new-password" placeholder="New password (optional)" aria-label="New password">
                                    </div>
                                    <div class="user-action"><button type="submit">Save changes</button></div>
                                </form>
                                <?php if ((int) $user['id'] !== (int) currentUserId()): ?>
                                    <form method="POST" class="user-action" onsubmit="return confirm('Change this account access status?')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo (int) !$user['is_active']; ?>">
                                        <button class="<?php echo $user['is_active'] ? 'deactivate' : ''; ?>" type="submit"><?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['last_login'] ? formatDateTime($user['last_login']) : 'Never', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
<script>
(function () {
    var input = document.getElementById('userFilter');
    var count = document.getElementById('userCount');
    if (!input) { return; }
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        document.querySelectorAll('[data-user-row]').forEach(function (row) {
            var match = q === '' || row.dataset.userRow.indexOf(q) !== -1;
            row.hidden = !match;
            shown += match ? 1 : 0;
        });
        count.textContent = shown;
    });
}());
</script>
<?php require_once 'footer.php'; ?>
