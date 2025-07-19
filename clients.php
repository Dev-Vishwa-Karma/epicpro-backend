<?php
ini_set('display_errors', '1');
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
    
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Set the header for JSON response
    header('Content-Type: application/json');

    include 'db_connection.php';
    include 'auth_validate.php';
    require_once 'helpers.php';

    $action = !empty($_GET['action']) ? $_GET['action'] : 'view';

    if (isset($action)) {
        switch ($action) {
            case 'view':
                if (isset($_GET['client_id']) && is_numeric($_GET['client_id']) && $_GET['client_id'] > 0) {
                    $client_id = (int) $_GET['client_id'];

                    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
                    $stmt->bind_param("i", $client_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result) {
                            $clients = $result->fetch_all(MYSQLI_ASSOC);
                            sendJsonResponse('success', $clients, null);
                        } else {
                            sendJsonResponse('error', null, "Clients not found : $conn->error");
                        }
                    } else {
                        sendJsonResponse('error', null, "Failed to execute query : $stmt->error");
                    }
                } else {
                    $result = $conn->query("SELECT * FROM clients");
                    if ($result) {
                        $clients = $result->fetch_all(MYSQLI_ASSOC);
                        sendJsonResponse('success', $clients);
                    } else {
                        sendJsonResponse('error', null, "No records found $conn->error");
                    }
                }
                break;

            case 'view_client':
                $conditions = [];

                if (isset($_GET['client_id']) && is_numeric($_GET['client_id']) && $_GET['client_id'] > 0) {
                    $client_id = intval($_GET['client_id']);
                    $conditions[] = "c.id = $client_id";
                }

                if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
                    $search = $conn->real_escape_string(trim($_GET['search']));
                    $conditions[] = "c.name LIKE '%$search%'";
                }

                // Combine conditions
                $client_condition = "";
                if (!empty($conditions)) {
                    $client_condition = "WHERE " . implode(" AND ", $conditions);
                }

                $query = "
                    SELECT 
                        c.id AS client_id,
                        c.name AS client_name,
                        c.email AS client_email,
                        c.profile AS client_profile,
                        c.country AS client_country,
                        c.state AS client_state,
                        c.city AS client_city,
                        c.status AS client_status,
                        c.about AS client_about,
                        COUNT(p.id) AS project_count
                    FROM clients c
                    LEFT JOIN projects p ON c.id = p.client_id
                    $client_condition
                    GROUP BY c.id
                ";

                $result = $conn->query($query);

                if ($result) {
                    $clients = [];

                    while ($row = $result->fetch_assoc()) {
                        $client_id = $row['client_id'];

                        // Fetch all projects for this client to get team_member_ids
                        $project_query = "SELECT name, start_date, technology, team_member_ids FROM projects WHERE client_id = $client_id";
                        $project_result = $conn->query($project_query);

                        $all_team_ids = [];
                        $projects_details = [];

                        while ($project = $project_result->fetch_assoc()) {
                            // Decode team member IDs for this project
                            $team_ids = json_decode($project['team_member_ids'], true);
                            $team_member_names = [];

                            if (is_array($team_ids) && count($team_ids) > 0) {
                                // Sanitize IDs for query
                                $escaped_ids = implode(",", array_map('intval', $team_ids));

                                // Fetch team member names
                                $name_query = "SELECT first_name, last_name FROM employees WHERE id IN ($escaped_ids)";
                                $name_result = $conn->query($name_query);

                                if ($name_result) {
                                    while ($name_row = $name_result->fetch_assoc()) {
                                        $team_member_names[] = $name_row['first_name'] . ' ' . $name_row['last_name'];
                                    }
                                }
                            }

                            // Add project details including team member names
                            $projects_details[] = [
                                'team_member_names' => $team_member_names,
                                'project_name' => $project['name'],
                                'start_date' => $project['start_date'],
                                'technology' => $project['technology']
                            ];

                            // Collect all team member IDs for counting unique members
                            if (is_array($team_ids)) {
                                $all_team_ids = array_merge($all_team_ids, $team_ids);
                            }
                        }

                        $unique_ids = array_unique($all_team_ids);
                        $team_member_details = [];

                        if (!empty($unique_ids)) {
                            $escaped_ids = implode(",", array_map('intval', $unique_ids));
                            $empl_query = "SELECT id, first_name, last_name, profile FROM employees WHERE id IN ($escaped_ids)";
                            $empl_result = $conn->query($empl_query);
                            while ($member = $empl_result->fetch_assoc()) {
                                $team_member_details[] = [
                                    'employee_id' => $member['id'],
                                    'first_name' => $member['first_name'],
                                    'last_name' => $member['last_name'],
                                    'profile' => $member['profile'],
                                ];
                            }
                        }

                        // Add team member info and employee count
                        $row['team_members'] = $team_member_details;
                        $row['employee_count'] = count($unique_ids);

                        // Add project details
                        $row['projects'] = $projects_details;

                        $clients[] = $row;
                    }

                    sendJsonResponse('success', $clients);
                } else {
                    sendJsonResponse('error', null, "Query failed: " . $conn->error);
                }

                break;

            case 'add':
                // Capture and sanitize POST data
                $logged_in_employee_id = isset($_POST['logged_in_employee_id']) ? (int)$_POST['logged_in_employee_id'] : '';
                $name = $_POST['name'] ?? "";
                $email = $_POST['email'] ?? "";
                $profile = $_FILES['profile']['name'] ?? "";
                $country = $_POST['country'] ?? "";
                $state = $_POST['state'] ?? "";
                $city = $_POST['city'] ?? "";
                $status = $_POST['status'] ?? 1; // Default to 1 (active)
                $about = $_POST['about'] ?? "";
                //$created_by = (int)$logged_in_employee_id;
                $created_at = date('Y-m-d H:i:s');

                // Sanitize email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    sendJsonResponse('error', null, 'Invalid email format.');
                    exit;
                }

                // Upload profile image if present
                if ($profile) {
                    try {
                        // Upload the profile image to a specific folder
                        $profilePath = uploadFile($_FILES['profile'], 'uploads/profiles', ['image/jpeg', 'image/png', 'image/webp']);
                        if ($profilePath) {
                            $profile = $profilePath;
                        }
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, $e->getMessage());
                        exit;
                    }
                }

                // Direct SQL query (no prepared statements or bind parameters)
                $query = "INSERT INTO clients (name, email, profile, country, state, city, status, about) 
                        VALUES ('$name', '$email', '$profile', '$country', '$state', '$city', '$status', '$about')";

                // Execute the query
                if ($conn->query($query)) {
                    $client_id = $conn->insert_id; // Get the last inserted client ID
                    $created_at = date('Y-m-d H:i:s');
                    sendJsonResponse('success', [
                        'client_id' => $client_id,
                        'name' => $name,
                        'email' => $email,
                        'profile' => $profile,
                        'country' => $country,
                        'state' => $state,
                        'city' => $city,
                        'status' => $status,
                        'about' => $about,
                        'created_at' => $created_at
                    ], 'Client added successfully');
                } else {
                    $error = $conn->error;
                    sendJsonResponse('error', null, "Failed to add client: $error");
                }
                break;

            case 'edit':
                $client_id = $_POST['client_id'] ?? 0; 
                $name = $_POST['name'] ?? "";
                $email = $_POST['email'] ?? "";
                $profile = $_FILES['profile']['name'] ?? "";
                $country = $_POST['country'] ?? "";
                $state = $_POST['state'] ?? "";
                $city = $_POST['city'] ?? "";
                $status = $_POST['status'] ?? 1;
                $about = $_POST['about'] ?? "";

                // Validate client_id
                if ($client_id <= 0) {
                    sendJsonResponse('error', null, 'Invalid client ID.');
                    exit;
                }

                // Upload new profile image if present
                if ($profile) {
                    try {
                        // Upload the profile image to a specific folder
                        $profilePath = uploadFile($_FILES['profile'], 'uploads/profiles', ['image/jpeg', 'image/png', 'image/webp']);
                        if ($profilePath) {
                            $profile = $profilePath;
                        }
                    } catch (Exception $e) {
                        sendJsonResponse('error', null, $e->getMessage());
                        exit;
                    }
                }

                // Direct SQL query (no prepared statements or bind parameters)
                // We need to only update the values that were provided in the request
                $query = "UPDATE clients SET 
                            name = '$name', 
                            email = '$email', 
                            country = '$country', 
                            state = '$state', 
                            city = '$city', 
                            status = '$status', 
                            about = '$about'";

                // If profile is being updated, include the profile field in the query
                if ($profile) {
                    $query .= ", profile = '$profile'";
                }

                // Complete the query by specifying the WHERE condition to update the specific client
                $query .= " WHERE id = $client_id";

                // Execute the query
                if ($conn->query($query)) {
                    $updated_at = date('Y-m-d H:i:s');
                    sendJsonResponse('success', [
                        'client_id' => $client_id,
                        'name' => $name,
                        'email' => $email,
                        'profile' => $profile,
                        'country' => $country,
                        'state' => $state,
                        'city' => $city,
                        'status' => $status,
                        'about' => $about,
                        'updated_at' => $updated_at
                    ], 'Client updated successfully');
                } else {
                    $error = $conn->error;
                    sendJsonResponse('error', null, "Failed to update client: $error");
                }
                break;
                
            case 'delete':
                if (isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0) {
                    $client_id = (int)$_GET['id'];

                    $deleteSql = "DELETE FROM clients WHERE id = $client_id";
                    if ($conn->query($deleteSql)) {
                        sendJsonResponse('success', null, 'Client deleted successfully');
                    } else {
                        http_response_code(500);
                        sendJsonResponse('error', null, 'Failed to delete client: ' . $conn->error);
                    }
                  
                } else {
                    http_response_code(400);
                    sendJsonResponse('error', null, 'Invalid Client ID');
                }
                exit;
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

   
    $conn->close();
?>