<?php
session_start();
// Now safe to include files that might output content
require_once "../lang.php";
require_once "../navbar.php";
// include_once "../index.php";
// Update last dashboard preference when user accesses a dashboard
// if (isset($_SESSION['user_id'])) {
//     $current_dashboard = basename($_SERVER['PHP_SELF']) == 'adminDashboard.php' ? 'admin' : 'user';
//     updateLastDashboard($conn, $_SESSION['user_id'], $current_dashboard);
// }

// Check if user is logged in
if (!isset($_SESSION["user_name"]) && !isset($_SESSION["Admin_name"])) {
    header("Location: ../index.php");
    exit();
}

// Ensure we have a valid admin role
// if (empty($admin_role)) {
//     header("Location: ../Dashboard/userDashboard.php");
//     exit();
// }

// REPLACE the existing role checking section with:
$admin_role = ''; 
$faculty_id = 0;
$admin_user_id = 0;

// Use the new role system from role_switch.php
if (isset($_SESSION['current_active_role'])) {
    $current_role = $_SESSION['current_active_role'];
    
    // Check if current role is admin-level
    $adminRoles = ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate', 'Admin', 'SuperAdmin'];
    
    if (!in_array($current_role, $adminRoles)) {
        // User has a non-admin role, redirect to user dashboard
        header("Location: ../Dashboard/userDashboard.php");
        exit();
    }
    
    $admin_role = $current_role;
    $faculty_id = isset($_SESSION["user_facultyID"]) ? $_SESSION["user_facultyID"] : 0;
    $admin_user_id = isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : 0;
} else {
    // Fallback to legacy system
    if (isset($_SESSION["AdminRole"])) {
        $admin_role = $_SESSION["AdminRole"];
        $faculty_id = isset($_SESSION["Admin_facultyID"]) ? $_SESSION["Admin_facultyID"] : 0;
        $admin_user_id = isset($_SESSION["Admin_id"]) ? $_SESSION["Admin_id"] : 0;
    } elseif (isset($_SESSION["SRole"])) {
        $admin_role = $_SESSION["SRole"];
        $faculty_id = isset($_SESSION["user_facultyID"]) ? $_SESSION["user_facultyID"] : 0;
        $admin_user_id = isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : 0;
    } else {
        // No admin role found, redirect to user dashboard
        header("Location: ../Dashboard/userDashboard.php");
        exit();
    }
}

// Move POST handling BEFORE any includes or output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['form_id'])) {
    require_once "../configuration/configuration.php";
    require_once "../notifications/notifications.php";
    
    $conn = new mysqli($servername, $username, $password, $database);

    $form_id = intval($_POST['form_id']);
    $action = $_POST['action'];
    $rejection_reason = isset($_POST['rejection_reason']) ? htmlspecialchars(trim($_POST['rejection_reason']), ENT_QUOTES, 'UTF-8') : null;
    $form_details = getFormDetails($conn, $form_id);
    
    if ($form_details) {
        $old_status = '';
        $new_status = '';
        $faculty_id = $form_details['user_faculty_id'];
        
        if ($admin_role == "HOF" && in_array($action, ['approve', 'reject'])) {
            $old_status = $form_details['Department'];
            $new_status = ($action == 'approve') ? 'Approved' : 'Rejected';
            $session_faculty_id = isset($_SESSION["Admin_facultyID"]) ? $_SESSION["Admin_facultyID"] : 0;
            $stmt = $conn->prepare("UPDATE form1 SET Department = ? WHERE submission_number = ? AND faculty_id = ?");
            $stmt->bind_param("sii", $new_status, $form_id, $session_faculty_id);
            $stmt->execute();
            $stmt->close();
            sendStatusUpdateNotifications($conn, $form_id, $admin_role, $old_status, $new_status, $faculty_id, $rejection_reason);
        } elseif ($admin_role == "HumanResource" && in_array($action, ['approve', 'reject'])) {
            $old_status = $form_details['HumanResource'];
            $new_status = ($action == 'approve') ? 'Approved' : 'Rejected';
            $stmt = $conn->prepare("UPDATE form1 SET HumanResource = ? WHERE submission_number = ? AND Department = 'Approved'");
            $stmt->bind_param("si", $new_status, $form_id);
            $stmt->execute();
            $stmt->close();
            sendStatusUpdateNotifications($conn, $form_id, $admin_role, $old_status, $new_status, $faculty_id, $rejection_reason);
        } elseif ($admin_role == "Rectorate" && in_array($action, ['approve', 'reject'])) {
            $old_status = $form_details['Rectorate'];
            $new_status = ($action == 'approve') ? 'Approved' : 'Rejected';
            $stmt = $conn->prepare("UPDATE form1 SET Rectorate = ? WHERE submission_number = ? AND Department = 'Approved' AND HumanResource = 'Approved'");
            $stmt->bind_param("si", $new_status, $form_id);
            $stmt->execute();
            $stmt->close();
            sendStatusUpdateNotifications($conn, $form_id, $admin_role, $old_status, $new_status, $faculty_id, $rejection_reason);
        } elseif ($action === 'change_state' && isset($_POST['new_state'])) {
            $new_state = $_POST['new_state'];
            $rejection_reason = isset($_POST['rejection_reason']) ? htmlspecialchars(trim($_POST['rejection_reason']), ENT_QUOTES, 'UTF-8') : null;
            if ($admin_role == "HOF") {
                $old_status = $form_details['Department'];
                $session_faculty_id = isset($_SESSION["Admin_facultyID"]) ? $_SESSION["Admin_facultyID"] : 0;
                $stmt = $conn->prepare("UPDATE form1 SET Department = ? WHERE submission_number = ? AND faculty_id = ?");
                if (!$stmt) {
                    error_log("HOF prepare error: " . $conn->error);
                    $_SESSION['error_message'] = __("Error preparing query: ") . $conn->error;
                    $conn->close();
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                $stmt->bind_param("sii", $new_state, $form_id, $session_faculty_id);
            } elseif ($admin_role == "HumanResource") {
                $old_status = $form_details['HumanResource'];
                $stmt = $conn->prepare("UPDATE form1 SET HumanResource = ? WHERE submission_number = ? AND Department = 'Approved'");
                if (!$stmt) {
                    error_log("HR prepare error: " . $conn->error);
                    $_SESSION['error_message'] = __("Error preparing query: ") . $conn->error;
                    $conn->close();
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                $stmt->bind_param("si", $new_state, $form_id);
            } elseif ($admin_role == "Rectorate") {
                $old_status = $form_details['Rectorate'];
                $stmt = $conn->prepare("UPDATE form1 SET Rectorate = ? WHERE submission_number = ? AND Department = 'Approved' AND HumanResource = 'Approved'");
                if (!$stmt) {
                    error_log("Rectorate prepare error: " . $conn->error);
                    $_SESSION['error_message'] = __("Error preparing query: ") . $conn->error;
                    $conn->close();
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
                $stmt->bind_param("si", $new_state, $form_id);
            }
            if ($stmt->execute()) {
                $stmt->close();
                sendStatusUpdateNotifications($conn, $form_id, $admin_role, $old_status, $new_state, $faculty_id, $rejection_reason);
                $_SESSION['success_message_temp'] = __("Status updated successfully and notifications sent.");
            } else {
                error_log("Update error: " . $stmt->error);
                $_SESSION['error_message_temp'] = __("Error updating request: ") . $stmt->error;
                $stmt->close();
            }
        }

        // Set success/error message to be displayed after reload
        if ($action == 'approve') {
            $_SESSION['success_message_temp'] = __("Request approved successfully and notifications sent.");
        } elseif ($action == 'reject') {
            $_SESSION['success_message_temp'] = __("Request rejected successfully and notifications sent.");
        } elseif ($action == 'change_state') {
            $_SESSION['success_message_temp'] = __("Status updated successfully.");
        }
        
        // Trigger reload to update UI
        // $_SESSION['reload_flag'] = true; // Set flag to indicate reload is needed
        // $conn->close();
        // header("Location: " . $_SERVER['PHP_SELF']);
        // exit();
    } else {
        $_SESSION['error_message_temp'] = __("Error: Form details not found.");
        $conn->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}


// Create connection and fetch forms (unchanged from your code)
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sqlFetchForms = "";
if ($admin_role == "HOF") {
    $sqlFetchForms = "
        SELECT  
            SUM(CASE WHEN Department = 'Approved' THEN 1 ELSE 0 END) AS total_approved_count,
            SUM(CASE WHEN Department = 'Rejected' THEN 1 ELSE 0 END) AS total_rejected_count,
            SUM(CASE WHEN Department = 'Pending' THEN 1 ELSE 0 END) AS total_pending_count
        FROM form1
        WHERE faculty_id = ?";
} elseif ($admin_role == "HumanResource") {
    $sqlFetchForms = "
        SELECT  
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Approved' THEN 1 ELSE 0 END) AS hr_approved_count,
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Rejected' THEN 1 ELSE 0 END) AS hr_rejected_count,
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Pending' THEN 1 ELSE 0 END) AS hr_pending_count
        FROM form1
        WHERE Department = 'Approved'";
} elseif ($admin_role == "Rectorate") {
    $sqlFetchForms = "
        SELECT  
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Approved' AND Rectorate = 'Approved' THEN 1 ELSE 0 END) AS rectorate_approved_count,
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Approved' AND Rectorate = 'Rejected' THEN 1 ELSE 0 END) AS rectorate_rejected_count,
            SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Approved' AND Rectorate = 'Pending' THEN 1 ELSE 0 END) AS rectorate_pending_count
        FROM form1
        WHERE Department = 'Approved' AND HumanResource = 'Approved'";
} else {
    die("Error: Unknown admin role.");
}

if (empty($sqlFetchForms)) {
    die("Error: SQL query is empty. Admin role: " . htmlspecialchars($admin_role));
}

$stmtFetchForms = $conn->prepare($sqlFetchForms);
if (!$stmtFetchForms) {
    die("Error preparing SQL statement: " . $conn->error);
}

if ($admin_role == "HOF") {
    $stmtFetchForms->bind_param("i", $faculty_id);
    $stmtFetchForms->execute();
    $stmtFetchForms->bind_result($totalApprovedCount, $totalRejectedCount, $totalPendingCount);
} elseif ($admin_role == "HumanResource") {
    $stmtFetchForms->execute();
    $stmtFetchForms->bind_result($hr_approved_count, $hr_rejected_count, $hr_pending_count);
} elseif ($admin_role == "Rectorate") {
    $stmtFetchForms->execute();
    $stmtFetchForms->bind_result($rectorate_approved_count, $rectorate_rejected_count, $rectorate_pending_count);
}

$stmtFetchForms->fetch();
$stmtFetchForms->close();

// Fetch pending requests
$pendingRequests = [];
if ($admin_role == "HOF") {
    $stmt = $conn->prepare("SELECT submission_number, FullName, RequestTest, PermitStartDate, LeaveExpiryDate, submission_date, phone, passport_no, Address, unit, MakeUpDays, input, semester, Department, HumanResource, Rectorate FROM form1 WHERE faculty_id = ? ORDER BY submission_number DESC");
    $stmt->bind_param("i", $faculty_id);
} elseif ($admin_role == "HumanResource") {
    $stmt = $conn->prepare("SELECT submission_number, FullName, RequestTest, PermitStartDate, LeaveExpiryDate, submission_date, phone, passport_no, Address, unit, MakeUpDays, input, semester, Department, HumanResource, Rectorate FROM form1 WHERE Department = 'Approved' ORDER BY submission_number DESC");
} elseif ($admin_role == "Rectorate") {
    $stmt = $conn->prepare("SELECT submission_number, FullName, RequestTest, PermitStartDate, LeaveExpiryDate, submission_date, phone, passport_no, Address, unit, MakeUpDays, input, semester, Department, HumanResource, Rectorate FROM form1 WHERE Department = 'Approved' AND HumanResource = 'Approved' ORDER BY submission_number DESC");
}

if (isset($stmt)) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pendingRequests[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($translator->getCurrentLanguage()); ?>" dir="<?php echo htmlspecialchars($translator->getTextDirection()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&display=swap">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <title>Dashboard</title>

    <style>
        
        
       /* Desktop layout (screens > 1200px) */
@media (min-width: 1200px) {
    .sidebar, #sidebar.sidebar {
        width: 300px !important;
        min-width: 300px !important;
        max-width: 300px !important;
    }
    header.header {
        margin-left: 0px !important;
        width: calc(100% - 300px) !important;
    }
    .main-content {
        margin-left: 300px !important;
        width: calc(100% - 300px) !important;
    }
}

/* Mobile layout (screens <= 1200px) */
@media (max-width: 1200px) {
    .sidebar, #sidebar.sidebar {
        left: -300px; /* Hidden by default */
        width: 280px !important;
        min-width: 280px !important;
        max-width: 280px !important;
    }
    header.header {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        padding-top: 80px; /* Account for fixed header */
    }
}


        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .btn-approve {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-reject {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-update {
            background-color: #ffc107;
            color: black;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-approve:hover {
            background-color: #218838;
        }
        .btn-reject:hover {
            background-color: #c82333;
        }
        .btn-update:hover {
            background-color: #e0a800;
        }

        /* Mobile-friendly action buttons */
@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
        gap: 8px;
        align-items: stretch;
    }
    
    .btn-approve, .btn-reject, .btn-update, .btn-send {
        width: 100%;
        min-height: 44px; /* Touch-friendly size */
        font-size: 16px;
        padding: 10px 15px;
    }
    
    .action-buttons form {
        width: 100%;
    }
    
    /* Stack rejection reason and submit button */
    .rejection-reason {
        width: 100%;
        margin-top: 10px;
    }
    
    .rejection-reason textarea {
        min-height: 80px;
        font-size: 16px; /* Prevents zoom on iOS */
    }
}

        .request-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: #f8f9fa;
        }

        /* Mobile request cards */
@media (max-width: 768px) {
    .request-card {
        padding: 15px 10px;
        margin-bottom: 20px;
    }
    
    .request-card .col-md-6 {
        margin-bottom: 15px;
    }
    
    .request-card .text-end {
        text-align: left !important;
    }
    
    /* Make collapsible details full width */
    .request-card .collapse .row {
        margin: 0;
    }
    
    .request-card .collapse .col-md-6 {
        padding: 5px 0;
        margin-bottom: 10px;
    }
}

/* Very small screens */
@media (max-width: 576px) {
    .container {
        padding-left: 10px;
        padding-right: 10px;
    }
    
    .card {
        margin: 10px 5px;
    }
    
    .card-body {
        padding: 15px;
    }
}

        .pending-requests {
            margin-top: 30px;
        }
        .dash-widget {
            padding: 20px;
            margin-bottom: 20px;
            background: white;
            border-radius: 8px;
        }
        .dash-widgetimg {
            text-align: center;
            margin-bottom: 15px;
        }
        .dash-widgetimg i {
            font-size: 2rem;
            color: #6c757d;
        }
        .dash-widgetcontent h5 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: bold;
        }
        .dash-widgetcontent h6 {
            margin: 5px 0 0 0;
            color: #6c757d;
        }
        .dash4 .dash-widgetimg i { color: #ffc107; }
        .dash3 .dash-widgetimg i { color: #28a745; }
        .dash2 .dash-widgetimg i { color: #dc3545; }
        .update-locked-message {
            color: #dc3545;
            font-weight: bold;
            font-size: 0.9rem;
            margin-top: 10px;
        }

        /* Mobile dashboard widgets */
@media (max-width: 768px) {
    .dash-widget {
        margin-bottom: 15px;
        padding: 15px;
    }
    
    .dash-widgetcontent h5 {
        font-size: 1.25rem;
    }
    
    .dash-widgetcontent h6 {
        font-size: 0.875rem;
    }
    
    .dash-widgetimg i {
        font-size: 1.5rem;
    }
}

/* Stack dashboard cards vertically on small screens */
@media (max-width: 576px) {
    .col-lg-4.col-sm-6 {
        margin-bottom: 15px;
    }
}

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        .btn-send {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-send:hover {
            background-color: #0056b3;
        }
        .rejection-reason {
            margin-top: 10px;
            display: none;
        }
        .rejection-reason textarea {
            width: 100%;
            min-height: 60px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
            font-size: 0.9rem;
        }
        .status-progression {
            display: flex;
            align-items: center;
            margin: 10px 0;
            font-size: 0.9rem;
        }
        .status-step {
            display: flex;
            align-items: center;
            margin-right: 15px;
        }
        .status-arrow {
            margin: 0 8px;
            color: #6c757d;
        }
        /* Toast Notification Styles */
      .toast {
    position: fixed;
    top: 75px;
    right: 20px;
    left: 20px; /* Add this for mobile */
    min-width: 300px;
    max-width: calc(100vw - 40px); /* Add this for mobile */
    background-color: rgba(6, 183, 47, 0.9);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    border-radius: 15px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    z-index: 1000;
    padding: 15px;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Mobile toast positioning */
@media (max-width: 768px) {
    .toast {
        top: 80px;
        right: 10px;
        left: 10px;
        min-width: auto;
        max-width: calc(100vw - 20px);
        padding: 12px;
    }
    
    .toast-header {
        font-size: 14px;
    }
    
    .toast-body {
        font-size: 14px;
    }
}
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast.error {
            background-color: rgba(220, 53, 69, 0.9); /* Transparent red */
        }
        .toast-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .toast-header strong {
            font-size: 1rem;
        }
        .toast-header .btn-close {
            background: none;
            border: none;
            color: white;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .toast-body {
            font-size: 0.9rem;
        }

        /* Mobile form controls */
@media (max-width: 768px) {
    .form-select {
        font-size: 16px; /* Prevents zoom on iOS */
        min-height: 44px;
    }
    
    /* Update status form on mobile */
    .mt-3 .row.align-items-center {
        flex-direction: column;
        align-items: stretch !important;
    }
    
    .mt-3 .col-auto {
        width: 100%;
        margin-bottom: 10px;
        flex: none;
    }
    
    .mt-3 .col-auto label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    
    .mt-3 .form-select {
        width: 100%;
    }
    
    .mt-3 .btn-update {
        width: 100%;
        margin-top: 10px;
    }
}
    </style>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.1/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
// Initialize Bootstrap dropdowns explicitly
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all dropdowns
    var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
    var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
        return new bootstrap.Dropdown(dropdownToggleEl);
    });
    
    // Alternative: Initialize specific dropdown if the above doesn't work
    const roleDropdownTrigger = document.querySelector('[data-bs-toggle="dropdown"]');
    if (roleDropdownTrigger) {
        new bootstrap.Dropdown(roleDropdownTrigger);
    }

        // Set timeout variables
        const TIMEOUT_IN_MINUTES = 30;
        const WARNING_IN_MINUTES = 1;
        const TIMEOUT_IN_MS = TIMEOUT_IN_MINUTES * 60 * 1000;
        const WARNING_IN_MS = WARNING_IN_MINUTES * 60 * 1000;

        // Function to show warning
        function showTimeoutWarning() {
            if (confirm('Your session will expire in 1 minute. Click OK to continue session.')) {
                fetch(window.location.href);
            }
        }

        // Function to redirect to login
        function redirectToLogin() {
            window.location.href = '../index.php?timeout=1';
        }

        // Set timers
        setTimeout(showTimeoutWarning, TIMEOUT_IN_MS - WARNING_IN_MS);
        setTimeout(redirectToLogin, TIMEOUT_IN_MS);

        // Function to toggle rejection reason field
        function toggleRejectionReason(formId) {
            const reasonDiv = document.getElementById('rejection_reason_' + formId);
            const submitBtn = document.getElementById('reject_submit_' + formId);
            if (reasonDiv.style.display === 'none' || reasonDiv.style.display === '') {
                reasonDiv.style.display = 'block';
                submitBtn.style.display = 'inline-block';
            } else {
                reasonDiv.style.display = 'none';
                submitBtn.style.display = 'none';
            }
        }

        // Function to toggle update rejection reason field
        function toggleUpdateRejectionReason(formId) {
            const select = document.getElementById('new_state_' + formId);
            const reasonDiv = document.getElementById('update_rejection_reason_' + formId);
            if (select.value === 'Rejected') {
                reasonDiv.style.display = 'block';
            } else {
                reasonDiv.style.display = 'none';
            }
        }

        // Function to show toast notification
        function showToast(message, isError = false) {
            const toast = document.getElementById('toastNotification');
            const toastBody = toast.querySelector('.toast-body');
            toastBody.textContent = message;
            toast.classList.add('show');
            if (isError) {
                toast.classList.add('error');
            } else {
                toast.classList.remove('error');
            }
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
        window.toggleRejectionReason = toggleRejectionReason;
    window.toggleUpdateRejectionReason = toggleUpdateRejectionReason;
    window.showToast = showToast;

        document.getElementById("dropdownToggle").addEventListener("click", function () {
  const dropdown = document.getElementById("dropdownMenu");
  dropdown.classList.toggle("show");

  // Make functions globally available
    
})
});

    </script>

    <!-- Add meta tag to prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>
    <!-- Toast Notification -->
    <div id="toastNotification" class="toast">
    <div class="toast-body"></div>
</div>

    <?php
    // Check for temporary message and display toast after reload
    if (isset($_SESSION['success_message_temp'])) {
        echo "<script>showToast('" . htmlspecialchars($_SESSION['success_message_temp'], ENT_QUOTES, 'UTF-8') . "');</script>";
        unset($_SESSION['success_message_temp']);
    }
    if (isset($_SESSION['error_message_temp'])) {
        echo "<script>showToast('" . htmlspecialchars($_SESSION['error_message_temp'], ENT_QUOTES, 'UTF-8') . "', true);</script>";
        unset($_SESSION['error_message_temp']);
    }
    // Clear reload flag after use
    if (isset($_SESSION['reload_flag'])) {
        unset($_SESSION['reload_flag']);
    }
    ?>
    <section class="main-content">
    <?php if (isset($_SESSION['current_active_role'])): ?>
    <div class="container mt-5">
      <div class="row mb-3">
        <div class="col-12">
           <div class="alert alert-info d-flex justify-content-between align-items-center">
                <span>
                  <i class="bi bi-person-badge"></i>
                  <?php echo __("Current Role:"); ?> <strong><?php echo htmlspecialchars($_SESSION['current_active_role']); ?></strong>
                </span>
                <?php if (isset($_SESSION['user_roles']) && count($_SESSION['user_roles']) > 1): ?>
                    <div class="dropdown">
                       <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                          <i class="bi bi-arrow-repeat"></i> <?php echo __("Switch Role"); ?>
                       </button>
                      <ul class="dropdown-menu">
                          <?php foreach ($_SESSION['user_roles'] as $role): ?>
                              <?php if ($role !== $_SESSION['current_active_role']): ?>
                                <li><a class="dropdown-item" href="../Dashboard/role_switch.php?switch_to_role=<?php echo urlencode($role); ?>"><?php echo htmlspecialchars($role); ?></a></li>
                              <?php endif; ?>
                          <?php endforeach; ?>
                      </ul>
                    </div>
               <?php endif; ?>
           </div>
       </div>
    </div>
    </div>
    <?php endif; ?>
    

    
        <div class="container mt-4">
            <div class="card mx-auto" style="background-color: #f8f9fa; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); border-radius: 10px;">
                <div class="card-body">
                    <div class="row">
                        <?php if ($admin_role == 'HOF') : ?>
                            <div class="col-12 mb-2">
                                <h5><?php echo __('HOF Dashboard'); ?></h5>
                            </div>
                            <div class="col-xl-4 col-md-6 col-12 mb-3">
                                <div class="dash-widget dash4" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                    <div class="dash-widgetimg">
                                        <i class="bi bi-hourglass-split"></i>
                                    </div>
                                    <div class="dash-widgetcontent">
                                        <h5><span class="counters" style="text-align: center;"><?php echo $totalPendingCount; ?></span></h5>
                                        <h6 style="text-align:center;"><?php echo __("Pending");?></h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6 col-12 mb-3">
                                <div class="dash-widget dash3" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                    <div class="dash-widgetimg">
                                        <i class="bi bi-check2-circle"></i>
                                    </div>
                                    <div class="dash-widgetcontent">
                                        <h5><span class="counters" style="text-align: center;"><?php echo $totalApprovedCount; ?></span></h5>
                                        <h6 style="text-align:center;"><?php echo __("Approved");?></h6>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6 col-12 mb-3">
                                <div class="dash-widget dash2" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                    <div class="dash-widgetimg">
                                        <i class="bi bi-x-circle"></i>
                                    </div>
                                    <div class="dash-widgetcontent">
                                        <h5><span class="counters" style="text-align: center;"><?php echo $totalRejectedCount; ?></span></h5>
                                        <h6 style="text-align:center;"><?php echo __("Rejected");?></h6>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($admin_role == 'HumanResource') : ?>
                            <div class="col-12 mb-2">
                            <h5>
                                <?php echo __('Human Resource Dashboard'); ?>
                            </h5>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash4" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $hr_pending_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Pending");?></h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash3" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-check2-circle"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $hr_approved_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Approved");?></h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash2" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $hr_rejected_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Rejected");?></h6>
                                </div>
                            </div>
                        </div>
                    

                

                        <?php elseif ($admin_role == 'Rectorate') : ?>
                            <div class="col-12 mb-2">
                            <h5>
                                <?php echo __('Rectorate Dashboard'); ?>
                            </h5>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash4" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-hourglass-split"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $rectorate_pending_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Pending");?></h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash3" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-check2-circle"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $rectorate_approved_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Approved");?></h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-4 col-md-6 col-12 mb-3">
                            <div class="dash-widget dash2" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                                <div class="dash-widgetimg">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <div class="dash-widgetcontent">
                                    <h5>
                                        <span class="counters" style="text-align: center;"><?php echo $rectorate_rejected_count; ?></span>
                                    </h5>
                                    <h6 style="text-align:center;"><?php echo __("Rejected");?></h6>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mx-auto mt-4" style="background-color: #f8f9fa; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); border-radius: 10px;">
                <div class="card-body">
                    <h4 class="mb-4"><?php echo __('Requests for Review');?></h4>
                    <?php if (!empty($pendingRequests)) : ?>
                        <?php foreach ($pendingRequests as $request) : ?>
                            <?php
                                $department_state = isset($request['Department']) ? $request['Department'] : 'Pending';
                                $human_resource_state = isset($request['HumanResource']) ? $request['HumanResource'] : 'Pending';
                                $rectorate_state = isset($request['Rectorate']) ? $request['Rectorate'] : 'Pending';

                                $show_request = false;
                                $show_approve_reject = false;
                                $show_update = false;
                                $show_dropdown = false;
                                $current_state = 'Pending';
                                $update_locked = false;
                                $update_locked_message = '';

                                if ($admin_role == "HOF") {
                                    $show_request = true;
                                    $current_state = $department_state;
                                    $show_approve_reject = ($department_state == 'Pending');
                                    $show_update = ($department_state != 'Pending' && $human_resource_state == 'Pending' && $rectorate_state == 'Pending');
                                    $show_dropdown = ($show_approve_reject || $show_update);
                                    if ($human_resource_state != 'Pending' || $rectorate_state != 'Pending') {
                                        $update_locked = true;
                                        $update_locked_message = 'Update locked - Higher level has acted';
                                        $show_update = false;
                                        $show_dropdown = false;
                                    }
                                } elseif ($admin_role == "HumanResource") {
                                    $show_request = ($department_state == 'Approved');
                                    $current_state = $human_resource_state;
                                    $show_approve_reject = ($department_state == 'Approved' && $human_resource_state == 'Pending');
                                    $show_update = ($department_state == 'Approved' && $human_resource_state != 'Pending' && $rectorate_state == 'Pending');
                                    $show_dropdown = ($show_approve_reject || $show_update);
                                    if ($rectorate_state != 'Pending') {
                                        $update_locked = true;
                                        $update_locked_message = 'Update locked - Rectorate has acted';
                                        $show_update = false;
                                        $show_dropdown = false;
                                    }
                                } elseif ($admin_role == "Rectorate") {
                                    $show_request = ($department_state == 'Approved' && $human_resource_state == 'Approved');
                                    $current_state = $rectorate_state;
                                    $show_approve_reject = ($department_state == 'Approved' && $human_resource_state == 'Approved' && $rectorate_state == 'Pending');
                                    $show_update = ($department_state == 'Approved' && $human_resource_state == 'Approved' && $rectorate_state != 'Pending');
                                    $show_dropdown = ($show_approve_reject || $show_update);
                                }

                                if (!$show_request) {
                                    continue;
                                }

                                $collapseId = "details_" . $request['submission_number'];
                                $status_class = '';
                                switch($current_state) {
                                    case 'Pending':
                                        $status_class = 'status-pending';
                                        break;
                                    case 'Approved':
                                        $status_class = 'status-approved';
                                        break;
                                    case 'Rejected':
                                        $status_class = 'status-rejected';
                                        break;
                                }
                            ?>
                            <div class="request-card">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6><strong><?php echo __('Name:'); ?></strong> <?php echo htmlspecialchars($request['FullName']); ?></h6>
                                        <p><strong><?php echo __('Informations:'); ?></strong> <?php echo htmlspecialchars(__($request['RequestTest'])); ?></p>
                                        <p><strong><?php echo __('Status:'); ?></strong> <span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars(__($current_state)); ?></span></p>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <?php if ($show_approve_reject): ?>
                                            <div class="action-buttons" id="action-buttons-<?php echo $request['submission_number']; ?>">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="form_id" value="<?php echo $request['submission_number']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn-approve" ; ?>
                                                        <i class="bi bi-check"></i> <?php echo __('Approve'); ?>
                                                    </button>
                                                </form>
                                                <div style="display: inline;">
                                                    <button type="button" class="btn-reject" onclick="toggleRejectionReason(<?php echo $request['submission_number']; ?>)">
                                                        <i class="bi bi-x"></i> <?php echo __('Reject'); ?>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="form_id" value="<?php echo $request['submission_number']; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <div class="rejection-reason" id="rejection_reason_<?php echo $request['submission_number']; ?>">
                                                            <label for="rejection_reason_input_<?php echo $request['submission_number']; ?>"><?php echo __('Rejection Reason:'); ?></label>
                                                            <textarea name="rejection_reason" id="rejection_reason_input_<?php echo $request['submission_number']; ?>" placeholder="<?php echo __('Enter rejection reason'); ?>"></textarea>
                                                        </div>
                                                        <button type="submit" class="btn-send" id="reject_submit_<?php echo $request['submission_number']; ?>" style="display: none;"  ?>
                                                            <i class="bi bi-send"></i> <?php echo __('Send'); ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <button class="btn btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>">
                                            <?php echo __('More Details'); ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="collapse mt-3" id="<?php echo $collapseId; ?>">
                                    <div class="card card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong><?php echo __('Semester:'); ?></strong> <?php echo htmlspecialchars(__($request['semester'])); ?></p>
                                                <p><strong><?php echo __('Leave Type:'); ?></strong> <?php echo htmlspecialchars(__($request['input'])); ?></p>
                                                <p><strong><?php echo __('Leave Period:'); ?></strong> <?php echo htmlspecialchars(__($request['PermitStartDate'])); ?> <?php echo __('to'); ?> <?php echo htmlspecialchars($request['LeaveExpiryDate']); ?></p>
                                                <p><strong><?php echo __('Submission Number:'); ?></strong> <?php echo htmlspecialchars(__($request['submission_number'])); ?></p>
                                                <p><strong><?php echo __('Submission Date:'); ?></strong> <?php echo htmlspecialchars(__($request['submission_date'])); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong><?php echo __('Phone:'); ?></strong> <?php echo htmlspecialchars($request['phone']); ?></p>
                                                <p><strong><?php echo __('Passport No:'); ?></strong> <?php echo htmlspecialchars($request['passport_no']); ?></p>
                                                <p><strong><?php echo __('Address:'); ?></strong> <?php echo htmlspecialchars($request['Address']); ?></p>
                                                <p><strong><?php echo __('Department:'); ?></strong> <?php echo htmlspecialchars(__($request['unit'])); ?></p>
                                                <p><strong><?php echo __('Make Up Days:'); ?></strong> <?php echo !empty($request['MakeUpDays']) ? htmlspecialchars($request['MakeUpDays']) : __('N/A'); ?></p>
                                            </div>
                                        </div>
                                        <?php if ($show_update && !$update_locked): ?>
                                            <form method="POST" class="mt-3">
                                                <div class="row align-items-center">
                                                    <div class="col-auto">
                                                        <label for="new_state_<?php echo $request['submission_number']; ?>"><strong><?php echo __('Update Status:'); ?></strong></label>
                                                    </div>
                                                    <div class="col-auto">
                                                        <select name="new_state" id="new_state_<?php echo $request['submission_number']; ?>" class="form-select form-select-sm" onchange="toggleUpdateRejectionReason(<?php echo $request['submission_number']; ?>)">
                                                            <option value="Pending" <?php if($current_state == 'Pending') echo 'selected'; ?>><?php echo __('Pending'); ?></option>
                                                            <option value="Approved" <?php if($current_state == 'Approved') echo 'selected'; ?>><?php echo __('Approved'); ?></option>
                                                            <option value="Rejected" <?php if($current_state == 'Rejected') echo 'selected'; ?>><?php echo __('Rejected'); ?></option>
                                                        </select>
                                                    </div>
                                                    <div class="col-auto">
                                                        <div class="rejection-reason" id="update_rejection_reason_<?php echo $request['submission_number']; ?>" style="display: <?php echo $current_state == 'Rejected' ? 'block' : 'none'; ?>;">
                                                            <label for="update_rejection_reason_input_<?php echo $request['submission_number']; ?>"><?php echo __('Rejection Reason:'); ?></label>
                                                            <textarea name="rejection_reason" id="update_rejection_reason_input_<?php echo $request['submission_number']; ?>" placeholder="<?php echo __('Enter rejection reason'); ?>"></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="col-auto">
                                                        <input type="hidden" name="action" value="change_state">
                                                        <input type="hidden" name="form_id" value="<?php echo $request['submission_number']; ?>">
                                                        <button type="submit" class="btn-update"><?php echo __('Update Status'); ?></button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php elseif ($update_locked): ?>
                                            <div class="update-locked-message mt-3"><?php echo htmlspecialchars(__($update_locked_message)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-info"><?php echo __('No requests found for review.'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

   
</body>
</html>