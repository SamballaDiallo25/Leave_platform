<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Development mode - disable security delays
define('DEV_MODE', true);
if (DEV_MODE) {
    $progressive_delay_base = 0;
    $max_attempts_per_username = 1000;
    $max_attempts_per_ip = 1000;
}
// Set secure session settings BEFORE session_start
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
ini_set('session.cookie_samesite', 'Strict');

// Now start the session
session_start();

// session_start();

// Security Headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:;");

// // Configure secure session settings
// if (session_status() == PHP_SESSION_ACTIVE) {
//     ini_set('session.cookie_httponly', 1);
//     ini_set('session.use_only_cookies', 1);
//     ini_set('session.cookie_secure', 1);
//     ini_set('session.cookie_samesite', 'Strict');
// }

include("configuration/configuration.php");
$conn = new mysqli($servername, $username, $password, $database);

// Check for connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// CSRF Token Functions
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input Sanitization Function
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Check if the user is already logged in
if (isset($_SESSION['user_id']) || isset($_SESSION['AdminID']) || isset($_SESSION['SuperAdminId'])) {
    $_SESSION['logged_in_user'] = true;
    if (isset($_SESSION['SuperAdminId']) && $_SESSION['SRole'] === 'SuperAdmin') {
        header("Location: ../Dashboard/superAdminDashboard.php");
        exit();
    } elseif (isset($_SESSION['AdminID']) && $_SESSION['is_admin'] == 1) {
        header("Location: ../Dashboard/adminDashboard.php");
        exit();
    } elseif (isset($_SESSION['user_id']) && $_SESSION['is_admin'] == 0) {
        header("Location: ../Dashboard/userDashboard.php");
        exit();
    }
}

// Enhanced Rate Limiting Configuration
// $max_attempts_per_username = 5;
// $max_attempts_per_ip = 15;
$max_attempts_per_username = 100; // Increase from 5
$max_attempts_per_ip = 200; // Increase from 15
$max_attempts_global = 100;
$lockout_time_username = 900; // 15 minutes
$lockout_time_ip = 1800; // 30 minutes
$lockout_time_global = 3600; // 1 hour
$progressive_delay_base = 1; // seconds, doubles with each attempt

// Enhanced Rate Limiting Functions
function getClientIP() {
    $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cleanOldAttempts($conn) {
    global $lockout_time_username, $lockout_time_ip, $lockout_time_global;
    
    $max_cleanup_time = max($lockout_time_username, $lockout_time_ip, $lockout_time_global);
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("i", $max_cleanup_time);
    $stmt->execute();
    $stmt->close();
}

function checkUsernameRateLimit($conn, $username) {
    global $max_attempts_per_username, $lockout_time_username;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("si", $username, $lockout_time_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'] < $max_attempts_per_username;
}

function checkIPRateLimit($conn, $ip) {
    global $max_attempts_per_ip, $lockout_time_ip;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("si", $ip, $lockout_time_ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'] < $max_attempts_per_ip;
}

function checkGlobalRateLimit($conn) {
    global $max_attempts_global, $lockout_time_global;
    
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("i", $lockout_time_global);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'] < $max_attempts_global;
}

function getAttemptCount($conn, $identifier, $type) {
    global $lockout_time_username, $lockout_time_ip;
    
    $lockout_time = ($type === 'username') ? $lockout_time_username : $lockout_time_ip;
    $column = ($type === 'username') ? 'username' : 'ip_address';
    
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE $column = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("si", $identifier, $lockout_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'];
}

function calculateProgressiveDelay($attempts) {
    // global $progressive_delay_base;
    
    // if ($attempts <= 1) return 0;
    
    // // Progressive delay: 2^(attempts-1) seconds, capped at 60 seconds
    // $delay = pow($progressive_delay_base, $attempts - 1);
    // return min($delay, 60);
    return 0;
}

function applyProgressiveDelay($conn, $username, $ip) {
    $username_attempts = getAttemptCount($conn, $username, 'username');
    $ip_attempts = getAttemptCount($conn, $ip, 'ip');
    
    // Use the higher attempt count for delay calculation
    $max_attempts = max($username_attempts, $ip_attempts);
    $delay = calculateProgressiveDelay($max_attempts);
    
    if ($delay > 0) {
        sleep($delay);
    }
}

function logFailedAttempt($conn, $username, $ip) {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, attempt_time) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("sss", $username, $ip, $user_agent);
    $stmt->execute();
    $stmt->close();
}

function isAccountLocked($conn, $username) {
    global $max_attempts_per_username;
    
    // Check if account should be temporarily locked due to too many failed attempts
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Account is locked if more than 3 times the normal limit in the last hour
    return $row['attempts'] >= ($max_attempts_per_username * 3);
}

function getRemainingLockoutTime($conn, $username, $ip) {
    global $lockout_time_username, $lockout_time_ip;
    
    // Check username lockout
    $stmt = $conn->prepare("SELECT MAX(attempt_time) as last_attempt FROM login_attempts WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("si", $username, $lockout_time_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['last_attempt']) {
        $last_attempt = new DateTime($row['last_attempt']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $last_attempt->getTimestamp();
        $username_remaining = max(0, $lockout_time_username - $diff);
    } else {
        $username_remaining = 0;
    }
    
    // Check IP lockout
    $stmt = $conn->prepare("SELECT MAX(attempt_time) as last_attempt FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param("si", $ip, $lockout_time_ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['last_attempt']) {
        $last_attempt = new DateTime($row['last_attempt']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $last_attempt->getTimestamp();
        $ip_remaining = max(0, $lockout_time_ip - $diff);
    } else {
        $ip_remaining = 0;
    }
    
    return max($username_remaining, $ip_remaining);
}

function detectSuspiciousActivity($conn, $username, $ip) {
    // Check for rapid-fire attempts (more than 10 attempts in 1 minute)
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE (username = ? OR ip_address = ?) AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $stmt->bind_param("ss", $username, $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['attempts'] > 10) {
        return "Rapid-fire attempts detected";
    }
    
    // Check for distributed attacks (same IP trying multiple usernames)
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT username) as unique_usernames FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['unique_usernames'] > 5) {
        return "Multiple username attempts from same IP";
    }
    
    return null;
}

// Function to check if password is unique
function isPasswordUnique($conn, $password, $excludeUserId = null) {
    $sql = "SELECT COUNT(*) as count FROM users1 WHERE password = ?";
    $params = [$password];
    $types = "s";
    
    if ($excludeUserId) {
        $sql .= " AND user_id != ?";
        $params[] = $excludeUserId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] == 0;
}

// Function to generate unique password suggestion
function generateUniquePassword($conn, $basePassword) {
    $counter = 1;
    $uniquePassword = $basePassword;
    
    // Ensure base password meets minimum requirements
    if (strlen($basePassword) < 6) {
        $basePassword = "SecurePass" . $basePassword;
    }
    
    // Add uppercase if missing
    if (!preg_match('/[A-Z]/', $basePassword)) {
        $basePassword = ucfirst($basePassword);
    }
    
    // Add number if missing
    if (!preg_match('/[0-9]/', $basePassword)) {
        $basePassword .= "1";
    }
    
    // Generate unique password
    while (!isPasswordUnique($conn, $uniquePassword)) {
        $uniquePassword = $basePassword . "U" . $counter;
        $counter++;
        
        // Prevent infinite loop
        if ($counter > 1000) {
            $uniquePassword = $basePassword . "U" . uniqid();
            break;
        }
    }
    
    return $uniquePassword;
}


// Function to validate password format
function validatePasswordFormat($password) {
    $errors = [];
    
    // Check minimum length (8 characters)
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    // Check for at least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    // Check for at least one number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

// Function to hash password securely
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Function to check if migration is complete
function isMigrationComplete($conn) {
    // Check if password_migrated column exists
    $result = $conn->query("SHOW COLUMNS FROM users1 LIKE 'password_migrated'");
    if ($result->num_rows == 0) {
        return false; // Migration not started
    }
    
    // Check if all users have been migrated
    $result = $conn->query("SELECT COUNT(*) as count FROM users1 WHERE password_migrated = 0");
    $row = $result->fetch_assoc();
    return $row['count'] == 0;
}

// Function to check if user's password has been migrated
function isUserPasswordMigrated($conn, $user_id) {
    // Check if password_migrated column exists
    $result = $conn->query("SHOW COLUMNS FROM users1 LIKE 'password_migrated'");
    if ($result->num_rows == 0) {
        return false; // Migration not started
    }
    
    $stmt = $conn->prepare("SELECT password_migrated FROM users1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['password_migrated'] == 1 : false;
}

// Function to get user's multiple roles and determine dashboard options
function getUserRoleOptions($conn, $user_id) {
    $roles = [];
    $primary_role = '';
    $has_admin_role = false;
    
    // Get roles from user_roles table
    $rolesSql = "SELECT role_name, is_primary FROM user_roles WHERE user_id = ?";
    $rolesStmt = $conn->prepare($rolesSql);
    $rolesStmt->bind_param("i", $user_id);
    $rolesStmt->execute();
    $rolesResult = $rolesStmt->get_result();
    
    // Define which roles have admin privileges
    $availableRoles = [
        'Lecturer' => ['is_admin' => 0],
        'HOF' => ['is_admin' => 1],
        'HOF_Staff' => ['is_admin' => 1],
        'HumanResource' => ['is_admin' => 1],
        'HumanResource_Staff' => ['is_admin' => 1],
        'Rectorate' => ['is_admin' => 1]
    ];
    
    while ($roleRow = $rolesResult->fetch_assoc()) {
        $roles[] = $roleRow['role_name'];
        if ($roleRow['is_primary']) {
            $primary_role = $roleRow['role_name'];
        }
        // Check if this role has admin privileges
        if (isset($availableRoles[$roleRow['role_name']]) && $availableRoles[$roleRow['role_name']]['is_admin'] == 1) {
            $has_admin_role = true;
        }
    }
    $rolesStmt->close();
    
    // Fallback to main Role field if no roles found in user_roles table
    if (empty($roles)) {
        $userSql = "SELECT Role, is_admin FROM users1 WHERE user_id = ?";
        $userStmt = $conn->prepare($userSql);
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $userStmt->bind_result($main_role, $is_admin);
        $userStmt->fetch();
        $userStmt->close();
        
        if ($main_role) {
            $roles = [$main_role];
            $primary_role = $main_role;
            $has_admin_role = ($is_admin == 1);
        }
    }
    
    return [
        'roles' => $roles,
        'primary_role' => $primary_role,
        'has_admin_role' => $has_admin_role,
        'can_access_admin' => $has_admin_role,
        'can_access_user' => true // All users can access user dashboard
    ];
}

// Function to get user's last used dashboard
function getLastDashboard($conn, $user_id) {
    $stmt = $conn->prepare("SELECT last_dashboard FROM users1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['last_dashboard'] : null;
}

// Function to update user's last used dashboard
function updateLastDashboard($conn, $user_id, $dashboard_type) {
    $stmt = $conn->prepare("UPDATE users1 SET last_dashboard = ? WHERE user_id = ?");
    $stmt->bind_param("si", $dashboard_type, $user_id);
    $stmt->execute();
    $stmt->close();
}

// Initialize error message
$error_message = '';
$migration_complete = isMigrationComplete($conn);
$client_ip = getClientIP();

// Check if username and password are posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    // Clean old attempts first
    cleanOldAttempts($conn);
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = "Invalid request. Please try again.";
    } else {
        // Sanitize input
        $user_name = sanitizeInput($_POST['username']);
        $password = $_POST['password']; // Don't sanitize password as it needs to be exact
        
        // Input validation
        if (empty($user_name) || empty($password)) {
            $error_message = "Please enter both username and password.";
        } elseif (strlen($user_name) > 50 || strlen($password) > 100) {
            $error_message = "Invalid input length.";
        } else {
            // Check for suspicious activity
            $suspicious_activity = detectSuspiciousActivity($conn, $user_name, $client_ip);
            if ($suspicious_activity) {
                $error_message = "Suspicious activity detected. Please try again later.";
                // Log the suspicious activity
                logFailedAttempt($conn, $user_name, $client_ip);
            }
            // Check if account is locked
            elseif (isAccountLocked($conn, $user_name)) {
                $error_message = "Account temporarily locked due to multiple failed attempts. Please contact administrator.";
            }
            // Check all rate limits
            elseif (!checkUsernameRateLimit($conn, $user_name)) {
                $remaining_time = getRemainingLockoutTime($conn, $user_name, $client_ip);
                $minutes = ceil($remaining_time / 60);
                $error_message = "Too many failed attempts for this username. Please try again in $minutes minutes.";
            }
            elseif (!checkIPRateLimit($conn, $client_ip)) {
                $remaining_time = getRemainingLockoutTime($conn, $user_name, $client_ip);
                $minutes = ceil($remaining_time / 60);
                $error_message = "Too many failed attempts from this IP address. Please try again in $minutes minutes.";
            }
            elseif (!checkGlobalRateLimit($conn)) {
                $error_message = "System is experiencing high traffic. Please try again later.";
            }
            else {
                // Apply progressive delay before checking credentials
                applyProgressiveDelay($conn, $user_name, $client_ip);
                
                // First, check if user exists
                $sql = "SELECT user_id, user_name, password, is_admin, fullName, Role, faculty_id FROM users1 WHERE user_name = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $user_name);
                $stmt->execute();
                $stmt->bind_result($user_id, $user_name, $storedPassword, $is_admin, $fullName, $role, $faculty_id);
                $stmt->fetch();
                $stmt->close();

                if ($user_id) {
                    // Use plain text password comparison (no hash)
                    $password_valid = ($password === $storedPassword);

                    if ($password_valid) {
                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Clear any previous failed attempts for this user
                        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ?");
                        $stmt->bind_param("s", $user_name);
                        $stmt->execute();
                        $stmt->close();

                        // Get user's role options and last dashboard preference
                        $roleOptions = getUserRoleOptions($conn, $user_id);
                        $lastDashboard = getLastDashboard($conn, $user_id);

                        // Set common session variables
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['user_name'] = sanitizeInput($user_name);
                        $_SESSION['fullName'] = sanitizeInput($fullName);
                        $_SESSION['faculty_id'] = $faculty_id;
                        $_SESSION['user_roles'] = $roleOptions['roles'];
                        $_SESSION['primary_role'] = $roleOptions['primary_role'];

                        // Handle SuperAdmin (highest priority)
                        if ($role == 'SuperAdmin') {
                            $_SESSION['SuperAdminId'] = $user_id;
                            $_SESSION['SRole'] = sanitizeInput($role);
                            $_SESSION['SuperAdminFacultyId'] = $faculty_id;
                            $_SESSION['SuperAdminName'] = sanitizeInput($user_name);
                            $_SESSION['is_admin'] = $is_admin;
                            $_SESSION['SuperAdminRole'] = sanitizeInput($role);
                            $_SESSION['AdminfullName'] = sanitizeInput($fullName);
                            
                            updateLastDashboard($conn, $user_id, 'super_admin');
                            header("Location: ../Dashboard/superAdminDashboard.php");
                            exit();
                        }
                        
                        // Handle users with multiple role options
                        if ($roleOptions['can_access_admin'] && count($roleOptions['roles']) > 1) {
                            // User has multiple roles - check last preference
                            if ($lastDashboard === 'admin' && $roleOptions['can_access_admin']) {
                                // Redirect to admin dashboard
                                $_SESSION['AdminID'] = $user_id;
                                $_SESSION['AdminRole'] = $roleOptions['primary_role'];
                                $_SESSION['Admin_facultyID'] = $faculty_id;
                                $_SESSION['Admin_name'] = sanitizeInput($user_name);
                                $_SESSION['is_admin'] = 1;
                                $_SESSION['AdminfullName'] = sanitizeInput($fullName);
                                
                                header("Location: ../Dashboard/adminDashboard.php");
                                exit();
                            } elseif ($lastDashboard === 'user') {
                                // Redirect to user dashboard
                                $_SESSION['is_admin'] = 0;
                                $_SESSION['Role'] = $roleOptions['primary_role'];
                                
                                header("Location: ../Dashboard/userDashboard.php");
                                exit();
                            } else {
                                // No preference set - redirect based on primary role
                                if ($roleOptions['primary_role'] === 'Lecturer') {
                                    // Lecturer primary role goes to user dashboard
                                    $_SESSION['is_admin'] = 0;
                                    $_SESSION['Role'] = $roleOptions['primary_role'];
                                    
                                    updateLastDashboard($conn, $user_id, 'user');
                                    header("Location: ../Dashboard/userDashboard.php");
                                    exit();
                                } else {
                                    // All other primary roles (HOF, HOF_Staff, HumanResource, etc.) go to admin dashboard
                                    $_SESSION['AdminID'] = $user_id;
                                    $_SESSION['AdminRole'] = $roleOptions['primary_role'];
                                    $_SESSION['Admin_facultyID'] = $faculty_id;
                                    $_SESSION['Admin_name'] = sanitizeInput($user_name);
                                    $_SESSION['is_admin'] = 1;
                                    $_SESSION['AdminfullName'] = sanitizeInput($fullName);
                                    
                                    updateLastDashboard($conn, $user_id, 'admin');
                                    header("Location: ../Dashboard/adminDashboard.php");
                                    exit();
                                }
                            }
                        }
                        // Handle single-role users (existing logic)
                        elseif ($is_admin == 1 && $role != 'SuperAdmin') {
                            $_SESSION['AdminID'] = $user_id;
                            $_SESSION['AdminRole'] = sanitizeInput($role);
                            $_SESSION['Admin_facultyID'] = $faculty_id;
                            $_SESSION['Admin_name'] = sanitizeInput($user_name);
                            $_SESSION['is_admin'] = $is_admin;
                            $_SESSION['AdminfullName'] = sanitizeInput($fullName);
                            
                            updateLastDashboard($conn, $user_id, 'admin');
                            header("Location: ../Dashboard/adminDashboard.php");
                            exit();
                        } elseif ($is_admin == 0) {
                            $_SESSION['is_admin'] = $is_admin;
                            $_SESSION['Role'] = sanitizeInput($role);
                            
                            updateLastDashboard($conn, $user_id, 'user');
                            header("Location: ../Dashboard/userDashboard.php");
                            exit();
                        }
                    } else {
                        // Invalid credentials - log failed attempt
                        logFailedAttempt($conn, $user_name, $client_ip);
                        $error_message = "Incorrect username or password.";
                    }
                } else {
                    // User not found - log failed attempt
                    logFailedAttempt($conn, $user_name, $client_ip);
                    $error_message = "Incorrect username or password.";
                } 
            }
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

$conn->close();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Form</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="login.css?v=<?php echo time(); ?>">

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const inputs = document.querySelectorAll(".inputBox input");
            
            inputs.forEach(input => {
                input.addEventListener("click", function() {
                    const errorMsg = document.getElementById("error-message");
                    if (errorMsg) {
                        errorMsg.style.display = "none";
                    }
                });
            });

            // Real-time password validation feedback
            const passwordField = document.getElementById("password");
            const requirementsList = document.getElementById("password-requirements");
            
            if (passwordField && requirementsList) {
                passwordField.addEventListener("input", function() {
                    const password = this.value;
                    const requirements = {
                        length: password.length >= 8,
                        uppercase: /[A-Z]/.test(password),
                        number: /[0-9]/.test(password)
                    };
                    
                    // Update requirement indicators
                    updateRequirement("length-req", requirements.length);
                    updateRequirement("uppercase-req", requirements.uppercase);
                    updateRequirement("number-req", requirements.number);
                    
                    // Show/hide requirements list
                    if (password.length > 0) {
                        requirementsList.style.display = "block";
                    } else {
                        requirementsList.style.display = "none";
                    }
                });
            }
            
            function updateRequirement(id, met) {
                const element = document.getElementById(id);
                if (element) {
                    if (met) {
                        element.style.color = "green";
                        element.innerHTML = "✓ " + element.innerHTML.replace(/[✓✗] /, "");
                    } else {
                        element.style.color = "red";
                        element.innerHTML = "✗ " + element.innerHTML.replace(/[✓✗] /, "");
                    }
                }
            }

            // Disable form submission for a short time after failed attempt
            const form = document.querySelector('form');
            const submitButton = document.querySelector('.login-btn');
            
            <?php if (!empty($error_message)): ?>
            // Add a small delay after failed login to prevent rapid submissions
            submitButton.disabled = true;
            setTimeout(function() {
                submitButton.disabled = false;
            }, 500); // Reduce from 2000ms to 500ms
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="main-container">
        <div class="image-side"></div>
        <div class="login-side">
            <div class="login-container">
                <div class="login-logo">
                    <div class="logo-container">
                        <img src="../logo/logo5.png" alt="Logo" class='logo'>
                    </div>
                    <h1>Leave and Absence Portal</h1>
                </div>
                
                <div class="login-form">
                    <form action="index.php" method="POST">
                        <!-- CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <div class="inputBox">
                            <input type="text" id="username" name="username" required maxlength="50" 
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                            <span>Username</span>
                        </div>
                        <div class="inputBox">
                            <input type="password" id="password" name="password" required maxlength="100">
                            <span>Password</span>
                        </div>
                        
                        <!-- Password requirements display
                        <div id="password-requirements" style="display: none; margin-top: 10px; font-size: 12px;">
                            <div id="length-req" style="color: red;">✗ At least 8 characters</div>
                            <div id="uppercase-req" style="color: red;">✗ At least one uppercase letter</div>
                            <div id="number-req" style="color: red;">✗ At least one number</div>
                        </div> -->
                        
                        <?php if (!empty($error_message)) : ?>
                        <p id="error-message" style="color: red; margin-top: 10px;"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn login-btn">Log in</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Session timeout message -->
    <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning">
            Your session has expired. Please login again.
        </div>
    <?php endif; ?>
</body>
</html>