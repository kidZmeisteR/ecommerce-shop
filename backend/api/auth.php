<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost:4200");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    if (!$data || !isset($data->action)) {
        http_response_code(400);
        echo json_encode(["message" => "Invalid request"]);
        exit;
    }

    if (isset($data->action)) {
        switch ($data->action) {
            case 'register':
                // Check if username already exists
                $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $check_stmt->execute([$data->username]);
                if ($check_stmt->fetch()) {
                    http_response_code(409); // Conflict
                    echo json_encode(["message" => "Username already exists"]);
                } else {
                    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
                    if ($stmt->execute([$data->username, $hashed_password])) {
                        echo json_encode(["message" => "User registered successfully"]);
                    } else {
                        http_response_code(500);
                        echo json_encode(["message" => "Unable to register user"]);
                    }
                }
                break;

            case 'login':
                $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
                $stmt->execute([$data->username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($data->password, $user['password'])) {
                    $token = bin2hex(random_bytes(32)); // Generate a random token
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['token'] = $token;
                    echo json_encode([
                        "message" => "Login successful",
                        "user_id" => $user['id'],
                        "username" => $user['username'],
                        "token" => $token
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode(["message" => "Invalid credentials"]);
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(["message" => "Invalid action"]);
                break;
        }
    } else {
        http_response_code(400);
        echo json_encode(["message" => "No action specified"]);
    }
} else {
    http_response_code(405);
    echo json_encode(["message" => "Method not allowed"]);
}
