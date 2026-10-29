<?php
// ============================================
// FILE: api.php
// PURPOSE: Central AJAX endpoint for all CRUD operations and data fetching
// ============================================

require_once 'config.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only login is available before authentication.
$public_endpoints = ['login'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!in_array($action, $public_endpoints, true) && !refreshCurrentUserSession($pdo)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please login.']);
    exit;
}

$postActions = ['login', 'update_patient', 'delete_patient', 'save_anc_visit', 'logout'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($action, $postActions, true) !== ($method === 'POST')) {
    http_response_code(405);
    header('Allow: ' . (in_array($action, $postActions, true) ? 'POST' : 'GET'));
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (in_array($action, ['update_patient', 'delete_patient', 'save_anc_visit', 'logout'], true)) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired request token']);
        exit;
    }
}

try {
    switch ($action) {
        // ============================================
        // PATIENT ENDPOINTS
        // ============================================
        
        case 'get_patient':
            $id = $_GET['id'] ?? 0;
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Patient ID required']);
                break;
            }
            $stmt = $pdo->prepare("SELECT p.*, pe.id as episode_id, pe.client_number, pe.status as pregnancy_status 
                                   FROM patients p 
                                   LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active'
                                   WHERE p.id = :id");
            $stmt->execute([':id' => $id]);
            $patient = $stmt->fetch();
            echo json_encode(['success' => true, 'data' => $patient]);
            break;
            
        case 'search_patients':
            $q = $_GET['q'] ?? '';
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }
            $stmt = $pdo->prepare("SELECT p.id, p.surname, p.first_name, p.nin, p.phone, p.village, pe.client_number 
                                   FROM patients p 
                                   LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active'
                                   WHERE p.surname LIKE :q OR p.first_name LIKE :q OR p.nin LIKE :q OR p.phone LIKE :q
                                   LIMIT 20");
            $stmt->execute([':q' => "%$q%"]);
            $results = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $results]);
            break;
            
        case 'get_all_patients':
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
            $limit = $limit === false ? 50 : max(1, min($limit, 200));
            $stmt = $pdo->prepare("SELECT p.*, pe.client_number, pe.status as pregnancy_status 
                                   FROM patients p 
                                   LEFT JOIN pregnancy_episodes pe ON p.id = pe.patient_id AND pe.status = 'active'
                                   ORDER BY p.created_at DESC 
                                   LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $patients = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $patients]);
            break;
            
        case 'update_patient':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || empty($data['id'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE patients SET 
                                   nin = :nin, surname = :surname, first_name = :first_name, age = :age,
                                   client_category = :category, phone = :phone, district = :district,
                                   sub_county = :sub_county, parish = :parish, village = :village
                                   WHERE id = :id");
            $stmt->execute([
                ':id' => $data['id'],
                ':nin' => $data['nin'] ?? null,
                ':surname' => $data['surname'],
                ':first_name' => $data['first_name'],
                ':age' => $data['age'] ?? null,
                ':category' => $data['client_category'] ?? 'N',
                ':phone' => $data['phone'] ?? null,
                ':district' => $data['district'] ?? null,
                ':sub_county' => $data['sub_county'] ?? null,
                ':parish' => $data['parish'] ?? null,
                ':village' => $data['village'] ?? null
            ]);
            logAction($pdo, $_SESSION['user_id'], 'UPDATE_PATIENT', 'patients', $data['id'], 'Patient updated via API');
            echo json_encode(['success' => true, 'message' => 'Patient updated successfully']);
            break;
            
        case 'delete_patient':
            $id = filter_var($_POST['id'] ?? $_GET['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Valid patient ID required']);
                break;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pregnancy_episodes WHERE patient_id = :id");
            $stmt->execute([':id' => $id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete patient with existing pregnancy records']);
                break;
            }
            $stmt = $pdo->prepare("DELETE FROM patients WHERE id = :id");
            $stmt->execute([':id' => $id]);
            logAction($pdo, $_SESSION['user_id'], 'DELETE_PATIENT', 'patients', $id, 'Patient deleted via API');
            echo json_encode(['success' => true, 'message' => 'Patient deleted successfully']);
            break;
            
        // ============================================
        // PREGNANCY EPISODE ENDPOINTS
        // ============================================
        
        case 'get_pregnancy_episodes':
            $patient_id = $_GET['patient_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM pregnancy_episodes WHERE patient_id = :id ORDER BY created_at DESC");
            $stmt->execute([':id' => $patient_id]);
            $episodes = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $episodes]);
            break;
            
        case 'get_active_pregnancy':
            $patient_id = $_GET['patient_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM pregnancy_episodes WHERE patient_id = :id AND status = 'active' LIMIT 1");
            $stmt->execute([':id' => $patient_id]);
            $episode = $stmt->fetch();
            echo json_encode(['success' => true, 'data' => $episode]);
            break;
            
        // ============================================
        // ANC VISIT ENDPOINTS
        // ============================================
        
        case 'get_anc_visits':
            $episode_id = $_GET['episode_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM anc_visits WHERE pregnancy_episode_id = :id ORDER BY visit_number ASC");
            $stmt->execute([':id' => $episode_id]);
            $visits = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $visits]);
            break;
            
        case 'get_anc_visit':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM anc_visits WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $visit = $stmt->fetch();
            echo json_encode(['success' => true, 'data' => $visit]);
            break;
            
        case 'save_anc_visit':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                echo json_encode(['success' => false, 'error' => 'Invalid data']);
                break;
            }
            // Calculate GA from LMP
            $stmt = $pdo->prepare("SELECT lmp FROM pregnancy_episodes WHERE id = :id");
            $stmt->execute([':id' => $data['pregnancy_episode_id']]);
            $lmp = $stmt->fetch()['lmp'];
            $ga = calculateGestationalAge($lmp, $data['visit_date']);
            
            $serial_no = getNextSerialNo($pdo, 'anc_visits', 'visit_date');
            $muac_color = isset($data['muac_cm']) ? getMUACColor(floatval($data['muac_cm'])) : 'ND';
            
            $stmt = $pdo->prepare("INSERT INTO anc_visits (
                pregnancy_episode_id, serial_no, visit_number, visit_date, gestational_age_weeks,
                weight_kg, height_cm, muac_cm, muac_color, bp_systolic, bp_diastolic,
                diagnosis, complications, woman_hiv_code, tt_dose_received, iptp_dose
            ) VALUES (
                :episode_id, :serial_no, :visit_number, :visit_date, :ga,
                :weight, :height, :muac, :muac_color, :bp_sys, :bp_dias,
                :diagnosis, :complications, :hiv_code, :tt, :iptp
            )");
            $stmt->execute([
                ':episode_id' => $data['pregnancy_episode_id'],
                ':serial_no' => $serial_no,
                ':visit_number' => $data['visit_number'],
                ':visit_date' => $data['visit_date'],
                ':ga' => $ga,
                ':weight' => $data['weight_kg'] ?? null,
                ':height' => $data['height_cm'] ?? null,
                ':muac' => $data['muac_cm'] ?? null,
                ':muac_color' => $muac_color,
                ':bp_sys' => $data['bp_systolic'] ?? null,
                ':bp_dias' => $data['bp_diastolic'] ?? null,
                ':diagnosis' => $data['diagnosis'] ?? null,
                ':complications' => $data['complications'] ?? null,
                ':hiv_code' => $data['woman_hiv_code'] ?? null,
                ':tt' => $data['tt_dose_received'] ?? null,
                ':iptp' => $data['iptp_dose'] ?? null
            ]);
            logAction($pdo, $_SESSION['user_id'], 'CREATE_ANC_VISIT', 'anc_visits', $pdo->lastInsertId(), 'ANC visit recorded via API');
            echo json_encode(['success' => true, 'message' => 'ANC visit recorded successfully']);
            break;
            
        // ============================================
        // DELIVERY ENDPOINTS
        // ============================================
        
        case 'get_delivery':
            $episode_id = $_GET['episode_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM delivery_records WHERE pregnancy_episode_id = :id");
            $stmt->execute([':id' => $episode_id]);
            $delivery = $stmt->fetch();
            echo json_encode(['success' => true, 'data' => $delivery]);
            break;
            
        // ============================================
        // POSTNATAL ENDPOINTS
        // ============================================
        
        case 'get_postnatal_visits':
            $episode_id = $_GET['episode_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM postnatal_visits WHERE pregnancy_episode_id = :id ORDER BY visit_date ASC");
            $stmt->execute([':id' => $episode_id]);
            $visits = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $visits]);
            break;
            
        // ============================================
        // DASHBOARD STATS ENDPOINTS
        // ============================================
        
        case 'dashboard_stats':
            $stats = [];
            $stats['active_pregnancies'] = $pdo->query("SELECT COUNT(*) FROM pregnancy_episodes WHERE status = 'active'")->fetchColumn();
            $stats['anc_today'] = $pdo->query("SELECT COUNT(*) FROM anc_visits WHERE visit_date = CURDATE()")->fetchColumn();
            $stats['deliveries_today'] = $pdo->query("SELECT COUNT(*) FROM delivery_records WHERE DATE(delivery_datetime) = CURDATE()")->fetchColumn();
            $stats['deliveries_month'] = $pdo->query("SELECT COUNT(*) FROM delivery_records WHERE MONTH(delivery_datetime) = MONTH(CURDATE())")->fetchColumn();
            $stats['total_patients'] = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
            $stats['total_anc_visits'] = $pdo->query("SELECT COUNT(*) FROM anc_visits")->fetchColumn();
            $stats['total_deliveries'] = $pdo->query("SELECT COUNT(*) FROM delivery_records")->fetchColumn();
            $stats['hiv_positive'] = $pdo->query("SELECT COUNT(DISTINCT pe.id) FROM anc_visits av JOIN pregnancy_episodes pe ON av.pregnancy_episode_id = pe.id WHERE av.woman_hiv_code IN ('TRR','TRRK','TRR+')")->fetchColumn();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'monthly_trends':
            $stmt = $pdo->query("SELECT DATE_FORMAT(visit_date, '%b') as month, COUNT(*) as visits 
                                 FROM anc_visits 
                                 WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                                 GROUP BY MONTH(visit_date)
                                 ORDER BY visit_date");
            $trends = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $trends]);
            break;
            
        // ============================================
        // AUTHENTICATION ENDPOINTS
        // ============================================
        
        case 'login':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || empty($data['username']) || empty($data['password'])) {
                echo json_encode(['success' => false, 'error' => 'Username and password required']);
                break;
            }
            $user = verifyLogin($pdo, $data['username'], $data['password']);
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_fullname'] = $user['full_name'];
                $_SESSION['user_role'] = normalizeUserRole($user['role']);
                echo json_encode(['success' => true, 'user' => ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $_SESSION['user_role']]]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            }
            break;
            
        case 'check_username':
            $username = $_GET['username'] ?? '';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $exists = $stmt->fetchColumn() > 0;
            echo json_encode(['success' => true, 'exists' => $exists]);
            break;
            
        case 'get_current_user':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'Not logged in']);
                break;
            }
            echo json_encode(['success' => true, 'user' => ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'], 'full_name' => $_SESSION['user_fullname'], 'role' => $_SESSION['user_role']]]);
            break;
            
        case 'logout':
            $_SESSION = [];
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        // ============================================
        // SYSTEM ENDPOINTS
        // ============================================
        
        case 'get_system_stats':
            $stats = [];
            $stats['total_patients'] = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
            $stats['total_anc_visits'] = $pdo->query("SELECT COUNT(*) FROM anc_visits")->fetchColumn();
            $stats['total_deliveries'] = $pdo->query("SELECT COUNT(*) FROM delivery_records")->fetchColumn();
            $stats['total_postnatal'] = $pdo->query("SELECT COUNT(*) FROM postnatal_visits")->fetchColumn();
            $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats['database_size'] = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size 
                                                   FROM information_schema.tables 
                                                   WHERE table_schema = DATABASE()")->fetchColumn();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
    }
} catch (Throwable $e) {
    error_log('API request failed (' . $action . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The request could not be completed.']);
}
?>