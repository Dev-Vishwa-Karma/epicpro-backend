<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}


include "db_connection.php";
include 'auth_validate.php';
require_once 'helpers.php';

$action = $_GET['action'] ?? "";
switch ($action) {
    case "create-chat":
        $sender_id = $_POST['sender_id'];
        $receiver_id = $_POST['receiver_id'];
        $participants = [
            (int) $sender_id,
            (int) $receiver_id
        ];
        sort($participants);
        $stmt = $conn->prepare("SELECT * FROM chat WHERE chat_type='direct'");
        $stmt->execute();
        $result = $stmt->get_result();
        $chat_id = null;
        while ($row = $result->fetch_assoc()) {
            $old = json_decode($row['participants'], true);
            sort($old);
            if ($old == $participants) {
                $chat_id = $row['id'];
                break;
            }
        }
        if (!$chat_id) {
            $json = json_encode($participants);
            $stmt = $conn->prepare("INSERT INTO chat ( chat_type, participants, pin ) VALUES ( 'direct', ?, '[]' ) ");
            $stmt->bind_param("s", $json);
            $stmt->execute();
            $chat_id = $conn->insert_id;
        }
        $stmt_chat = $conn->prepare("SELECT * FROM chat WHERE id=?");
        $stmt_chat->bind_param("i", $chat_id);
        $stmt_chat->execute();
        $chat = $stmt_chat->get_result()->fetch_assoc();
        sendJsonResponse("success", $chat, "Chat created");
        break;

    case "chat-list":
        $user_id = $_GET['user_id'];
        $stmt = $conn->prepare("SELECT * FROM chat WHERE JSON_CONTAINS(participants, ? ) ORDER BY id DESC ");
        $user_json = json_encode((int) $user_id);
        $stmt->bind_param("s", $user_json);
        $stmt->execute();
        $result = $stmt->get_result();
        $chats = [];
        while ($row = $result->fetch_assoc()) {
            $participants = json_decode($row['participants'], true);
            $other_user = null;
            foreach ($participants as $id) {
                if ($id != $user_id) {
                    $other_user = $id;
                }

            }
            $name = "";
            $profile = null;
            /*
            Direct chat name
            */
            if ($row['chat_type'] == "direct") {
                $stmt2 = $conn->prepare(" SELECT id, first_name, last_name, profile, CONCAT(first_name,' ',last_name) AS name, profile FROM employees WHERE id=? ");
                $stmt2->bind_param("i", $other_user);
                $stmt2->execute();
                $user = $stmt2->get_result()->fetch_assoc();
                $name = $user['name'];
                $profile = $user['profile'];
            } else {
                $name = $row['title'];
            }

            /*
            Check pin
            */
            $is_pinned = false;
            if (!empty($row['pin'])) {
                $pin = json_decode($row['pin'], true);
                if (in_array($user_id, $pin)) {
                    $is_pinned = true;
                }
            }

            $chatItem = [
                "chat_id" => $row['id'],
                "name" => $name,
                "profile" => $profile,
                "chat_type" => $row['chat_type'],
                "is_pinned" => $is_pinned
            ];
            if ($row['chat_type'] == "direct" && isset($user)) {
                $chatItem['id'] = $user['id'];
                $chatItem['first_name'] = $user['first_name'];
                $chatItem['last_name'] = $user['last_name'];
                $chatItem['profile'] = $user['profile'];
            }

            $chats[] = $chatItem;
        }

        usort(
            $chats,
            function ($a, $b) {
                return $b['is_pinned'] <=> $a['is_pinned'];
            }
        );
        sendJsonResponse("success", $chats);
        break;

    case "pin-chat":
        $chat_id = $_POST['chat_id'];
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("SELECT pin FROM chat WHERE id=?");
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $pin = [];
        if (!empty($row['pin'])) {
            $pin = json_decode(
                $row['pin'],
                true
            );
        }

        if (!in_array($user_id, $pin)) {
            $pin[] = (int) $user_id;
        }

        $json = json_encode($pin);
        $stmt = $conn->prepare("UPDATE chat SET pin=? WHERE id=?");
        $stmt->bind_param("si", $json, $chat_id);
        $stmt->execute();
        sendJsonResponse("success", null, "Chat pinned");
        break;

    case "unpin-chat":
        $chat_id = $_POST['chat_id'];
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("SELECT pin FROM chat WHERE id=?");
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $pin = [];
        if (!empty($row['pin'])) {
            $pin = json_decode($row['pin'], true);
        }

        $pin = array_values(
            array_filter(
                $pin,
                function ($id) use ($user_id) {
                    return $id != $user_id;
                }
            )
        );

        $json = json_encode($pin);
        $stmt = $conn->prepare("UPDATE chat SET pin=? WHERE id=?");
        $stmt->bind_param("si", $json, $chat_id);
        $stmt->execute();
        sendJsonResponse("success", null, "Chat unpinned");
        break;

    default:
        sendJsonResponse("error", null, "Invalid action");
}
?>