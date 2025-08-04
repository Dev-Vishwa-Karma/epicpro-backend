<?php

$headers = getallheaders();
$auth = $headers['Authorization'] ?? null;
if ($auth) {
    $token_info = decode_token($auth);
    if (is_array($token_info) && isset($token_info[3])) {
        if (isUserDeleted($token_info[0])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'This account has been deleted']);
            exit;
        }


        $dt = new DateTime();
        if ($dt->format('Y-m-d H:i:s') > $token_info[3]) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Token has been expired']); 
            exit;
        }
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
        exit;
    }
} else {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token not found']);
    exit;
}

function decode_token($auth) {
    $authorization = explode(' ', $auth);
    if ($authorization[0] == 'Bearer' && isset($authorization[1])) {
        return explode('|', base64_decode($authorization[1], true));
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Token type invalid']);
        exit;
    }
}

function isAdmin()
{
    if(!isAdminCheck()){
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => "You don't have permission to access this"]);
        exit;
    }else{
        return true;
    }
}

function isAdminCheck()
{
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? null;
    $token_info = decode_token($auth);
    if (is_array($token_info) && isset($token_info[2])) {
        if (in_array($token_info[2], ['super_admin', 'admin'])) {
            return true;
        }
    }
    return false;
}

function isUserDeleted($userId) {
    global $conn;  // Assuming $conn is your database connection
    
    // Query to check if the user is deleted
    $stmt = $conn->prepare("SELECT deleted_at FROM employees WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Check if the user is marked as deleted
        return !empty($user['deleted_at']);
    }
    
    // If no user found, assume the user is deleted
    return true;
}
