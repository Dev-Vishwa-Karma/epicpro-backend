<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

// Include the database connection
include 'db_connection.php';

// Set the header for JSON response
header('Content-Type: application/json');

// Helper function to send JSON response
function sendJsonResponse($status, $data = null, $message = null)
{
    header('Content-Type: application/json');
    if ($status === 'success') {
        echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
    exit;
}

// Helper function to validate user ID
function validateId($id)
{
    return isset($id) && is_numeric($id) && $id > 0;
}

$action = !empty($_GET['action']) ? $_GET['action'] : 'view';
$status = !empty($_GET['status']) ? $_GET['status'] : null;

// File upload helper function
function uploadFile($file, $targetDir, $allowedTypes = [], $maxSize = 2 * 1024 * 1024)
{
    if ($file['error'] === UPLOAD_ERR_OK) {
        $fileType = mime_content_type($file['tmp_name']);
        error_log("Detected MIME Type: " . $fileType);
        $fileSize = $file['size'];

        // Validate file type
        if (!empty($allowedTypes) && !in_array($fileType, $allowedTypes)) {
            sendJsonResponse('error', null, "Invalid file type: $fileType");
        }

        // Validate file size
        if ($fileSize > $maxSize) {
            throw new Exception("File size exceeds the maximum allowed size of $maxSize bytes");
        }

        $originalFileName = $file['name'];
        $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
        
        if (!$extension) {
            $extension = 'pdf'; // Set default extension if missing
        }

        // Generate a unique file name
        $uniqueFileName = uniqid() . '-' . basename($file['name']);

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $uniqueFileName;

        // Ensure the target directory exists and set permissions
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
            chmod($targetDir, 0777);
        }

        // Move the file to the target directory
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return $targetPath;
        } else {
            throw new Exception("Failed to move uploaded file.");
        }
    } else {
        throw new Exception("File upload error: " . $file['error']);
    }
}

// Main action handler
if (isset($action)) {
    switch ($action) {
        case 'view':
            if (isset($_GET['user_id']) && validateId($_GET['user_id'])) {
                // Prepare SELECT statement with WHERE clause using a placeholder to prevent SQL injection
                $stmt = $conn->prepare("
                    SELECT e.*, 
                        d.department_name, 
                        d.department_head 
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.id
                    WHERE e.id = ? AND e.deleted_at IS NULL
                ");
                $stmt->bind_param('i', $_GET['user_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $employee = $result->fetch_assoc();
                    sendJsonResponse('success', $employee);
                } else {
                    sendJsonResponse('error', null, 'Employee not found');
                }
            } else {
                // Check if the role filter is passed via URL, e.g., role=employee or role=all
                $roleFilter = isset($_GET['role']) ? $_GET['role'] : 'all';
                if ($roleFilter == 'employee') {
                    $status = !empty($status) ? 'AND status = '. $status : '';
                    // If 'employee' role filter is passed, show only employees with role 'employee'
                    $stmt = $conn->prepare("
                        SELECT e.*, 
                            d.department_name, 
                            d.department_head 
                        FROM employees e
                        LEFT JOIN departments d ON e.department_id = d.id
                        WHERE e.role = 'employee' AND e.deleted_at IS NULL " .$status." 
                        ORDER BY 
                            CASE 
    							WHEN visibility_priority = 0 THEN 1 ELSE 0 
  								END,
  								visibility_priority ASC,
                                first_name ASC
                    ");
                } else if ($roleFilter == 'admin') {
                    $stmt = $conn->prepare("
                        SELECT e.*, 
                            d.department_name, 
                            d.department_head 
                        FROM employees e
                        LEFT JOIN departments d ON e.department_id = d.id
                        WHERE (e.role = 'admin' OR e.role = 'super_admin') 
                        AND e.deleted_at IS NULL
                        ORDER BY e.id DESC
                    ");
                } else {
                    // If no filter or 'all', show all employees
                    $stmt = $conn->prepare("
                        SELECT e.*, 
                            d.department_name, 
                            d.department_head 
                        FROM employees e
                        LEFT JOIN departments d ON e.department_id = d.id
                        WHERE e.deleted_at IS NULL
                        ORDER BY e.id DESC
                    ");
                }

                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $users = $result->fetch_all(MYSQLI_ASSOC);
                    sendJsonResponse('success', $users);
                } else {
                    sendJsonResponse('error', null, 'No users found');
                }
            }
            break;

        case 'add':
            // Capture and sanitize POST data
            $logged_in_user_id = $_POST['logged_in_employee_id'] ?? "";
            $logged_in_user_role = $_POST['logged_in_employee_role'] ?? "";

            // Capture and sanitize POST data
            $data = [
                'code' => $_POST['code'] ?? "",
                'department_id' => $_POST['department_id'] ?? "",
                'first_name' => $_POST['first_name'] ?? "",
                'last_name' => $_POST['last_name'] ?? "",
                'email' => $_POST['email'] ?? "",
                'role' => $_POST['selected_role'] ?? "",
                'profile' => $_FILES['photo']['name'] ?? "",
                'dob' => $_POST['dob'] ?? "",
                'gender' => $_POST['gender'] ?? "",
                'password' => $_POST['password'] ?? "",
                'joining_date' => $_POST['joining_date'] ?? "",
                'mobile_no1' => $_POST['mobile_no1'] ?? "",
                'mobile_no2' => $_POST['mobile_no2'] ?? "",
                'address_line1' => $_POST['address_line1'] ?? "",
                'address_line2' => $_POST['address_line2'] ?? "",
                'emergency_contact1' => $_POST['emergency_contact1'] ?? "",
                'emergency_contact2' => $_POST['emergency_contact2'] ?? "",
                'emergency_contact3' => $_POST['emergency_contact3'] ?? "",
                'frontend_skills' => $_POST['frontend_skills'] ?? "",
                'backend_skills' => $_POST['backend_skills'] ?? "",
                'account_holder_name' => $_POST['account_holder_name'] ?? "",
                'account_number' => $_POST['account_number'] ?? "",
                'ifsc_code' => $_POST['ifsc_code'] ?? "",
                'bank_name' => $_POST['bank_name'] ?? "",
                'bank_address' => $_POST['bank_address'] ?? "",
                'aadhar_card_number' => $_POST['aadhar_card_number'] ?? "",
                'aadhar_card_file' => $_FILES['aadhar_card_file']['name'] ?? "",
                'pan_card_number' => $_POST['pan_card_number'] ?? "",
                'pan_card_file' => $_FILES['pan_card_file']['name'] ?? "",
                'driving_license_number' => $_POST['driving_license_number'] ?? "",
                'driving_license_file' => $_FILES['driving_license_file']['name'] ?? "",
                'facebook_url' => $_POST['facebook_url'] ?? "",
                'twitter_url' => $_POST['twitter_url'] ?? "",
                'linkedin_url' => $_POST['linkedin_url'] ?? "",
                'instagram_url' => $_POST['instagram_url'] ?? "",
                'upwork_profile_url' => $_POST['upwork_profile_url'] ?? "",
                'resume' => $_FILES['resume']['name'] ?? "",
                'visibility_priority' => $_POST['visibility_priority'] ?? 0,
                'status' => $_POST['status'] ?? 1,
            ];

            // Upload profile image
            $profileImage = $_FILES['photo'] ?? "";
            if ($profileImage) {
                try {
                    // Upload to profile folder
                    $profilePath = uploadFile($profileImage, 'uploads/profiles', ['image/jpeg', 'image/png', 'image/webp']);
                    
                    if ($profilePath) {
                        $data['profile'] = $profilePath;
            
                        // Generate the same file name for the gallery
                        $galleryPath = str_replace('profiles', 'gallery', $profilePath);
            
                        // Copy the file to the gallery folder
                        if (!copy($profilePath, $galleryPath)) {
                            throw new Exception("Failed to copy image to gallery folder.");
                        }
                    }
                } catch (Exception $e) {
                    sendJsonResponse('error', null, $e->getMessage());
                    exit;
                }
            }

            // Upload Aadhaar card
            $aadharCardFile = $_FILES['aadhar_card_file'] ?? "";
            if ($aadharCardFile) {
                $data['aadhar_card_file'] = uploadFile($aadharCardFile, 'uploads/documents/aadhar', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp']);
            }

            // Upload PAN card
            $panCardFile = $_FILES['pan_card_file'] ?? "";
            if ($panCardFile) {
                $data['pan_card_file'] = uploadFile($panCardFile, 'uploads/documents/pan', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp']);
            }

            // Upload driving license
            $drivingLicenseFile = $_FILES['driving_license_file'] ?? "";
            if ($drivingLicenseFile) {
                $data['driving_license_file'] = uploadFile($drivingLicenseFile, 'uploads/documents/driving_license', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp']);
            }

            // Upload resume
            $resumeFile = $_FILES['resume'] ?? "";
            if ($resumeFile) {
                $data['resume'] = uploadFile($resumeFile, 'uploads/documents/resumes', ['application/pdf', 'application/msword', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/webp']);
            }

            if (in_array(strtolower($logged_in_user_role), ['admin', 'super_admin'])) {
                $created_by = $logged_in_user_id;
            }

            // Get the previous employee id and generate the new employee id
            if ($data['code'] != null) {
                $next_user_code = $data['code']; // Use the value from $data['code']
            } else {
                // Proceed with the existing code to generate the next employee code
                $stmt = $conn->prepare("SELECT code FROM employees ORDER BY id DESC LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                if ($row) {
                    $previous_employee_id = $row['code'];
                    // Extract numeric part of the employee_id
                    $employee_number = (int)substr($previous_employee_id, 3);
                    $next_employee_id_number = $employee_number + 1;
                    $next_user_code = "EMP" . str_pad($next_employee_id_number, 3, "0", STR_PAD_LEFT);
                } else {
                    $next_user_code = "EMP001";
                }
            }

            $role = !empty($data['role']) ? $data['role'] : 'employee';

            // Insert into employees table
            $stmt = $conn->prepare(
                "INSERT INTO employees 
                (department_id, code, first_name, last_name, email, role, profile, dob, gender, password, joining_date, mobile_no1, mobile_no2, address_line1, address_line2, 
                emergency_contact1, emergency_contact2, emergency_contact3, frontend_skills, backend_skills, account_holder_name, account_number, ifsc_code, bank_name, bank_address,
                aadhar_card_number, aadhar_card_file, pan_card_number, pan_card_file, driving_license_number, driving_license_file, facebook_url, twitter_url, linkedin_url, instagram_url, upwork_profile_url, resume, visibility_priority, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            // Bind parameters dynamically (use an array to store the data)
            $stmt->bind_param(
                'issssssssssssssssssssssssssssssssssssiis',
                $data['department_id'],
                $next_user_code,
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $role,
                $data['profile'],
                $data['dob'],
                $data['gender'],
                md5($data['password']),
                $data['joining_date'],
                $data['mobile_no1'],
                $data['mobile_no2'],
                $data['address_line1'],
                $data['address_line2'],
                $data['emergency_contact1'],
                $data['emergency_contact2'],
                $data['emergency_contact3'],
                $data['frontend_skills'],
                $data['backend_skills'],
                $data['account_holder_name'],
                $data['account_number'],
                $data['ifsc_code'],
                $data['bank_name'],
                $data['bank_address'],
                $data['aadhar_card_number'],
                $data['aadhar_card_file'],
                $data['pan_card_number'],
                $data['pan_card_file'],
                $data['driving_license_number'],
                $data['driving_license_file'],
                $data['facebook_url'],
                $data['twitter_url'],
                $data['linkedin_url'],
                $data['instagram_url'],
                $data['upwork_profile_url'],
                $data['resume'],
                $data['visibility_priority'],
                $data['status'],
                $created_by
            );

            if ($stmt->execute()) {
                // Fix: Get the last inserted employee ID
                $employee_id = $conn->insert_id;

                $salaryDetails = $_POST['salaryDetails'] ?? [];

                // Check if salary details are not empty.
                if (!empty($salaryDetails)) {
                    // Insert salary details into the salary_details table
                    $salary_stmt = $conn->prepare(
                        "INSERT INTO salary_details (employee_id, source, amount, from_date, to_date) 
                        VALUES (?, ?, ?, ?, ?)"
                    );

                    foreach ($salaryDetails as $detail) {
                        // Trim and validate fields to ensure there is no empty data
                        $source = isset($detail['source']) ? trim($detail['source']) : '';
                        $amount = isset($detail['amount']) && is_numeric($detail['amount']) && $detail['amount'] !== '' ? (int)$detail['amount'] : '';
                        $from_date = isset($detail['from_date']) && !empty($detail['from_date']) ? $detail['from_date'] : '';
                        $to_date = isset($detail['to_date']) && !empty($detail['to_date']) ? $detail['to_date'] : '';

                        // Skip the insertion if any of the required fields are empty or invalid
                        if (empty($source) || $amount === '' || $from_date === '' || $to_date === '') {
                            continue;
                        }

                        // Bind the parameters for each salary entry
                        $salary_stmt->bind_param(
                            'issss',
                            $employee_id,
                            $detail['source'],
                            $amount,
                            $from_date,
                            $to_date
                        );

                        // Execute the insert for each salary detail
                        if (!$salary_stmt->execute()) {
                            $salary_error = $salary_stmt->error;
                            sendJsonResponse('error', null, "Failed to add salary detail: $salary_error");
                            exit; // Exit if any insert fails
                        }
                    }
                }

                // Fetch department details based on department_id
                $dept_stmt = $conn->prepare("SELECT department_name, department_head FROM departments WHERE id = ?");
                $dept_stmt->bind_param("i", $data['department_id']);
                $dept_stmt->execute();
                $dept_result = $dept_stmt->get_result();
                $department = $dept_result->fetch_assoc();

                // If department exists, get its details
                $department_name = $department['department_name'] ?? '';
                $department_head = $department['department_head'] ?? '';

                $created_at = date('Y-m-d H:i:s');
                
                // Insert profile image into gallery if uploaded
                if (!empty($data['profile'])) {
                    $gallery_stmt = $conn->prepare(
                        "INSERT INTO gallery (employee_id, url, created_at, created_by) VALUES (?, ?, ?, ?)"
                    );

                    $gallery_stmt->bind_param('issi', $employee_id, $galleryPath, $created_at, $created_by);
                    
                    if (!$gallery_stmt->execute()) {
                        $gallery_error = $gallery_stmt->error;
                        sendJsonResponse('error', null, "Failed to add profile image to gallery: $gallery_error");
                        exit;
                    }
                }

                if (!empty($data['dob'])) {
                    $event_name = "Birthday of " . $data['first_name'] . " " . $data['last_name'];
                    $event_date = $data['dob'];
                    $event_type = 'event';
                    $created_at = date('Y-m-d H:i:s');

                    $event_stmt = $conn->prepare("INSERT INTO events (employee_id, event_type, event_date, event_name, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $event_stmt->bind_param("issssi", $employee_id, $event_type, $event_date, $event_name, $created_at, $created_by);
                    $event_stmt->execute();
                }

                sendJsonResponse('success', [
                    'id' => $employee_id,
                    'department_id' => $data['department_id'],
                    'department_name' => $department_name,
                    'department_head' => $department_head,
                    'code' => $data['code'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'role' => $role,
                    'created_by' => $created_by,
                    'created_at' => $created_at
                ], 'Employee and salary details added successfully');
            } else {
                $error = $stmt->error;
                sendJsonResponse('error', null, "Failed to add employee details: $error");
            }
            break;

        // Edit existing user case
        case 'edit':
            if (isset($_GET['user_id']) && validateId($_GET['user_id'])) {
                $id = $_GET['user_id'];

                // Ensure logged-in user data is provided in the API request
                if (empty($_POST['logged_in_employee_id']) || empty($_POST['logged_in_employee_role'])) {
                    sendJsonResponse('error', null, "Missing logged-in user details");
                    exit;
                }

                $logged_in_user_id = $_POST['logged_in_employee_id'];
                $logged_in_role = $_POST['logged_in_employee_role'];

                // Initialize data array
                $data = [];

                // Capture and sanitize POST data for each field conditionally
                if (isset($_POST['department_id'])) {
                    $data['department_id'] = $_POST['department_id'];
                }
                if (isset($_POST['first_name'])) {
                    $data['first_name'] = $_POST['first_name'];
                }
                if (isset($_POST['last_name'])) {
                    $data['last_name'] = $_POST['last_name'];
                }
                if (isset($_POST['email'])) {
                    $data['email'] = $_POST['email'];
                }
                if (isset($_POST['selected_role'])) {
                    $data['role'] = $_POST['selected_role'];
                }
                if (isset($_POST['about_me'])) {
                    $data['about_me'] = $_POST['about_me'];
                }
                if (isset($_POST['gender'])) {
                    $data['gender'] = $_POST['gender'];
                }
                if (isset($_POST['dob'])) {
                    $data['dob'] = $_POST['dob'];
                }
                if (isset($_POST['joining_date'])) {
                    $data['joining_date'] = $_POST['joining_date'];
                }
                if (isset($_POST['job_role'])) {
                    $data['job_role'] = $_POST['job_role'];
                }
                if (isset($_POST['mobile_no1'])) {
                    $data['mobile_no1'] = $_POST['mobile_no1'];
                }
                if (isset($_POST['mobile_no2'])) {
                    $data['mobile_no2'] = $_POST['mobile_no2'];
                }
                if (isset($_POST['address_line1'])) {
                    $data['address_line1'] = $_POST['address_line1'];
                }
                if (isset($_POST['address_line2'])) {
                    $data['address_line2'] = $_POST['address_line2'];
                }
                if (isset($_POST['emergency_contact1'])) {
                    $data['emergency_contact1'] = $_POST['emergency_contact1'];
                }
                if (isset($_POST['emergency_contact2'])) {
                    $data['emergency_contact2'] = $_POST['emergency_contact2'];
                }
                if (isset($_POST['emergency_contact3'])) {
                    $data['emergency_contact3'] = $_POST['emergency_contact3'];
                }
                if (isset($_POST['frontend_skills'])) {
                    $data['frontend_skills'] = $_POST['frontend_skills'];
                }
                if (isset($_POST['backend_skills'])) {
                    $data['backend_skills'] = $_POST['backend_skills'];
                }
                if (isset($_POST['account_holder_name'])) {
                    $data['account_holder_name'] = $_POST['account_holder_name'];
                }
                if (isset($_POST['account_number'])) {
                    $data['account_number'] = $_POST['account_number'];
                }
                if (isset($_POST['ifsc_code'])) {
                    $data['ifsc_code'] = $_POST['ifsc_code'];
                }
                if (isset($_POST['bank_name'])) {
                    $data['bank_name'] = $_POST['bank_name'];
                }
                if (isset($_POST['bank_address'])) {
                    $data['bank_address'] = $_POST['bank_address'];
                }
                if (isset($_POST['aadhar_card_number'])) {
                    $data['aadhar_card_number'] = $_POST['aadhar_card_number'];
                }
                if (isset($_POST['driving_license_number'])) {
                    $data['driving_license_number'] = $_POST['driving_license_number'];
                }
                if (isset($_POST['pan_card_number'])) {
                    $data['pan_card_number'] = $_POST['pan_card_number'];
                }
                if (isset($_POST['facebook_url'])) {
                    $data['facebook_url'] = $_POST['facebook_url'];
                }
                if (isset($_POST['twitter_url'])) {
                    $data['twitter_url'] = $_POST['twitter_url'];
                }
                if (isset($_POST['linkedin_url'])) {
                    $data['linkedin_url'] = $_POST['linkedin_url'];
                }
                if (isset($_POST['instagram_url'])) {
                    $data['instagram_url'] = $_POST['instagram_url'];
                }
                if (isset($_POST['upwork_profile_url'])) {
                    $data['upwork_profile_url'] = $_POST['upwork_profile_url'];
                }
                if (isset($_POST['status'])) {
                    $data['status'] = $_POST['status'];
                }
                if (isset($_POST['visibility_priority'])) {
                    $data['visibility_priority'] = $_POST['visibility_priority'];
                }

                // File uploads: handle files only if they are present
                // Upload profile image
                $profileImage = $_FILES['photo'];
                if ($profileImage) {
                    try {
                        // Upload to profile folder
                        $profilePath = uploadFile($profileImage, 'uploads/profiles', ['image/jpeg', 'image/png', 'image/webp']);
                        
                        if ($profilePath) {
                            $data['profile'] = $profilePath;
                
                            // Generate the same file name for the gallery
                            $galleryPath = str_replace('profiles', 'gallery', $profilePath);
                
                            // Copy the file to the gallery folder
                            if (!copy($profilePath, $galleryPath)) {
                                throw new Exception("Failed to copy image to gallery folder.");
                            }
                        }
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, $e->getMessage());
                        exit;
                    }
                }

                // Upload Aadhaar card
                $aadharCardFile = $_FILES['aadhar_card_file'];
                if ($aadharCardFile) {
                    $data['aadhar_card_file'] = uploadFile($aadharCardFile, 'uploads/documents/aadhar', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream']);
                }

                // Upload PAN card
                $panCardFile = $_FILES['pan_card_file'];
                if ($panCardFile) {
                    $data['pan_card_file'] = uploadFile($panCardFile, 'uploads/documents/pan', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp']);
                }

                // Upload driving license
                $drivingLicenseFile = $_FILES['driving_license_file'];
                if ($drivingLicenseFile) {
                    $data['driving_license_file'] = uploadFile($drivingLicenseFile, 'uploads/documents/driving_license', ['application/pdf', 'application/msword', 'text/plain', 'image/jpeg', 'image/png', 'image/webp']);
                }

                // Upload resume
                $resumeFile = $_FILES['resume'];
                if ($resumeFile) {
                    $data['resume'] = uploadFile($resumeFile, 'uploads/documents/resumes', ['application/pdf', 'application/msword', 'text/plain', 'application/octet-stream', 'image/jpeg', 'image/png', 'image/webp']);
                }

                // Check if admin or super_admin is updating another user's profile
                if ($logged_in_user_id != $id && ($logged_in_role === 'admin' || $logged_in_role === 'super_admin')) {
                    $data['updated_by'] = $logged_in_user_id; // Store admin/super_admin ID
                }

                $data['updated_at'] = date('Y-m-d H:i:s');

                $updateColumns = [];
                $updateValues = [];
                foreach ($data as $column => $value) {
                    $updateColumns[] = "$column = '" . $conn->real_escape_string($value) . "'";
                }
                // SQL query
                $sql = "UPDATE employees SET " . implode(', ', $updateColumns) . " WHERE id = $id";

                if ($conn->query($sql)) {
                    // Insert new profile image into the gallery
                    if (!empty($data['profile'])) {
                        $created_at = date('Y-m-d H:i:s');
                        $gallerySql = "INSERT INTO gallery (employee_id, url, created_at, created_by) VALUES ($id, '$galleryPath', '$created_at', {$data['updated_by']})";
                        $conn->query($gallerySql);
                    }

                    $salaryDetails = $_POST['salaryDetails'] ?? [];

                    if (!empty($salaryDetails)) {
                        foreach ($salaryDetails as $detail) {
                            if (!isset($detail['id'])) {
                                sendJsonResponse('error', null, "Salary detail ID is missing.");
                                exit;
                            }

                            $source = isset($detail['source']) ? trim($detail['source']) : '';
                            $amount = isset($detail['amount']) && is_numeric($detail['amount']) ? (int)$detail['amount'] : null;
                            $from_date = isset($detail['from_date']) ? $detail['from_date'] : null;
                            $to_date = isset($detail['to_date']) ? $detail['to_date'] : null;

                            // Skip if any field is invalid
                            if (empty($source) || $amount === null || empty($from_date) || empty($to_date)) {
                                continue;
                            }

                            $updated_at = date('Y-m-d H:i:s');
                            $salarySql = "UPDATE salary_details SET source = '$source', amount = $amount, from_date = '$from_date', to_date = '$to_date', updated_at = '$updated_at' WHERE employee_id = $id AND id = {$detail['id']}";
                            $conn->query($salarySql);
                        }
                    }

                    // Fetch department details based on department_id
                    if (!empty($data['department_id'])) {
                        $deptSql = "SELECT department_name, department_head FROM departments WHERE id = {$data['department_id']}";
                        $deptResult = $conn->query($deptSql);
                        $department = $deptResult->fetch_assoc();

                        $department_name = $department['department_name'] ?? null;
                        $department_head = $department['department_head'] ?? null;
                    }

                    // Update event related to employee birthday
                    if (isset($data['dob']) && !empty($data['dob'])) {
                        $event_name = "Birthday of " . $data['first_name'] . " " . $data['last_name'];
                        $event_type = 'event';
                        $event_date = $data['dob'];
                        $updated_at = date('Y-m-d H:i:s');

                        $eventCheckSql = "SELECT id FROM events WHERE employee_id = $logged_in_user_id";
                        $eventResult = $conn->query($eventCheckSql);

                        if ($eventResult->num_rows > 0) {
                            $event_row = $eventResult->fetch_assoc();
                            $event_id = $event_row['id'];
                            
                            $updateEventSql = "UPDATE events SET event_name = '$event_name', event_date = '$event_date', event_type = '$event_type', updated_at = '$updated_at', updated_by = {$data['updated_by']} WHERE employee_id = $logged_in_user_id";
                            $conn->query($updateEventSql);
                        } else {
                            $created_at = date('Y-m-d H:i:s');
                            $insertEventSql = "INSERT INTO events (employee_id, event_type, event_date, event_name, created_at, created_by) VALUES ($logged_in_user_id, '$event_type', '$event_date', '$event_name', '$created_at', {$data['updated_by']})";
                            $conn->query($insertEventSql);
                        }
                    }

                    $updatedData = [
                        'id' => $id,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'profile' => $data['profile'],
                        'email' => $data['email'],
                        'dob' => $data['dob'],
                        'address_line1' => $data['address_line1'],
                        'role' => $data['role'] ?? $logged_in_role,
                        'mobile_no1' => $data['mobile_no1'],
                        'about_me' => $data['about_me'],
                        'joining_date' => $data['joining_date'],
                        'job_role' => $data['job_role'],
                        'facebook_url' => $data['facebook_url'],
                        'twitter_url' => $data['twitter_url'],
                        'department_name' => $department_name,
                        'department_head' => $department_head,
                    ];

                    sendJsonResponse('success', $updatedData, "Employee and salary details updated successfully");
                } else {
                    $error = $conn->error;
                    sendJsonResponse('error', null, "Failed to update employee details: $error");
                }
            } else {
                sendJsonResponse('error', null, 'Invalid user ID');
            }
            break;


            case 'delete':
                // Get request body
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
            
                if (isset($data['user_id']) && validateId($data['user_id'])) {
                    $id = $data['user_id'];
                    $deleted_by = null;
            
                    // Check if logged-in user ID and role are provided
                    if (isset($data['logged_in_employee_id']) && isset($data['logged_in_employee_role'])) {
                        $logged_in_user_id = $data['logged_in_employee_id'];
                        $logged_in_user_role = strtolower($data['logged_in_employee_role']);
            
                        // Allow only admin and super admin to set deleted_by
                        if ($logged_in_user_role === 'admin' || $logged_in_user_role === 'super_admin') {
                            $deleted_by = $logged_in_user_id;
                        }
                    }
            
                    // Prepare the SQL query based on role condition
                    if ($deleted_by) {
                        $stmt = $conn->prepare("UPDATE employees SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                        $stmt->bind_param('ii', $deleted_by, $id);
                    } else {
                        $stmt = $conn->prepare("UPDATE employees SET deleted_at = NOW() WHERE id = ?");
                        $stmt->bind_param('i', $id);
                    }
            
                    if ($stmt->execute()) {
                        // Soft delete from salary_details table
                        $stmt = $conn->prepare("UPDATE salary_details SET deleted_at = NOW() WHERE employee_id = ?");
                        $stmt->bind_param('i', $id);
                        if ($stmt->execute()) {
                            sendJsonResponse('success', null, 'Employee and salary details deleted successfully');
                        } else {
                            $error = $stmt->error;
                            sendJsonResponse('error', null, "Failed to delete salary details: $error");
                        }
                    } else {
                        sendJsonResponse('error', null, 'Failed to delete employee details');
                    }
                } else {
                    sendJsonResponse('error', null, 'Invalid user ID');
                }
                break;            

        case 'check-login':
            $email = $_POST['email'] ?? null;
            $password = $_POST['password'] ?? null;

            /** Validate */
            if (!$email) {
                sendJsonResponse('error', null, 'Email is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                sendJsonResponse('error', null, 'Please enter a valid email address');
            }
            if (!$password) {
                sendJsonResponse('error', null, 'Password is required');
            }

            $password = md5($password);
            $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, status FROM employees WHERE email = ? AND password = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->bind_param("ss", $email, $password);
            $stmt->execute();
            $result = $stmt->get_result();

            /** Validate email */
            if ($result->num_rows == 0) {
                sendJsonResponse('error', null, 'Please enter a valid registered email address and password.');
            } else {
                $result = $result->fetch_assoc();
                if ($result['status'] === 0) {
                    sendJsonResponse('error', null, 'Your account is deactivated. Please contact the administrator.');
                }

                sendJsonResponse('success', $result, 'Login successful!');
            }

            break;

        default:
            sendJsonResponse('error', null, 'Invalid action');
            break;
    }
} else {
    sendJsonResponse('error', null, 'Action parameter is missing');
}
