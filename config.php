<?php
/**
 * Shared bootstrap: environment, database, auth, and clinical helpers.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '1' : '0');
    session_start();
}

date_default_timezone_set('Africa/Kampala');

loadEnvFile(__DIR__ . '/.env');

$appDebug = filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
error_reporting(E_ALL);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));

define('APP_NAME', env('APP_NAME', 'Lubaga Hospital MCH System'));
define('APP_VERSION', '2.1.0');
define('HOSPITAL_NAME', env('HOSPITAL_NAME', 'Lubaga Hospital'));
define('HOSPITAL_LOCATION', env('HOSPITAL_LOCATION', 'Kampala, Uganda'));
define('ITEMS_PER_PAGE', 25);
define('APP_SHOW_DEMO_LOGINS', filter_var(env('APP_SHOW_DEMO_LOGINS', '0'), FILTER_VALIDATE_BOOLEAN));

if (DB_NAME === '' || DB_USER === '') {
    die('Database configuration is incomplete. Set DB_NAME and DB_USER in .env.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    if ($appDebug) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }
    die('Database connection failed. Check .env settings.');
}

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

function emptyToNull($value)
{
    if ($value === null) {
        return null;
    }
    if (is_string($value) && trim($value) === '') {
        return null;
    }
    return $value;
}

function formBoolean(array $formData, string $key): int
{
    $value = $formData[$key] ?? null;
    return in_array($value, [true, 1, '1', 'on'], true) ? 1 : 0;
}

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function normalizeUserRole(?string $role): string
{
    $roleMap = [
        'super_admin' => 'admin',
    ];

    if ($role === null || $role === '') {
        return '';
    }

    return $roleMap[$role] ?? $role;
}

function getUserRoleLabel(?string $role): string
{
    $labels = [
        'super_admin' => 'Super Admin',
        'staff_admin' => 'Staff Admin',
        'admin' => 'Admin',
        'doctor' => 'Doctor',
        'midwife' => 'Midwife',
        'nurse' => 'Nurse',
        'receptionist' => 'Receptionist',
        'manager' => 'Manager',
        'community_health_worker' => 'Community Health Worker',
    ];

    $normalizedRole = normalizeUserRole($role);
    return $labels[$normalizedRole] ?? ucfirst(str_replace('_', ' ', $normalizedRole));
}

function verifyLogin(PDO $pdo, string $username, string $password)
{
    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, full_name, role, is_active
         FROM users
         WHERE username = :username AND is_active = 1"
    );
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $hash = $user['password_hash'];
    $valid = false;

    if (password_get_info($hash)['algo'] !== 0) {
        $valid = password_verify($password, $hash);
    } elseif (hash_equals($hash, md5($password))) {
        $valid = true;
        $upgrade = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $upgrade->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $user['id'],
        ]);
    }

    if (!$valid) {
        return false;
    }

    $user['role'] = normalizeUserRole($user['role'] ?? null);
    unset($user['password_hash']);
    return $user;
}

function requireLogin(): void
{
    global $pdo;
    if (!refreshCurrentUserSession($pdo)) {
        header('Location: login.php');
        exit;
    }
}

function refreshCurrentUserSession(PDO $pdo): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT username, full_name, role FROM users WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        return false;
    }

    $_SESSION['username'] = $user['username'];
    $_SESSION['user_fullname'] = $user['full_name'];
    $_SESSION['user_role'] = normalizeUserRole($user['role']);
    return true;
}

function hasRole($roles): bool
{
    $sessionRole = normalizeUserRole($_SESSION['user_role'] ?? null);
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    if (is_array($roles)) {
        $normalizedRoles = array_map('normalizeUserRole', $roles);
        return in_array($sessionRole, $normalizedRoles, true);
    }
    return $sessionRole === normalizeUserRole($roles);
}

function requireRole($roles): void
{
    requireLogin();
    if (!hasRole($roles)) {
        header('Location: index.php?error=unauthorized');
        exit;
    }
}

function formatDate($date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

function formatDateCustom($date, string $format = 'd/m/Y'): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function formatDateTime($datetime): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d M Y H:i', strtotime($datetime));
}

function getPatientContextSummary(PDO $pdo, int $patientId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.surname, p.first_name, p.age, p.phone, p.village, p.district,
                pe.id AS episode_id, pe.client_number, pe.gravida, pe.parity,
                pe.lmp, pe.edd, pe.status AS pregnancy_status,
                (SELECT COUNT(*) FROM anc_visits av WHERE av.pregnancy_episode_id = pe.id) AS anc_visit_count,
                (SELECT MAX(av.visit_date) FROM anc_visits av WHERE av.pregnancy_episode_id = pe.id) AS last_anc_date
         FROM patients p
         LEFT JOIN pregnancy_episodes pe ON pe.id = (
             SELECT pe2.id
             FROM pregnancy_episodes pe2
             WHERE pe2.patient_id = p.id
             ORDER BY (pe2.status = 'active') DESC, pe2.created_at DESC, pe2.id DESC
             LIMIT 1
         )
         WHERE p.id = :patient_id
         LIMIT 1"
    );
    $stmt->execute([':patient_id' => $patientId]);
    $patient = $stmt->fetch();

    return $patient ?: null;
}

function getCurrentDateTime(): string
{
    return date('Y-m-d H:i:s');
}

function getCurrentDate(): string
{
    return date('Y-m-d');
}

function calculateEDD($lmp): ?string
{
    if (empty($lmp) || $lmp === '0000-00-00') {
        return null;
    }
    $date = new DateTime($lmp);
    $date->add(new DateInterval('P280D'));
    return $date->format('Y-m-d');
}

function calculateGestationalAge($lmp, $visitDate): ?int
{
    if (empty($lmp) || empty($visitDate) || $lmp === '0000-00-00') {
        return null;
    }
    $lmpDate = new DateTime($lmp);
    $visit = new DateTime($visitDate);
    $diff = $lmpDate->diff($visit);
    return (int) floor($diff->days / 7);
}

function getBookingTiming($gaWeeks): ?string
{
    if ($gaWeeks === null) {
        return null;
    }
    return ($gaWeeks <= 12) ? 'early' : 'late';
}

function getMUACColor($muac_cm): string
{
    if ($muac_cm === null || $muac_cm <= 0) {
        return 'ND';
    }
    if ($muac_cm < 19.0) {
        return 'R';
    }
    if ($muac_cm < 23.0) {
        return 'Y';
    }
    return 'G';
}

function getMUACDescription($color): string
{
    $descriptions = [
        'R' => 'Severe Acute Malnutrition (SAM) - Refer to nutrition corner',
        'Y' => 'Moderate Acute Malnutrition (MAM) - Refer to nutrition corner',
        'G' => 'Normal nutritional status',
        'ND' => 'Not assessed',
    ];
    return $descriptions[$color] ?? 'Unknown';
}

function classifyBirthWeight($weight): string
{
    if ($weight === null) {
        return 'Unknown';
    }
    if ($weight < 1.5) {
        return 'Extremely Low Birth Weight (ELBW)';
    }
    if ($weight < 2.5) {
        return 'Low Birth Weight (LBW)';
    }
    if ($weight < 4.0) {
        return 'Normal Birth Weight';
    }
    return 'Macrosomia (>4kg)';
}

function generateClientNumber(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(client_number, '/', 1) AS UNSIGNED)) AS max_num
         FROM pregnancy_episodes
         WHERE client_number LIKE :pattern"
    );
    $stmt->execute([':pattern' => '%/' . $year]);
    $next = (int) ($stmt->fetchColumn() ?: 0) + 1;
    return sprintf('%03d/%s', $next, $year);
}

function generateIPDNumber(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(ipd_no, '/', -1) AS UNSIGNED)) AS max_num
         FROM delivery_records
         WHERE ipd_no LIKE CONCAT('IPD/', :year, '/%')"
    );
    $stmt->execute([':year' => $year]);
    $next = (int) ($stmt->fetchColumn() ?: 0) + 1;
    return sprintf('IPD/%s/%05d', $year, $next);
}

function getNextSerialNo(PDO $pdo, string $table, string $dateColumn): int
{
    $allowed = [
        'anc_visits' => 'visit_date',
        'delivery_records' => 'admission_datetime',
        'postnatal_visits' => 'visit_date',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $dateColumn) {
        throw new InvalidArgumentException('Invalid register table.');
    }

    $stmt = $pdo->prepare(
        "SELECT MAX(serial_no) AS max_serial
         FROM {$table}
         WHERE DATE_FORMAT({$dateColumn}, '%Y-%m') = :month"
    );
    $stmt->execute([':month' => date('Y-m')]);
    return (int) ($stmt->fetchColumn() ?: 0) + 1;
}

function logAction(PDO $pdo, $userId, string $action, $tableName = null, $recordId = null, $details = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO system_logs (user_id, action, table_name, record_id, details, ip_address, user_agent)
         VALUES (:user_id, :action, :table_name, :record_id, :details, :ip, :ua)"
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':table_name' => $tableName,
        ':record_id' => $recordId,
        ':details' => $details,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

function getPatientName(PDO $pdo, $patientId): string
{
    $stmt = $pdo->prepare("SELECT CONCAT(surname, ' ', first_name) AS name FROM patients WHERE id = :id");
    $stmt->execute([':id' => $patientId]);
    $row = $stmt->fetch();
    return $row['name'] ?? 'Unknown Patient';
}

function getActivePregnancy(PDO $pdo, $patientId)
{
    $stmt = $pdo->prepare(
        "SELECT * FROM pregnancy_episodes
         WHERE patient_id = :patient_id AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([':patient_id' => $patientId]);
    return $stmt->fetch();
}

function getPatientPregnancies(PDO $pdo, $patientId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM pregnancy_episodes
         WHERE patient_id = :patient_id
         ORDER BY created_at DESC"
    );
    $stmt->execute([':patient_id' => $patientId]);
    return $stmt->fetchAll();
}

function getAncVisits(PDO $pdo, $episodeId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM anc_visits
         WHERE pregnancy_episode_id = :episode_id
         ORDER BY visit_number ASC"
    );
    $stmt->execute([':episode_id' => $episodeId]);
    return $stmt->fetchAll();
}

function getDeliveryRecord(PDO $pdo, $episodeId)
{
    $stmt = $pdo->prepare(
        "SELECT * FROM delivery_records
         WHERE pregnancy_episode_id = :episode_id
         LIMIT 1"
    );
    $stmt->execute([':episode_id' => $episodeId]);
    return $stmt->fetch();
}

function getPostnatalVisits(PDO $pdo, $episodeId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM postnatal_visits
         WHERE pregnancy_episode_id = :episode_id
         ORDER BY visit_date ASC"
    );
    $stmt->execute([':episode_id' => $episodeId]);
    return $stmt->fetchAll();
}

function getDashboardStats(PDO $pdo): array
{
    $stats = [];

    $stats['active_pregnancies'] = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE status = 'active'")->fetchColumn();
    $stats['new_anc_month'] = $pdo->query(
        "SELECT COUNT(*) FROM pregnancy_episodes
         WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
    )->fetchColumn();
    $stats['deliveries_month'] = $pdo->query(
        "SELECT COUNT(*) FROM delivery_records
         WHERE MONTH(delivery_datetime) = MONTH(CURDATE()) AND YEAR(delivery_datetime) = YEAR(CURDATE())"
    )->fetchColumn();
    $stats['total_patients'] = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $stats['total_anc_visits'] = $pdo->query("SELECT COUNT(*) FROM anc_visits")->fetchColumn();
    $stats['total_deliveries'] = $pdo->query("SELECT COUNT(*) FROM delivery_records")->fetchColumn();
    $stats['total_postnatal'] = $pdo->query("SELECT COUNT(*) FROM postnatal_visits")->fetchColumn();

    $row = $pdo->query(
        "SELECT COUNT(CASE WHEN booking_timing = 'early' THEN 1 END) AS early, COUNT(*) AS total
         FROM pregnancy_episodes WHERE booking_timing IS NOT NULL"
    )->fetch();
    $stats['early_booking_rate'] = $row['total'] > 0 ? round(($row['early'] / $row['total']) * 100) : 0;

    $row = $pdo->query(
        "SELECT COUNT(CASE WHEN mode_of_delivery = 'caesarean_section' THEN 1 END) AS cs, COUNT(*) AS total
         FROM delivery_records"
    )->fetch();
    $stats['cs_rate'] = $row['total'] > 0 ? round(($row['cs'] / $row['total']) * 100) : 0;

    $stats['hiv_positive'] = $pdo->query(
        "SELECT COUNT(DISTINCT pe.id)
         FROM anc_visits av
         JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id
         WHERE av.woman_hiv_code IN ('TRR', 'TRRK', 'TRR+')"
    )->fetchColumn();

    $row = $pdo->query(
        "SELECT COUNT(DISTINCT dr.pregnancy_episode_id) AS delivered,
                COUNT(DISTINCT pv.pregnancy_episode_id) AS pnc6
         FROM delivery_records dr
         LEFT JOIN postnatal_visits pv
           ON dr.pregnancy_episode_id = pv.pregnancy_episode_id
          AND pv.visit_timing = '6_weeks'"
    )->fetch();
    $stats['pnc_6week_rate'] = $row['delivered'] > 0 ? round(($row['pnc6'] / $row['delivered']) * 100) : 0;

    return $stats;
}

function validateNIN($nin): bool
{
    return !empty($nin) && strlen($nin) >= 10;
}

function validatePhone($phone): bool
{
    return (bool) preg_match('/^0(7|3)[0-9]{8}$/', $phone);
}

function validateEmail($email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sanitizeOutput($input): string
{
    return htmlspecialchars($input ?? '', ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token): bool
{
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCSRF(): void
{
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new RuntimeException('Invalid or expired form token. Reload the page and try again.');
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function currentUserId()
{
    return $_SESSION['user_id'] ?? null;
}

function exportToCSV(array $data, string $filename): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
