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

        $sql = 'INSERT INTO applicants (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, skills, joining_timeframe, bond_agreement, branch, graduate_year, resume_path, status, source, employee_id, employee_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        error_log("SQL Query: " . $sql);
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            respond('error', ['message' => 'Database prepare failed: ' . $conn->error], 500);
        }
        
        $bindResult = $stmt->bind_param('ssssssssssssisssss', $fullname, $email, $phone, $alternate_phone, $dob, $marital_status, $experience, $address, $skills, $joining_timeframe, $bond_agreement, $branch, $graduate_year, $resume_path, $status, $source, $employee_id, $employee_name);

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

    case 'update':
        $id = $_POST['id'] ?? null;
        $emailForUpdate = $_POST['email'] ?? null;
        if (!$id && !$emailForUpdate) respond('error', ['message' => 'ID or Email required'], 400);

        $fields = ['fullname', 'email', 'phone', 'alternate_phone', 'dob', 'marital_status', 'experience', 'address', 'skills', 'joining_timeframe', 'bond_agreement', 'branch', 'graduate_year', 'reject_reason', 'status', 'employee_id', 'employee_name'];
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
                $params[] = $value;
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

                $statusUpdate = getStatusUpdateEmail($applicant, $_POST['status']);
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
                            $fileContent = file_get_contents($resume_path);
                            if ($fileContent !== false) {
                                $resumeDir = 'uploads/resumes/';
                                if (!file_exists($resumeDir)) {
                                    mkdir($resumeDir, 0777, true); // Make sure directory exists
                                }

                                // Extract the file extension from the URL or path
                                $fileInfo = pathinfo($resume_path);
                                $fileExtension = strtolower($fileInfo['extension']);
                                
                                // Ensure it's a valid file extension (pdf, docx, txt, etc.)
                                // $validExtensions = ['pdf','doc','docx', 'txt', 'rtf', 'odt'];
                                // if (!in_array($fileExtension, $validExtensions)) {
                                //     $fileExtension = 'pdf'; // Default to PDF if the extension is invalid
                                // }

                                // Generate a unique file name and save the file with the correct extension
                                $fileName = uniqid('resume_', true) . '.' . $fileExtension;
                                $filePath = $resumeDir . $fileName;

                                // Save the resume file
                                file_put_contents($filePath, $fileContent);

                                // Update resume path to the new location
                                $resume_path = $filePath;
                            } else {
                                error_log("Failed to download resume: " . $resume_path);
                            }
                        }

                        $stmtInsert = $conn->prepare('INSERT INTO applicants 
                            (fullname, email, phone, alternate_phone, dob, marital_status, experience, address, skills, joining_timeframe, bond_agreement, resume_path, branch, graduate_year, status, source) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmtInsert->bind_param(
                            'ssssssssssssssss',
                            $fullname, $email, $phone, $alternate_phone, $dob, $marital_status, $experience,
                            $address, $skills, $joining_timeframe, $bond_agreement, $resume_path, $branch, $graduate_year, $status, $source_sync
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

                        $fieldsToCheck = ['fullname', 'phone', 'alternate_phone', 'dob', 'address', 'marital_status', 'experience', 'joining_timeframe', 'bond_agreement', 'resume_path', 'branch', 'graduate_year', 'skills', 'employee_id', 'employee_name'];
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
            if ($insertedApplicants > 0 || $updatedApplicants > 0 ) {
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


    default:
        respond('error', ['message' => 'Invalid action'], 400);
}