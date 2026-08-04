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

// Helper to parse and verify participants against employees table during add/edit
function verifyAndGetParticipants($conn, $input)
{
    if (empty($input)) {
        return [];
    }

    if (is_string($input)) {
        $jsonParsed = json_decode($input, true);
        if (is_array($jsonParsed)) {
            $input = $jsonParsed;
        } else {
            $input = explode(',', $input);
        }
    }

    $rawParticipants = [];
    $userIds = [];
    if (is_array($input)) {
        foreach ($input as $item) {
            if (is_array($item)) {
                $uId = (int)($item['user_id'] ?? $item['id'] ?? 0);
                $role = !empty($item['role']) ? trim($item['role']) : 'participant';
                $encKey = $item['encrypted_key'] ?? null;
                if ($uId > 0) {
                    $rawParticipants[$uId] = ['role' => $role, 'encrypted_key' => $encKey];
                    $userIds[] = $uId;
                }
            } else {
                $uId = (int)$item;
                if ($uId > 0) {
                    $rawParticipants[$uId] = ['role' => 'participant', 'encrypted_key' => null];
                    $userIds[] = $uId;
                }
            }
        }
    }

    if (empty($userIds)) {
        return [];
    }

    $userIds = array_values(array_unique($userIds));
    $inClause = implode(',', $userIds);

    // Query employees table to verify existence of all participant user IDs
    $res = $conn->query("SELECT id, role FROM employees WHERE id IN ($inClause)");
    $validEmployees = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $validEmployees[(int)$row['id']] = $row['role'];
        }
    }

    // Verify if any provided participant user ID is invalid
    $invalidIds = [];
    foreach ($userIds as $uId) {
        if (!isset($validEmployees[$uId])) {
            $invalidIds[] = $uId;
        }
    }

    if (!empty($invalidIds)) {
        throw new Exception("Invalid participant user ID(s): " . implode(', ', $invalidIds) . ". Participants must be valid employees or admins.");
    }

    $verifiedParticipants = [];
    foreach ($userIds as $uId) {
        $verifiedParticipants[] = [
            'user_id' => $uId,
            'role' => is_array($rawParticipants[$uId]) ? $rawParticipants[$uId]['role'] : $rawParticipants[$uId],
            'encrypted_key' => is_array($rawParticipants[$uId]) ? ($rawParticipants[$uId]['encrypted_key'] ?? null) : null
        ];
    }

    return $verifiedParticipants;
}

$currentUserId = (int)$token_info[0];
isAdminCheck();

switch ($action) {
    case 'view':
        $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        $created_by = isset($_GET['created_by']) && is_numeric($_GET['created_by']) ? (int)$_GET['created_by'] : null;
        $from_date = $_GET['from_date'] ?? null;
        $to_date = $_GET['to_date'] ?? null;
        $participants_filter = $_GET['participants'] ?? null; // Can be single ID or comma-separated IDs or array

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 5;
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : (isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)floor((int)$_GET['offset'] / $limit) + 1 : 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];
        $types = "";

        // Non-admin user access: view discussions created by user OR where user is a participant
        if ($currentUserId > 0) {
            $conditions[] = "(d.created_by = ? OR d.id IN (SELECT discussion_id FROM discussion_participants WHERE user_id = ?))";
            $params[] = $currentUserId;
            $params[] = $currentUserId;
            $types .= "ii";
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

        $exact_date = $_GET['date'] ?? null;
        if (!empty($exact_date)) {
            $conditions[] = "DATE(d.created_at) = ?";
            $params[] = $exact_date;
            $types .= "s";
        }

        // Participants filter handling via discussion_participants table
        if (!empty($participants_filter)) {
            $partIds = [];
            if (is_array($participants_filter)) {
                $partIds = array_map('intval', $participants_filter);
            } else {
                $partIds = array_map('intval', explode(',', $participants_filter));
            }
            $partIds = array_values(array_filter($partIds, function ($v) {
                return $v > 0;
            }));

            if (!empty($partIds)) {
                $inClause = implode(',', $partIds);
                $conditions[] = "d.id IN (SELECT discussion_id FROM discussion_participants WHERE user_id IN ($inClause))";
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
            $discussionIds = [];

            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['created_by'] = (int)$row['created_by'];
                $discussions[] = $row;
                $discussionIds[] = $row['id'];
            }

            // Fetch participants for retrieved discussions
            $discussionParticipantsMap = [];
            if (!empty($discussionIds)) {
                $inClause = implode(',', array_map('intval', $discussionIds));
                $dpQuery = "SELECT dp.discussion_id, dp.user_id, dp.role, dp.encrypted_key, dp.created_at, dp.updated_at,
                                   e.first_name, e.last_name, e.email, e.role AS employee_role, e.profile
                            FROM discussion_participants dp
                            LEFT JOIN employees e ON dp.user_id = e.id
                            WHERE dp.discussion_id IN ($inClause)";
                $dpResult = $conn->query($dpQuery);
                if ($dpResult) {
                    while ($dpRow = $dpResult->fetch_assoc()) {
                        $discId = (int)$dpRow['discussion_id'];
                        $uId = (int)$dpRow['user_id'];
                        $participantObj = [
                            'id' => $uId,
                            'user_id' => $uId,
                            'role' => $dpRow['role'],
                            'encrypted_key' => $dpRow['encrypted_key'] ?? null,
                            'name' => trim(($dpRow['first_name'] ?? '') . ' ' . ($dpRow['last_name'] ?? '')),
                            'first_name' => $dpRow['first_name'],
                            'last_name' => $dpRow['last_name'],
                            'email' => $dpRow['email'],
                            'employee_role' => $dpRow['employee_role'],
                            'profile' => $dpRow['profile'] ?? null,
                            'created_at' => $dpRow['created_at'],
                            'updated_at' => $dpRow['updated_at']
                        ];
                        $discussionParticipantsMap[$discId][] = $participantObj;
                    }
                }
            }

            // Merge participants data into discussions
            foreach ($discussions as &$disc) {
                $pList = $discussionParticipantsMap[(int)$disc['id']] ?? [];
                $disc['participants'] = array_values(array_map(function ($p) {
                    return $p['user_id'];
                }, $pList));
                $disc['participant_details'] = $pList;
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
                    'page' => $page,
                    'has_more' => ($offset + count($discussions)) < $totalCount
                ]);
            }
        } else {
            sendJsonResponse('error', null, "Query execution failed: " . $conn->error);
        }
        break;

    case 'add':
        $title = $_POST['title'] ?? $requestData['title'] ?? [];
        $description = $_POST['description'] ?? $requestData['description'] ?? null;
        $conclusion = $_POST['conclusion'] ?? $requestData['conclusion'] ?? null;
        $created_by = $currentUserId;
        $participantsInput = $_POST['participants'] ?? $requestData['participants'] ?? [];

        if (empty($title)) {
            sendJsonResponse('error', null, "Title is required.");
        }

        if ($created_by <= 0) {
            sendJsonResponse('error', null, "Valid creator user ID is required.");
        }

        $conn->begin_transaction();
        try {
            // Verify participants exist in employees table
            $parsedParticipants = verifyAndGetParticipants($conn, $participantsInput);

            $is_encrypted = isset($_POST['is_encrypted']) ? (int)$_POST['is_encrypted'] : (isset($requestData['is_encrypted']) ? (int)$requestData['is_encrypted'] : 1);

            $stmt = $conn->prepare("INSERT INTO discussions (title, description, conclusion, created_by, is_encrypted) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssii", $title, $description, $conclusion, $created_by, $is_encrypted);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create discussion: " . $stmt->error);
            }

            $newId = $stmt->insert_id;

            if (!empty($parsedParticipants)) {
                $pStmt = $conn->prepare("INSERT INTO discussion_participants (discussion_id, user_id, role, encrypted_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), encrypted_key = VALUES(encrypted_key)");
                foreach ($parsedParticipants as $p) {
                    $pUserId = $p['user_id'];
                    $pRole = $p['role'];
                    $pEncKey = $p['encrypted_key'] ?? null;
                    $pStmt->bind_param("iiss", $newId, $pUserId, $pRole, $pEncKey);
                    if (!$pStmt->execute()) {
                        throw new Exception("Failed to insert participant user_id {$pUserId}: " . $pStmt->error);
                    }
                }
            }

            $conn->commit();
            sendJsonResponse('success', ['id' => $newId], "Discussion created successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse('error', null, $e->getMessage());
        }
        break;

    case 'edit':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? $requestData['id'] ?? 0);
        $title = $_POST['title'] ?? $requestData['title'] ?? [];
        $description = $_POST['description'] ?? $requestData['description'] ?? null;
        $conclusion = $_POST['conclusion'] ?? $requestData['conclusion'] ?? null;
        $participantsInput = $_POST['participants'] ?? $requestData['participants'] ?? null;
        $is_encrypted = isset($_POST['is_encrypted']) ? (int)$_POST['is_encrypted'] : (isset($requestData['is_encrypted']) ? (int)$requestData['is_encrypted'] : 1);

        if ($id <= 0) {
            sendJsonResponse('error', null, "Valid discussion ID is required.");
        }

        // Authorization Check: Creator, Admin, or assigned Discussion Participant can update
        $checkStmt = $conn->prepare("SELECT created_by FROM discussions WHERE id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        $existingDisc = $checkRes ? $checkRes->fetch_assoc() : null;

        if (!$existingDisc) {
            sendJsonResponse('error', null, "Discussion not found.");
        }

        $isParticipant = false;
        if ($currentUserId > 0) {
            $partCheck = $conn->query("SELECT 1 FROM discussion_participants WHERE discussion_id = " . intval($id) . " AND user_id = " . intval($currentUserId) . " LIMIT 1");
            if ($partCheck && $partCheck->num_rows > 0) {
                $isParticipant = true;
            }
        }

        if ($currentUserId > 0 && (int)$existingDisc['created_by'] !== $currentUserId && !$isParticipant) {
            sendJsonResponse('error', null, "Unauthorized: Only the discussion creator, admin, or participants can update this discussion.");
        }

        if (empty($title)) {
            sendJsonResponse('error', null, "Title is required.");
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE discussions SET title = ?, description = ?, conclusion = ?, is_encrypted = ? WHERE id = ?");
            $stmt->bind_param("sssii", $title, $description, $conclusion, $is_encrypted, $id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to update discussion: " . $stmt->error);
            }

            if ($participantsInput !== null) {
                // Verify participants exist in employees table (throws Exception if any participant user ID is invalid)
                $parsedParticipants = verifyAndGetParticipants($conn, $participantsInput);

                $delStmt = $conn->prepare("DELETE FROM discussion_participants WHERE discussion_id = ?");
                $delStmt->bind_param("i", $id);
                if (!$delStmt->execute()) {
                    throw new Exception("Failed to update discussion participants: " . $delStmt->error);
                }

                if (!empty($parsedParticipants)) {
                    $pStmt = $conn->prepare("INSERT INTO discussion_participants (discussion_id, user_id, role, encrypted_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), encrypted_key = VALUES(encrypted_key)");
                    foreach ($parsedParticipants as $p) {
                        $pUserId = $p['user_id'];
                        $pRole = $p['role'];
                        $pEncKey = $p['encrypted_key'] ?? null;
                        $pStmt->bind_param("iiss", $id, $pUserId, $pRole, $pEncKey);
                        if (!$pStmt->execute()) {
                            throw new Exception("Failed to insert participant user_id {$pUserId}: " . $pStmt->error);
                        }
                    }
                }
            }

            $conn->commit();
            sendJsonResponse('success', ['id' => $id], "Discussion updated successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse('error', null, $e->getMessage());
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

        if ($currentUserId > 0 && (int)$existingDisc['created_by'] !== $currentUserId) {
            sendJsonResponse('error', null, "Unauthorized: Only the discussion creator or admin can delete this discussion.");
        }

        $conn->begin_transaction();
        try {
            $delPartStmt = $conn->prepare("DELETE FROM discussion_participants WHERE discussion_id = ?");
            $delPartStmt->bind_param("i", $id);
            $delPartStmt->execute();

            $delStmt = $conn->prepare("DELETE FROM discussions WHERE id = ?");
            $delStmt->bind_param("i", $id);
            if (!$delStmt->execute()) {
                throw new Exception("Failed to delete discussion: " . $delStmt->error);
            }

            $conn->commit();
            sendJsonResponse('success', null, "Discussion deleted successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse('error', null, $e->getMessage());
        }
        break;

    case 'add_participant':
        $discussion_id = (int)($_POST['discussion_id'] ?? $requestData['discussion_id'] ?? $_GET['discussion_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? $requestData['user_id'] ?? $_GET['user_id'] ?? 0);
        $role = trim($_POST['role'] ?? $requestData['role'] ?? 'participant');

        if ($discussion_id <= 0 || $user_id <= 0) {
            sendJsonResponse('error', null, "Valid discussion ID and user ID are required.");
        }

        try {
            $parsedParticipants = verifyAndGetParticipants($conn, [$user_id]);
            if (empty($parsedParticipants)) {
                sendJsonResponse('error', null, "Invalid participant user ID: Employee not found.");
            }

            $stmt = $conn->prepare("INSERT INTO discussion_participants (discussion_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)");
            $stmt->bind_param("iis", $discussion_id, $user_id, $role);

            if ($stmt->execute()) {
                sendJsonResponse('success', ['discussion_id' => $discussion_id, 'user_id' => $user_id], "Participant added/updated successfully.");
            } else {
                sendJsonResponse('error', null, "Failed to update participant: " . $stmt->error);
            }
        } catch (Exception $e) {
            sendJsonResponse('error', null, $e->getMessage());
        }
        break;

    case 'remove_participant':
        $discussion_id = (int)($_POST['discussion_id'] ?? $requestData['discussion_id'] ?? $_GET['discussion_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? $requestData['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($discussion_id <= 0 || $user_id <= 0) {
            sendJsonResponse('error', null, "Valid discussion ID and user ID are required.");
        }

        $stmt = $conn->prepare("DELETE FROM discussion_participants WHERE discussion_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $discussion_id, $user_id);

        if ($stmt->execute()) {
            sendJsonResponse('success', null, "Participant removed successfully.");
        } else {
            sendJsonResponse('error', null, "Failed to remove participant: " . $stmt->error);
        }
        break;

    case 'update_participant_keys':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? $requestData['id'] ?? 0);
        $participantsInput = $_POST['participants'] ?? $requestData['participants'] ?? [];

        if ($id <= 0 || empty($participantsInput)) {
            sendJsonResponse('error', null, "Valid discussion ID and participants array are required.");
        }

        // Authorization Check: Creator, Admin, or assigned Discussion Participant can update keys
        $checkStmt = $conn->prepare("SELECT created_by FROM discussions WHERE id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        $existingDisc = $checkRes ? $checkRes->fetch_assoc() : null;

        if (!$existingDisc) {
            sendJsonResponse('error', null, "Discussion not found.");
        }

        $isParticipant = false;
        if ($currentUserId > 0) {
            $partCheck = $conn->query("SELECT 1 FROM discussion_participants WHERE discussion_id = " . intval($id) . " AND user_id = " . intval($currentUserId) . " LIMIT 1");
            if ($partCheck && $partCheck->num_rows > 0) {
                $isParticipant = true;
            }
        }

        if ($currentUserId > 0 && (int)$existingDisc['created_by'] !== $currentUserId && !$isParticipant) {
            sendJsonResponse('error', null, "Unauthorized to update participant keys for this discussion.");
        }

        $parsedParticipants = verifyAndGetParticipants($conn, $participantsInput);

        $conn->begin_transaction();
        try {
            $pStmt = $conn->prepare("INSERT INTO discussion_participants (discussion_id, user_id, role, encrypted_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key)");
            foreach ($parsedParticipants as $p) {
                $pUserId = $p['user_id'];
                $pRole = $p['role'];
                $pEncKey = $p['encrypted_key'] ?? null;
                if (!empty($pEncKey)) {
                    $pStmt->bind_param("iiss", $id, $pUserId, $pRole, $pEncKey);
                    if (!$pStmt->execute()) {
                        throw new Exception("Failed to update participant key for user_id {$pUserId}: " . $pStmt->error);
                    }
                }
            }

            $conn->commit();
            sendJsonResponse('success', ['id' => $id], "Participant keys updated successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse('error', null, $e->getMessage());
        }
        break;

    default:
        sendJsonResponse('error', null, "Invalid action requested.");
        break;
}
