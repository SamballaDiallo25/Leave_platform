<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Enhanced notifications.php - Complete Notification System with Email Support
require_once(__DIR__ . "/../lang.php"); 
require_once(__DIR__ . "/../configuration/configuration.php");
require_once (__DIR__ . '/../testMail.php');

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        
        // Get the directory path of your project
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        
        // Remove the current subdirectory from the path to get the project root
        $projectPath = str_replace(['/Form_1', '/notifications', '/Dashboard'], '', $scriptPath);
        
        return $protocol . '://' . $host . $projectPath;
    }
}

if (!function_exists('createNotification')) {
    function createNotification($conn, $user_id, $message, $type = 'info', $url = null) {
        $sql = "INSERT INTO notifications (user_id, message, type, url, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $message, $type, $url);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
        return false;
    }
}

if (!function_exists('getUserIdByUsername')) {
    function getUserIdByUsername($conn, $username) {
        $sql = "SELECT user_id FROM users1 WHERE user_name = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row['user_id'];
            }
            $stmt->close();
        }
        return null;
    }
}

if (!function_exists('getAdminsByRole')) {
    function getAdminsByRole($conn, $role, $faculty_id = null) {
        $sql = "SELECT user_id, email, fullName FROM users1 WHERE role = ?";
        $params = [$role];
        $types = "s";

        if ($faculty_id !== null && $role === 'HOF') {
            $sql .= " AND faculty_id = ?";
            $params[] = $faculty_id;
            $types .= "i";
        }

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $admins = [];
            while ($row = $result->fetch_assoc()) {
                $admins[] = $row;
            }
            $stmt->close();
            return $admins;
        }
        return [];
    }
}

if (!function_exists('getFormDetails')) {
    function getFormDetails($conn, $submission_number) {
        $sql = "SELECT f.*, u.fullName as user_fullname, u.user_name, u.faculty_id as user_faculty_id, u.email as user_email
                FROM form1 f 
                JOIN users1 u ON f.user_id = u.user_id
                WHERE f.submission_number = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $submission_number);
            $stmt->execute();
            $result = $stmt->get_result();
            $form = $result->fetch_assoc();
            $stmt->close();
            return $form;
        }
        return null;
    }
}

// 1. FORM SUBMISSION NOTIFICATIONS
if (!function_exists('sendFormSubmissionNotifications')) {
    function sendFormSubmissionNotifications($conn, $submission_number, $user_id, $faculty_id, $fullName, $leaveType) {
        $baseUrl = getBaseUrl();
        
        // Notify HOF admins in the same faculty
        $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
        foreach ($hofAdmins as $hofAdmin) {
            $message = __("New leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("requires your review.");
            createNotification($conn, $hofAdmin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");
            
            // Send email to HOF
            $subject = __("New Leave Request Submitted (ID: ") . $submission_number . ")";
            $bodyHtml = "<h3>New Leave Request</h3><p>A new leave request from {$fullName} (ID: {$submission_number}) requires your review.</p><p>Type: {$leaveType}</p><p><a href='{$baseUrl}/Dashboard/adminDashboard.php'>View Request</a></p>";
            sendEmail($hofAdmin['email'], $hofAdmin['fullName'], $subject, $bodyHtml);
        }
        
        
    }
}

// // Function to send notifications when form is deleted
// function sendFormDeletionNotifications($conn, $submission_number, $user_id, $faculty_id, $fullName, $leaveType) {
//     try {
//         $baseUrl = getBaseUrl();
        
//         // Get HOF admins for the specific faculty
//         $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
//         foreach ($hofAdmins as $admin) {
//             $message = sprintf(
//                 __("%s has deleted their %s request (ID: %d)."),
//                 $fullName,
//                 __($leaveType),
//                 $submission_number
//             );
            
//             // Create database notification
//             createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
            
//             // Send email notification
//             $subject = __("Leave Request Deleted by Employee (ID: ") . $submission_number . ")";
//             $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
//             $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
//             $bodyHtml .= "<p>" . __("This notification is for your information as the Department Head.") . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Submission ID:") . "</strong> " . $submission_number . "</p>";
//             $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
//             $bodyHtml .= "<p><em>" . __("No action is required - this is an informational notification.") . "</em></p>";
            
//             sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
//         }
        
//         // Get HR admins
//         $hrAdmins = getAdminsByRole($conn, 'HumanResource');
//         foreach ($hrAdmins as $admin) {
//             $message = sprintf(
//                 __("%s has deleted their %s request (ID: %d)."),
//                 $fullName,
//                 __($leaveType),
//                 $submission_number
//             );
            
//             // Create database notification
//             createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
            
//             // Send email notification
//             $subject = __("Leave Request Deleted by Employee (ID: ") . $submission_number . ")";
//             $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
//             $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
//             $bodyHtml .= "<p>" . __("This notification is for your information as Human Resources.") . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Submission ID:") . "</strong> " . $submission_number . "</p>";
//             $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
//             $bodyHtml .= "<p><em>" . __("No action is required - this is an informational notification.") . "</em></p>";
            
//             sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
//         }
        
//         // Get Rectorate admins
//         $rectorateAdmins = getAdminsByRole($conn, 'Rectorate');
//         foreach ($rectorateAdmins as $admin) {
//             $message = sprintf(
//                 __("%s has deleted their %s request (ID: %d)."),
//                 $fullName,
//                 __($leaveType),
//                 $submission_number
//             );
            
//             // Create database notification
//             createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
            
//             // Send email notification
//             $subject = __("Leave Request Deleted by Employee (ID: ") . $submission_number . ")";
//             $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
//             $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
//             $bodyHtml .= "<p>" . __("This notification is for your information as Rectorate.") . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
//             $bodyHtml .= "<p><strong>" . __("Submission ID:") . "</strong> " . $submission_number . "</p>";
//             $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
//             $bodyHtml .= "<p><em>" . __("No action is required - this is an informational notification.") . "</em></p>";
            
//             sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
//         }
        
//         // Confirm deletion to user (database notification only, no email)
//         $user_message = sprintf(
//             __("Your %s request (ID: %d) has been successfully deleted."),
//             __($leaveType),
//             $submission_number
//         );
//         createNotification($conn, $user_id, $user_message, 'info', "../Dashboard/userDashboard.php");
        
//         return true;
//     } catch (Exception $e) {
//         error_log("Error sending form deletion notifications: " . $e->getMessage());
//         return false;
//     }
// }

// 3. APPROVAL NOTIFICATIONS
if (!function_exists('sendApprovalNotifications')) {
    function sendApprovalNotifications($conn, $submission_number, $admin_role, $faculty_id) {
        $form = getFormDetails($conn, $submission_number);
        if (!$form) return;
        
        $user_id = $form['user_id'];
        $user_email = $form['user_email'];
        $fullName = $form['user_fullname'];
        $leaveType = $form['input'];
        $baseUrl = getBaseUrl();
        
        switch ($admin_role) {
            case 'HOF':
                // HOF approved - notify HR and user
                $hrAdmins = getAdminsByRole($conn, 'HumanResource');
                foreach ($hrAdmins as $hrAdmin) {
                    $message = __("Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been approved by Department and requires your review.");
                    createNotification($conn, $hrAdmin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");

                    // Send email to HR
                    $subject = __("Leave Request Approved by HOF (ID: ") . $submission_number . ")";
                    $bodyHtml = "<h3>Leave Request Approved by HOF</h3><p>Leave request from {$fullName} (ID: {$submission_number}) has been approved by the Department and requires your review.</p><p>Type: {$leaveType}</p><p><a href='{$baseUrl}/Dashboard/adminDashboard.php'>View Request</a></p>";
                    sendEmail($hrAdmin['email'], $hrAdmin['fullName'], $subject, $bodyHtml);
                }
                
                // Notify user (via notification and email)
                $user_message = __("Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been approved by Department and is now under HR review.");
                createNotification($conn, $user_id, $user_message, 'success', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Approved by Department (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) has been approved by the Department and is now under HR review.</p><p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                break;
                
            case 'HumanResource':
                // HR approved - notify Rectorate and user
                $rectorateAdmins = getAdminsByRole($conn, 'Rectorate');
                foreach ($rectorateAdmins as $rectorateAdmin) {
                    $message = __("Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been approved by HR and requires final approval.");
                    createNotification($conn, $rectorateAdmin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");

                    // Send email to Rectorate
                    $subject = __("Leave Request Approved by HR (ID: ") . $submission_number . ")";
                    $bodyHtml = "<h3>Leave Request Approved by HR</h3><p>Leave request from {$fullName} (ID: {$submission_number}) has been approved by Human Resources and requires your final approval.</p><p>Type: {$leaveType}</p><p><a href='{$baseUrl}/Dashboard/adminDashboard.php'>View Request</a></p>";
                    sendEmail($rectorateAdmin['email'], $rectorateAdmin['fullName'], $subject, $bodyHtml);
                }
                
                // Notify user (via notification and email)
                $user_message = __("Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been approved by HR and is now under final review by Rectorate.");
                createNotification($conn, $user_id, $user_message, 'success', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Approved by HR (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) has been approved by Human Resources and is now under final review by the Rectorate.</p><p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                
                // Notify HOF that their approved request moved to next level
                $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
                foreach ($hofAdmins as $hofAdmin) {
                    $message = __("Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been approved by HR and moved to Rectorate for final approval.");
                    createNotification($conn, $hofAdmin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");
                }
                break;
                
            case 'Rectorate':
                // Rectorate approved - FINAL APPROVAL, user can take leave
                $user_message = __("🎉 Congratulations! Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been FULLY APPROVED by all departments. You are now authorized to take your leave!");
                createNotification($conn, $user_id, $user_message, 'success', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Fully Approved (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Approved</h3><p>Congratulations! Your {$leaveType} request (ID: {$submission_number}) has been fully approved by all departments. You are now authorized to take your leave.</p><p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                
                // Notify HOF of final approval
                $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
                foreach ($hofAdmins as $hofAdmin) {
                    $message = __("✅ Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has received FINAL APPROVAL from Rectorate. Employee is authorized to take leave.");
                    createNotification($conn, $hofAdmin['user_id'], $message, 'success', "../Dashboard/adminDashboard.php");
                }
                
                // Notify HR of final approval
                $hrAdmins = getAdminsByRole($conn, 'HumanResource');
                foreach ($hrAdmins as $hrAdmin) {
                    $message = __("✅ Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has received FINAL APPROVAL from Rectorate. Employee is authorized to take leave.");
                    createNotification($conn, $hrAdmin['user_id'], $message, 'success', "../Dashboard/adminDashboard.php");
                }
                break;
        }
    }
}

// 4. REJECTION NOTIFICATIONS
if (!function_exists('sendRejectionNotifications')) {
    function sendRejectionNotifications($conn, $submission_number, $admin_role, $faculty_id, $rejection_reason = null) {
        $form = getFormDetails($conn, $submission_number);
        if (!$form) return;
        
        $user_id = $form['user_id'];
        $user_email = $form['user_email'];
        $fullName = $form['user_fullname'];
        $leaveType = $form['input'];
        $baseUrl = getBaseUrl();
        
        $reasonText = $rejection_reason ? " " . __("Reason:") . " " . $rejection_reason : "";
        
        switch ($admin_role) {
            case 'HOF':
                // HOF rejected - notify user (via notification and email)
                $user_message = __("❌ Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been REJECTED by Department.") . $reasonText;
                createNotification($conn, $user_id, $user_message, 'danger', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Rejected by Department (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) has been rejected by the Department.</p>" . ($reasonText ? "<p>Reason: {$rejection_reason}</p>" : "") . "<p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                break;
                
            case 'HumanResource':
                // HR rejected - notify HOF and user
                $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
                foreach ($hofAdmins as $hofAdmin) {
                    $message = __("⚠️ Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been REJECTED by Human Resources.") . $reasonText;
                    createNotification($conn, $hofAdmin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                }
                
                // Notify user (via notification and email)
                $user_message = __("❌ Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been REJECTED by Human Resources.") . $reasonText;
                createNotification($conn, $user_id, $user_message, 'danger', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Rejected by HR (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) has been rejected by Human Resources.</p>" . ($reasonText ? "<p>Reason: {$rejection_reason}</p>" : "") . "<p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                break;
                
            case 'Rectorate':
                // Rectorate rejected - notify all lower levels and user
                $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
                foreach ($hofAdmins as $hofAdmin) {
                    $message = __("⚠️ Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been REJECTED by Rectorate (Final Authority).") . $reasonText;
                    createNotification($conn, $hofAdmin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                }
                
                $hrAdmins = getAdminsByRole($conn, 'HumanResource');
                foreach ($hrAdmins as $hrAdmin) {
                    $message = __("⚠️ Leave request from") . " " . $fullName . " (ID: " . $submission_number . ") " . __("has been REJECTED by Rectorate (Final Authority).") . $reasonText;
                    createNotification($conn, $hrAdmin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                }
                
                // Notify user (via notification and email)
                $user_message = __("❌ Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("has been REJECTED by Rectorate (Final Authority).") . $reasonText;
                createNotification($conn, $user_id, $user_message, 'danger', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Rejected by Rectorate (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) has been rejected by the Rectorate (Final Authority).</p>" . ($reasonText ? "<p>Reason: {$rejection_reason}</p>" : "") . "<p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
                break;
        }
    }
}

// Function to send smart notifications when form is modified (only to relevant admins)
function sendSmartFormModificationNotifications($conn, $submission_number, $user_id, $faculty_id, $fullName, $input, $form_details) {
    try {
        $baseUrl = getBaseUrl();
        $department_status = $form_details['Department'] ?? 'Pending';
        $hr_status = $form_details['HumanResource'] ?? 'Pending';
        $rectorate_status = $form_details['Rectorate'] ?? 'Pending';
        
        // Only notify HOF if the form has been in their queue (not first submission)
        if ($department_status !== 'Pending' || $department_status === '0') {
            $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
            foreach ($hofAdmins as $admin) {
                $message = sprintf(
                    __("Form #%d by %s (%s) has been modified and reset to Pending status. Please review the updated application."),
                    $submission_number,
                    $fullName,
                    $input
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Modified - Requires Re-Review (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Modified & Reset") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("Form #%d by %s (%s) has been modified and reset to Pending status."), $submission_number, $fullName, $input) . "</p>";
                $bodyHtml .= "<p>" . __("Since this form was previously in your review queue, it now requires your re-review as the Department Head.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($input) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Previous Status:") . "</strong> " . $department_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #ffc107; color: black; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("Re-Review Application") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("Please log in to your admin dashboard to review the modified application.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        // Only notify HR if the form has reached HR stage
        if ($hr_status !== 'Pending' && $hr_status !== '0') {
            $hrAdmins = getAdminsByRole($conn, 'HumanResource');
            foreach ($hrAdmins as $admin) {
                $message = sprintf(
                    __("Form #%d by %s (%s) has been modified and reset to Pending status. This form was previously at HR level."),
                    $submission_number,
                    $fullName,
                    $input
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Modified - Was at HR Level (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Modified & Reset") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("Form #%d by %s (%s) has been modified and reset to Pending status."), $submission_number, $fullName, $input) . "</p>";
                $bodyHtml .= "<p>" . __("This form was previously at the HR review level and has now been reset due to employee modifications.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($input) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Previous HR Status:") . "</strong> " . $hr_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #ffc107; color: black; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("The form will go through the approval process again starting from Department level.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        // Only notify Rectorate if the form has reached Rectorate stage
        if ($rectorate_status !== 'Pending' && $rectorate_status !== '0') {
            $rectorateAdmins = getAdminsByRole($conn, 'Rectorate');
            foreach ($rectorateAdmins as $admin) {
                $message = sprintf(
                    __("Form #%d by %s (%s) has been modified and reset to Pending status. This form was previously at Rectorate level."),
                    $submission_number,
                    $fullName,
                    $input
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'warning', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Modified - Was at Rectorate Level (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Modified & Reset") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("Form #%d by %s (%s) has been modified and reset to Pending status."), $submission_number, $fullName, $input) . "</p>";
                $bodyHtml .= "<p>" . __("This form was previously at the Rectorate review level and has now been reset due to employee modifications.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($input) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Previous Rectorate Status:") . "</strong> " . $rectorate_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #ffc107; color: black; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("The form will go through the approval process again starting from Department level.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error sending smart form modification notifications: " . $e->getMessage());
        return false;
    }
}

// Function to send smart notifications when form is deleted (only to relevant admins)
function sendSmartFormDeletionNotifications($conn, $submission_number, $user_id, $faculty_id, $fullName, $leaveType, $form_details) {
    try {
        $baseUrl = getBaseUrl();
        $department_status = $form_details['Department'] ?? 'Pending';
        $hr_status = $form_details['HumanResource'] ?? 'Pending';
        $rectorate_status = $form_details['Rectorate'] ?? 'Pending';
        
        // Only notify HOF if they have seen the form (either processed it or it was in their queue)
        if ($department_status !== 'Pending' && $department_status !== '0') {
            $hofAdmins = getAdminsByRole($conn, 'HOF', $faculty_id);
            foreach ($hofAdmins as $admin) {
                $message = sprintf(
                    __("%s has deleted their %s request (ID: %d) which was previously %s by Department."),
                    $fullName,
                    __($leaveType),
                    $submission_number,
                    strtolower($department_status)
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Deleted by Employee - Previously Reviewed (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
                $bodyHtml .= "<p>" . __("This form was previously reviewed by your department.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Your Previous Decision:") . "</strong> " . $department_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("This is an informational notification - no action is required.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        // Only notify HR if the form reached HR level
        if ($hr_status !== 'Pending' && $hr_status !== '0') {
            $hrAdmins = getAdminsByRole($conn, 'HumanResource');
            foreach ($hrAdmins as $admin) {
                $message = sprintf(
                    __("%s has deleted their %s request (ID: %d) which was previously %s by HR."),
                    $fullName,
                    __($leaveType),
                    $submission_number,
                    strtolower($hr_status)
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Deleted by Employee - Previously at HR (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
                $bodyHtml .= "<p>" . __("This form was previously reviewed by Human Resources.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Your Previous Decision:") . "</strong> " . $hr_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("This is an informational notification - no action is required.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        // Only notify Rectorate if the form reached Rectorate level
        if ($rectorate_status !== 'Pending' && $rectorate_status !== '0') {
            $rectorateAdmins = getAdminsByRole($conn, 'Rectorate');
            foreach ($rectorateAdmins as $admin) {
                $message = sprintf(
                    __("%s has deleted their %s request (ID: %d) which was previously %s by Rectorate."),
                    $fullName,
                    __($leaveType),
                    $submission_number,
                    strtolower($rectorate_status)
                );
                
                // Create database notification
                createNotification($conn, $admin['user_id'], $message, 'info', "../Dashboard/adminDashboard.php");
                
                // Send email notification
                $subject = __("Leave Request Deleted by Employee - Previously at Rectorate (ID: ") . $submission_number . ")";
                $bodyHtml = "<h3>" . __("Leave Request Deleted") . "</h3>";
                $bodyHtml .= "<p>" . sprintf(__("%s has deleted their %s request (ID: %d)."), $fullName, __($leaveType), $submission_number) . "</p>";
                $bodyHtml .= "<p>" . __("This form was previously reviewed by the Rectorate.") . "</p>";
                $bodyHtml .= "<p><strong>" . __("Employee:") . "</strong> " . $fullName . "</p>";
                $bodyHtml .= "<p><strong>" . __("Leave Type:") . "</strong> " . __($leaveType) . "</p>";
                $bodyHtml .= "<p><strong>" . __("Your Previous Decision:") . "</strong> " . $rectorate_status . "</p>";
                $bodyHtml .= "<p><a href='{$baseUrl}/Dashboard/adminDashboard.php' style='background-color: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>" . __("View Dashboard") . "</a></p>";
                $bodyHtml .= "<p><em>" . __("This is an informational notification - no action is required.") . "</em></p>";
                
                sendEmail($admin['email'], $admin['fullName'], $subject, $bodyHtml);
            }
        }
        
        // Always confirm deletion to user (database notification only)
        $user_message = sprintf(
            __("Your %s request (ID: %d) has been successfully deleted."),
            __($leaveType),
            $submission_number
        );
        createNotification($conn, $user_id, $user_message, 'info', "../Dashboard/userDashboard.php");
        
        return true;
    } catch (Exception $e) {
        error_log("Error sending smart form deletion notifications: " . $e->getMessage());
        return false;
    }
}

// 5. GENERAL STATUS UPDATE NOTIFICATIONS
if (!function_exists('sendStatusUpdateNotifications')) {
    function sendStatusUpdateNotifications($conn, $submission_number, $admin_role, $old_status, $new_status, $faculty_id, $rejection_reason = null) {
        if ($new_status == 'Approved') {
            sendApprovalNotifications($conn, $submission_number, $admin_role, $faculty_id);
        } elseif ($new_status == 'Rejected') {
            sendRejectionNotifications($conn, $submission_number, $admin_role, $faculty_id, $rejection_reason);
        } else {
            $form = getFormDetails($conn, $submission_number);
            if ($form) {
                $user_id = $form['user_id'];
                $user_email = $form['user_email'];
                $fullName = $form['user_fullname'];
                $leaveType = $form['input'];
                $baseUrl = getBaseUrl();
                
                $levelNames = [
                    'HOF' => __('Department'),
                    'HumanResource' => __('Human Resources'),
                    'Rectorate' => __('Rectorate')
                ];
                $levelName = $levelNames[$admin_role] ?? $admin_role;
                
                $user_message = __("Your") . " " . __($leaveType) . " " . __("request") . " (ID: " . $submission_number . ") " . __("status has been updated by") . " " . $levelName . " " . __("to") . " " . __($new_status) . ".";
                createNotification($conn, $user_id, $user_message, 'info', "../Form_1/formReview.php?id=" . $submission_number);
                $user_subject = __("Leave Request Status Updated (ID: ") . $submission_number . ")";
                $user_bodyHtml = "<h3>Leave Request Update</h3><p>Your {$leaveType} request (ID: {$submission_number}) status has been updated by {$levelName} to {$new_status}.</p><p><a href='{$baseUrl}/Form_1/formReview.php?id={$submission_number}'>View Request</a></p>";
                sendEmail($user_email, $fullName, $user_subject, $user_bodyHtml);
            }
        }
    }
}

// 6. UTILITY FUNCTIONS FOR NOTIFICATIONS
if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead($conn, $notification_id, $user_id) {
        $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $notification_id, $user_id);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
        return false;
    }
}

if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead($conn, $user_id) {
        $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
        return false;
    }
}

if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount($conn, $user_id) {
        $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['count'];
        }
        return 0;
    }
}

if (!function_exists('getUserNotifications')) {
    function getUserNotifications($conn, $user_id, $limit = 10) {
        $sql = "SELECT id, message, type, url, is_read, created_at 
                FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $user_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }
            $stmt->close();
            return $notifications;
        }
        return [];
    }
}

if (!function_exists('cleanOldNotifications')) {
    function cleanOldNotifications($conn, $days = 30) {
        $sql = "DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $days);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
        return false;
    }
}

// 7. DATABASE SCHEMA HELPER FUNCTION
if (!function_exists('createNotificationsTable')) {
    function createNotificationsTable($conn) {
        $sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
            url VARCHAR(255) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_is_read (is_read),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users1(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        return $conn->query($sql);
    }
}
?>