<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database connection and auth validation
include_once 'db_connection.php';
include_once 'auth_validate.php';

header('Content-Type: application/json');

function sendJsonResponse($status, $data = null, $message = null)
{
    if ($status === 'success') {
        echo json_encode(['status' => 'success', 'data' => $data, 'message' => $message]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $message]);
    }
    exit;
}

// Parse request payload if JSON
$inputJSON = file_get_contents('php://input');
$requestData = json_decode($inputJSON, true) ?? [];

$action = $_GET['action'] ?? $_POST['action'] ?? $requestData['action'] ?? 'view';

// Helper to fetch employee mapping by IDs
function fetchEmployeeMap($conn, $employeeIds)
{
    if (empty($employeeIds)) return [];
    $idsClean = array_map('intval', array_unique($employeeIds));
    $idsClean = array_filter($idsClean, function ($id) {
        return $id > 0;
    });
    if (empty($idsClean)) return [];

    $inClause = implode(',', $idsClean);
    $query = "SELECT id, first_name, last_name, email, role, profile FROM employees WHERE id IN ($inClause)";
    $result = $conn->query($query);
    $map = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[$row['id']] = [
                'id' => (int)$row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'profile' => $row['profile'] ?? null
            ];
        }
    }
    return $map;
}

$currentUserId = isset($token_info[0]) ? (int)$token_info[0] : (isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0);
$isUserAdmin = isAdminCheck();

switch ($action) {
    case 'view':
        $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        $created_by = isset($_GET['created_by']) && is_numeric($_GET['created_by']) ? (int)$_GET['created_by'] : null;
        $from_date = $_GET['from_date'] ?? null;
        $to_date = $_GET['to_date'] ?? null;
        $participants_filter = $_GET['participants'] ?? null; // Can be single ID or comma-separated IDs or array string

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 5;
        $offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : null;
        if ($page !== null && $page > 0) {
            $offset = ($page - 1) * $limit;
        }

        $conditions = [];
        $params = [];
        $types = "";

        // Non-admin user can only view discussions they created or are a participant in
        if (!$isUserAdmin && $currentUserId > 0) {
            $uIdStr = (string)$currentUserId;
            $conditions[] = "(d.created_by = ? OR JSON_CONTAINS(d.participants, CAST(? AS JSON)) OR JSON_SEARCH(d.participants, 'one', ?) IS NOT NULL OR d.participants LIKE ?)";
            $params[] = $currentUserId;
            $params[] = $uIdStr;
            $params[] = $uIdStr;
            $params[] = '%"' . $currentUserId . '"%';
            $types .= "isss";
        }

        if ($id !== null) {
            $conditions[] = "d.id = ?";
            $params[] = $id;
            $types .= "i";
        }

        if ($created_by !== null && $created_by > 0) {
            $conditions[] = "d.created_by = ?";
            $params[] = $created_by;
            $types .= "i";
        }

        if (!empty($search)) {
            $conditions[] = "(d.title LIKE ? OR d.description LIKE ? OR d.conclusion LIKE ?)";
            $searchParam = "%" . $search . "%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "sss";
        }

        $exact_date = $_GET['date'] ?? null;
        if (!empty($exact_date)) {
            $conditions[] = "DATE(d.created_at) = ?";
            $params[] = $exact_date;
            $types .= "s";
        }

        // Participants filter handling
        if (!empty($participants_filter)) {
            $partIds = [];
            if (is_array($participants_filter)) {
                $partIds = array_map('intval', $participants_filter);
            } else {
                $partIds = array_map('intval', explode(',', $participants_filter));
            }
            $partIds = array_filter($partIds, function ($v) {
                return $v > 0;
            });

            if (!empty($partIds)) {
                $partConds = [];
                foreach ($partIds as $pId) {
                    $partConds[] = "(JSON_CONTAINS(d.participants, CAST(? AS JSON)) OR JSON_SEARCH(d.participants, 'one', ?) IS NOT NULL OR d.participants LIKE ?)";
                    $params[] = (string)$pId;
                    $params[] = (string)$pId;
                    $params[] = '%"' . $pId . '"%';
                    $types .= "sss";
                }
                $conditions[] = "(" . implode(" OR ", $partConds) . ")";
            }
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        // Calculate total count
        $totalCount = 0;
        $countSql = "SELECT COUNT(*) AS total FROM discussions d $whereClause";
        $countStmt = $conn->prepare($countSql);
        if ($countStmt && !empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        if ($countStmt) {
            $countStmt->execute();
            $resCount = $countStmt->get_result();
            if ($rowC = $resCount->fetch_assoc()) {
                $totalCount = (int)$rowC['total'];
            }
        }

        $sql = "SELECT d.*, CONCAT(e.first_name, ' ', e.last_name) AS creator_name, e.first_name AS creator_first_name, e.last_name AS creator_last_name, e.email AS creator_email, e.profile AS creator_profile 
                FROM discussions d 
                LEFT JOIN employees e ON d.created_by = e.id 
                $whereClause 
                ORDER BY d.created_at DESC";

        if ($limit > 0) {
            $limitInt = (int)$limit;
            $offsetInt = (int)$offset;
            $sql .= " LIMIT $limitInt OFFSET $offsetInt";
        }

        $stmt = $conn->prepare($sql);
        if ($stmt && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $discussions = [];
            $allParticipantIds = [];

            while ($row = $result->fetch_assoc()) {
                $rawParticipants = $row['participants'];
                $decodedParticipants = [];
                if (!empty($rawParticipants)) {
                    if (is_string($rawParticipants)) {
                        $decodedParticipants = json_decode($rawParticipants, true) ?? [];
                    } elseif (is_array($rawParticipants)) {
                        $decodedParticipants = $rawParticipants;
                    }
                }
                $row['participants'] = array_values(array_map('intval', (array)$decodedParticipants));
                foreach ($row['participants'] as $pId) {
                    if ($pId > 0) $allParticipantIds[] = $pId;
                }
                $discussions[] = $row;
            }

            // Fetch details for participants
            $employeeMap = fetchEmployeeMap($conn, $allParticipantIds);
            foreach ($discussions as &$disc) {
                $pDetails = [];
                foreach ($disc['participants'] as $pId) {
                    if (isset($employeeMap[$pId])) {
                        $pDetails[] = $employeeMap[$pId];
                    }
                }
                $disc['participant_details'] = $pDetails;
            }

            if ($id !== null) {
                if (!empty($discussions)) {
                    sendJsonResponse('success', $discussions[0]);
                } else {
                    sendJsonResponse('error', null, "Discussion not found.");
                }
            } else {
                sendJsonResponse('success', [
                    'discussions' => $discussions,
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + count($discussions)) < $totalCount
                ]);
            }
        } else {
            sendJsonResponse('error', null, "Query execution failed: " . $conn->error);
        }
        break;

    case 'add':
        $title = trim($_POST['title'] ?? $requestData['title'] ?? '');
        $description = trim($_POST['description'] ?? $requestData['description'] ?? '');
        $conclusion = trim($_POST['conclusion'] ?? $requestData['conclusion'] ?? '');
        $created_by = (int)($_POST['created_by'] ?? $requestData['created_by'] ?? 0);
        $participantsInput = $_POST['participants'] ?? $requestData['participants'] ?? [];

        if (empty($title) || empty($description)) {
            sendJsonResponse('error', null, "Title is required.");
        }

        if ($created_by <= 0) {
            sendJsonResponse('error', null, "Valid creator user ID is required.");
        }

        if (is_string($participantsInput)) {
            $jsonParsed = json_decode($participantsInput, true);
            if (is_array($jsonParsed)) {
                $participantsInput = $jsonParsed;
            } else {
                $participantsInput = explode(',', $participantsInput);
            }
        }
        $participantsArr = array_values(array_filter(array_map('intval', (array)$participantsInput), function ($v) {
            return $v > 0;
        }));
        $participantsJSON = json_encode($participantsArr);

        $stmt = $conn->prepare("INSERT INTO discussions (title, description, conclusion, participants, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $title, $description, $conclusion, $participantsJSON, $created_by);

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            sendJsonResponse('success', ['id' => $newId], "Discussion created successfully.");
        } else {
            sendJsonResponse('error', null, "Failed to create discussion: " . $stmt->error);
        }
        break;

    case 'edit':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? $requestData['id'] ?? 0);
        $title = trim($_POST['title'] ?? $requestData['title'] ?? '');
        $description = trim($_POST['description'] ?? $requestData['description'] ?? '');
        $conclusion = trim($_POST['conclusion'] ?? $requestData['conclusion'] ?? '');
        $participantsInput = $_POST['participants'] ?? $requestData['participants'] ?? [];

        if ($id <= 0) {
            sendJsonResponse('error', null, "Valid discussion ID is required.");
        }

        // Authorization Check: Only creator or admin can update
        $checkStmt = $conn->prepare("SELECT created_by FROM discussions WHERE id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        $existingDisc = $checkRes ? $checkRes->fetch_assoc() : null;

        if (!$existingDisc) {
            sendJsonResponse('error', null, "Discussion not found.");
        }

        if (!$isUserAdmin && $currentUserId > 0 && (int)$existingDisc['created_by'] !== $currentUserId) {
            sendJsonResponse('error', null, "Unauthorized: Only the discussion creator or admin can update this discussion.");
        }

        if (empty($title)) {
            sendJsonResponse('error', null, "Title is required.");
        }

        if (is_string($participantsInput)) {
            $jsonParsed = json_decode($participantsInput, true);
            if (is_array($jsonParsed)) {
                $participantsInput = $jsonParsed;
            } else {
                $participantsInput = explode(',', $participantsInput);
            }
        }
        $participantsArr = array_values(array_filter(array_map('intval', (array)$participantsInput), function ($v) {
            return $v > 0;
        }));
        $participantsJSON = json_encode($participantsArr);

        $stmt = $conn->prepare("UPDATE discussions SET title = ?, description = ?, conclusion = ?, participants = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $description, $conclusion, $participantsJSON, $id);

        if ($stmt->execute()) {
            sendJsonResponse('success', ['id' => $id], "Discussion updated successfully.");
        } else {
            sendJsonResponse('error', null, "Failed to update discussion: " . $stmt->error);
        }
        break;

    case 'delete':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? $requestData['id'] ?? 0);

        if ($id <= 0) {
            sendJsonResponse('error', null, "Valid discussion ID is required.");
        }

        // Authorization Check: Only creator or admin can delete
        $checkStmt = $conn->prepare("SELECT created_by FROM discussions WHERE id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        $existingDisc = $checkRes ? $checkRes->fetch_assoc() : null;

        if (!$existingDisc) {
            sendJsonResponse('error', null, "Discussion not found.");
        }

        if (!$isUserAdmin && $currentUserId > 0 && (int)$existingDisc['created_by'] !== $currentUserId) {
            sendJsonResponse('error', null, "Unauthorized: Only the discussion creator or admin can delete this discussion.");
        }

        $stmt = $conn->prepare("DELETE FROM discussions WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            sendJsonResponse('success', null, "Discussion deleted successfully.");
        } else {
            sendJsonResponse('error', null, "Failed to delete discussion: " . $stmt->error);
        }
        break;

    default:
        sendJsonResponse('error', null, "Invalid action requested.");
        break;
}
