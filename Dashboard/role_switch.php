<?php
// Updated role_switch.php - Fixed status column issue
session_start();

// Include database configuration
include_once("../configuration/configuration.php");

// Session timeout check
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) >= $session_timeout) {
    // Preserve switch state for recovery
    $recovery_data = [
        'was_switched' => isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user'],
        'original_role' => $_SESSION['original_session_data']['current_role'] ?? '',
        'timestamp' => time()
    ];
    session_unset();
    session_destroy();
    header("Location: ../index.php?timeout=1&recovery=" . base64_encode(json_encode($recovery_data)));
    exit();
}
$_SESSION['last_activity'] = time();

// Enhanced database connection
try {
    $conn = new mysqli($servername, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8");
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $_SESSION['error_message'] = "System temporarily unavailable. Please try again later.";
    header("Location: ../index.php");
    exit();
}

// Function to log role switches
function logRoleSwitch($conn, $username, $action, $from_role, $to_role) {
    $create_table = "CREATE TABLE IF NOT EXISTS role_switch_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100),
        action_type VARCHAR(50),
        from_role VARCHAR(50),
        to_role VARCHAR(50),
        switch_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45)
    )";
    $conn->query($create_table);
    
    $log_sql = "INSERT INTO role_switch_log (username, action_type, from_role, to_role, ip_address) 
                VALUES (?, ?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $log_stmt->bind_param("sssss", $username, $action, $from_role, $to_role, $ip_address);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

// Function to get user's available roles
function getUserRoles($conn, $username) {
    $roles = [];
    $check_table = "SHOW TABLES LIKE 'user_roles'";
    $result = $conn->query($check_table);
    
    if ($result && $result->num_rows > 0) {
        $sql = "SELECT ur.role_name, ur.is_primary 
                FROM user_roles ur 
                JOIN users1 u ON ur.user_id = u.user_id 
                WHERE u.user_name = ? 
                ORDER BY ur.is_primary DESC, ur.role_name";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row['role_name'];
            }
            $stmt->close();
        }
    } else {
        $sql = "SELECT Role FROM users1 WHERE user_name = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $roles[] = $row['Role'];
            }
            $stmt->close();
        }
    }
    return $roles;
}

// Function to check if role is admin-level
function isAdminRole($role) {
    return in_array($role, ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate', 'Admin', 'SuperAdmin']);
}

// Function to determine dashboard URL based on role
function getDashboardUrl($role) {
    if ($role === 'SuperAdmin') {
        return "../Dashboard/superAdminDashboard.php";
    } elseif (isAdminRole($role)) {
        return "../Dashboard/adminDashboard.php";
    } else {
        return "../Dashboard/userDashboard.php";
    }
}

// Check if user is logged in
if (!isset($_SESSION['Admin_name']) && !isset($_SESSION['user_name'])) {
    $_SESSION['error_message'] = "You must be logged in to perform this action.";
    header("Location: ../index.php");
    exit();
}

// Get current user
$currentUser = $_SESSION['Admin_name'] ?? $_SESSION['user_name'] ?? '';
if (empty($currentUser)) {
    $_SESSION['error_message'] = "Unable to determine current user.";
    header("Location: ../index.php");
    exit();
}

// Get the target role
$target_role = $_GET['switch_to_role'] ?? '';
if (empty($target_role)) {
    $_SESSION['error_message'] = "No target role specified.";
    $redirect = isset($_SESSION['AdminRole']) || isset($_SESSION['SRole']) ? 
                "../Dashboard/adminDashboard.php" : "../Dashboard/userDashboard.php";
    header("Location: $redirect");
    exit();
}

// Get user's available roles
$userRoles = getUserRoles($conn, $currentUser);
if (!in_array($target_role, $userRoles)) {
    $_SESSION['error_message'] = "You don't have access to the role: $target_role";
    logRoleSwitch($conn, $currentUser, 'unauthorized_attempt', 'unknown', $target_role);
    $redirect = isset($_SESSION['AdminRole']) || isset($_SESSION['SRole']) ? 
                "../Dashboard/adminDashboard.php" : "../Dashboard/userDashboard.php";
    header("Location: $redirect");
    exit();
}

// Get current role for logging
$currentRole = $_SESSION['current_active_role'] ?? 
               $_SESSION['AdminRole'] ?? 
               $_SESSION['SRole'] ?? 
               $_SESSION['Role'] ?? 
               'unknown';

// Store original session data before switching
if (!isset($_SESSION['original_session_data'])) {
    $_SESSION['original_session_data'] = [
        'current_role' => $currentRole,
        'user_name' => $currentUser,
        'user_full_name' => $_SESSION['UserFullName'] ?? '',
        'user_id' => $_SESSION['user_id'] ?? null,
        'faculty_id' => $_SESSION['user_facultyID'] ?? null,
        'admin_name' => $_SESSION['Admin_name'] ?? null,
        'admin_role' => $_SESSION['AdminRole'] ?? null,
        'super_admin_role' => $_SESSION['SRole'] ?? null,
        'user_role' => $_SESSION['Role'] ?? null,
        'switch_time' => time(),
        'switch_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
}

// FIXED: Get user details without status column
$sql = "SELECT user_name, fullName, Role, user_id, faculty_id FROM users1 WHERE user_name = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();
if (!($userRow = $result->fetch_assoc())) {
    $stmt->close();
    $conn->close();
    $_SESSION['error_message'] = "User account not found.";
    header("Location: ../index.php");
    exit();
}
$stmt->close();

// Set switch flag
$_SESSION['is_switched_user'] = ($currentRole !== $target_role);

// Clear existing role session variables
unset($_SESSION['AdminRole'], $_SESSION['SRole'], $_SESSION['Role'], $_SESSION['Admin_name'], $_SESSION['Admin_id'], $_SESSION['Admin_facultyID']);

// Set new role sessions
$_SESSION['current_active_role'] = $target_role;
$_SESSION['user_roles'] = $userRoles;
$_SESSION['user_name'] = $userRow['user_name'];
$_SESSION['UserFullName'] = $userRow['fullName'];
$_SESSION['user_id'] = $userRow['user_id'];
$_SESSION['user_facultyID'] = $userRow['faculty_id'];

if (isAdminRole($target_role)) {
    $_SESSION['Admin_name'] = $userRow['user_name'];
    if ($target_role === 'SuperAdmin') {
        $_SESSION['SRole'] = $target_role;
    } else {
        $_SESSION['AdminRole'] = $target_role;
    }
    $_SESSION['Admin_id'] = $userRow['user_id'];
    $_SESSION['Admin_facultyID'] = $userRow['faculty_id'];
} else {
    $_SESSION['Role'] = $target_role;
}

// Log the role switch
logRoleSwitch($conn, $currentUser, 'role_switch', $currentRole, $target_role);
$conn->close();

// Redirect to appropriate dashboard
$redirectUrl = getDashboardUrl($target_role);
$_SESSION['success_message'] = "Successfully switched to role: $target_role";
header("Location: $redirectUrl");
exit();
?>