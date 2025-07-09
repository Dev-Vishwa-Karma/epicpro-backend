<?php
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

                if (isset($_GET['client_name']) && !empty(trim($_GET['client_name']))) {
                    $client_name = $conn->real_escape_string(trim($_GET['client_name']));
                    $conditions[] = "c.name LIKE '%$client_name%'";
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
                        c.country AS client_country,
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
                        $project_query = "SELECT team_member_ids FROM projects WHERE client_id = $client_id";
                        $project_result = $conn->query($project_query);

                        $all_team_ids = [];
                        while ($project = $project_result->fetch_assoc()) {
                            $team_ids = json_decode($project['team_member_ids'], true);
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

                        $clients[] = $row;
                    }

                    sendJsonResponse('success', $clients);
                } else {
                    sendJsonResponse('error', null, "Query failed: " . $conn->error);
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

   
    $conn->close();
?>