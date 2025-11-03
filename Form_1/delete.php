<?php
// Start session
session_start();

// Check if user is authenticated (either regular user or super admin)
$is_super_admin = isset($_SESSION["SuperAdminName"]);
$is_regular_user = isset($_SESSION["user_name"]) && isset($_SESSION["user_id"]);

if (!$is_super_admin && !$is_regular_user) {
    header("Location: ../index.php");
    exit();
}

// Use consistent configuration file
include "../configuration/configuration.php";

// Include notifications system
require_once "../notifications/notifications.php";

// Get and validate input parameters
if (!isset($_POST['id']) && !isset($_GET['id'])) {
    die("Error: Missing or empty ID parameter");
}

if (!isset($_POST['table']) && !isset($_GET['table'])) {
    die("Error: Missing table parameter");
}

$id = isset($_POST['id']) ? $_POST['id'] : $_GET['id'];
$table = isset($_POST['table']) ? $_POST['table'] : $_GET['table'];

// Validate ID
if (empty($id) || !is_numeric($id)) {
    die("Error: Invalid ID parameter");
}

// Validate table
if (empty($table)) {
    die("Error: Invalid table parameter");
}

// Database connection
$conn = new mysqli($servername, $username, $password, $database);

// Check for connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Define allowed tables based on user type
if ($is_super_admin) {
    $allowed_tables = ['form1', 'semesters', 'faculties1', 'users1'];
} else {
    // Regular users can only delete from form1 table
    $allowed_tables = ['form1'];
}

if (!in_array($table, $allowed_tables)) {
    $conn->close();
    die("Invalid table name: $table");
}

// Determine the primary key column based on the table name
$primary_key = '';
switch ($table) {
    case 'form1':
        $primary_key = 'submission_number';
        break;
    case 'semesters':
        $primary_key = 'id';
        break;
    case 'faculties1':
        $primary_key = 'faculty_id';
        break;
    case 'users1':
        $primary_key = 'user_id';
        break;
    default:
        $conn->close();
        die("Invalid table name in switch: $table");
}

// Store form details for notifications (before deletion)
$form_details = null;
if ($table === 'form1') {
    $details_sql = "SELECT f.*, u.fullName as user_fullname, u.user_name, u.faculty_id as user_faculty_id, u.email as user_email
                    FROM form1 f 
                    JOIN users1 u ON f.user_id = u.user_id
                    WHERE f.{$primary_key} = ?";
    $details_stmt = $conn->prepare($details_sql);
    if ($details_stmt) {
        $details_stmt->bind_param("i", $id);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();
        $form_details = $details_result->fetch_assoc();
        $details_stmt->close();
    }
}

// For regular users, add additional validation for form1 table
if ($is_regular_user && $table === 'form1') {
    // Check if the record belongs to the current user (no status restriction)
    $check_sql = "SELECT user_id FROM form1 WHERE $primary_key = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        $conn->close();
        die("Prepare failed for ownership check query: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $record = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if (!$record) {
        $conn->close();
        die("Record not found with ID: $id");
    }
    
    // Check if the record belongs to the current user
    if ($record['user_id'] != $_SESSION['user_id']) {
        $conn->close();
        die("Access denied: You can only delete your own applications");
    }
    
    // Users can now delete applications regardless of approval status
}

// Perform the deletion
$sql = "DELETE FROM $table WHERE $primary_key = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    $conn->close();
    die("Prepare failed for delete query: " . $conn->error);
}

$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $affected_rows = $stmt->affected_rows;
    if ($affected_rows > 0) {
        // Send notifications for form1 deletion
        if ($table === 'form1' && $form_details && $is_regular_user) {
            // Send smart deletion notifications only to admins who have seen the form
            sendSmartFormDeletionNotifications(
                $conn, 
                $id, 
                $form_details['user_id'], 
                $form_details['user_faculty_id'], 
                $form_details['user_fullname'], 
                $form_details['input'],
                $form_details // Pass full form details for status checking
            );
        }
        
        $stmt->close();
        $conn->close();
        
        // Clear any output buffer to ensure clean response
        ob_clean();
        
        // Set proper headers for AJAX response
        header('Content-Type: text/plain');
        header('Cache-Control: no-cache');
        
        // Return consistent success message
        echo "SUCCESS: Application deleted successfully";
        exit();
    } else {
        $stmt->close();
        $conn->close();
        
        // Clear output buffer and return error
        ob_clean();
        header('Content-Type: text/plain');
        echo "ERROR: No rows were deleted. Record may not exist.";
        exit();
    }
} else {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    
    // Clear output buffer and return error
    ob_clean();
    header('Content-Type: text/plain');
    echo "ERROR: Delete failed - " . $error;
    exit();
}
?>