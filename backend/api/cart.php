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

$headers = getallheaders();
$token = null;
if (isset($headers['Authorization'])) {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
}

if (!isset($_SESSION['token']) || $_SESSION['token'] !== $token) {
    http_response_code(401);
    echo json_encode(["message" => "Unauthorized"]);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["message" => "Unauthorized"]);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($_GET['action'])) {
        http_response_code(400);
        echo json_encode(["message" => "No action specified"]);
        exit();
    }

    switch ($_GET['action']) {
        case 'add':
            $stmt = $db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?");
            $stmt->execute([$user_id, $data->id, $data->quantity, $data->quantity]);
            echo json_encode(["message" => "Item added to cart"]);
            break;

        case 'remove':
            $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$user_id, $data->id]);
            echo json_encode(["message" => "Item removed from cart"]);
            break;

        case 'update':
            $stmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$data->quantity, $user_id, $data->id]);
            echo json_encode(["message" => "Cart updated"]);
            break;

        case 'clear':
            $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(["message" => "Cart cleared"]);
            break;

        case 'checkout':
            // Start transaction
            $db->beginTransaction();

            try {
                // Get cart items
                $stmt = $db->prepare("SELECT product_id, quantity FROM cart_items WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Create order
                $stmt = $db->prepare("INSERT INTO orders (user_id, total_amount) VALUES (?, 0)");
                $stmt->execute([$user_id]);
                $order_id = $db->lastInsertId();

                $total_amount = 0;

                // Process each cart item
                foreach ($cart_items as $item) {
                    // Get product details
                    $stmt = $db->prepare("SELECT price FROM products WHERE id = ?");
                    $stmt->execute([$item['product_id']]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);

                    $item_total = $product['price'] * $item['quantity'];
                    $total_amount += $item_total;

                    // Add to order_items
                    $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $product['price']]);
                }

                // Update order total
                $stmt = $db->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
                $stmt->execute([$total_amount, $order_id]);

                // Clear cart
                $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
                $stmt->execute([$user_id]);

                // Commit transaction
                $db->commit();

                echo json_encode(["message" => "Checkout successful", "order_id" => $order_id]);
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollBack();
                http_response_code(500);
                echo json_encode(["message" => "Checkout failed: " . $e->getMessage()]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid action"]);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(["message" => "Method not allowed"]);
}