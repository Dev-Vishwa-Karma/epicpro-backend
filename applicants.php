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
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function respond($status, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'data' => $data]);
    exit;
}

function get_applicant($conn, $id) {
    $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function list_applicants($conn) {
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
    if (!empty($_GET['order'])) {
        if ($_GET['order'] === 'oldest') $order = "created_at ASC";
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $count_sql = "SELECT COUNT(*) as total FROM applicants";
    if ($where) $count_sql .= " WHERE " . implode(" AND ", $where);
    $count_stmt = $conn->prepare($count_sql);
    if ($params) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];

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
    return [
        'applicants' => $applicants,
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'totalPages' => ceil($total / $limit)
    ];
}

function add_applicant($conn) {
    $fullname = $_POST['fullname'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $experience = $_POST['experience'] ?? '';
    $streetaddress = $_POST['streetaddress'] ?? '';
    $skills = $_POST['skills'] ?? '[]';
    $status = 'pending';
    $resume_path = null;
    $resume = null;

    if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/resumes/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['resume']['name']);
        $targetFile = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetFile)) {
            $resume_path = 'uploads/resumes/' . $filename;
            $resume = $_FILES['resume']['name'];
        }
    }

    $stmt = $conn->prepare('INSERT INTO applicants (fullname, email, phone, experience, streetaddress, skills, resume_path, resume, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssssss', $fullname, $email, $phone, $experience, $streetaddress, $skills, $resume_path, $resume, $status);
    if ($stmt->execute()) {
        $applicantId = $conn->insert_id;
        $applicant = get_applicant($conn, $applicantId);
    
        // Format admin email
        $subjectAdmin = "New Application Received - {$fullname}";
        $skillsList = implode(', ', json_decode($skills, true));
    
        $messageAdmin = "<h2>New Application Submission</h2>";
        $messageAdmin .= "<p><strong>Name:</strong> {$fullname}<br>";
        $messageAdmin .= "<strong>Email:</strong> {$email}<br>";
        $messageAdmin .= "<strong>Phone:</strong> {$phone}<br>";
        $messageAdmin .= "<strong>Address:</strong> {$streetaddress}<br>";
        $messageAdmin .= "<strong>Experience:</strong> {$experience}<br>";
        $messageAdmin .= "<strong>Skills:</strong> {$skillsList}<br>";
        $messageAdmin .= "<strong>Resume:</strong> {$resumeLink}</p>";
        $messageAdmin .= "<p>View full application in the admin dashboard.</p>";
    
        sendEmail('akash.profilics@gmail.com', $subjectAdmin, $messageAdmin);
    
        // Format applicant email
        $subjectApplicant = "Application Received - {$fullname}";

        $logoUrl = "https://media.licdn.com/dms/image/v2/C4E1BAQHFGqLkG3JFdQ/company-background_10000/company-background_10000/0/1594115529786/profilics_cover?e=2147483647&v=beta&t=DTPwUTb3dR51d6ofWRy95FDEJJkpNOdq1hc-bmTXtPI";

        $messageApplicant = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: auto; border:1px solid #e0e0e0; border-radius:8px; overflow:hidden;">
                <div style="background: #f8f8f8; padding: 24px 24px 12px 24px; text-align: center;">
                    <img src="' . $logoUrl . '" alt="Profilics Systems" style="max-width: 180px; margin-bottom: 10px;">
                </div>
                <div style="padding: 24px;">
                    <p style="font-size: 18px; color: #333;">Dear <b>' . htmlspecialchars($fullname) . '</b>,</p>
                    <p style="font-size: 16px; color: #444;">
                        Thank you for applying for a position at <b>Profilics Systems</b>.<br>
                        We have received your application and our team will review your profile soon.
                    </p>
                    <div style="background: #f3f7fa; border-radius: 6px; padding: 16px; margin: 20px 0;">
                        <strong>Summary of your submission:</strong><br>
                        <b>Email:</b> ' . htmlspecialchars($email) . '<br>
                        <b>Phone:</b> ' . htmlspecialchars($phone) . '<br>
                        <b>Experience:</b> ' . htmlspecialchars($experience) . '<br>
                        <b>Skills:</b> ' . htmlspecialchars($skillsList) . '
                    </div>
                    <p style="font-size: 15px; color: #444;">
                        We appreciate your interest and will be in touch if your profile matches our requirements.<br>
                        If you have any questions, feel free to reply to this email.
                    </p>
                    <p style="margin-top: 32px; font-size: 15px;">
                        Best regards,<br>
                        <span style="color: #0a6ebd;"><b>Hiring Team</b></span><br>
                        <span style="color: #888;">Profilics Systems</span>
                    </p>
                </div>
                <div style="background: #f8f8f8; padding: 12px 24px; text-align: center; color: #aaa; font-size: 13px;">
                    &copy; ' . date('Y') . ' Profilics Systems. All rights reserved.
                </div>
            </div>
        ';
    
        sendEmail($email, $subjectApplicant, $messageApplicant);
    
        respond('success', ['id' => $applicantId]);
    } else {
        respond('error', ['message' => $stmt->error], 400);
    }
    
}

function update_applicant($conn) {
    $id = $_POST['id'] ?? null;
    if (!$id) respond('error', ['message' => 'ID required'], 400);
    $fields = ['fullname', 'email', 'phone', 'experience', 'streetaddress', 'skills', 'status'];
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
        $ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['resume']['name']);
        $targetFile = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetFile)) {
            $updates[] = 'resume_path = ?';
            $params[] = 'uploads/resumes/' . $filename;
            $types .= 's';
            $updates[] = 'resume = ?';
            $params[] = $_FILES['resume']['name'];
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
        if (in_array($_POST['status'], ['reviewed', 'interviewed', 'hired'])) {
    $applicant = get_applicant($conn, $id);
    $status = $_POST['status'];
    $subject = "Your Application Status Has Been Updated";
    $message = "Dear {$applicant['fullname']},<br><br>";

    switch ($status) {
    case 'reviewed':
        $message .= "Dear Candidate,<br><br>";
        $message .= "Thank you for your interest in joining <strong>Profilics Systems</strong>.<br><br>";
        $message .= "We’ve carefully reviewed your application and appreciate the time and effort you invested in sharing your background with us. Your profile has caught our attention, and we're currently evaluating it for potential progression to the next stage.<br><br>";
        $message .= "You can expect to hear from us shortly regarding the outcome or further steps in the process.<br><br>";
        $message .= "We truly value your patience and continued interest.<br>";
        break;

    case 'interviewed':
        $message .= "Dear Candidate,<br><br>";
        $message .= "Thank you for taking the time to speak with our team.<br><br>";
        $message .= "We greatly enjoyed learning more about your experience and the strengths you could bring to our organization. We are currently assessing all interviewed candidates to determine the best fit for the role.<br><br>";
        $message .= "We will keep you informed and aim to provide an update as soon as a final decision has been made.<br><br>";
        $message .= "We appreciate your time, effort, and interest in being part of Profilics Systems.<br>";
        break;

    case 'hired':
        $message .= "Dear Candidate,<br><br>";
        $message .= "<strong>Congratulations!</strong><br><br>";
        $message .= "We are delighted to extend an offer for the position you applied for at <strong>Profilics Systems</strong>.<br><br>";
        $message .= "Your qualifications and enthusiasm stood out during the selection process, and we are confident that you will be a valuable addition to our team.<br><br>";
        $message .= "Our HR department will be in touch shortly with your official offer letter, onboarding details, and other relevant information.<br><br>";
        $message .= "Welcome aboard—we look forward to a successful journey together!<br>";
        break;
}


    $message .= "<small>This is an automated message. Please do not reply to this email.</small>";

    sendEmail($applicant['email'], $subject, $message);


        }
        respond('success', ['updated' => $stmt->affected_rows]);
    } else {
        respond('error', ['message' => $stmt->error], 400);
    }
}

function delete_applicant($conn) {
    $id = $_POST['id'] ?? null;
    if (!$id) respond('error', ['message' => 'ID required'], 400);
    $stmt = $conn->prepare('DELETE FROM applicants WHERE id = ?');
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        respond('success', ['deleted' => $stmt->affected_rows]);
    } else {
        respond('error', ['message' => $stmt->error], 400);
    }
}


switch ($action) {
    case 'add':
        add_applicant($conn);
        break;
    case 'get':
        $id = $_GET['id'] ?? null;
        if (!$id) respond('error', ['message' => 'ID required'], 400);
        $applicant = get_applicant($conn, $id);
        if ($applicant) {
            respond('success', $applicant);
        } else {
            respond('error', ['message' => 'Applicant not found'], 404);
        }
        break;
    case 'list':
        $data = list_applicants($conn);
        respond('success', $data);
        break;
    case 'update':
        update_applicant($conn);
        break;
    case 'delete':
        delete_applicant($conn);
        break;
    default:
        respond('error', ['message' => 'Invalid action'], 400);
}