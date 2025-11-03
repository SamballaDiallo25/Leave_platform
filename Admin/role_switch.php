<?php
// role_switch.php - Place this file in your /Admin/ directory

session_start();

// Enhanced session timeout check that preserves role switching data
$session_timeout = 1800;
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) >= $session_timeout) {
        // Store important data before clearing session
        $was_switched_user = isset($_SESSION['is_switched_user']);
        $original_role = isset($_SESSION['original_admin_role']) ? $_SESSION['original_admin_role'] : '';
        
        session_unset();
        session_destroy();
        
        // Redirect with appropriate timeout parameter
        if ($was_switched_user) {
            header("Location: ../index.php?timeout=1&switched=1");
        } else {
            header("Location: ../index.php?timeout=1");
        }
        exit();
    }
}
$_SESSION['last_activity'] = time();

// Security check - only allow HOF and Rectorate to switch roles
if (!isset($_SESSION["Admin_name"]) || !in_array($_SESSION["AdminRole"], ["HOF", "Rectorate"])) {
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['switch_to'])) {
    $switch_to = $_GET['switch_to'];
    
    if ($switch_to === 'user') {
        // Store original admin data
        $_SESSION['original_admin_role'] = $_SESSION["AdminRole"];
        $_SESSION['original_admin_name'] = $_SESSION["Admin_name"];
        $_SESSION['original_admin_id'] = $_SESSION["Admin_id"];
        $_SESSION['original_faculty_id'] = $_SESSION["Admin_facultyID"];
        
        // Set user session variables
        $_SESSION["loggedin"] = true;
        $_SESSION["User_name"] = $_SESSION["Admin_name"];
        $_SESSION["User_id"] = $_SESSION["Admin_id"];
        $_SESSION["faculty_id"] = $_SESSION["Admin_facultyID"];
        $_SESSION["is_switched_user"] = true; // Flag to indicate this is a switched user
        
        // Keep admin session active but mark as switched
        // Don't unset admin variables, just add user ones
        
        // Redirect to user dashboard
        header("Location: /Dashboard/userDashboard.php");
        exit();
        
    } elseif ($switch_to === 'admin' && isset($_SESSION['is_switched_user'])) {
        // Switch back to admin
        unset($_SESSION["loggedin"]);
        unset($_SESSION["User_name"]);
        unset($_SESSION["User_id"]);
        unset($_SESSION["faculty_id"]);
        unset($_SESSION["is_switched_user"]);
        
        // Clean up temporary variables
        unset($_SESSION['original_admin_role']);
        unset($_SESSION['original_admin_name']);
        unset($_SESSION['original_admin_id']);
        unset($_SESSION['original_faculty_id']);
        
        // Admin session variables should still be intact
        // Redirect back to admin dashboard
        header("Location: ../adminDashboard.php");
        exit();
    }
}

// If no valid switch parameter, redirect back
header("Location: ../adminDashboard.php");
exit();
?>