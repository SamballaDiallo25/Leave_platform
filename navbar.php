<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default language
}

// Add translation function
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        // Get language from session instead of URL parameter
        $lang = $_SESSION['lang'] ?? 'en';
        
        // Load translations for Turkish
        if ($lang === 'tr') {
            static $translations = null;
            if ($translations === null) {
                // Include your translation file from Languages directory
                $translation_path = "../Languages/tr.php";
                if (file_exists($translation_path)) {
                    include_once($translation_path);
                }
                $translations = $translations ?? [];
            }
            
            // Return translation if exists, otherwise return original text
            if (isset($translations[$text])) {
                return $translations[$text];
            }
        }
        
        return $text; // Return original text for English or if no translation found
    }
}

include(__DIR__ . "/configuration/configuration.php");   // If configuration.php is in the same folder
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) >= $session_timeout) {
        // Check if user is in switched mode before destroying session
        $is_switched = isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user'];
        $original_admin = isset($_SESSION['original_session_data']['admin_name']) ? $_SESSION['original_session_data']['admin_name'] : '';
        
        // Log timeout event
        error_log("Session timeout - User: " . ($original_admin ?: ($_SESSION['Admin_name'] ?? $_SESSION['user_name'] ?? 'unknown')) . ", Switched: " . ($is_switched ? 'yes' : 'no'));
        
        session_unset();
        session_destroy();
        
        // Redirect with appropriate message
        $redirect_params = "timeout=1";
        if ($is_switched) {
            $redirect_params .= "&switched=1";
        }
        
        header("Location: ../index.php?$redirect_params");
        exit();
    }
}
$_SESSION['last_activity'] = time();
$current_lang = $_SESSION['lang'] ?? 'en';

// FIXED: Retrieve fullName and Role for current user
$fullName = '';
$role = '';
$currentUser = '';
$userDisplayRole = '';

// FIXED: Determine current user based on session variables and role switching
if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
    // User is in switched mode - use user session data
    $currentUser = $_SESSION['user_name'] ?? '';
    $userDisplayRole = $_SESSION['Role'] ?? '';
    $fullName = $_SESSION['UserFullName'] ?? '';
} else {
    // Normal mode - determine based on session variables
    if (isset($_SESSION['Admin_name'])) {
        $currentUser = $_SESSION['Admin_name'];
        $userDisplayRole = $_SESSION['AdminRole'] ?? $_SESSION['SRole'] ?? '';
    } elseif (isset($_SESSION['user_name'])) {
        $currentUser = $_SESSION['user_name'];
        $userDisplayRole = $_SESSION['Role'] ?? '';
    }
}

if (!empty($currentUser)) {
    $sql = "SELECT fullName, Role FROM users1 WHERE user_name = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $currentUser);
        $stmt->execute();
        $stmt->bind_result($dbFullName, $dbRole);
        
        if ($stmt->fetch()) {
            // Use database full name if session full name is empty
            if (empty($fullName)) {
                $_SESSION['UserFullName'] = $dbFullName;
                $fullName = $dbFullName;
            }
            
            // Update role information for consistency
            $role = $dbRole;
            
            // Handle role session assignments (only if not switched)
            if (!isset($_SESSION['is_switched_user']) || !$_SESSION['is_switched_user']) {
                $normalizedRole = strtolower($role);
                
                // Clear previous role sessions
                unset($_SESSION['AdminRole'], $_SESSION['SRole'], $_SESSION['Role']);
                
                // Assign role session based on role string
                if ($normalizedRole === 'superadmin') {
                    $_SESSION['SRole'] = $role;
                } elseif (in_array($normalizedRole, ['admin', 'hof', 'humanresource', 'rectorate'])) {
                    $_SESSION['AdminRole'] = $role;
                } else {
                    $_SESSION['Role'] = $role;
                }
            }
        }
        $stmt->close();
    }
}

// Function to get notifications for the current user
if (!function_exists('getNotifications')) {
    function getNotifications($conn, $username, $limit = null) {
        $notifications = array();
        
        // Build the LIMIT clause if limit is specified, default to 3 for navbar
        $limitClause = $limit ? "LIMIT " . intval($limit) : "LIMIT 3";
        
        // Get notifications from the notifications table (including read ones)
        $sql = "SELECT id, message, type, url, is_read, created_at 
                FROM notifications 
                WHERE user_id = (SELECT user_id FROM users1 WHERE user_name = ?) 
                ORDER BY created_at DESC $limitClause";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $notifications[] = array(
                    'id' => $row['id'],
                    'message' => $row['message'],
                    'type' => $row['type'],
                    'url' => $row['url'],
                    'is_read' => $row['is_read'],
                    'created_at' => $row['created_at'],
                    'source' => 'notifications'
                );
            }
            $stmt->close();
        }
        
        // If no notifications in notifications table, check form1 table for leave requests
        if (empty($notifications)) {
            // Check if form1 table has the required columns first
            $checkColumns = "SHOW COLUMNS FROM form1";
            $result = $conn->query($checkColumns);
            $columns = array();
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            // Build query based on available columns
            $hasStatus = in_array('status', $columns);
            $hasLeaveType = in_array('leave_type', $columns);
            $hasResponseMessage = in_array('response_message', $columns);
            $hasCreatedAt = in_array('created_at', $columns);
            $hasUserName = in_array('user_name', $columns) || in_array('lecturer_username', $columns);
            
            if ($hasUserName) {
                $userNameColumn = in_array('user_name', $columns) ? 'user_name' : 'lecturer_username';
                
                $selectFields = "id";
                if ($hasLeaveType) $selectFields .= ", leave_type";
                if ($hasStatus) $selectFields .= ", status";
                if ($hasResponseMessage) $selectFields .= ", response_message";
                if ($hasCreatedAt) $selectFields .= ", created_at";
                
                $whereClause = "$userNameColumn = ?";
                
                $orderClause = $hasCreatedAt ? "ORDER BY created_at DESC" : "ORDER BY id DESC";
                
                $sql = "SELECT $selectFields FROM form1 WHERE $whereClause $orderClause $limitClause";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    while ($row = $result->fetch_assoc()) {
                        $leaveType = $hasLeaveType ? $row['leave_type'] : 'general';
                        $status = $hasStatus ? $row['status'] : 'processed';
                        $responseMessage = $hasResponseMessage ? $row['response_message'] : '';
                        $createdAt = $hasCreatedAt ? $row['created_at'] : date('Y-m-d H:i:s');
                        
                        $notifications[] = array(
                            'id' => $row['id'],
                            'leave_type' => $leaveType,
                            'status' => $status,
                            'response_message' => $responseMessage,
                            'created_at' => $createdAt,
                            'source' => 'form1'
                        );
                    }
                    $stmt->close();
                }
            }
        }
        
        return $notifications;
    }
}

// Add new function to get total notification count
if (!function_exists('getTotalNotificationCount')) {
    function getTotalNotificationCount($conn, $username) {
        $totalCount = 0;
        
        // Count notifications from the notifications table
        $sql = "SELECT COUNT(*) as count 
                FROM notifications 
                WHERE user_id = (SELECT user_id FROM users1 WHERE user_name = ?) 
                AND is_read = 0";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $totalCount = $row['count'];
            $stmt->close();
        }
        
        // If no notifications in notifications table, count from form1 table
        if ($totalCount == 0) {
            $checkColumns = "SHOW COLUMNS FROM form1";
            $result = $conn->query($checkColumns);
            $columns = array();
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            $hasStatus = in_array('status', $columns);
            $hasUserName = in_array('user_name', $columns) || in_array('lecturer_username', $columns);
            
            if ($hasUserName) {
                $userNameColumn = in_array('user_name', $columns) ? 'user_name' : 'lecturer_username';
                $whereClause = "$userNameColumn = ?";
                if ($hasStatus) $whereClause .= " AND status != 'pending'";
                
                $sql = "SELECT COUNT(*) as count FROM form1 WHERE $whereClause";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $totalCount = $row['count'];
                    $stmt->close();
                }
            }
        }
        
        return $totalCount;
    }
}

// Function to format username (replace dots with spaces)
if (!function_exists('formatUsername')) {
    function formatUsername($username) {
        return str_replace('.', ' ', $username);
    }
}

// FIXED: Determine if we should show notifications - CORRECTED LOGIC
$showNotifications = false;
$notifications = array();
$totalNotificationCount = 0;
$notificationUser = '';

if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
    // In switched user mode - show user notifications
    $showNotifications = true;
    $notificationUser = $_SESSION['user_name'] ?? '';
} elseif (isset($_SESSION['Role']) && !isset($_SESSION['AdminRole']) && !isset($_SESSION['SRole'])) {
    // Regular user mode (not admin, not switched)
    $showNotifications = true;
    $notificationUser = $_SESSION['user_name'] ?? '';
} elseif ((isset($_SESSION['AdminRole']) || isset($_SESSION['SRole'])) && !isset($_SESSION['is_switched_user'])) {
    // Admin mode - show admin notifications
    $showNotifications = true;
    $notificationUser = $_SESSION['Admin_name'] ?? '';
}

// Fetch notifications if we should show them
if ($showNotifications && !empty($notificationUser)) {
    $notifications = getNotifications($conn, $notificationUser, 5);
    $totalNotificationCount = getTotalNotificationCount($conn, $notificationUser);
}

// Utility function to append lang query param
if (!function_exists('appendLangParameter')) {
    function appendLangParameter($url, $lang)
    {
        $parsedUrl = parse_url($url);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $queryParams['lang'] = $lang;
        $newQueryString = http_build_query($queryParams);
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host = $parsedUrl['host'] ?? '';
        $path = $parsedUrl['path'] ?? '';
        return $scheme . $host . $path . '?' . $newQueryString;
    }
}

if (!function_exists('generateMainLink')) {
    function generateMainLink() {
        // Check if user is switched - if so, show user links
        if (isset($_SESSION['is_switched_user']) || $_SESSION['is_switched_user']) {
            return "../Form_1/applicationForm.php";
        }
        
        // Normal logic
        if (isset($_SESSION['AdminRole']) || isset($_SESSION['SRole'])) {
            return "../Form_1/absenceRecordsForm.php";
        } elseif (isset($_SESSION['Role'])) {
            return "../Form_1/applicationForm.php";
        } else {
            return "#";
        }
    }
}

if (!function_exists('generateMainLink1')) {
    function generateMainLink1() {
        $currentActiveRole = $_SESSION['current_active_role'] ?? ($_SESSION['AdminRole'] ?? $_SESSION['SRole'] ?? $_SESSION['Role'] ?? '');
        
        if ($currentActiveRole === 'SuperAdmin') {
            return "../Dashboard/superAdminDashboard.php";
        } elseif (in_array($currentActiveRole, ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate', 'Admin'])) {
            return "../Dashboard/adminDashboard.php";
        } else {
            return "../Dashboard/userDashboard.php";
        }
    }
}

if (!function_exists('getDashboardTitle')) {
    function getDashboardTitle() {
        // Get user's roles and current active role
        global $userRoles, $currentActiveRole, $conn, $currentUser;
        
        // Get user's original/primary role
        $originalRole = '';
        if (!empty($userRoles)) {
            foreach ($userRoles as $roleData) {
                if ($roleData['is_primary'] == 1) {
                    $originalRole = $roleData['role_name'];
                    break;
                }
            }
            
            // If no primary role found, use the first role as original
            if (empty($originalRole)) {
                $originalRole = $userRoles[0]['role_name'];
            }
        }
        
        // If userRoles is empty, get from database
        // if (empty($originalRole) && !empty($currentUser) && isset($conn)) {
        //     $sql = "SELECT role_name FROM user_roles ur 
        //             JOIN users1 u ON ur.user_id = u.user_id 
        //             WHERE u.user_name = ? AND ur.is_primary = 1 
        //             LIMIT 1";
        //     $stmt = $conn->prepare($sql);
        //     if ($stmt) {
        //         $stmt->bind_param("s", $currentUser);
        //         $stmt->execute();
        //         $stmt->bind_result($originalRole);
        //         $stmt->fetch();
        //         $stmt->close();
        //     }
        // }
        
        // Fallback to session data if still empty
        if (empty($currentActiveRole)) {
            $currentActiveRole = $_SESSION['current_active_role'] ?? 
                                ($_SESSION['SRole'] ?? 
                                ($_SESSION['AdminRole'] ?? 
                                $_SESSION['Role'] ?? ''));
        }
        
        // Determine if user is in switched mode
        $isSwitched = !empty($originalRole) && ($currentActiveRole !== $originalRole);
        
        // Define role categories
        $superAdminRoles = ['SuperAdmin'];
        $adminRoles = ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate', 'Admin'];
        $lecturerRoles = ['Lecturer'];
        
        // Determine dashboard title based on current active role
        $dashboardTitle = '';
        
        if (in_array($currentActiveRole, $superAdminRoles)) {
            $dashboardTitle = __("Super Admin Dashboard");
        } elseif (in_array($currentActiveRole, $adminRoles)) {
            $dashboardTitle = __("Admin Dashboard");
        } elseif (in_array($currentActiveRole, $lecturerRoles)) {
            $dashboardTitle = __("User Dashboard");
        } else {
            // Fallback based on session variables (for backward compatibility)
            if (isset($_SESSION['SRole'])) {
                $dashboardTitle = __("Super Admin Dashboard");
            } elseif (isset($_SESSION['AdminRole'])) {
                $dashboardTitle = __("Admin Dashboard");
            } elseif (isset($_SESSION['Role'])) {
                $dashboardTitle = __("User Dashboard");
            } else {
                $dashboardTitle = __("Dashboard");
            }
        }
        
        // Add "(Switched Mode)" if user is not in their original role
        if ($isSwitched) {
            $dashboardTitle .= " (" . __("Switched Mode") . ")";
        }
        
        return $dashboardTitle;
    }
}

if (!function_exists('getDisplayName')) {
    function getDisplayName() {
        global $fullName, $currentUser;
        
        if (!empty($fullName)) {
            // Add mode indicator to full name
            if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
                return $fullName . ' (' . __("User Mode") . ')';
            }
            return $fullName;
        } elseif (!empty($currentUser)) {
            $formatted = formatUsername($currentUser);
            
            // Add mode indicator
            if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
                return $formatted . ' (' . __("User Mode") . ')';
            }
            
            return $formatted;
        } else {
            $fallback = $_SESSION['AdminRole'] ?? $_SESSION['SRole'] ?? $_SESSION['Role'] ?? __('Unknown User');
            
            if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
                return $fallback . ' (' . __("User Mode") . ')';
            }
            
            return $fallback;
        }
    }
}

// Role switching functions
if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole() {
        if (isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user']) {
            return 'switched_user';
        } elseif (isset($_SESSION['SRole'])) {
            return 'super_admin';
        } elseif (isset($_SESSION['AdminRole'])) {
            return 'admin';
        } elseif (isset($_SESSION['Role'])) {
            return 'user';
        }
        return 'unknown';
    }
}

if (!function_exists('canSwitchToUser')) {
    function canSwitchToUser() {
        // Only HOF and Rectorate can switch to user mode
        $allowed_roles = ['HOF', 'Rectorate', 'HOF_Staff', 'Rectorate_Staff', 'HumanResource', 'HumanResource_Staff', 'Admin', 'SuperAdmin'];
        
        if (isset($_SESSION['AdminRole']) && in_array($_SESSION['AdminRole'], $allowed_roles)) {
            return true;
        }
        
        return false;
    }
}

if (!function_exists('canSwitchToAdmin')) {
    function canSwitchToAdmin() {
        return isset($_SESSION['is_switched_user']) && $_SESSION['is_switched_user'] && isset($_SESSION['original_session_data']);
    }
}

// Function to get all user roles
if (!function_exists('getUserRoles')) {
    function getUserRoles($conn, $username) {
        $roles = [];
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
                $roles[] = [
                    'role_name' => $row['role_name'],
                    'is_primary' => $row['is_primary']
                ];
            }
            $stmt->close();
        }
        
        return $roles;
    }
}

// Function to check if role is admin
if (!function_exists('isAdminRole')) {
    function isAdminRole($roleName) {
        $adminRoles = ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate'];
        return in_array($roleName, $adminRoles);
    }
}

// Function to get role display name
if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($roleName) {
        $roleLabels = [
            'Lecturer' => __('Lecturer'),
            'HOF' => __('Head of Faculty'),
            'HOF_Staff' => __('HOF Staff'),
            'HumanResource' => __('Human Resource'),
            'HumanResource_Staff' => __('HR Staff'),
            'Rectorate' => __('Rectorate')
        ];
        
        return $roleLabels[$roleName] ?? $roleName;
    }
}

// Function to switch user role
if (!function_exists('switchUserRole')) {
    function switchUserRole($conn, $username, $targetRole) {
        // Verify user has this role
        $userRoles = getUserRoles($conn, $username);
        $hasRole = false;
        
        foreach ($userRoles as $role) {
            if ($role['role_name'] === $targetRole) {
                $hasRole = true;
                break;
            }
        }
        
        if (!$hasRole) {
            return false;
        }
        
        // Set session variables based on target role
        $_SESSION['current_active_role'] = $targetRole;
        
        if (isAdminRole($targetRole)) {
            $_SESSION['AdminRole'] = $targetRole;
            unset($_SESSION['Role']);
            unset($_SESSION['is_switched_user']);
        } else {
            $_SESSION['Role'] = $targetRole;
            $_SESSION['is_switched_user'] = true;
            unset($_SESSION['AdminRole']);
        }
        
        return true;
    }
}

// Get user roles for current user
$userRoles = [];
if (!empty($currentUser)) {
    $userRoles = getUserRoles($conn, $currentUser);
}

// Determine current active role
$currentActiveRole = $_SESSION['current_active_role'] ?? $primaryRole ?? $role;

// Update the existing functions to work with multiple roles

// Update canSwitchToUser function
if (!function_exists('canSwitchToUser')) {
    function canSwitchToUser() {
        global $userRoles, $currentActiveRole;
        
        // Check if user has any non-admin roles
        foreach ($userRoles as $roleData) {
            if (!isAdminRole($roleData['role_name'])) {
                return true;
            }
        }
        
        return false;
    }
}

// Update canSwitchToAdmin function
if (!function_exists('canSwitchToAdmin')) {
    function canSwitchToAdmin() {
        global $userRoles, $currentActiveRole;
        
        // Check if user has any admin roles
        foreach ($userRoles as $roleData) {
            if (isAdminRole($roleData['role_name'])) {
                return true;
            }
        }
        
        return false;
    }
}

// Update getDisplayName function
if (!function_exists('getDisplayName')) {
    function getDisplayName() {
        global $fullName, $currentUser, $currentActiveRole;
        
        $baseName = !empty($fullName) ? $fullName : (!empty($currentUser) ? formatUsername($currentUser) : __('Unknown User'));
        
        if (!empty($currentActiveRole)) {
            $roleDisplay = getRoleDisplayName($currentActiveRole);
            return $baseName . ' (' . $roleDisplay . ')';
        }
        
        return $baseName;
    }
}

// Update getDashboardTitle function
if (!function_exists('getDashboardTitle')) {
    function getDashboardTitle() {
        global $currentActiveRole;
        
        if (isAdminRole($currentActiveRole)) {
            return __("Admin Dashboard") . " - " . getRoleDisplayName($currentActiveRole);
        } else {
            return __("User Dashboard") . " - " . getRoleDisplayName($currentActiveRole);
        }
        
        return __("Dashboard");
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <script>
// Set current language for JavaScript use
window.currentLang = "<?php echo $current_lang; ?>";
</script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet"> 
  <script src="https://kit.fontawesome.com/242d4b38d8.js" crossorigin="anonymous"></script> 
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
  <link rel="stylesheet" href="../Css/style.css"> 
  <link rel="icon" href="../logo/logo1.png" type="image/png" class="logo">
  <style>
   




/* Mobile responsive */
@media (max-width: 768px) {
    .dropdown-menu-end {
        position: fixed !important;
        right: 10px !important;
        top: 70px !important;
        left: auto !important;
        width: 220px !important;
    }
}
    
    
    .nav-link {
    width: auto !important;
    max-width: 100%;
}
     .profile-dropdown {
        min-width: 200px;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-top: 8px;
    }

    .profile-dropdown .dropdown-item {
        padding: 10px 16px;
        transition: background-color 0.2s ease;
    }

    .profile-dropdown .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    .profile-dropdown .dropdown-item i {
        width: 16px;
        text-align: center;
    }

    .nav-profile.dropdown-toggle::after {
        margin-left: 8px;
    }

    .nav-profile {
        display: flex !important;
    align-items: center;
    cursor: pointer;
    transition: color 0.3s ease;
    min-width: 0;
    max-width: 200px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    }

    .nav-profile i {
    display: inline-block !important;
    margin-right: 8px;
    flex-shrink: 0;
    font-size: 16px;
}

    .nav-profile span {
    display: inline-block !important;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
    }

    .nav-profile:hover {
        color: #007bff !important;
    }
    
    .notification-footer {
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
        padding: 10px 16px;
        border-radius: 0 0 8px 8px;
    }

    .view-all-link {
        display: block;
        text-align: center;
        color: #007bff;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: color 0.2s ease;
    }

    .view-all-link:hover {
        color: #0056b3;
        text-decoration: none;
    }

    .view-all-link i {
        margin-right: 5px;
    }

    /* Ensure proper spacing for badge in header */
    .notification-header .badge {
        font-size: 10px;
        padding: 2px 6px;
    }
    
    @media (min-width: 1203px) {
      .main-content {
        margin-left: 300px;
        width: calc(100% - 300px);
        margin-top: 80px;
      }
    }
    .card-inner {
      justify-content: space-between;
    }
    @media (max-width: 768px) {
      .logo {
        display: none !important;
      }
    }

    /* Notification Styles */
    .notification-dropdown {
        position: relative;
        display: inline-block;
    }

    .notification-btn {
        background: none;
        border: none;
        color: #333;
        font-size: 18px;
        position: relative;
        padding: 8px 12px;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .notification-btn:hover {
        color: #007bff;
    }

    .notification-badge {
        position: absolute;
        top: 5px;
        right: 5px;
        background-color: #dc3545;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 10px;
        min-width: 16px;
        text-align: center;
    }

    .notification-dropdown-content {
        display: none;
        position: absolute;
        right: 0;
        background-color: white;
        min-width: 350px;
        max-width: 400px;
        box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
        z-index: 1000;
        border-radius: 8px;
        border: 1px solid #ddd;
        max-height: 450px;
        overflow-y: auto;
    }

    .notification-header {
        background-color: #f8f9fa;
        padding: 12px 16px;
        border-bottom: 1px solid #dee2e6;
        font-weight: bold;
        color: #495057;
        border-radius: 8px 8px 0 0;
    }

    .notification-item {
        padding: 12px 16px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .notification-item:hover {
        background-color: #f8f9fa;
    }

    .notification-item:last-child {
        border-bottom: none;
        border-radius: 0 0 8px 8px;
    }

    .notification-title {
        font-weight: 600;
        color: #333;
        margin-bottom: 4px;
    }

    .notification-message {
        font-size: 14px;
        color: #666;
        margin-bottom: 4px;
    }

    .notification-time {
        font-size: 12px;
        color: #999;
    }

    .notification-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
        margin-top: 4px;
    }

    .status-approved {
        background-color: #d4edda;
        color: #155724;
    }

    .status-rejected {
        background-color: #f8d7da;
        color: #721c24;
    }

    .status-pending {
        background-color: #fff3cd;
        color: #856404;
    }

    .status-info {
        background-color: #d1ecf1;
        color: #0c5460;
    }

    .status-success {
        background-color: #d4edda;
        color: #155724;
    }

    .status-warning {
        background-color: #fff3cd;
        color: #856404;
    }

    .status-danger {
        background-color: #f8d7da;
        color: #721c24;
    }

    .no-notifications {
        padding: 20px;
        text-align: center;
        color: #666;
        font-style: italic;
    }

    

        
    .select {
        width: 60px !important;
    }

    .languages .select {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: white;
    font-size: 14px;
    cursor: pointer;
    min-width: 80px;
}

.languages .select:hover {
    border-color: #007bff;
}

.languages .select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,.25);
}

/* Support RTL pour l'arabe */
html[dir="rtl"] .languages {
    margin-left: 0;
    margin-right: 0.5rem;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .languages .select {
        font-size: 12px;
        padding: 3px 6px;
        min-width: 70px;
    }
}

.role-indicator {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    margin-left: 8px;
}

.role-admin {
    background-color: #dc3545;
    color: white;
}

.role-user {
    background-color: #28a745;
    color: white;
}

.role-super {
    background-color: #6f42c1;
    color: white;
}

.current-role-highlight {
    background-color: #e3f2fd;
    border-left: 3px solid #2196f3;
}

.role-switch-item {
    transition: all 0.2s ease;
}

.role-switch-item:hover {
    background-color: #f8f9fa;
    transform: translateX(2px);
}

/* Quick dashboard switch buttons */
.dashboard-quick-switch {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #dee2e6;
}

.dashboard-btn {
    display: inline-block;
    padding: 4px 12px;
    margin: 2px;
    border-radius: 15px;
    font-size: 11px;
    text-decoration: none;
    transition: all 0.2s ease;
}

.dashboard-btn-admin {
    background-color: #dc3545;
    color: white;
}

.dashboard-btn-user {
    background-color: #28a745;
    color: white;
}

.dashboard-btn:hover {
    transform: scale(1.05);
    text-decoration: none;
    color: white;
}

/* Sidebar dropdown improvements */
.nav-content {
    background-color: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
    margin-top: 5px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.nav-content.show {
    display: block !important;
}

.nav-content li {
    list-style: none;
}

.nav-content .nav-link {
    padding: 8px 20px 8px 40px;
    font-size: 13px;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.3s ease;
    border-radius: 4px;
    margin: 2px 8px;
}

.nav-content .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.1);
    color: #fff;
    transform: translateX(5px);
}

.nav-content .nav-link i {
    width: 16px;
    text-align: center;
}

/* Chevron rotation animation */
.nav-link .bi-chevron-down {
    transition: transform 0.3s ease;
}

.nav-link[aria-expanded="true"] .bi-chevron-down {
    transform: rotate(180deg);
}

/* Simplified dropdown styles */
.nav-item.dropdown {
    position: relative;
}

.dropdown-menu {
    min-width: 220px;
    margin-top: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
    border: 1px solid #dee2e6;
    border-radius: 8px;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .dropdown-menu-end {
        position: fixed !important;
        right: 10px !important;
        top: 70px !important;
        left: auto !important;
        width: 220px !important;
    }
}

/* .profile-dropdown {
    min-width: 200px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-top: 8px;
}

.profile-dropdown .dropdown-item {
    padding: 10px 16px;
    transition: background-color 0.2s ease;
}

.profile-dropdown .dropdown-item:hover {
    background-color: #f8f9fa;
} */

    .sidebar-nav {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 150px);  /* Adjust based on your logo/header height; e.g., subtract sidebar-logo height */
    overflow-y: auto;
}
.sidebar {
    overflow-y: auto;
    max-height: 100vh;  /* Ensures it doesn't exceed viewport height */
}
.nav-content {
    position: relative !important;
    z-index: 1;  /* Low z-index to ensure it's below other elements if needed */
}
  </style>
</head>
<body>
<header id="header" class="header d-flex align-items-center">
  <div class="container-fluid container-xxl d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center">
      <i class="bi bi-list toggle-sidebar-btn"></i>
      <a class="logo d-flex align-items-center">
        <span class="d-none d-lg-block" id="admin"><?php echo getDashboardTitle(); ?></span>
      </a>
    </div>
    
    <!-- New grouped right section -->
    <div class="header-right-group">
        <!-- Notification Dropdown - FIXED CONDITION -->
        <?php if ($showNotifications): ?>
        <div class="notification-dropdown">
            <button class="notification-btn" onclick="toggleNotifications()" type="button">
                <i class="bi bi-bell"></i>
                <?php if ($totalNotificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $totalNotificationCount; ?></span>
                <?php endif; ?>
            </button>
            
            <div class="notification-dropdown-content" id="notificationDropdown">
                <!-- Your existing notification dropdown content -->
                <div class="notification-header">
                    <?php echo __("Recent Notifications"); ?>
                    <?php if ($totalNotificationCount > 0): ?>
                        <span class="badge badge-primary ms-2"><?php echo $totalNotificationCount; ?> <?php echo __("Unread"); ?></span>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($notifications)): ?>
                    <div class="no-notifications">
                        <?php echo __("No notifications available"); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo ($notification['is_read'] ?? false) ? 'read-notification' : 'unread-notification'; ?>" 
                             onclick="handleNotificationClick(<?php echo $notification['id']; ?>)">
                            <?php if (($notification['source'] ?? '') == 'notifications'): ?>
                                <!-- Display notifications from notifications table -->
                                <div class="notification-title <?php echo !($notification['is_read'] ?? true) ? 'fw-bold' : ''; ?>">
                                    <?php echo htmlspecialchars(__($notification['message']) ?? ''); ?>
                                    <?php if (!($notification['is_read'] ?? true)): ?>
                                        <span class="badge bg-primary ms-1" style="font-size: 9px;"><?php echo __("New"); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="notification-time">
                                    <?php 
                                    try {
                                        $timestamp = $notification['created_at'] ?? 'now';
                                        if (function_exists('formatTranslatedDate')) {
                                            echo formatTranslatedDate($timestamp, 'M j, Y g:i A');
                                        } else {
                                            echo date('M j, Y g:i A', strtotime($timestamp));
                                        }
                                    } catch (Exception $e) {
                                        echo date('M j, Y g:i A');
                                    }
                                    ?>
                                </div>
                                <span class="notification-status status-<?php echo $notification['type'] ?? 'info'; ?>">
                                    <?php echo __(ucfirst($notification['type'] ?? 'info')); ?>
                                </span>
                            <?php else: ?>
                                <!-- Display notifications from form1 table -->
                                <div class="notification-title">
                                    <?php 
                                    $leave_types = [
                                        'annual' => __('Annual Leave'),
                                        'sick' => __('Sick Leave'),
                                        'excuse' => __('Excuse Leave'),
                                        'other' => __('Other Leave'),
                                        'general' => __('Leave Request')
                                    ];
                                    $leave_type = $notification['leave_type'] ?? 'general';
                                    echo $leave_types[$leave_type] ?? ucfirst($leave_type) . ' ' . __('Leave');
                                    ?>
                                </div>
                                <?php if (!empty($notification['response_message'] ?? '')): ?>
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars($notification['response_message']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="notification-time">
                                    <?php 
                                    try {
                                        $timestamp = $notification['created_at'] ?? 'now';
                                        if (function_exists('formatTranslatedDate')) {
                                            echo formatTranslatedDate($timestamp, 'M j, Y g:i A');
                                        } else {
                                            echo date('M j, Y g:i A', strtotime($timestamp));
                                        }
                                    } catch (Exception $e) {
                                        echo date('M j, Y g:i A');
                                    }
                                    ?>
                                </div>
                                <span class="notification-status status-<?php echo $notification['status'] ?? 'pending'; ?>">
                                    <?php echo __(ucfirst($notification['status'] ?? 'pending')); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Always show "View All" link -->
                <div class="notification-footer">
                    <a href="../notifications/all_notifications.php" class="view-all-link">
                        <i class="bi bi-list"></i>
                        <?php echo __("View All Notifications"); ?>
                        <?php if ($totalNotificationCount > 0): ?>
                            (<?php echo $totalNotificationCount; ?> <?php echo __("unread"); ?>)
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

       <!-- User Profile Dropdown -->
        <?php $displayName = getDisplayName(); ?>
        <div class="nav-item dropdown">
    <a class="nav-profile d-flex align-items-center pe-0 dropdown-toggle" 
       href="#" 
       data-bs-toggle="dropdown" 
       aria-expanded="false"
       role="button">
        <i class="fas fa-user"></i>
        <span class="ms-2"><?php echo getDisplayName(); ?></span>
    </a>
    
    <ul class="dropdown-menu dropdown-menu-end profile-dropdown">
    <!-- User Info Section with enhanced role indicator -->
    <li>
        <div class="dropdown-item-text">
            <strong><?php echo !empty($fullName) ? $fullName : 'User'; ?></strong>
            <span class="role-indicator <?php echo isAdminRole($currentActiveRole) ? 'role-admin' : 'role-user'; ?>">
                <?php echo getRoleDisplayName($currentActiveRole); ?>
            </span>
            <br>
            <small class="text-muted">
                <?php echo count($userRoles) > 1 ? count($userRoles) . ' ' . __("roles available") : __("Single role"); ?>
            </small>
        </div>
    </li>
    
    <!-- Enhanced role switching section -->
    <?php if (count($userRoles) > 1): ?>
    <li><hr class="dropdown-divider"></li>
    <li>
        <h6 class="dropdown-header">
            <i class="bi bi-arrow-repeat me-1"></i>
            <?php echo __("Switch Role"); ?>
        </h6>
    </li>
    
    <?php foreach ($userRoles as $roleData): ?>
        <?php if ($roleData['role_name'] !== $currentActiveRole): ?>
            <li>
                <a class="dropdown-item role-switch-item d-flex align-items-center justify-content-between" 
                   href="../Dashboard/role_switch.php?switch_to_role=<?php echo urlencode($roleData['role_name']); ?>">
                    <div class="d-flex align-items-center">
                        <?php if (isAdminRole($roleData['role_name'])): ?>
                            <i class="bi bi-shield-check me-2 text-danger"></i>
                        <?php else: ?>
                            <i class="bi bi-person me-2 text-success"></i>
                        <?php endif; ?>
                        <span><?php echo getRoleDisplayName($roleData['role_name']); ?></span>
                    </div>
                    <small class="text-muted">
                        <i class="bi bi-arrow-right"></i>
                    </small>
                </a>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
    
    
    
    <li><hr class="dropdown-divider"></li>
    <?php endif; ?>
    
    <!-- Current role indicator with more details -->
    <li>
        <div class="dropdown-item-text current-role-highlight">
            <small>
                <i class="bi bi-dot text-primary"></i>
                <?php echo __("Active Role"); ?>: 
                <strong><?php echo getRoleDisplayName($currentActiveRole); ?></strong>
                
                <?php if (isAdminRole($currentActiveRole)): ?>
                    <span class="role-indicator role-admin"><?php echo __("Admin Access"); ?></span>
                <?php else: ?>
                    <span class="role-indicator role-user"><?php echo __("User Access"); ?></span>
                <?php endif; ?>
                
                <br>
                <i class="bi bi-clock me-1"></i>
                <?php echo __("Session active since"); ?>: 
                <?php echo date('H:i', $_SESSION['last_activity'] ?? time()); ?>
            </small>
        </div>
    </li>
    
    <li><hr class="dropdown-divider"></li>
    
    
    
</ul>
</div>

        <!-- Language Dropdown -->
        <div class="languages">
            <select class="select" onchange="changeLanguage(this)" id="languageSelect">
                <option value="">Lang</option>
                <option value="<?php echo appendLangParameter($_SERVER['REQUEST_URI'], 'en'); ?>" <?php echo ($current_lang === 'en') ? 'selected' : ''; ?>>En</option>
                <option value="<?php echo appendLangParameter($_SERVER['REQUEST_URI'], 'fr'); ?>" <?php echo ($current_lang === 'fr') ? 'selected' : ''; ?>>Fr</option>
                <option value="<?php echo appendLangParameter($_SERVER['REQUEST_URI'], 'ru'); ?>" <?php echo ($current_lang === 'ru') ? 'selected' : ''; ?>>Ru</option>
                <option value="<?php echo appendLangParameter($_SERVER['REQUEST_URI'], 'ar'); ?>" <?php echo ($current_lang === 'ar') ? 'selected' : ''; ?>>Ar</option>
                <option value="<?php echo appendLangParameter($_SERVER['REQUEST_URI'], 'tr'); ?>" <?php echo ($current_lang === 'tr') ? 'selected' : ''; ?>>Tr</option>
            </select>
        </div>
    </div>
  </div>
</header>

<aside id="sidebar" class="sidebar">
    <div class="sidebar-logo text-center mb-5">
        <a href="#">
            <img src="../logo/logo1.png" alt="Logo" style="max-width: 150px; height: auto;">
        </a>
    </div>
    
    <ul class="sidebar-nav" id="sidebar-nav">
        <li class="nav-item">
            <a class="nav-link" href="<?php echo generateMainLink1(); ?>">
                <i class="bi bi-grid"></i>
                <span><?php echo __("Dashboard"); ?></span>
            </a>
        </li>
        
        <?php if (!isset($_SESSION['SRole'])) : ?>
    <?php 
    // Check current active role instead of session variables
    $currentActiveRole = $_SESSION['current_active_role'] ?? ($_SESSION['AdminRole'] ?? $_SESSION['Role'] ?? '');
    $isAdminRole = in_array($currentActiveRole, ['HOF', 'HOF_Staff', 'HumanResource', 'HumanResource_Staff', 'Rectorate', 'Admin']);
    ?>
    
    <?php if ($isAdminRole): ?>
        <!-- Admin roles see Absence Records -->
        <li class="nav-item">
            <a class="nav-link" href="../Form_1/absenceRecordsForm.php">
                <i class="bi bi-menu-button-wide"></i>
                <span><?php echo __("Absence Records"); ?></span>
            </a>
        </li>
    <?php else: ?>
        <!-- Lecturer and other user roles see Applications -->
        <li class="nav-item">
            <a class="nav-link" href="../Form_1/applicationForm.php">
                <i class="bi bi-menu-button-wide"></i>
                <span><?php echo __("Form Application"); ?></span>
            </a>
        </li>
    <?php endif; ?>
<?php endif; ?>
        
        <?php if (isset($_SESSION['SRole'])) : ?>
       <li class="nav-item">
    <a class="nav-link collapsed" href="#" data-bs-toggle="collapse" data-bs-target="#forms-nav" aria-expanded="false" aria-controls="forms-nav" id="usersButton">
        <i class="bi bi-people"></i>
        <span><?php echo __("Users"); ?></span>
        <i class="bi bi-chevron-down ms-auto"></i>
    </a>
    <ul id="forms-nav" class="nav-content collapse" data-bs-parent="#sidebar-nav">
        <li><a href="../Form_1/addUser.php" class="nav-link"><i class="fa-solid fa-plus me-2"></i><span><?php echo __("Add User"); ?></span></a></li>
        <li><a href="../Form_1/addFaculty.php" class="nav-link"><i class="fa-solid fa-plus me-2"></i><span><?php echo __("Add Faculty"); ?></span></a></li>
        <li><a href="../Status/adminList.php" class="nav-link"><i class="bi bi-table me-2"></i><span><?php echo __("Admin List"); ?></span></a></li>
        <li><a href="../Status/userList.php" class="nav-link"><i class="bi bi-table me-2"></i><span><?php echo __("User List"); ?></span></a></li>
    </ul>
</li>
        <?php endif; ?>
        
        <li class="nav-item mt-auto">
    <a class="nav-link collapsed logout-link" href="../Dashboard/logout.php">
        <i class="bi bi-box-arrow-in-right"></i>
        <span><?php echo __("Logout"); ?></span>
    </a>
</li>
    </ul>
</aside>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
   // Enhanced mobile-responsive JavaScript
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggleButton = document.querySelector('.toggle-sidebar-btn');
    const body = document.body;
    
    // Create overlay element for mobile
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
    
    // Toggle sidebar function
    function toggleSidebar() {
        if (window.innerWidth <= 1200) {
            sidebar.classList.toggle('show-sidebar');
            overlay.classList.toggle('show');
            body.classList.toggle('sidebar-open');
        }
    }
    
    // Close sidebar function
    function closeSidebar() {
        if (window.innerWidth <= 1200) {
            sidebar.classList.remove('show-sidebar');
            overlay.classList.remove('show');
            body.classList.remove('sidebar-open');
        }
    }
    
    // Toggle button event listener
    if (toggleButton) {
        toggleButton.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }
    
    // Overlay click to close sidebar
    overlay.addEventListener('click', closeSidebar);
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 1200) {
            const isClickInsideSidebar = sidebar.contains(e.target);
            const isToggleButton = e.target.closest('.toggle-sidebar-btn');
            
            if (!isClickInsideSidebar && !isToggleButton && sidebar.classList.contains('show-sidebar')) {
                closeSidebar();
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1200) {
            // Desktop view - reset mobile states
            sidebar.classList.remove('show-sidebar');
            overlay.classList.remove('show');
            body.classList.remove('sidebar-open');
        }
    });
    
    // Users dropdown functionality
    const usersButton = document.getElementById("usersButton");
    if (usersButton) {
        usersButton.addEventListener("click", function (event) {
            event.preventDefault();
            const usersList = document.getElementById("forms-nav");
            if (usersList) {
                usersList.classList.toggle("collapse");
            }
        });
    }
    
    // Enhanced notification dropdown for mobile
    function toggleNotifications() {
        const dropdown = document.getElementById("notificationDropdown");
        if (dropdown) {
            dropdown.classList.toggle("show");
            
            // Close other dropdowns
            const profileDropdown = document.querySelector('.dropdown-menu');
            if (profileDropdown && profileDropdown.classList.contains('show')) {
                profileDropdown.classList.remove('show');
            }
            
            // Refresh notifications when opening dropdown
            if (dropdown.classList.contains("show")) {
                refreshNotifications();
            }
        }
    }
    
    // Make toggleNotifications globally available
    window.toggleNotifications = toggleNotifications;
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        // Close notification dropdown
        const notificationDropdown = document.getElementById("notificationDropdown");
        const notificationBtn = e.target.closest('.notification-dropdown');
        
        if (notificationDropdown && !notificationBtn) {
            notificationDropdown.classList.remove('show');
        }
        
        // Close profile dropdown (handled by Bootstrap, but ensure it works on mobile)
        const profileDropdown = document.querySelector('.dropdown-menu.show');
        const profileBtn = e.target.closest('.nav-item.dropdown');
        
        if (profileDropdown && !profileBtn) {
            profileDropdown.classList.remove('show');
        }
    });
    
    // Initialize notification count on page load
    if (typeof updateNotificationCount === 'function') {
        updateNotificationCount();
    }
    
    // Touch-friendly improvements
    if ('ontouchstart' in window) {
        // Add touch class for touch-specific styling
        document.body.classList.add('touch-device');
        
        // Improve touch interactions for sidebar links
        const sidebarLinks = document.querySelectorAll('.sidebar-nav .nav-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('touchstart', function() {
                this.classList.add('touch-active');
            });
            
            link.addEventListener('touchend', function() {
                setTimeout(() => {
                    this.classList.remove('touch-active');
                }, 150);
            });
        });
    }
});

// Enhanced language change function for mobile
function changeLanguage(selectElement) {
    if (selectElement.value) {
        // Add loading state for better UX
        selectElement.disabled = true;
        selectElement.style.opacity = '0.6';
        
        window.location.href = selectElement.value;
    }
}

// Mark notification as read (using toggle_notification_read.php)
function markNotificationAsRead(notificationId) {
    fetch('../notifications/toggle_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Refresh the notifications dropdown and count
            refreshNotifications();
            updateNotificationCount();
        } else {
            console.error('Failed to mark notification as read:', data.message);
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Delete notification from navbar
function deleteNotificationFromNavbar(notificationId) {
    if (confirm('Are you sure you want to delete this notification?')) {
        fetch('../notifications/delete_notification.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'notification_id=' + notificationId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Refresh the notifications dropdown and count
                refreshNotifications();
                updateNotificationCount();
            } else {
                console.error('Failed to delete notification:', data.message);
            }
        })
        .catch(error => {
            console.error('Error deleting notification:', error);
        });
    }
}

// Handle notification click
function handleNotificationClick(notificationId) {
    markNotificationAsRead(notificationId);
}

// Update notification count
function updateNotificationCount() {
    fetch('../notifications/get_notification_count.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notification-badge');
                const headerBadge = document.querySelector('.notification-header .badge');
                
                if (data.count > 0) {
                    if (badge) {
                        badge.textContent = data.count;
                        badge.style.display = 'block';
                    } else {
                        const notificationBtn = document.querySelector('.notification-btn');
                        if (notificationBtn) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'notification-badge';
                            newBadge.textContent = data.count;
                            notificationBtn.appendChild(newBadge);
                        }
                    }
                    
                    if (headerBadge) {
                        headerBadge.textContent = data.count + ' ' + (window.currentLang === 'tr' ? 'Okunmamış' : 'Unread');
                        headerBadge.style.display = 'inline';
                    }
                } else {
                    if (badge) badge.style.display = 'none';
                    if (headerBadge) headerBadge.style.display = 'none';
                }
            } else {
                console.warn('Notification count fetch failed:', data.error);
            }
        })
        .catch(error => {
            console.error('Error updating notification count:', error);
            // Hide badges on error
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.style.display = 'none';
        });
}

// Refresh notifications dropdown
function refreshNotifications() {
    fetch('../notifications/get_notifications_dropdown.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const dropdown = document.getElementById('notificationDropdown');
                if (dropdown) {
                    dropdown.innerHTML = data.html;
                }
                updateNotificationCount();
            } else {
                console.warn('Notification refresh failed:', data.error);
            }
        })
        .catch(error => {
            console.error('Error refreshing notifications:', error);
            // Show error message in dropdown
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown) {
                dropdown.innerHTML = '<div class="no-notifications">Unable to load notifications</div>';
            }
        });
}

// Auto-refresh notification count every 30 seconds
setInterval(updateNotificationCount, 30000);

</script>
</body>
</html>