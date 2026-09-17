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
require_once __DIR__ . '/mailer.php';
require_once 'db_connection.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/email_template.php';
require_once 'helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function respond($status, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode(['status' => $status, 'data' => $data]);
    exit;
}

// Function to convert decimal experience to readable text
function formatExperience($experience)
{
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
    $text = strtolower($experience);
    if (str_contains($text, 'year') || str_contains($text, 'month')) {
        return $text;
    }
    $years = (int)$experience;
    if ($years == 0) return '0 months';
    return $years . ' year' . ($years > 1 ? 's' : '');
}

// Function to normalize incoming status strings from HR CSV/Excel import to valid DB ENUM values
function normalizeApplicantStatus($statusStr)
{
    if (empty($statusStr)) return 'pending';
    $lower = strtolower(trim((string)$statusStr));
    if (in_array($lower, ['pending', 'reviewed', 'interviewed', 'hired', 'rejected'])) {
        return $lower;
    }
    if (strpos($lower, 'shortlist') !== false && (strpos($lower, 'not') !== false || strpos($lower, 'no') !== false)) return 'rejected';
    if (strpos($lower, 'reject') !== false || strpos($lower, 'decline') !== false) return 'rejected';
    if (strpos($lower, 'select') !== false || strpos($lower, 'hire') !== false || strpos($lower, 'join') !== false || strpos($lower, 'offer') !== false) return 'hired';
    if (strpos($lower, 'interview') !== false || strpos($lower, 'test') !== false || strpos($lower, 'shortlist') !== false) return 'interviewed';
    if (strpos($lower, 'schedul') !== false || strpos($lower, 'review') !== false) return 'reviewed';
    if (strpos($lower, 'completed') !== false || strpos($lower, 'close') !== false  || strpos($lower, 'done') !== false) return 'closed';
    return 'pending';
}

switch ($action) {
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

    case 'view':
        $where = [];
        $params = [];
        $types = '';

        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $where[] = "(fullname LIKE ? OR email LIKE ? OR phone LIKE ? OR location LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'ssss';
        }

        if (!empty($_GET['status']) && in_array($_GET['status'], ['closed', 'pending', 'reviewed', 'interviewed', 'hired', 'rejected'])) {
            $where[] = "status = ?";
            $params[] = $_GET['status'];
            $types .= 's';
        }

        $order = "created_at DESC, id DESC";
        if (!empty($_GET['order']) && $_GET['order'] === 'oldest') {
            $order = "created_at ASC, id ASC";
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

        $stmt = $conn->prepare("SELECT last_sync FROM sync_logs ORDER BY id DESC LIMIT 1");
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $lastSyncDate = $row['last_sync'];
            }
        }

        respond('success', [
            'applicants' => $applicants,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit),
            'last_sync' => $lastSyncDate
        ]);
        break;

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
        $location = $_POST['location'] ?? '';
        $skills = $_POST['skills'] ?? [];
        // Ensure skills is stored as valid JSON string
        if (is_array($skills)) {
            $skills = json_encode($skills);
        } elseif ($skills === null || $skills === '') {
            $skills = '[]';
        }
        $joining_timeframe = $_POST['joining_timeframe'] ?? '';
        $bond_agreement = !empty($_POST['bond_agreement']) ? $_POST['bond_agreement'] : null;
        $branch = $_POST['branch'] ?? '';
        $graduate_year = !empty($_POST['graduate_year']) ? (int)$_POST['graduate_year'] : null;
        $note = $_POST['note'] ?? '';
        $status = 'pending';
        $resume_path = null;
        $source = $_POST['source'] ?? 'admin';
        $employee_id = (isset($_POST['employee_id']) && $_POST['employee_id'] !== '') ? $_POST['employee_id'] : null;
        $employee_name = !empty($_POST['employee_name']) ? $_POST['employee_name'] : null;

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
            try {
                $uploadedFilePath = uploadFile($_FILES['resume'], 'uploads/resumes');
                $resume_path = $uploadedFilePath;
            } catch (Exception $e) {
                error_log("Resume upload failed: " . $e->getMessage());
            }
        }

        $sql = 'INSERT INTO applicants (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, location, note, skills, joining_timeframe, bond_agreement, branch, graduate_year, resume_path, status, source, employee_id, employee_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        error_log("SQL Query: " . $sql);

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            respond('error', ['message' => 'Database prepare failed: ' . $conn->error], 500);
        }

        $bindResult = $stmt->bind_param('sssssssssssissssssss', $fullname, $email, $phone, $alternate_phone, $dob, $marital_status, $experience, $address, $location, $note, $skills, $joining_timeframe, $bond_agreement, $branch, $graduate_year, $resume_path, $status, $source, $employee_id, $employee_name);

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
            // $messageAdmin = EmailTemplate::getAdminNotificationEmail($applicant);
            // sendEmail('akash.profilics@gmail.com', $subjectAdmin, $messageAdmin);

            // // Send applicant confirmation
            // $subjectApplicant = "Application Received - {$fullname}";
            // $messageApplicant = EmailTemplate::getApplicantConfirmationEmail($applicant);
            // sendEmail($email, $subjectApplicant, $messageApplicant);
            $companyDetails = isset($_POST['companyDetails']) ? json_decode($_POST['companyDetails'], true) : [];
            if (!empty($companyDetails) && is_array($companyDetails)) {
                $stmtCompany = $conn->prepare('INSERT INTO applicant_company_details (applicant_id, company_name, job_title, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($companyDetails as $company) {
                    if (!empty($company['company_name'])) {
                        $c_name = $company['company_name'];
                        $c_title = $company['job_title'] ?? '';
                        $c_start = $company['start_date'] ?? '';
                        $c_end = !empty($company['end_date']) ? $company['end_date'] : null;
                        $c_current = isset($company['is_current']) ? (int)$company['is_current'] : 0;

                        $stmtCompany->bind_param('issssi', $applicantId, $c_name, $c_title, $c_start, $c_end, $c_current);
                        $stmtCompany->execute();
                    }
                }
            }

            respond('success', ['id' => $applicantId]);
        } else {
            error_log("Add applicant error: " . $stmt->error);
            respond('error', ['message' => $stmt->error], 400);
        }
        break;

    case 'update':
        $id = $_POST['id'] ?? null;
        $emailForUpdate = $_POST['email'] ?? null;
        if (!$id && !$emailForUpdate) respond('error', ['message' => 'ID or Email required'], 400);

        $fields = ['fullname', 'email', 'phone', 'alternate_phone', 'dob', 'marital_status', 'experience', 'address', 'location', 'note', 'skills', 'joining_timeframe', 'bond_agreement', 'branch', 'graduate_year', 'reject_reason', 'status', 'employee_id', 'employee_name'];
        $updates = [];
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $value = $_POST[$field];
                if ($field === 'skills') {
                    if (is_array($value)) {
                        $value = json_encode($value);
                    } elseif ($value === null || $value === '') {
                        $value = '[]';
                    }
                }
                if ($value == 'null' or $value == '') {
                    $value = null;
                }
                $params[] = $value;
                $types .= 's';
            }
        }


        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadedFilePath = uploadFile($_FILES['resume'], 'uploads/resumes');
                $updates[] = 'resume_path = ?';
                $params[] = $uploadedFilePath;
                $types .= 's';
            } catch (Exception $e) {
                error_log("Resume update upload failed: " . $e->getMessage());
            }
        }

        if (empty($updates)) respond('error', ['message' => 'No fields to update'], 400);

        $whereClause = '';
        $whereType = '';
        $whereValue = null;
        if ($id) {
            $whereClause = 'id = ?';
            $whereType = 'i';
            $whereValue = (int)$id;
        } else {
            $whereClause = 'email = ?';
            $whereType = 's';
            $whereValue = $emailForUpdate;
        }

        $params[] = $whereValue;
        $types .= $whereType;
        $sql = 'UPDATE applicants SET ' . implode(', ', $updates) . ', updated_at = CURRENT_TIMESTAMP WHERE ' . $whereClause;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            if (isset($_POST['status']) && in_array($_POST['status'], ['reviewed', 'interviewed', 'hired'])) {
                $stmt2 = $conn->prepare('SELECT * FROM applicants WHERE ' . $whereClause . ' LIMIT 1');
                $stmt2->bind_param($whereType, $whereValue);
                $stmt2->execute();
                $applicant = $stmt2->get_result()->fetch_assoc();

                $statusUpdate = EmailTemplate::getStatusUpdateEmail($applicant, $_POST['status']);
                // sendEmail($applicant['email'], $statusUpdate['subject'], $statusUpdate['message']);

                // Send notification to referring employee if status changed and applicant is a referral
                // if (
                //     isset($applicant['source']) && $applicant['source'] === 'referral' &&
                //     !empty($applicant['employee_code']) && isset($_POST['status'])
                // ) {
                //     $employee_code = $applicant['employee_code'];
                //     $title = "Referral Status Updated";
                //     $body = "The status of your referred applicant ({$applicant['fullname']}) has been changed to '{$_POST['status']}'.";
                //     $type = "referral_status";
                //     $read = 0;

                //     $stmtNotif = $conn->prepare("INSERT INTO notifications (employee_code, title, body, type, `read`, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                //     $stmtNotif->bind_param("isssi", $employee_code, $title, $body, $type, $read);
                //     $stmtNotif->execute();
                // }
            }
            if (isset($_POST['companyDetails'])) {
                $applicantId = (int)($_POST['id'] ?? 0);
                if ($applicantId > 0) {
                    // First delete existing
                    $stmtDel = $conn->prepare('DELETE FROM applicant_company_details WHERE applicant_id = ?');
                    $stmtDel->bind_param('i', $applicantId);
                    $stmtDel->execute();

                    // Parse JSON
                    $companyDetails = json_decode($_POST['companyDetails'], true);
                    if ($companyDetails && is_array($companyDetails)) {
                        // Then insert new
                        $stmtCompany = $conn->prepare('INSERT INTO applicant_company_details (applicant_id, company_name, job_title, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?)');
                        foreach ($companyDetails as $company) {
                            if (!empty($company['company_name'])) {
                                $c_name = $company['company_name'];
                                $c_title = $company['job_title'] ?? '';
                                $c_start = $company['start_date'] ?? '';
                                $c_end = !empty($company['end_date']) ? $company['end_date'] : null;
                                $c_current = isset($company['is_current']) ? (int)$company['is_current'] : 0;

                                $stmtCompany->bind_param('issssi', $applicantId, $c_name, $c_title, $c_start, $c_end, $c_current);
                                $stmtCompany->execute();
                            }
                        }
                    }
                }
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

        $lastSync = null;
        $stmt = $conn->prepare("SELECT last_sync FROM sync_logs ORDER BY id DESC LIMIT 1");
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $lastSync = $row['last_sync'];
            }
        }

        // Build API URL with filter (assuming API supports since date filter)
        $url = "https://qna.profilics.com/api/candidates";

        if ($lastSync) {
            $url .= "?since=" . str_replace(' ', '%20', $lastSync);
        }
        // var_dump($url);die;
        $response = file_get_contents($url);
        // var_dump($response);die;
        if ($response === false) {
            error_log("Failed to fetch data from: " . $url);
            respond('error', ['message' => 'Failed to fetch data from external API'], 500);
        }

        $applicantData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            respond('error', ['message' => 'Invalid JSON response from external API'], 500);
        }

        if ($applicantData) {
            $insertedApplicants = 0;
            $duplicateApplicants = [];
            $autoUpdated = 0;

            foreach ($applicantData['data'] as $applicant) {
                $email = $applicant['email'] ?? '';

                if (!empty($email)) {
                    // Fetch existing record by email (id and fullname) to properly build duplicate payload
                    $stmtExisting = $conn->prepare('SELECT * FROM applicants WHERE email = ? LIMIT 1');
                    $stmtExisting->bind_param('s', $email);
                    $stmtExisting->execute();
                    $resultExisting = $stmtExisting->get_result();
                    $existing = $resultExisting->fetch_assoc();

                    if (!$existing) {
                        $fullname = $applicant['fullname'] ?? (($applicant['first_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
                        $phone = $applicant['phone'] ?? '';
                        $alternate_phone = $applicant['alternate_phone'] ?? '';
                        $dob = !empty($applicant['dob']) ? date('Y-m-d', strtotime($applicant['dob'])) : null;
                        $address = trim(($applicant['address_1'] ?? '') . ' ' . ($applicant['address_2'] ?? ''));
                        $location = $applicant['location'] ?? null;
                        $skills = isset($applicant['skills']) ? (is_array($applicant['skills']) ? json_encode($applicant['skills']) : (string)$applicant['skills']) : json_encode([]);
                        $status = $applicant['status'] ?? 'pending';
                        $source_sync = 'sync';
                        $marital_status = $applicant['marital_status'] ?? null;
                        $experience = $applicant['experience'] ?? null;
                        $joining_timeframe = $applicant['joining_timeframe'] ?? null;
                        $bond_agreement = $applicant['bond_agreement'] ?? null;
                        $resume_path = $applicant['resume_path'] ?? null;
                        $branch = $applicant['branch'] ?? null;
                        $graduate_year = $applicant['graduate_year'] ?? null;

                        // 3. Download the resume if it exists
                        if ($resume_path) {
                            try {
                                $resume_path = uploadExternalFile($resume_path, 'uploads/resumes');
                            } catch (Exception $e) {
                                error_log("Failed to download or upload resume: " . $e->getMessage());
                                $resume_path = null;
                            }
                        }

                        $stmtInsert = $conn->prepare('INSERT INTO applicants 
                            (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, location, skills, joining_timeframe, bond_agreement, resume_path, branch, graduate_year, status, source) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmtInsert->bind_param(
                            'sssssssssssssssss',
                            $fullname,
                            $email,
                            $phone,
                            $alternate_phone,
                            $dob,
                            $marital_status,
                            $experience,
                            $address,
                            $location,
                            $skills,
                            $joining_timeframe,
                            $bond_agreement,
                            $resume_path,
                            $branch,
                            $graduate_year,
                            $status,
                            $source_sync
                        );

                        if ($stmtInsert->execute()) {
                            $insertedApplicants++;
                        } else {
                            error_log("Sync applicant insert error: " . $stmtInsert->error);
                        }
                    } else {
                        // Build richer duplicate payload with existing and incoming data
                        $incomingFullname = $applicant['fullname'] ?? (($applicant['first_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
                        $incomingDob = !empty($applicant['dob']) ? date('Y-m-d', strtotime($applicant['dob'])) : null;
                        // $incomingAddress = trim(($applicant['address_1'] ?? '') . ' ' . ($applicant['address_2'] ?? ''));
                        $incomingAddress = $applicant['address'] ?? null;
                        $incomingMaritalStatus = $applicant['marital_status'] ?? null;
                        $incomingExperience = $applicant['experience'] ?? null;
                        $incomingJoiningTimeframe = $applicant['joining_timeframe'] ?? null;
                        $incomingBondAgreement = $applicant['bond_agreement'] ?? null;
                        $incomingResumepath = $applicant['resume_path'] ?? null;
                        $incomingBranch = $applicant['branch'] ?? null;
                        $incomingGraduateYear = $applicant['graduate_year'] ?? null;
                        $incomingSkills = isset($applicant['skills']) ? (is_array($applicant['skills']) ? json_encode($applicant['skills']) : (string)$applicant['skills']) : null;
                        $incomingEmployeeCode = $applicant['employee_id'] ?? null;
                        $incomingEmployeeName = $applicant['employee_name'] ?? null;

                        // Check if there are actual differences
                        $incomingValues = [
                            'fullname' => $incomingFullname,
                            'phone' => $applicant['phone'] ?? '',
                            'alternate_phone' => $applicant['alternate_phone'] ?? '',
                            'dob' => $incomingDob,
                            'address' => $incomingAddress,
                            'location' => $location,
                            'marital_status' => $incomingMaritalStatus,
                            'experience' => $incomingExperience,
                            'joining_timeframe' => $incomingJoiningTimeframe,
                            'bond_agreement' => $incomingBondAgreement,
                            'resume_path' => $incomingResumepath,
                            'branch' => $incomingBranch,
                            'graduate_year' => $incomingGraduateYear,
                            'skills' => $incomingSkills,
                            'employee_id' => $incomingEmployeeCode,
                            'employee_name' => $incomingEmployeeName,
                        ];

                        $fieldsToCheck = ['fullname', 'phone', 'alternate_phone', 'dob', 'address', 'location', 'marital_status', 'experience', 'joining_timeframe', 'bond_agreement', 'resume_path', 'branch', 'graduate_year', 'skills', 'employee_id', 'employee_name'];
                        $hasDifference = false;

                        foreach ($fieldsToCheck as $field) {
                            $existingValue = $existing[$field];
                            $incomingValue = $incomingValues[$field];

                            // Normalize null and empty values for comparison
                            $existingValue = ($existingValue === null || $existingValue === '') ? null : $existingValue;
                            $incomingValue = ($incomingValue === null || $incomingValue === '') ? null : $incomingValue;

                            // Special handling for skills (JSON comparison)
                            if ($field === 'skills') {
                                $existingSkills = json_decode($existingValue, true);
                                $incomingSkills = json_decode($incomingValue, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $existingValue = $existingSkills;
                                    $incomingValue = $incomingSkills;
                                }
                                // Compare as arrays if both are arrays
                                if (is_array($existingValue) && is_array($incomingValue)) {
                                    if (count($existingValue) !== count($incomingValue) || array_diff($existingValue, $incomingValue) !== array_diff($incomingValue, $existingValue)) {
                                        $hasDifference = true;
                                        break;
                                    }
                                    continue; // Skip the general comparison
                                }
                            }

                            // General comparison
                            if ($existingValue !== $incomingValue) {
                                $hasDifference = true;
                                break;
                            }
                        }

                        if ($hasDifference) {
                            $duplicateApplicants[] = [
                                'email' => $email,
                                'existing_id' => $existing['id'],
                                'existing_name' => $existing['fullname'],
                                'existing_fullname' => $existing['fullname'],
                                'existing_email' => $existing['email'],
                                'existing_phone' => $existing['phone'],
                                'existing_alternate_phone' => $existing['alternate_phone'],
                                'existing_dob' => $existing['dob'],
                                'existing_marital_status' => $existing['marital_status'],
                                'existing_experience' => $existing['experience'],
                                'existing_location' => $existing['location'],
                                'existing_address' => $existing['address'],
                                'existing_skills' => $existing['skills'],
                                'existing_joining_timeframe' => $existing['joining_timeframe'],
                                'existing_bond_agreement' => $existing['bond_agreement'],
                                'existing_resume_path' => $existing['resume_path'],
                                'existing_branch' => $existing['branch'],
                                'existing_graduate_year' => $existing['graduate_year'],
                                'existing_employee_id' => $existing['employee_id'],
                                'existing_employee_name' => $existing['employee_name'],
                                'new_data' => [
                                    'fullname' => $incomingFullname,
                                    'email' => $applicant['email'],
                                    'phone' => $applicant['phone'] ?? '',
                                    'alternate_phone' => $applicant['alternate_phone'] ?? '',
                                    'dob' => $incomingDob,
                                    'address' => $incomingAddress,
                                    'location' => $location,
                                    'marital_status' => $incomingMaritalStatus,
                                    'experience' => $incomingExperience,
                                    'joining_timeframe' => $incomingJoiningTimeframe,
                                    'bond_agreement' => $incomingBondAgreement,
                                    'resume_path' => $incomingResumepath,
                                    'branch' => $incomingBranch,
                                    'graduate_year' => $incomingGraduateYear,
                                    'skills' => $incomingSkills,
                                    'employee_id' => $incomingEmployeeCode,
                                    'employee_name' => $incomingEmployeeName,
                                ]
                            ];
                        }
                    }
                }
            }
            $updatedApplicants = count($duplicateApplicants);
            if ($insertedApplicants > 0 || $updatedApplicants > 0) {
                $stmt = $conn->prepare("INSERT INTO sync_logs (last_sync) VALUES (NOW())");
                $stmt->execute();
            }

            respond('success', [
                'inserted' => $insertedApplicants,
                'updated' => $updatedApplicants,
                'duplicates' => $duplicateApplicants,
                'duplicate_details' => $duplicateApplicants,
            ]);
        }

        break;


    case 'get_attempts':
        $applicant_id = $_GET['applicant_id'] ?? null;
        if (!$applicant_id) respond('error', ['message' => 'Applicant ID required'], 400);

        $stmt = $conn->prepare('SELECT * FROM applicant_attempts WHERE applicant_id = ? AND is_deleted = 0 ORDER BY applied_date DESC');
        $stmt->bind_param('i', $applicant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attempts = [];
        while ($row = $result->fetch_assoc()) {
            $attempts[] = $row;
        }
        respond('success', $attempts);
        break;

    case 'update_attempt':
        $id = $_POST['id'] ?? null;
        if (!$id) respond('error', ['message' => 'Attempt ID required'], 400);

        $applicant_id = $_POST['applicant_id'] ?? null;
        $applied_date = !empty($_POST['applied_date']) ? $_POST['applied_date'] : null;
        $contacted_date = !empty($_POST['contacted_date']) ? $_POST['contacted_date'] : null;
        $interview_date = !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
        $result = $_POST['result'] ?? 'pending';
        $comments = $_POST['comments'] ?? '';

        $sql = 'UPDATE applicant_attempts SET applied_date = ?, contacted_date = ?, interview_date = ?, result = ?, comments = ? WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) respond('error', ['message' => 'Database prepare failed: ' . $conn->error], 500);

        $stmt->bind_param('sssssi', $applied_date, $contacted_date, $interview_date, $result, $comments, $id);

        if ($stmt->execute()) {
            // Optionally update applicant status if result is a valid stage
            $valid_statuses = ['pending', 'reviewed', 'interviewed', 'hired', 'rejected'];
            if ($applicant_id && !empty($result) && in_array($result, $valid_statuses)) {
                $statusUpdateSql = 'UPDATE applicants SET status = ? WHERE id = ?';
                $statusStmt = $conn->prepare($statusUpdateSql);
                $statusStmt->bind_param('si', $result, $applicant_id);
                $statusStmt->execute();
            }
            respond('success', ['updated' => $stmt->affected_rows]);
        } else {
            respond('error', ['message' => $stmt->error], 400);
        }
        break;

    case 'delete_attempt':
        $id = $_POST['id'] ?? null;
        if (!$id) respond('error', ['message' => 'Attempt ID required'], 400);

        $stmt = $conn->prepare('UPDATE applicant_attempts SET is_deleted = 1 WHERE id = ?');
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            respond('success', ['deleted' => $stmt->affected_rows]);
        } else {
            respond('error', ['message' => $stmt->error], 400);
        }
        break;


    case 'add_attempt':
        $applicant_id = $_POST['applicant_id'] ?? null;
        $applied_date = !empty($_POST['applied_date']) ? $_POST['applied_date'] : null;
        $contacted_date = !empty($_POST['contacted_date']) ? $_POST['contacted_date'] : null;
        $interview_date = !empty($_POST['interview_date']) ? $_POST['interview_date'] : null;
        $result = $_POST['result'] ?? 'pending';
        $comments = $_POST['comments'] ?? '';

        if (!$applicant_id) {
            respond('error', ['message' => 'Applicant ID is required.'], 400);
        }

        $sql = 'INSERT INTO applicant_attempts (applicant_id, applied_date, contacted_date, interview_date, result, comments) VALUES (?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            respond('error', ['message' => 'Database prepare failed: ' . $conn->error], 500);
        }

        $stmt->bind_param('isssss', $applicant_id, $applied_date, $contacted_date, $interview_date, $result, $comments);

        if ($stmt->execute()) {
            // Optionally update applicant status if result is a valid stage
            $valid_statuses = ['pending', 'reviewed', 'interviewed', 'hired', 'rejected'];
            if (!empty($result) && in_array($result, $valid_statuses)) {
                $statusUpdateSql = 'UPDATE applicants SET status = ? WHERE id = ?';
                $statusStmt = $conn->prepare($statusUpdateSql);
                $statusStmt->bind_param('si', $result, $applicant_id);
                $statusStmt->execute();
            }
            respond('success', ['id' => $conn->insert_id]);
        } else {
            respond('error', ['message' => $stmt->error], 400);
        }
        break;


        break;


    case 'get_company_details':
        $applicant_id = $_GET['applicant_id'] ?? null;
        if (!$applicant_id) respond('error', ['message' => 'Applicant ID required'], 400);

        $stmt = $conn->prepare('SELECT * FROM applicant_company_details WHERE applicant_id = ? ORDER BY start_date DESC');
        $stmt->bind_param('i', $applicant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $companies = [];
        while ($row = $result->fetch_assoc()) {
            $companies[] = $row;
        }
        respond('success', $companies);
        break;

    case 'import':
        $rawInput = file_get_contents('php://input');
        $inputData = json_decode($rawInput, true);

        $fieldMapping = [];
        if (isset($_POST['field_mapping'])) {
            $fieldMapping = is_array($_POST['field_mapping']) ? $_POST['field_mapping'] : (json_decode($_POST['field_mapping'], true) ?? []);
        } elseif (isset($inputData['field_mapping'])) {
            $fieldMapping = $inputData['field_mapping'];
        }

        $applicantsToImport = [];

        // Parse file on backend if uploaded
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['file']['tmp_name'];
            $fileName = $_FILES['file']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $rows = [];
            if (in_array($fileExtension, ['csv', 'txt'])) {
                if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                    while (($data = fgetcsv($handle, 10000, ',', '"', '\\')) !== false) {
                        if (!empty(array_filter($data, function ($val) {
                            return trim((string)$val) !== '';
                        }))) {
                            $rows[] = $data;
                        }
                    }
                    fclose($handle);
                }
            } elseif (in_array($fileExtension, ['xlsx', 'xls'])) {
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpPath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $rowData = [];
                        foreach ($row->getCellIterator() as $cell) {
                            $rowData[] = $cell->getValue();
                        }
                        $rows[] = $rowData;
                    }
                } catch (\Exception $e) {
                    respond('error', ['message' => 'Failed to parse Excel file: ' . $e->getMessage()], 400);
                }
            } else {
                respond('error', ['message' => 'Unsupported file format. Please upload a CSV or XLSX file.'], 400);
            }

            if (!empty($rows)) {
                $headerRowIndex = 0;
                while ($headerRowIndex < count($rows) && empty(array_filter($rows[$headerRowIndex], function ($v) {
                    return trim((string)$v) !== '';
                }))) {
                    $headerRowIndex++;
                }

                if ($headerRowIndex < count($rows)) {
                    $headers = array_map(function ($h) {
                        $str = trim((string)$h);
                        return preg_replace('/\x{EF}\x{BB}\x{BF}/', '', $str);
                    }, $rows[$headerRowIndex]);

                    $dataRows = array_slice($rows, $headerRowIndex + 1);

                    foreach ($dataRows as $row) {
                        $appObj = [];
                        foreach ($fieldMapping as $sysKey => $mappedHeader) {
                            if (!empty($mappedHeader)) {
                                $mappedHeaderClean = strtolower(trim((string)$mappedHeader));
                                $colIndex = false;
                                foreach ($headers as $idx => $h) {
                                    if (strtolower(trim((string)$h)) === $mappedHeaderClean) {
                                        $colIndex = $idx;
                                        break;
                                    }
                                }
                                if ($colIndex !== false && isset($row[$colIndex])) {
                                    $val = trim((string)$row[$colIndex]);
                                    if ($val !== '') {
                                        $appObj[$sysKey] = $val;
                                    }
                                }
                            }
                        }

                        // Also capture all original spreadsheet columns by raw header name
                        foreach ($headers as $idx => $h) {
                            if (isset($row[$idx])) {
                                $val = trim((string)$row[$idx]);
                                if ($val !== '') {
                                    $appObj['raw_fields'][$h] = $val;
                                }
                            }
                        }

                        // Ignore summary/total count rows
                        $nameLower = strtolower($appObj['fullname'] ?? '');
                        if (strpos($nameLower, 'total candidates') !== false || strpos($nameLower, 'scheduled interviews') !== false || strpos($nameLower, 'completed interviews') !== false || strpos($nameLower, 'selected candidates') !== false || strpos($nameLower, 'rejected candidates') !== false) {
                            continue;
                        }

                        if (!empty($appObj['fullname']) || !empty($appObj['email']) || !empty($appObj['phone'])) {
                            $applicantsToImport[] = $appObj;
                        }
                    }
                }
            }
        } elseif (isset($_POST['applicants'])) {
            if (is_array($_POST['applicants'])) {
                $applicantsToImport = $_POST['applicants'];
            } else {
                $applicantsToImport = json_decode($_POST['applicants'], true) ?? [];
            }
        } elseif (is_array($inputData) && isset($inputData['applicants'])) {
            $applicantsToImport = $inputData['applicants'];
        } elseif (is_array($inputData)) {
            $applicantsToImport = $inputData;
        }

        if (empty($applicantsToImport) || !is_array($applicantsToImport)) {
            respond('error', ['message' => 'No applicant data provided for import.'], 400);
        }

        $loggedInEmpName = $_POST['logged_in_employee_name'] ?? $inputData['logged_in_employee_name'] ?? null;

        $insertedCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $errors = [];
        $createdInThisBatch = [];
        $updatedInThisBatch = [];

        $stmtInsert = $conn->prepare('INSERT INTO applicants 
            (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, location, note, reject_reason, resume_path, skills, joining_timeframe, bond_agreement, branch, graduate_year, status, source, employee_name) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($applicantsToImport as $index => $app) {
            $fullname = trim($app['fullname'] ?? '');
            $email = !empty($app['email']) ? strtolower(trim($app['email'])) : '';
            $phone = trim($app['phone'] ?? '');

            // Handle comma-separated multiple phone numbers e.g. "8602850930, 9294665629"
            $alternate_phone = !empty($app['alternate_phone']) ? trim($app['alternate_phone']) : null;
            if (!empty($phone) && strpos($phone, ',') !== false) {
                $phoneParts = array_map('trim', explode(',', $phone));
                $phone = $phoneParts[0];
                if (empty($alternate_phone) && isset($phoneParts[1])) {
                    $alternate_phone = $phoneParts[1];
                }
            }

            // Rule: Name is required AND at least one of Email or Phone must be provided
            if (empty($email) && empty($phone)) {
                $errors[] = "Row #" . ($index + 1) . ": At least Email or Phone is required.";
                continue;
            }
            if (empty($fullname)) {
                $fullname = !empty($email) ? strstr($email, '@', true) : $phone;
            }

            $dob = !empty($app['dob']) ? trim($app['dob']) : null;
            $marital_status = !empty($app['marital_status']) ? trim($app['marital_status']) : null;
            $experience = !empty($app['experience']) ? trim($app['experience']) : null;
            $address = !empty($app['address']) ? trim($app['address']) : null;
            $location = !empty($app['location']) ? trim($app['location']) : null;
            $reject_reason = !empty($app['reject_reason']) ? trim($app['reject_reason']) : null;
            $resume_path = !empty($app['resume_path']) ? trim($app['resume_path']) : null;
            $joining_timeframe = !empty($app['joining_timeframe']) ? trim($app['joining_timeframe']) : null;
            $bond_agreement = !empty($app['bond_agreement']) ? trim($app['bond_agreement']) : null;
            $branch = !empty($app['branch']) ? trim($app['branch']) : null;
            $graduate_year = !empty($app['graduate_year']) ? (int)$app['graduate_year'] : (!empty($app['graduation_year']) ? (int)$app['graduation_year'] : null);

            // Normalize status to valid DB ENUM ('pending', 'reviewed', 'interviewed', 'hired', 'rejected')
            $rawStatus = !empty($app['status']) ? trim($app['status']) : (!empty($app['final_status']) ? trim($app['final_status']) : (!empty($app['outcome']) ? trim($app['outcome']) : 'pending'));
            $status = normalizeApplicantStatus($rawStatus);

            $source = !empty($app['source']) ? trim($app['source']) : 'import';
            $employee_name = !empty($app['employee_name']) ? trim($app['employee_name']) : (!empty($loggedInEmpName) ? trim($loggedInEmpName) : null);

            // Construct rich note string with accurate prefixes for all followup, remark, review & outcome fields
            $noteParts = [];

            if (!empty($app['position']) && trim($app['position']) !== '-') {
                $noteParts[] = "Position: " . trim($app['position']);
            }
            if (!empty($app['ctc']) && trim($app['ctc']) !== '-') {
                $noteParts[] = "CTC: " . trim($app['ctc']);
            }
            if (!empty($app['note']) && trim($app['note']) !== '-') {
                $noteParts[] = trim($app['note']);
            }

            $getRawVal = function ($labels) use ($app) {
                if (empty($app['raw_fields']) || !is_array($app['raw_fields'])) return '';
                foreach ($app['raw_fields'] as $h => $v) {
                    $cleanH = strtolower(trim($h));
                    foreach ($labels as $lbl) {
                        if (strtolower(trim($lbl)) === $cleanH) {
                            return trim((string)$v);
                        }
                    }
                }
                return '';
            };

            $f1 = $getRawVal(['follow-up1', 'followup1', 'follow_up1', 'follow up 1']);
            if ($f1 !== '' && $f1 !== '-') {
                $noteParts[] = "Follow-up 1: " . $f1;
            }

            $f2 = $getRawVal(['follow-up2', 'followup2', 'follow_up2', 'follow up 2']);
            if ($f2 !== '' && $f2 !== '-' && !in_array($f2, $noteParts) && !in_array("Follow-up 2: " . $f2, $noteParts)) {
                $noteParts[] = "Follow-up 2: " . $f2;
            }

            $remark = $getRawVal(['remark', 'remarks']);
            if ($remark !== '' && $remark !== '-' && !in_array("Remark: " . $remark, $noteParts) && !in_array($remark, $noteParts)) {
                $noteParts[] = "Remark: " . $remark;
            }

            $aptitude = $getRawVal(['aptitude test marks', 'aptitude_test_marks', 'aptitude marks', 'aptitude_marks']);
            if ($aptitude !== '' && $aptitude !== '-') {
                $noteParts[] = "Aptitude Marks: " . $aptitude;
            }

            $testReview = $getRawVal(['test review', 'test_review']);
            if ($testReview !== '' && $testReview !== '-' && !in_array("Test Review: " . $testReview, $noteParts)) {
                $noteParts[] = "Test Review: " . $testReview;
            }

            $machineTest = $getRawVal(['machine test', 'machine test ', 'machine_test']);
            if ($machineTest !== '' && $machineTest !== '-') {
                $noteParts[] = "Machine Test: " . $machineTest;
            }

            $finalRound = $getRawVal(['final round', 'final_round']);
            if ($finalRound !== '' && $finalRound !== '-') {
                $noteParts[] = "Final Round: " . $finalRound;
            }

            $outcome = $getRawVal(['outcome']);
            if ($outcome !== '' && $outcome !== '-' && strtolower($outcome) !== 'completed' && strtolower($outcome) !== 'done') {
                $noteParts[] = "Outcome: " . $outcome;
            }

            $finalRemark = $getRawVal(['final remark', 'final_remark']);
            if ($finalRemark !== '' && $finalRemark !== '-' && !in_array("Final Remark: " . $finalRemark, $noteParts)) {
                $noteParts[] = "Final Remark: " . $finalRemark;
            }

            $interviewDateVal = !empty($app['interview_date']) ? trim($app['interview_date']) : $getRawVal(['interview date', 'interview_date', 'date interviewed', 'interview schedule date']);
            if ($interviewDateVal !== '' && $interviewDateVal !== '-') {
                $noteParts[] = "Interview Date: " . $interviewDateVal;
            }

            $interviewTimeVal = !empty($app['interview_time']) ? trim($app['interview_time']) : $getRawVal(['interview time', 'interview_time', 'time interviewed', 'interview schedule time', 'time']);
            if ($interviewTimeVal !== '' && $interviewTimeVal !== '-') {
                $noteParts[] = "Interview Time: " . $interviewTimeVal;
            }

            $note = !empty($noteParts) ? implode(" | ", array_unique($noteParts)) : null;

            $skillsVal = $app['skills'] ?? [];
            if (is_array($skillsVal)) {
                $skills = json_encode($skillsVal);
            } elseif (is_string($skillsVal) && !empty($skillsVal)) {
                $skillsArray = array_map('trim', explode(',', $skillsVal));
                $skills = json_encode($skillsArray);
            } else {
                $skills = null;
            }

            // Check if applicant already exists by Email OR Phone
            $existing = null;
            if (!empty($email) || !empty($phone) || !empty($alternate_phone)) {
                if (!empty($email)) {
                    $stmtCheck = $conn->prepare('SELECT * FROM applicants WHERE LOWER(email) = ? LIMIT 1');
                    $stmtCheck->bind_param('s', $email);
                    $stmtCheck->execute();
                    $existing = $stmtCheck->get_result()->fetch_assoc();
                }
                if ((!empty($phone) || !empty($alternate_phone)) && !$existing) {
                    if (!empty($phone)) {
                        $stmtCheck = $conn->prepare('SELECT * FROM applicants WHERE phone = ? OR alternate_phone = ? LIMIT 1');
                        $stmtCheck->bind_param('ss', $phone, $phone);
                        $stmtCheck->execute();
                        $existing = $stmtCheck->get_result()->fetch_assoc();
                    }
                    if (!empty($alternate_phone) && !$existing) {
                        $stmtCheck = $conn->prepare('SELECT * FROM applicants WHERE phone = ? OR alternate_phone = ? LIMIT 1');
                        $stmtCheck->bind_param('ss', $alternate_phone, $alternate_phone);
                        $stmtCheck->execute();
                        $existing = $stmtCheck->get_result()->fetch_assoc();
                    }
                }
            }

            $targetApplicantId = null;

            if ($existing) {
                $targetApplicantId = (int)$existing['id'];
                // Merge: Update only fields that are currently empty in database with incoming non-empty data
                $updates = [];
                $params = [];
                $types = '';

                $incomingFields = [
                    'fullname' => $fullname,
                    'email' => $email,
                    'phone' => $phone,
                    'alternate_phone' => $alternate_phone,
                    'dob' => $dob,
                    'marital_status' => $marital_status,
                    'experience' => $experience,
                    'address' => $address,
                    'location' => $location,
                    'note' => $note,
                    'reject_reason' => $reject_reason,
                    'resume_path' => $resume_path,
                    'skills' => $skills,
                    'joining_timeframe' => $joining_timeframe,
                    'bond_agreement' => $bond_agreement,
                    'branch' => $branch,
                    'graduate_year' => $graduate_year,
                    'source' => $source,
                    'employee_name' => $employee_name,
                ];

                foreach ($incomingFields as $field => $val) {
                    if ($val !== null && $val !== '' && $val !== '[]') {
                        $dbVal = $existing[$field] ?? null;
                        if ($dbVal === null || $dbVal === '' || $dbVal === '[]') {
                            $updates[] = "$field = ?";
                            $params[] = $val;
                            $types .= (($field === 'graduate_year') ? 'i' : 's');
                        }
                    }
                }

                if (!empty($updates)) {
                    $params[] = $existing['id'];
                    $types .= 'i';
                    $sqlUp = "UPDATE applicants SET " . implode(", ", $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                    $stmtUp = $conn->prepare($sqlUp);
                    $stmtUp->bind_param($types, ...$params);
                    if ($stmtUp->execute()) {
                        if (!in_array($targetApplicantId, $createdInThisBatch) && !in_array($targetApplicantId, $updatedInThisBatch)) {
                            $updatedCount++;
                            $updatedInThisBatch[] = $targetApplicantId;
                        }
                    } else {
                        $errors[] = "Row #" . ($index + 1) . " Update Error: " . $stmtUp->error;
                    }
                } else {
                    if (!in_array($targetApplicantId, $createdInThisBatch) && !in_array($targetApplicantId, $updatedInThisBatch)) {
                        $unchangedCount++;
                    }
                }
            } else {
                // Insert new applicant
                $emailVal = !empty($email) ? $email : null;
                $phoneVal = !empty($phone) ? $phone : null;
                $skillsValToInsert = $skills ? $skills : json_encode([]);

                $stmtInsert->bind_param(
                    'sssssssssssssissssss',
                    $fullname,
                    $emailVal,
                    $phoneVal,
                    $alternate_phone,
                    $dob,
                    $marital_status,
                    $experience,
                    $address,
                    $location,
                    $note,
                    $reject_reason,
                    $resume_path,
                    $skillsValToInsert,
                    $joining_timeframe,
                    $bond_agreement,
                    $branch,
                    $graduate_year,
                    $status,
                    $source,
                    $employee_name
                );

                if ($stmtInsert->execute()) {
                    $targetApplicantId = (int)$conn->insert_id;
                    $insertedCount++;
                    $createdInThisBatch[] = $targetApplicantId;
                } else {
                    $errors[] = "Row #" . ($index + 1) . " Insert Error: " . $stmtInsert->error;
                }
            }
        }

        respond('success', [
            'inserted' => $insertedCount,
            'updated' => $updatedCount,
            'errors' => $errors
        ]);
        break;

    default:
        respond('error', ['message' => 'Invalid action'], 400);
}
