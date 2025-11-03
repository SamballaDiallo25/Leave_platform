<?php
session_start();

// Disable error display to prevent HTML in JSON response
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    include(__DIR__ . "/../configuration/configuration.php");
    
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception('Connection failed');
    }

    // Get current user
    $currentUser = '';
    if (isset($_SESSION['Admin_name'])) {
        $currentUser = $_SESSION['Admin_name'];
    } elseif (isset($_SESSION['user_name'])) {
        $currentUser = $_SESSION['user_name'];
    }

    if (empty($currentUser)) {
        throw new Exception('Not authenticated');
    }

    // Get user ID - FIXED: Removed COALESCE wrapper
    $userSql = "SELECT user_id FROM users1 WHERE user_name = ?";
    $stmt = $conn->prepare($userSql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();

    if (!$userData) {
        throw new Exception('User not found');
    }

    $user_id = $userData['user_id'];

    // Get both unread and total notification counts
    $sql = "SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
            FROM notifications 
            WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $countData = $result->fetch_assoc();

    echo json_encode([
        'success' => true, 
        'count' => (int)($countData['unread_count'] ?? 0),
        'total_count' => (int)($countData['total_count'] ?? 0),
        'user_id' => $user_id
    ]);

    $conn->close();
    
} catch (Exception $e) {
    // Always return valid JSON, even on error
    echo json_encode([
        'success' => false,
        'count' => 0,
        'total_count' => 0,
        'error' => $e->getMessage()
    ]);
}
exit;
?>