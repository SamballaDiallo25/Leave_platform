<?php
// Debug/debug_session.php
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if session data is available
if (!isset($_SESSION)) {
    echo "No session data available.";
    exit;
}

// Include navbar.php to access its variables
require_once __DIR__ . "/../navbar.php";

// Output debug information
echo "<h2>Session Debug Information</h2>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n\n";
echo "Session Variables:\n";
echo htmlspecialchars(print_r($_SESSION, true));
echo "\n\nSpecific Session Variables:\n";
echo "AdminRole: " . (isset($_SESSION['AdminRole']) ? $_SESSION['AdminRole'] : 'unset') . "\n";
echo "SRole: " . (isset($_SESSION['SRole']) ? $_SESSION['SRole'] : 'unset') . "\n";
echo "Role: " . (isset($_SESSION['Role']) ? $_SESSION['Role'] : 'unset') . "\n";
echo "is_switched_user: " . (isset($_SESSION['is_switched_user']) ? ($_SESSION['is_switched_user'] ? 'true' : 'false') : 'unset') . "\n";
echo "Admin_name: " . (isset($_SESSION['Admin_name']) ? $_SESSION['Admin_name'] : 'unset') . "\n";
echo "user_name: " . (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'unset') . "\n";
echo "last_activity: " . (isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'unset') . "\n";
echo "\nNavbar.php Variables:\n";
echo "currentUser: " . ($currentUser ?? 'unset') . "\n";
echo "fullName: " . ($fullName ?? 'unset') . "\n";
echo "userDisplayRole: " . ($userDisplayRole ?? 'unset') . "\n";
echo "showNotifications: " . (isset($showNotifications) ? ($showNotifications ? 'true' : 'false') : 'unset') . "\n";
echo "notificationUser: " . ($notificationUser ?? 'unset') . "\n";
echo "userRoles: " . htmlspecialchars(print_r($userRoles ?? [], true)) . "\n";
echo "currentActiveRole: " . ($currentActiveRole ?? 'unset') . "\n";
echo "</pre>";

// Link back to adminDashboard.php
echo '<p><a href="../Dashboard/adminDashboard.php">Back to Admin Dashboard</a></p>';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Debug</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f8f9fa;
        }
        h2 {
            color: #333;
        }
        pre {
            background-color: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
</body>
</html>