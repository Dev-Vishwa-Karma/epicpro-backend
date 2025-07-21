<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    include 'db_connection.php';
    include 'auth_validate.php';

    // Set the header for JSON response
    header('Content-Type: application/json');
    
    // SQL query to get all dapartments
    $sql = "SELECT * FROM salary_details";

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id']) && $_GET['employee_id'] > 0) {
                    // Prepare SELECT statement with WHERE clause
                    $sql .= " WHERE employee_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $_GET['employee_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } else {
                    $result = $conn->query($sql);
                }

                // Fetch and display results
                if ($result) {
                    $salary_details = $result->fetch_all(MYSQLI_ASSOC);
                    echo json_encode([
                        'status' => 'success',
                        'data'   => $salary_details
                    ]);
                } else {
                    echo json_encode(['error' => 'No records found']);
                }
                break;

            case 'add':
                // Validate and get POST data
                $employee_id = '';
                $source = isset($_POST['source']) ? $_POST['source'] : null;
                $amount = isset($_POST['amount']) ? $_POST['amount'] : null;
                $from_date = isset($_POST['from_date']) ? $_POST['from_date'] : null;
                $to_date = isset($_POST['to_date']) ? $_POST['to_date'] : null;
                $created_at = date('Y-m-d H:i:s');
                $updated_at = $created_at;
            
                if ($source && $amount) {
                    // Prepare the SQL insert statement
                    $stmt = $conn->prepare("INSERT INTO salary_details (employee_id, source, amount, from_date, to_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isissss", $employee_id, $source, $amount, $from_date, $to_date, $created_at, $updated_at);
            
                    // Execute the statement and check for success
                    if ($stmt->execute()) {
                        $salaryDetails = [
                            'id' => $stmt->insert_id,
                            'employee_id' => '',
                            'source' => $source,
                            'amount' => $amount,
                            'from_date' => $from_date,
                            'to_date' => $to_date,
                            'created_at' => $created_at,
                            'updated_at' => $updated_at
                        ];

                        echo json_encode(['success' => 'Salary details added successfully', 'salaryDetails' => $salaryDetails]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to add salary details', 'details' => $stmt->error]);
                    }
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing required fields']);
                }
                break;
                

            case 'edit':
                if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id']) && $_GET['employee_id'] > 0) {
                    $id = $_GET['employee_id'];

                    // Validate and get the data from the decoded JSON
                    $source = isset($_POST['source']) ? $_POST['source'] : null;
                    $amount = isset($_POST['amount']) ? $_POST['amount'] : null;
                    $from_date = isset($_POST['from_date']) ? $_POST['from_date'] : null;
                    $to_date = isset($_POST['to_date']) ? $_POST['to_date'] : null;
                    $updated_at = date('Y-m-d H:i:s');

                    if ($department_name && $department_head) {
                        // Prepare the SQL update statement
                        $stmt = $conn->prepare("UPDATE salary_details SET source = ?, amount = ?, from_date = ?, to_date = ?, updated_at = ? WHERE id = ?");
                        $stmt->bind_param("sisssi", $source, $amount, $from_date, $to_date, $updated_at, $employee_id);
            
                        // Execute the statement and check for success
                        if ($stmt->execute()) {
                            $updatedSalaryData = [
                                'id' => $id,
                                'source' => $source,
                                'amount' => $amount,
                                'from_date' => $from_date,
                                'to_date' => $to_date,
                                'updated_at' => $updated_at
                            ];
                            echo json_encode(['success' => 'Salary details updated successfully', 'updatedSalaryData' => $updatedSalaryData]);
                        } else {
                            echo json_encode(['error' => 'Failed to update salary details']);
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => 'Missing required fields']);
                    }
                    exit;
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid salary ID']);
                    exit;
                }
                break;

            case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    // Prepare DELETE statement
                    $stmt = $conn->prepare("DELETE FROM salary_details WHERE id = ?");
                    $stmt->bind_param('i', $_GET['id']);
                    if ($stmt->execute()) {
                        echo json_encode(['success' => 'Record deleted successfully']);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to delete record']);
                    }
                    exit;
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid salary ID']);
                    exit;
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

    // Close the connection
    $conn->close();
?>