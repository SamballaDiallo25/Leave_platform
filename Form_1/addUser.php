<?php 
session_start();
if (!isset($_SESSION['SuperAdminName'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../lang.php';
require_once '../navbar.php';
include("../configuration/configuration.php");

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$successMessage = "";
$errors = [];

// Get the next available user ID
$nextUserId = 1; // Default if no users exist
$getLastIdSql = "SELECT MAX(user_id) as last_id FROM users1";
$lastIdResult = $conn->query($getLastIdSql);
if ($lastIdResult && $row = $lastIdResult->fetch_assoc()) {
    if ($row['last_id'] !== null) {
        $nextUserId = $row['last_id'] + 1;
    }
}

// Define available roles
$availableRoles = [
    'Lecturer' => ['label' => 'Lecturer', 'is_admin' => 0],
    'HOF' => ['label' => 'Head of Faculty (HOF)', 'is_admin' => 1],
    'HOF_Staff' => ['label' => 'HOF Staff', 'is_admin' => 1],
    'HumanResource' => ['label' => 'Human Resource', 'is_admin' => 1],
    'HumanResource_Staff' => ['label' => 'Human Resource Staff', 'is_admin' => 1],
    'Rectorate' => ['label' => 'Rectorate', 'is_admin' => 1],
    'SuperAdmin' => ['label' => 'SuperAdmin', 'is_admin' => 1]
];

if (isset($_POST["submit"])) {
    $fullname = trim($_POST["fullname"]);
    $username = trim($_POST["user_name"]);
    $password = $_POST["password"];
    $faculty_id = $_POST["department"];
    $email = trim($_POST["email"]);
    $selectedRoles = $_POST["roles"] ?? [];
    $primaryRole = $_POST["primary_role"] ?? '';

    // Validation for roles
    if (empty($selectedRoles)) {
        $errors['roles'] = __("Please select at least one role.");
    }

    if (empty($primaryRole) && !empty($selectedRoles)) {
        $errors['primary_role'] = __("Please select a primary role.");
    }

    if (!empty($primaryRole) && !in_array($primaryRole, $selectedRoles)) {
        $errors['primary_role'] = __("Primary role must be one of the selected roles.");
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = __("Invalid email format.");
    } else {
        // Check if email already exists
        $checkEmailSql = "SELECT email FROM users1 WHERE email = ?";
        if ($checkEmailStmt = $conn->prepare($checkEmailSql)) {
            $checkEmailStmt->bind_param("s", $email);
            $checkEmailStmt->execute();
            $checkEmailResult = $checkEmailStmt->get_result();
            
            if ($checkEmailResult->num_rows > 0) {
                $errors['email'] = __("This email is already in use.");
            }
            $checkEmailStmt->close();
        }
    }

    // Check if username already exists
    $checkUserSql = "SELECT user_name FROM users1 WHERE user_name = ?";
    if ($checkUserStmt = $conn->prepare($checkUserSql)) {
        $checkUserStmt->bind_param("s", $username);
        $checkUserStmt->execute();
        $checkUserResult = $checkUserStmt->get_result();
        
        if ($checkUserResult->num_rows > 0) {
            $errors['username'] = __("This username is already taken.");
        }
        $checkUserStmt->close();
    }

    // Validate required fields
    if (empty($fullname)) {
        $errors['fullname'] = __("Full name is required.");
    }
    if (empty($username)) {
        $errors['username'] = __("Username is required.");
    }
    if (empty($password)) {
        $errors['password'] = __("Password is required.");
    }

    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Determine is_admin based on roles
            $is_admin = 0;
            foreach ($selectedRoles as $role) {
                if (isset($availableRoles[$role]) && $availableRoles[$role]['is_admin'] == 1) {
                    $is_admin = 1;
                    break;
                }
            }

            // Insert new user with primary role
            $sql = "INSERT INTO users1 (fullName, password, Role, user_name, faculty_id, email, is_admin, primary_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssisss", $fullname, $password, $primaryRole, $username, $faculty_id, $email, $is_admin, $primaryRole);
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating user: " . $stmt->error);
            }
            
            $new_user_id = $conn->insert_id;
            $stmt->close();

            // Insert roles into user_roles table
            foreach ($selectedRoles as $role) {
                $isPrimary = ($role === $primaryRole) ? 1 : 0;
                
                $roleStmt = $conn->prepare("INSERT INTO user_roles (user_id, role_name, is_primary) VALUES (?, ?, ?)");
                $roleStmt->bind_param("isi", $new_user_id, $role, $isPrimary);
                
                if (!$roleStmt->execute()) {
                    throw new Exception("Error inserting role: " . $roleStmt->error);
                }
                $roleStmt->close();
            }

            $conn->commit();
            $successMessage = __("User added successfully!") . " " . __("New User ID:") . " " . $new_user_id;
            
            // Recalculate next user ID after successful addition
            $getLastIdSql = "SELECT MAX(user_id) as last_id FROM users1";
            $lastIdResult = $conn->query($getLastIdSql);
            if ($lastIdResult && $row = $lastIdResult->fetch_assoc()) {
                if ($row['last_id'] !== null) {
                    $nextUserId = $row['last_id'] + 1;
                }
            }
            
            // Clear form data after successful submission
            $_POST = array();
            
        } catch (Exception $e) {
            $conn->rollback();
            $successMessage = __("Error: ") . $e->getMessage();
        }
    } else {
        $successMessage = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($translator->getCurrentLanguage()); ?>" dir="<?php echo htmlspecialchars($translator->getTextDirection()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Add New User') ?></title>
    <style>
        @media (min-width: 1203px) {
            .main-content {
                margin-left: 300px;
                width: calc(100% - 300px);
                margin-top:50px;
            }
        }
        section{
            padding-top:20px;
        }
        .SubmitButton1 {
            background-color: #141414;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            border-radius: 5%;
            width: auto;
            float: right;
            margin-right: 10px;
        }
        @media (max-width: 430px) {
            .button {
                display: flex;
                justify-content: center;
                align-items: center;
            }
        }
        h5 {
            text-align: center;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .error-message {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
        .form-control.error {
            border-color: #dc3545;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
    <link rel="icon" href="../logo/logo1.png" type="image/png">
</head>
<body>
<section class="main-content">
   <div class="container mt-5">
       <?php if ($successMessage): ?>
           <div id="success-message" class="alert <?php echo empty($errors) ? 'alert-success' : 'alert-danger'; ?>">
               <?php echo $successMessage; ?>
           </div>
       <?php endif; ?>
       
       <form action="" method="post">
           <div class="card mb-4">
               <div class="card-header"><?php echo __("Add New User"); ?></div>
               <div class="card-body">
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="user_id_display" class="mr-2"><?php echo __("User ID"); ?>:</label>
                           <input type="text" id="user_id_display" class="form-control" value="<?php echo $nextUserId; ?>" readonly style="background-color: #e9ecef;">
                           <!-- <small class="form-text text-muted"><?php echo __("This ID will be automatically assigned to the new user."); ?></small> -->
                       </div>
                   </div>
                   
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="fullname" class="mr-2"><?php echo __("Full Name"); ?>: <span class="text-danger">*</span></label>
                           <input type="text" name="fullname" id="fullname" class="form-control <?php echo isset($errors['fullname']) ? 'error' : ''; ?>" value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>" required>
                           <?php if (isset($errors['fullname'])): ?>
                               <span class="error-message"><?php echo $errors['fullname']; ?></span>
                           <?php endif; ?>
                       </div>
                   </div>
                   
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="user_name" class="mr-2"><?php echo __("User Name"); ?>: <span class="text-danger">*</span></label>
                           <input type="text" name="user_name" id="user_name" class="form-control <?php echo isset($errors['username']) ? 'error' : ''; ?>" value="<?php echo isset($_POST['user_name']) ? htmlspecialchars($_POST['user_name']) : ''; ?>" required>
                           <?php if (isset($errors['username'])): ?>
                               <span class="error-message"><?php echo $errors['username']; ?></span>
                           <?php endif; ?>
                       </div>
                   </div>
                   
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="password" class="mr-2"><?php echo __("Password"); ?>: <span class="text-danger">*</span></label>
                           <input type="password" name="password" id="password" class="form-control <?php echo isset($errors['password']) ? 'error' : ''; ?>" required>
                           <?php if (isset($errors['password'])): ?>
                               <span class="error-message"><?php echo $errors['password']; ?></span>
                           <?php endif; ?>
                       </div>
                   </div>
                   
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="email" class="mr-2"><?php echo __("Email"); ?>: <span class="text-danger">*</span></label>
                           <input type="email" name="email" id="email" class="form-control <?php echo isset($errors['email']) ? 'error' : ''; ?>" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                           <?php if (isset($errors['email'])): ?>
                               <span class="error-message"><?php echo $errors['email']; ?></span>
                           <?php endif; ?>
                       </div>
                   </div>
                   
                   <div class="form-row align-items-center mb-2">
                       <div class="form-group col-md-12">
                           <label for="Department" class="mr-2"><?php echo __("Department"); ?>: <span class="text-danger">*</span></label>
                           <select class="form-control" id="Department" name="department" required>
                               <option value=""><?php echo __("Select Department"); ?></option>
                               <?php
                               $sql_faculties = "SELECT faculty_id, faculty_name FROM faculties1";
                               $result = $conn->query($sql_faculties);
                               while ($row = $result->fetch_assoc()) {
                                   $selected = (isset($_POST['department']) && $_POST['department'] == $row["faculty_id"]) ? 'selected' : '';
                                   echo '<option value="' . $row["faculty_id"] . '" ' . $selected . '>' . htmlspecialchars($row["faculty_name"]) . '</option>';
                               }
                               ?>
                           </select>
                       </div>
                   </div>
                   
                   <!-- Multi-Role Section -->
                   <div class="form-row align-items-center mb-4">
                       <div class="form-group col-md-12">
                           <label class="mr-2"><?php echo __("Roles/Positions"); ?>: <span class="text-danger">*</span></label>
                           <div class="role-checkbox-container" style="border: 1px solid #ced4da; padding: 15px; border-radius: 4px; background-color: #f8f9fa;">
                               <?php foreach ($availableRoles as $roleKey => $roleData): ?>
                                   <div class="form-check mb-2">
                                       <input 
                                           class="form-check-input role-checkbox" 
                                           type="checkbox" 
                                           name="roles[]" 
                                           value="<?php echo $roleKey; ?>" 
                                           id="role_<?php echo $roleKey; ?>"
                                           <?php echo (isset($_POST['roles']) && in_array($roleKey, $_POST['roles'])) ? 'checked' : ''; ?>
                                           onchange="updatePrimaryRoleOptions()"
                                       >
                                       <label class="form-check-label" for="role_<?php echo $roleKey; ?>">
                                           <?php echo __($roleData['label']); ?>
                                           <small class="text-muted">(<?php echo $roleData['is_admin'] ? __('Admin') : __('User'); ?>)</small>
                                       </label>
                                   </div>
                               <?php endforeach; ?>
                           </div>
                           <?php if (isset($errors['roles'])): ?>
                               <span class="error-message"><?php echo $errors['roles']; ?></span>
                           <?php endif; ?>
                           <div class="error-message" id="roles-error" style="display: none;"></div>
                       </div>
                   </div>

                   <div class="form-row align-items-center mb-4">
                       <div class="form-group col-md-12">
                           <label for="primary_role" class="mr-2"><?php echo __("Primary Role"); ?>: <span class="text-danger">*</span></label>
                           <select class="form-control" id="primary_role" name="primary_role" required>
                               <option value=""><?php echo __("Select Primary Role"); ?></option>
                               <?php 
                               if (isset($_POST['roles'])) {
                                   foreach ($_POST['roles'] as $role) {
                                       $selected = (isset($_POST['primary_role']) && $_POST['primary_role'] == $role) ? 'selected' : '';
                                       echo '<option value="' . $role . '" ' . $selected . '>' . __($availableRoles[$role]['label']) . '</option>';
                                   }
                               }
                               ?>
                           </select>
                           <small class="form-text text-muted"><?php echo __("The primary role will be used as the default role and main position."); ?></small>
                           <?php if (isset($errors['primary_role'])): ?>
                               <span class="error-message"><?php echo $errors['primary_role']; ?></span>
                           <?php endif; ?>
                           <div class="error-message" id="primary-role-error" style="display: none;"></div>
                       </div>
                   </div>
                   
                   <div class="button">
                       <button type="submit" class="SubmitButton1" name="submit" id="submit"><?php echo __("Add User"); ?></button>
                       <button type="button" class="SubmitButton1" name="btn" id="btn"><?php echo __("Cancel"); ?></button>
                   </div>
               </div>
           </div>
       </form>
   </div>
</section>

<script>
    // Success message handling with delay
    document.addEventListener('DOMContentLoaded', function() {
        const successMessage = document.getElementById('success-message');
        let canHideMessage = false;

        if (successMessage) {
            setTimeout(() => {
                canHideMessage = true;
            }, 3000);
        }

        document.querySelectorAll('input').forEach(function(input) {
            input.addEventListener('focus', function() {
                if (successMessage && canHideMessage) {
                    successMessage.style.display = 'none';
                }
            });
        });
    });

    document.getElementById("btn").addEventListener("click", function(event) {
        event.preventDefault();
        window.location.href = "userList.php";
    });

    // Dynamic primary role dropdown
    const roleLabels = <?php echo json_encode(array_map(function($role) { return __($role['label']); }, $availableRoles)); ?>;

    function updatePrimaryRoleOptions() {
        const checkboxes = document.querySelectorAll('.role-checkbox:checked');
        const primaryRoleSelect = document.getElementById('primary_role');
        const currentValue = primaryRoleSelect.value;
        
        primaryRoleSelect.innerHTML = '<option value=""><?php echo __("Select Primary Role"); ?></option>';
        
        checkboxes.forEach(checkbox => {
            const option = document.createElement('option');
            option.value = checkbox.value;
            option.textContent = checkbox.nextElementSibling.textContent.split('(')[0].trim();
            if (checkbox.value === currentValue) {
                option.selected = true;
            }
            primaryRoleSelect.appendChild(option);
        });
        
        if (currentValue && !Array.from(checkboxes).some(cb => cb.value === currentValue)) {
            primaryRoleSelect.value = '';
        }
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const selectedRoles = document.querySelectorAll('.role-checkbox:checked');
        const primaryRole = document.getElementById('primary_role').value;
        
        let hasError = false;
        
        if (selectedRoles.length === 0) {
            document.getElementById('roles-error').textContent = '<?php echo __("Please select at least one role."); ?>';
            document.getElementById('roles-error').style.display = 'block';
            hasError = true;
        } else {
            document.getElementById('roles-error').style.display = 'none';
        }
        
        if (!primaryRole) {
            document.getElementById('primary-role-error').textContent = '<?php echo __("Please select a primary role."); ?>';
            document.getElementById('primary-role-error').style.display = 'block';
            hasError = true;
        } else {
            document.getElementById('primary-role-error').style.display = 'none';
        }
        
        if (hasError) {
            e.preventDefault();
            return false;
        }
    });

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updatePrimaryRoleOptions();
    });
</script>
</body>
</html>

<?php $conn->close(); ?>