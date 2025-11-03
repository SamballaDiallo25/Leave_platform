<?php
session_start();
if (!isset($_SESSION["SuperAdminName"])) {
    header("Location: ../index.php");
    exit();
}

require_once "../lang.php";
include_once "../navbar.php";
include "../configuration/configuration.php";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$id = $_GET["id"];
$successMessage = "";

// Define available roles - same as addUser.php
$availableRoles = [
    'Lecturer' => ['label' => 'Lecturer', 'is_admin' => 0],
    'HOF' => ['label' => 'Head of Faculty (HOF)', 'is_admin' => 1],
    'HOF_Staff' => ['label' => 'HOF Staff', 'is_admin' => 1],
    'HumanResource' => ['label' => 'Human Resource', 'is_admin' => 1],
    'HumanResource_Staff' => ['label' => 'Human Resource Staff', 'is_admin' => 1],
    'Rectorate' => ['label' => 'Rectorate', 'is_admin' => 1],
    'SuperAdmin' => ['label' => 'SuperAdmin', 'is_admin' => 1]
];

// Get current user roles
$currentRoles = [];
$primaryRole = '';
$rolesSql = "SELECT role_name, is_primary FROM user_roles WHERE user_id = ?";
$rolesStmt = $conn->prepare($rolesSql);
$rolesStmt->bind_param("i", $id);
$rolesStmt->execute();
$rolesResult = $rolesStmt->get_result();

while ($roleRow = $rolesResult->fetch_assoc()) {
    $currentRoles[] = $roleRow['role_name'];
    if ($roleRow['is_primary']) {
        $primaryRole = $roleRow['role_name'];
    }
}
$rolesStmt->close();

if (isset($_POST["submit"])) {
    $fullname = $_POST["fullname"];
    $username = $_POST["user_name"];
    $password = $_POST["password"];
    $faculty_id = $_POST["department"];
    $email = $_POST["email"];
    $selectedRoles = $_POST["roles"] ?? [];
    $newPrimaryRole = $_POST["primary_role"] ?? '';

    // Validation for roles
    $errors = [];
    if (empty($selectedRoles)) {
        $errors['roles'] = __("Please select at least one role.");
    }

    if (empty($newPrimaryRole) && !empty($selectedRoles)) {
        $errors['primary_role'] = __("Please select a primary role.");
    }

    if (!empty($newPrimaryRole) && !in_array($newPrimaryRole, $selectedRoles)) {
        $errors['primary_role'] = __("Primary role must be one of the selected roles.");
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = __("Invalid email format.");
    } else {
        // Check if email is already in use by another user
        $checkEmailSql = "SELECT email FROM users1 WHERE email = ? AND user_id != ?";
        if ($checkEmailStmt = $conn->prepare($checkEmailSql)) {
            $checkEmailStmt->bind_param("si", $email, $id);
            $checkEmailStmt->execute();
            $checkEmailResult = $checkEmailStmt->get_result();
            
            if ($checkEmailResult->num_rows > 0) {
                $errors['email'] = __("This email is already in use.");
            }
            $checkEmailStmt->close();
        }
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

            // Update user with primary role
            $sql = "UPDATE users1 SET fullName = ?, password = ?, Role = ?, user_name = ?, faculty_id = ?, email = ?, is_admin = ?, primary_role = ? WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssisisi", $fullname, $password, $newPrimaryRole, $username, $faculty_id, $email, $is_admin, $newPrimaryRole, $id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error updating user: " . $stmt->error);
            }
            $stmt->close();

            // Delete existing roles
            $deleteRolesStmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $deleteRolesStmt->bind_param("i", $id);
            
            if (!$deleteRolesStmt->execute()) {
                throw new Exception("Error deleting old roles: " . $deleteRolesStmt->error);
            }
            $deleteRolesStmt->close();

            // Insert new roles
            foreach ($selectedRoles as $role) {
                $isPrimary = ($role === $newPrimaryRole) ? 1 : 0;
                
                $roleStmt = $conn->prepare("INSERT INTO user_roles (user_id, role_name, is_primary) VALUES (?, ?, ?)");
                $roleStmt->bind_param("isi", $id, $role, $isPrimary);
                
                if (!$roleStmt->execute()) {
                    throw new Exception("Error inserting role: " . $roleStmt->error);
                }
                $roleStmt->close();
            }

            $conn->commit();
            $successMessage = __("User Info Updated successfully with multiple roles!");
            
            // Update current roles for display
            $currentRoles = $selectedRoles;
            $primaryRole = $newPrimaryRole;
            
        } catch (Exception $e) {
            $conn->rollback();
            $successMessage = __("Error: ") . $e->getMessage();
        }
    } else {
        $successMessage = implode(" ", $errors);
    }
}

// Get user information
$sql = "SELECT fullName, password, user_name, Role, faculty_id, email FROM users1 WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($fullName, $password, $user_name, $Role, $faculty_id, $email);
$stmt->fetch();
$stmt->close();

// If no roles exist, get from main Role field (backward compatibility)
if (empty($currentRoles) && !empty($Role)) {
    $currentRoles = [$Role];
    $primaryRole = $Role;
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($translator->getCurrentLanguage()); ?>" dir="<?php echo htmlspecialchars($translator->getTextDirection()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Edit</title>
    <style>
        .SubmitButton1 {
            background-color: #141414;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            border-radius: 5%;
            width: 100px;
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
    </style>
    <link rel="icon" href="../logo/logo1.png" type="image/png">
</head>
<body>
<section class="main-content">
   <div class="container mt-5">
       <?php if ($successMessage): ?>
           <div id="success-message" class="alert alert-success">
               <?php echo $successMessage; ?>
           </div>
       <?php endif; ?>
       <form action="" method="post">
           <div class="card mb-4">
               <div class="card-header"><?php echo __("Edit User Information"); ?></div>
               <div class="card-body">
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="name" class="mr-2"><?php echo __("Full Name"); ?>:</label>
                           <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($fullName); ?>" required>
                       </div>
                   </div>
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="user_name" class="mr-2"><?php echo __("User Name"); ?>:</label>
                           <input type="text" name="user_name" class="form-control" value="<?php echo htmlspecialchars($user_name); ?>" required>
                       </div>
                   </div>
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="password" class="mr-2"><?php echo __("Password"); ?>:</label>
                           <input type="text" name="password" class="form-control" value="<?php echo htmlspecialchars($password); ?>" required>
                       </div>
                   </div>
                   <div class="form-row align-items mb-4 mt-4">
                       <div class="form-group col-md-12">
                           <label for="email" class="mr-2"><?php echo __("Email"); ?>:</label>
                           <input type="email" name="email" class="form-control <?php echo isset($errors['email']) ? 'error' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required>
                           <?php if (isset($errors['email'])): ?>
                               <span class="error-message"><?php echo $errors['email']; ?></span>
                           <?php endif; ?>
                       </div>
                   </div>
                   <div class="form-row align-items-center mb-2">
                       <div class="form-group col-md-12">
                           <label for="Department" class="mr-2"><?php echo __("Department"); ?>:</label>
                           <select class="form-control" id="Department" name="department" required>
                               <?php
                               // Fetch and display faculties dynamically
                               $sql_faculties = "SELECT faculty_id, faculty_name FROM faculties1";
                               $result = $conn->query($sql_faculties);
                               while ($row = $result->fetch_assoc()) {
                                   echo '<option value="' . $row["faculty_id"] . '"' . ($row["faculty_id"] == $faculty_id ? ' selected' : '') . '>' . htmlspecialchars($row["faculty_name"]) . '</option>';
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
                                           <?php echo in_array($roleKey, $currentRoles) ? 'checked' : ''; ?>
                                           onchange="updatePrimaryRoleOptions()"
                                       >
                                       <label class="form-check-label" for="role_<?php echo $roleKey; ?>">
                                           <?php echo __($roleData['label']); ?>
                                           <small class="text-muted">(<?php echo $roleData['is_admin'] ? __('Admin') : __('User'); ?>)</small>
                                       </label>
                                   </div>
                               <?php endforeach; ?>
                           </div>
                           <div class="error-message" id="roles-error" style="display: none;"></div>
                       </div>
                   </div>

                   <div class="form-row align-items-center mb-4">
                       <div class="form-group col-md-12">
                           <label for="primary_role" class="mr-2"><?php echo __("Primary Role"); ?>: <span class="text-danger">*</span></label>
                           <select class="form-control" id="primary_role" name="primary_role" required>
                               <option value=""><?php echo __("Select Primary Role"); ?></option>
                               <?php foreach ($currentRoles as $role): ?>
                                   <option value="<?php echo $role; ?>" <?php echo ($role === $primaryRole) ? 'selected' : ''; ?>>
                                       <?php echo __($availableRoles[$role]['label'] ?? $role); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                           <small class="form-text text-muted"><?php echo __("The primary role will be used as the default role and main position."); ?></small>
                           <div class="error-message" id="primary-role-error" style="display: none;"></div>
                       </div>
                   </div>
                   
                   <div class="button">
                       <button type="submit" class="SubmitButton1" name="submit" id="submit"><?php echo __("Save"); ?></button>
                       <button type="button" class="SubmitButton1" name="btn" id="btn"><?php echo __("Back"); ?></button>
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

        // Allow message to be hidden only after 3 seconds
        if (successMessage) {
            setTimeout(() => {
                canHideMessage = true;
            }, 3000); // 3-second delay
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
        window.location.href = "../Status/userList.php";
    });

    // Add this JavaScript to handle dynamic primary role dropdown
    const roleLabels = <?php echo json_encode(array_map(function($role) { return __($role['label']); }, $availableRoles)); ?>;

    function updatePrimaryRoleOptions() {
        const checkboxes = document.querySelectorAll('.role-checkbox:checked');
        const primaryRoleSelect = document.getElementById('primary_role');
        const currentValue = primaryRoleSelect.value;
        
        // Clear existing options except the first one
        primaryRoleSelect.innerHTML = '<option value=""><?php echo __("Select Primary Role"); ?></option>';
        
        // Add options for checked roles
        checkboxes.forEach(checkbox => {
            const option = document.createElement('option');
            option.value = checkbox.value;
            option.textContent = checkbox.nextElementSibling.textContent.split('(')[0].trim();
            if (checkbox.value === currentValue) {
                option.selected = true;
            }
            primaryRoleSelect.appendChild(option);
        });
        
        // If current value is no longer available, clear it
        if (currentValue && !Array.from(checkboxes).some(cb => cb.value === currentValue)) {
            primaryRoleSelect.value = '';
        }
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const selectedRoles = document.querySelectorAll('.role-checkbox:checked');
        const primaryRole = document.getElementById('primary_role').value;
        
        let hasError = false;
        
        // Check if at least one role is selected
        if (selectedRoles.length === 0) {
            document.getElementById('roles-error').textContent = '<?php echo __("Please select at least one role."); ?>';
            document.getElementById('roles-error').style.display = 'block';
            hasError = true;
        } else {
            document.getElementById('roles-error').style.display = 'none';
        }
        
        // Check if primary role is selected
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