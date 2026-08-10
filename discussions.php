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
function fetchEmployee($conn, $requestId)
{
    $id = intval($requestId);
    $query = "SELECT id, first_name, last_name, email, role, profile FROM employees WHERE id = ($id)";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return [
            'id' => (int)$row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'profile' => $row['profile'] ?? null
        ];
    }
    return [];
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
                    $rawParticipants[$uId] = [
                        'role' => $role,
                        'encrypted_key' => $encKey
                    ];
                    $userIds[] = $uId;
                }
            } else {
                $uId = (int)$item;
                if ($uId > 0) {
                    $rawParticipants[$uId] = [
                        'role' => 'participant',
                        'encrypted_key' => null
                    ];
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

    $sql = "
        SELECT id, role, first_name, last_name
        FROM employees
        WHERE id IN ($inClause)
    ";

    $res = $conn->query($sql);

    if (!$res) {
        throw new Exception("Failed to verify participants.");
    }

    $employees = [];
    while ($row = $res->fetch_assoc()) {
        $employees[(int)$row['id']] = [
            'role' => $row['role'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name'])
        ];
    }

    $invalidParticipants = [];
    $validParticipants = [];
    foreach ($userIds as $uId) {
        if (!isset($employees[$uId])) {
            $invalidParticipants[] = "User ID {$uId}";
            continue;
        }

        // User exists but is not admin/super_admin
        if (!in_array($employees[$uId]['role'], ['admin', 'super_admin'])) {
            $invalidParticipants[] = $employees[$uId]['name'];
            continue;
        }

        // Valid participant
        $validParticipants[] = [
            'user_id' => $uId,
            'role' => $rawParticipants[$uId]['role'],
            'encrypted_key' => $rawParticipants[$uId]['encrypted_key']
        ];
    }

    if (!empty($invalidParticipants)) {
        throw new Exception(
            "Invalid participants: " . implode(', ', $invalidParticipants) .
                ". Only admin and super admin users are allowed."
        );
    }

    return $validParticipants;
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
        $participant_id = isset($_GET['participant_id']) && is_numeric($_GET['participant_id']) ? (int)$_GET['participant_id'] : null;

        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 5;
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : (isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int)floor((int)$_GET['offset'] / $limit) + 1 : 1);
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];
        $types = "";

        if ($currentUserId > 0) {
            $conditions[] = "(d.created_by = ? OR d.id IN (SELECT discussion_id FROM discussion_participants WHERE user_id = ?))";
            $params[] = $currentUserId;
            $params[] = $currentUserId;
            $types .= "ii";
        }

        if ($participant_id !== null && $participant_id > 0) {
            $conditions[] = "d.id IN (SELECT discussion_id FROM discussion_participants WHERE user_id = ?)";
            $params[] = $participant_id;
            $types .= "i";
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
                $dpQuery = "SELECT dp.id, dp.discussion_id, dp.user_id, dp.role, dp.encrypted_key, dp.created_at, dp.updated_at, dp.tags,
                                   e.first_name, e.last_name, e.email, e.role AS employee_role, e.profile, e.public_key
                            FROM discussion_participants dp
                            LEFT JOIN employees e ON dp.user_id = e.id
                            WHERE dp.discussion_id IN ($inClause)";
                $dpResult = $conn->query($dpQuery);
                if ($dpResult) {
                    while ($dpRow = $dpResult->fetch_assoc()) {
                        $discId = (int)$dpRow['discussion_id'];
                        $uId = (int)$dpRow['user_id'];
                        $participantObj = [
                            'id' => $dpRow['id'],
                            'user_id' => $uId,
                            'role' => $dpRow['role'],
                            'encrypted_key' => $dpRow['encrypted_key'] ?? null,
                            'name' => trim(($dpRow['first_name'] ?? '') . ' ' . ($dpRow['last_name'] ?? '')),
                            'first_name' => $dpRow['first_name'],
                            'last_name' => $dpRow['last_name'],
                            'email' => $dpRow['email'],
                            'employee_role' => $dpRow['employee_role'],
                            'tags' => $dpRow['tags'] ?? null,
                            'public_key' => $dpRow['public_key'] ?? null,
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
                $disc['participant_details'] = $pList;
            }

            if ($id !== null) {
                if (!empty($discussions)) {
                    sendJsonResponse('success', $discussions[0]);
                } else {
                    sendJsonResponse('error', null, "Discussion not found.");
                }
            } else {
                $requester_public_key = null;
                if ($participant_id !== null && $participant_id > 0) {
                    $pkStmt = $conn->prepare("SELECT public_key FROM employees WHERE id = ?");
                    if ($pkStmt) {
                        $pkStmt->bind_param("i", $participant_id);
                        $pkStmt->execute();
                        $pkRes = $pkStmt->get_result();
                        if ($pkRow = $pkRes->fetch_assoc()) {
                            $requester_public_key = $pkRow['public_key'] ?? null;
                        }
                    }
                }

                sendJsonResponse('success', [
                    'discussions' => $discussions,
                    'total' => $totalCount,
                    'limit' => $limit,
                    'page' => $page,
                    'has_more' => ($offset + count($discussions)) < $totalCount,
                    'requester_public_key' => $requester_public_key
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

    case 'request_key_recovery':
        $discussionId = (int)($_POST['discussion_id'] ?? $requestData['discussion_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? $requestData['target_user_id'] ?? 0);

        if ($discussionId <= 0 || $targetUserId <= 0) {
            sendJsonResponse('error', null, "Valid discussion_id and target_user_id are required.");
        }

        $fetchStmt = $conn->prepare("SELECT tags FROM discussion_participants WHERE discussion_id = ? AND user_id = ?");
        $fetchStmt->bind_param("ii", $discussionId, $targetUserId);
        $fetchStmt->execute();
        $fetchRes = $fetchStmt->get_result();
        $row = $fetchRes ? $fetchRes->fetch_assoc() : null;

        if (!$row) {
            sendJsonResponse('error', null, "Participant not found in discussion.");
        }

        $existingTags = [];
        if (!empty($row['tags'])) {
            $decoded = json_decode($row['tags'], true);
            if (is_array($decoded)) {
                $existingTags = array_map('intval', $decoded);
            } else {
                $existingTags = array_map('intval', explode(',', $row['tags']));
            }
        }

        $message = "Recovery request resent successfully.";
        $user = fetchEmployee($conn, $currentUserId);
        $notificationText = ($user['name'] ?? 'User ' . $currentUserId) . " has again requested to recover the discussion id " . $discussionId . ".";

        if (!in_array((int)$currentUserId, $existingTags, true)) {
            $existingTags[] = (int)$currentUserId;
            $message = "Recovery request has been sent successfully.";
            $notificationText = ($user['name'] ?? 'User ' . $currentUserId) . " has requested to recover the discussion id " . $discussionId . ".";
        }
        $cleanTags = array_values(array_unique(array_filter($existingTags)));
        $jsonTags = !empty($cleanTags) ? json_encode($cleanTags) : null;

        $updateStmt = $conn->prepare("UPDATE discussion_participants SET tags = ? WHERE discussion_id = ? AND user_id = ?");
        $updateStmt->bind_param("sii", $jsonTags, $discussionId, $targetUserId);

        if (!$updateStmt->execute()) {
            sendJsonResponse('error', null, "Failed to send recovery request.");
        }

        $notif_sql = "
                        INSERT INTO notifications 
                        (employee_id, body, title, type, created_by) 
                        VALUES ($targetUserId, '$notificationText', 'Discussion Recovery Request', 'recovery_request', $currentUserId)
                    ";
        $notifStmt = $conn->prepare($notif_sql);
        if (!$notifStmt->execute()) {
            sendJsonResponse('error', null, "Failed to send recovery request.");
        }

        sendJsonResponse('success', ['tags' => $jsonTags], $message);
        break;

    case 'recover_participant_key':
        $discussionId = (int)($_POST['discussion_id'] ?? $requestData['discussion_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? $requestData['target_user_id'] ?? 0);
        $encryptedKey = $_POST['encrypted_key'] ?? $requestData['encrypted_key'] ?? null;

        if ($discussionId <= 0 || $targetUserId <= 0 || empty($encryptedKey)) {
            sendJsonResponse('error', null, "Valid discussion_id, target_user_id, and encrypted_key are required.");
        }

        // 1. Update target user's encrypted_key
        $keyStmt = $conn->prepare("UPDATE discussion_participants SET encrypted_key = ?, tags = NULL WHERE discussion_id = ? AND user_id = ?");
        $keyStmt->bind_param("sii", $encryptedKey, $discussionId, $targetUserId);
        if (!$keyStmt->execute()) {
            sendJsonResponse('error', null, "Failed to update recovered key.");
        }

        // 2. Fetch tags for both current user & target user in a single query
        $stmt = $conn->prepare("SELECT user_id, tags FROM discussion_participants WHERE discussion_id = ? AND user_id IN (?, ?)");
        $stmt->bind_param("iii", $discussionId, $currentUserId, $targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();

        $upStmt = $conn->prepare("UPDATE discussion_participants SET tags = ? WHERE discussion_id = ? AND user_id = ?");
        while ($res && $row = $res->fetch_assoc()) {
            $uId = (int)$row['user_id'];
            $otherId = ($uId === $currentUserId) ? $targetUserId : $currentUserId;

            if (!empty($row['tags'])) {
                $decoded = json_decode($row['tags'], true);
                $existing = is_array($decoded) ? array_map('intval', $decoded) : array_map('intval', explode(',', $row['tags']));
                $filtered = array_values(array_filter($existing, function ($t) use ($otherId) {
                    return (int)$t !== (int)$otherId;
                }));
                $newTags = !empty($filtered) ? json_encode($filtered) : null;

                $upStmt->bind_param("sii", $newTags, $discussionId, $uId);
                $upStmt->execute();
            }
        }

        $user = fetchEmployee($conn, $currentUserId);
        $notificationText = ($user['name'] ?? 'User ' . $currentUserId) . " has recovered your discussion id " . $discussionId . ".";

        $notif_sql = "
                        INSERT INTO notifications 
                        (employee_id, body, title, type, created_by) 
                        VALUES ($targetUserId, '$notificationText', 'Discussion Recovery', 'recovery', $currentUserId)
                    ";
        $notifStmt = $conn->prepare($notif_sql);
        if (!$notifStmt->execute()) {
            sendJsonResponse('error', null, "Failed to send notification.");
        }

        sendJsonResponse('success', ['encrypted_key' => $encryptedKey], "Participant key recovered successfully.");
        break;

    case 'request_bulk_recovery':
        $targetUserId = (int)($_POST['target_user_id'] ?? $requestData['target_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            sendJsonResponse('error', null, "Valid target_user_id is required.");
        }

        $user = fetchEmployee($conn, $currentUserId);
        $userName = $user['name'] ?? ('User ' . $currentUserId);

        $notificationText = "$userName has requested a bulk E2EE key recovery for shared discussion(s).";
        $notifSql = "INSERT INTO notifications (employee_id, body, title, type, created_by) VALUES (?, ?, 'Discussion Bulk Recovery Request', 'discussion_bulk_request', ?)";
        $notifStmt = $conn->prepare($notifSql);
        $notifId = 0;
        if ($notifStmt) {
            $notifStmt->bind_param("isi", $targetUserId, $notificationText, $currentUserId);
            if ($notifStmt->execute()) {
                $notifId = $notifStmt->insert_id;
            }
        }

        sendJsonResponse('success', ['request_id' => $notifId], "Bulk recovery request sent successfully.");
        break;

    case 'bulk_recover_keys':
        $requesterId = (int)($_POST['requester_id'] ?? $requestData['requester_id'] ?? 0);
        $recoveries = $_POST['recoveries'] ?? $requestData['recoveries'] ?? [];

        if ($requesterId <= 0 || empty($recoveries) || !is_array($recoveries)) {
            sendJsonResponse('error', null, "Valid requester_id and recoveries array are required.");
        }

        $conn->begin_transaction();
        $recoveredCount = 0;

        try {
            $updateKeyStmt = $conn->prepare("INSERT INTO discussion_participants (discussion_id, user_id, role, encrypted_key, tags) VALUES (?, ?, 'participant', ?, NULL) ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key), tags = NULL");

            foreach ($recoveries as $rec) {
                $discId = (int)($rec['discussion_id'] ?? 0);
                $encKey = $rec['encrypted_key'] ?? null;

                if ($discId > 0 && !empty($encKey)) {
                    $updateKeyStmt->bind_param("iis", $discId, $requesterId, $encKey);
                    if ($updateKeyStmt->execute()) {
                        $recoveredCount++;
                    }
                }
            }

            // Create notification for requester
            $helper = fetchEmployee($conn, $currentUserId);
            $helperName = $helper['name'] ?? ('User ' . $currentUserId);
            $notifText = "$helperName has recovered your E2EE keys for $recoveredCount discussion(s).";

            $notifStmt = $conn->prepare("INSERT INTO notifications (employee_id, body, title, type, created_by) VALUES (?, ?, 'Discussions Recovered', 'discussion_bulk_recovered', ?)");
            if ($notifStmt) {
                $notifStmt->bind_param("isi", $requesterId, $notifText, $currentUserId);
                $notifStmt->execute();
            }

            $conn->commit();
            sendJsonResponse('success', [
                'recovered_count' => $recoveredCount
            ], "Bulk discussion keys recovered successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse('error', null, "Failed to recover bulk keys: " . $e->getMessage());
        }
        break;

    default:
        sendJsonResponse('error', null, "Invalid action requested.");
        break;
}
