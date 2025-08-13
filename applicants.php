<?php
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

switch ($action) {
    case 'add':
        $fullname = $_POST['fullname'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $experience = $_POST['experience'] ?? '';
        $address = $_POST['address'] ?? '';
        $skills = $_POST['skills'] ?? '[]';
        $status = 'pending';
        $resume_path = null;

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

        $stmt = $conn->prepare('INSERT INTO applicants (fullname, email, phone, experience, address, skills, resume_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssss', $fullname, $email, $phone, $experience, $address, $skills, $resume_path, $status);
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

        $fields = ['fullname', 'email', 'phone', 'experience', 'address', 'skills', 'status'];
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
        $url = 'https://randomuser.me/api/?results=10';
        $response = file_get_contents($url);
        $applicantData = json_decode($response, true);
    
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
                        // Prepare data from API response
                        $fullname = ($applicant['name']['first'] ?? '') . ' ' . ($applicant['name']['last'] ?? '');
                        $phone = $applicant['phone'] ?? '';
                        $address = ($applicant['location']['street']['number'] ?? '') . ' ' . 
                                        ($applicant['location']['street']['name'] ?? '');
                        $skills = json_encode(['Random Skill 1', 'Random Skill 2']); // Default skills
                        $status = 'pending';
                        $experience = rand(1, 10); // Random experience
    
                        // Insert new applicant
                        $stmt = $conn->prepare('INSERT INTO applicants 
                            (fullname, email, phone, experience, address, skills, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('sssssss', 
                            $fullname, $email, $phone, $experience, $address, $skills, $status);
                        
                        if ($stmt->execute()) {
                            $insertedApplicants++;
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