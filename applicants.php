<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
require_once 'mailer.php';
require_once 'db_connection.php';
require_once 'email_templates.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function respond($status, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'data' => $data]);
    exit;
}

// Function to convert decimal experience to readable text
function formatExperience($experience) {
    if (empty($experience)) return '';
    
    // Handle decimal format (e.g., 1.6 = 1 year 6 months)
    if (strpos($experience, '.') !== false) {
        $parts = explode('.', $experience);
        $years = (int)$parts[0];
        $months = (int)$parts[1];
        
        $result = '';
        if ($years > 0) {
            $result .= $years . ' year' . ($years > 1 ? 's' : '');
        }
        if ($months > 0) {
            if ($result) $result .= ' ';
            $result .= $months . ' month' . ($months > 1 ? 's' : '');
        }
        return $result;
    }
    
    // Handle integer format (e.g., 1 = 1 year)
    $years = (int)$experience;
    if ($years == 0) return '0 months';
    return $years . ' year' . ($years > 1 ? 's' : '');
}

switch ($action) {
    case 'add':
        // Check database connection
        if (!$conn) {
            error_log("Database connection failed in add action");
            respond('error', ['message' => 'Database connection failed'], 500);
        }
    
        
        // Log received data for debugging
        error_log("Received POST data: " . print_r($_POST, true));
        
        $fullname = $_POST['fullname'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $alternate_phone = $_POST['alternate_phone'] ?? '';
        $dob = $_POST['dob'] ?? '';
        $marital_status = !empty($_POST['marital_status']) ? $_POST['marital_status'] : 'single';
        $experience = $_POST['experience'] ?? '';
        $address = $_POST['address'] ?? '';
        $skills = $_POST['skills'] ?? '[]';
        $joining_timeframe = $_POST['joining_timeframe'] ?? '';
        $bond_agreement = !empty($_POST['bond_agreement']) ? $_POST['bond_agreement'] : null;
        $branch = $_POST['branch'] ?? '';
        $graduate_year = !empty($_POST['graduate_year']) ? (int)$_POST['graduate_year'] : null;
        $status = 'pending';
        $resume_path = null;
        $source = $_POST['source'] ?? 'admin';

        if (empty($fullname) || empty($email)) {
            respond('error', ['message' => 'Full Name and Email are required.'], 400);
        }

        // Check if email already exists
        $stmt = $conn->prepare('SELECT id FROM applicants WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            respond('error', ['message' => 'Email already exists.'], 200);
        }

        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/resumes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['resume']['name']);
            $targetFile = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetFile)) {
                $resume_path = 'uploads/resumes/' . $filename;
            }
        }

        $sql = 'INSERT INTO applicants (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, skills, joining_timeframe, bond_agreement, branch, graduate_year, resume_path, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        error_log("SQL Query: " . $sql);
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            respond('error', ['message' => 'Database prepare failed: ' . $conn->error], 500);
        }
        
        $bindResult = $stmt->bind_param('ssssssssssssisss', $fullname, $email, $phone, $alternate_phone, $dob, $marital_status, $experience, $address, $skills, $joining_timeframe, $bond_agreement, $branch, $graduate_year, $resume_path, $status, $source);

        if (!$bindResult) {
            error_log("Bind failed: " . $stmt->error);
            respond('error', ['message' => 'Database bind failed: ' . $stmt->error], 500);
        }
        if ($stmt->execute()) {
            $applicantId = $conn->insert_id;

            $stmt2 = $conn->prepare('SELECT * FROM applicants WHERE id = ?');
            $stmt2->bind_param('i', $applicantId);
            $stmt2->execute();
            $applicant = $stmt2->get_result()->fetch_assoc();

            // // Send admin notification
            // $subjectAdmin = "New Application Received - {$fullname}";
            // $messageAdmin = getAdminNotificationEmail($applicant);
            // sendEmail('akash.profilics@gmail.com', $subjectAdmin, $messageAdmin);

            // // Send applicant confirmation
            // $subjectApplicant = "Application Received - {$fullname}";
            // $messageApplicant = getApplicantConfirmationEmail($applicant);
            // sendEmail($email, $subjectApplicant, $messageApplicant);

            respond('success', ['id' => $applicantId]);
        } else {
            error_log("Add applicant error: " . $stmt->error);
            respond('error', ['message' => $stmt->error], 400);
        }
        break;

    // case 'get':
    //     $id = $_GET['id'] ?? null;
    //     if (!$id) respond('error', ['message' => 'ID required'], 400);
    //     $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ?');
    //     $stmt->bind_param('i', $id);
    //     $stmt->execute();
    //     $applicant = $stmt->get_result()->fetch_assoc();
    //     if ($applicant) {
    //         respond('success', $applicant);
    //     } else {
    //         respond('error', ['message' => 'Applicant not found'], 404);
    //     }
    //     break;

    case 'get':
        $where = [];
        $params = [];
        $types = '';

        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $where[] = "(fullname LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'sss';
        }

        if (!empty($_GET['status']) && in_array($_GET['status'], ['pending','reviewed','interviewed','hired','rejected'])) {
            $where[] = "status = ?";
            $params[] = $_GET['status'];
            $types .= 's';
        }

        $order = "created_at DESC";
        if (!empty($_GET['order']) && $_GET['order'] === 'oldest') {
            $order = "created_at ASC";
        }

        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $count_sql = "SELECT COUNT(*) as total FROM applicants";
        if ($where) $count_sql .= " WHERE " . implode(" AND ", $where);
        $count_stmt = $conn->prepare($count_sql);
        if ($params) $count_stmt->bind_param($types, ...$params);
        $count_stmt->execute();
        $total = $count_stmt->get_result()->fetch_assoc()['total'];

        $sql = "SELECT * FROM applicants";
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY $order LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types_with_pagination = $types . 'ii';
            array_push($params, $limit, $offset);
            $stmt->bind_param($types_with_pagination, ...$params);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $applicants = [];
        while ($row = $result->fetch_assoc()) {
            // Format experience for display
            if (isset($row['experience'])) {
                $row['experience_display'] = formatExperience($row['experience']);
            }
            $applicants[] = $row;
        }

        respond('success', [
            'applicants' => $applicants,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit)
        ]);
        break;

    case 'update':
        $id = $_POST['id'] ?? null;
        if (!$id) respond('error', ['message' => 'ID required'], 400);

        $fields = ['fullname', 'email', 'phone', 'alternate_phone', 'dob', 'marital_status', 'experience', 'address', 'skills', 'joining_timeframe', 'bond_agreement', 'branch', 'graduate_year', 'reject_reason', 'status'];
        $updates = [];
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = $_POST[$field];
                $types .= 's';
            }
        }

        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/resumes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['resume']['name']);
            $targetFile = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetFile)) {
                $updates[] = 'resume_path = ?';
                $params[] = 'uploads/resumes/' . $filename;
                $types .= 's';
            }
        }

        if (empty($updates)) respond('error', ['message' => 'No fields to update'], 400);
        $params[] = $id;
        $types .= 'i';
        $sql = 'UPDATE applicants SET ' . implode(', ', $updates) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            if (isset($_POST['status']) && in_array($_POST['status'], ['reviewed', 'interviewed', 'hired'])) {
                $stmt2 = $conn->prepare('SELECT * FROM applicants WHERE id = ?');
                $stmt2->bind_param('i', $id);
                $stmt2->execute();
                $applicant = $stmt2->get_result()->fetch_assoc();

                $statusUpdate = getStatusUpdateEmail($applicant, $_POST['status']);
                // sendEmail($applicant['email'], $statusUpdate['subject'], $statusUpdate['message']);
            }
            respond('success', ['updated' => $stmt->affected_rows]);
        } else {
            respond('error', ['message' => $stmt->error], 400);
        }
        break;

    case 'delete':
        $id = $_POST['id'] ?? null;
        if (!$id) respond('error', ['message' => 'ID required'], 400);
        $stmt = $conn->prepare('DELETE FROM applicants WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            respond('success', ['deleted' => $stmt->affected_rows]);
        } else {
            respond('error', ['message' => $stmt->error], 400);                                                                                 
        }
        break;

    case 'sync_applicant':
        if (!$conn) {
            error_log("Database connection failed in sync_applicant");
            respond('error', ['message' => 'Database connection failed'], 500);
        }                                                                                                                               
        $url = 'https://randomuser.me/api/?results=50';                                                                                                                                                                                                                
        $response = file_get_contents($url);
        if ($response === false) {
            error_log("Failed to fetch data from: " . $url);
            respond('error', ['message' => 'Failed to fetch data from external API'], 500);
        }
        $applicantData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            respond('error', ['message' => 'Invalid JSON response from external API'], 500);
        }
    
        if ($applicantData && isset($applicantData['results'])) {
            $insertedApplicants = 0;
    
            foreach ($applicantData['results'] as $applicant) {
                $email = $applicant['email'] ?? '';
                
                if (!empty($email)) {
                    // Check if applicant exists
                    $stmt = $conn->prepare('SELECT COUNT(*) AS count FROM applicants WHERE email = ?');
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $count = $row['count'];
    
                    if ($count == 0) {
                        error_log("Processing new applicant: " . $email);
                        $fullname = ($applicant['name']['first'] ?? '') . ' ' . ($applicant['name']['last'] ?? '');
                        $phone = $applicant['phone'] ?? '';
                        $alternate_phone = $applicant['phone'] ?? '';
                        $dob = '';
                        if (isset($applicant['dob']['date'])) {
                            $dob = date('Y-m-d', strtotime($applicant['dob']['date']));
                        }
                        $marital_status = null;
                        $address = ($applicant['location']['street']['number'] ?? '') . ' ' . 
                                        ($applicant['location']['street']['name'] ?? '');
                        $skills = json_encode(['']);
                        $status = 'pending';
                        $experience = null;
                        $joining_timeframe = null;
                        $bond_agreement = null;
                        $branch = '';
                        $graduate_year = null;
                        $source_sync = 'sync';

                        $stmt = $conn->prepare('INSERT INTO applicants 
                            (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, skills, joining_timeframe, bond_agreement, branch, graduate_year, status, source) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('sssssssssssssss', 
                            $fullname, $email, $phone, $alternate_phone, $dob, $marital_status, $experience, $address, $skills, $joining_timeframe, $bond_agreement, $branch, $graduate_year, $status, $source_sync);
                        
                        if ($stmt->execute()) {
                            $insertedApplicants++;
                        } else {
                            error_log("Sync applicant error: " . $stmt->error);
                        }
                    }
                }
            }
                                                                                                                                                                                                                                            respond('success', ['inserted' => $insertedApplicants]);
        } else {
            respond('error', ['message' => 'Failed to fetch applicant data from external source'], 500);
        }
        break;

    default:
        respond('error', ['message' => 'Invalid action'], 400);
}