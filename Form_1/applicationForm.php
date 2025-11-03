<?php
session_start();
require_once "../lang.php";
require_once "../navbar.php";
require_once "../configuration/configuration.php";

// Session timeout logic
$session_timeout = 1800;
if (isset($_SESSION['last_activity'])) {
    $inactive_time = time() - $_SESSION['last_activity'];
    if ($inactive_time >= $session_timeout) {
        session_unset();
        session_destroy();
        header("Location: ../index.php?timeout=1");
        exit();
    }
}
$_SESSION['last_activity'] = time();

// Authentication check
if (!isset($_SESSION["user_name"]) || !isset($_SESSION["Role"])) {
    header("Location: ../index.php");
    exit();
}


$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failure: " . $conn->connect_error);
}

// Get form errors and data
$form_errors = isset($_SESSION['form_errors']) ? $_SESSION['form_errors'] : [];
$form_data = isset($_SESSION['form_data']) ? $_SESSION['form_data'] : [];
unset($_SESSION['form_errors']);
unset($_SESSION['form_data']);

// Get success flag
$success = isset($_GET['success']) && $_GET['success'] === '1';

// Retrieve user details
$sql = "SELECT user_id, fullName, faculty_id FROM users1 WHERE user_name = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $_SESSION["user_name"]);
$stmt->execute();
$stmt->bind_result($user_id, $fullName, $facultyId);
if ($stmt->fetch()) {
    $_SESSION["user_id"] = $user_id;
    $_SESSION["fullName"] = $fullName;
    $_SESSION["faculty_id"] = $facultyId;
} else {
    $form_errors['general'] = __("No user found with the specified username.");
}
$stmt->close();

// Retrieve faculty name
$sql = "SELECT faculty_name FROM faculties1 WHERE faculty_id = ?";
$stmt_faculty = $conn->prepare($sql);
$stmt_faculty->bind_param("i", $facultyId);
$stmt_faculty->execute();
$stmt_faculty->bind_result($facultyName);
if ($stmt_faculty->fetch()) {
    $_SESSION["faculty_name"] = $facultyName;
}
$stmt_faculty->close();

// Retrieve faculty head
$sql_admin = "SELECT faculty_head FROM faculties1 WHERE faculty_id = ?";
$stmt_admin = $conn->prepare($sql_admin);
$stmt_admin->bind_param("i", $facultyId);
$stmt_admin->execute();
$stmt_admin->bind_result($adminName);
if ($stmt_admin->fetch()) {
    $_SESSION["admin_name"] = $adminName;
} else {
    $form_errors['general'] = __("No admin found for the specified faculty.");
}
$stmt_admin->close();

// Determine current semester
$currentDate = date("Y-m-d");
$sql_semester = "SELECT id, Semester_name, Start_date, End_date FROM semesters";
$stmt_semester = $conn->prepare($sql_semester);
$stmt_semester->execute();
$stmt_semester->bind_result($semesterId, $semesterName, $startDate, $endDate);

$currentSemester = "";
$currentSemesterId = null;
while ($stmt_semester->fetch()) {
    if ($currentDate >= $startDate && $currentDate <= $endDate) {
        $currentSemester = $semesterName;
        $currentSemesterId = $semesterId;
        break;
    }
}
if (empty($currentSemester)) {
    $form_errors['general'] = __("No active semester found.");
}
$stmt_semester->close();

// Get annual leave period for current semester
$annual_leave_start = "";
$annual_leave_end = "";
if ($currentSemesterId) {
    $sql_annual = "SELECT annual_leave_start, annual_leave_end FROM semesters WHERE id = ?";
    $stmt_annual = $conn->prepare($sql_annual);
    $stmt_annual->bind_param("i", $currentSemesterId);
    $stmt_annual->execute();
    $stmt_annual->bind_result($annual_leave_start, $annual_leave_end);
    $stmt_annual->fetch();
    $stmt_annual->close();
}

// Calculate remaining annual leave days
$remaining_annual_days = 20; // Default total
if ($user_id) {
    // Using the correct table name and column names based on your schema
    $sql_used = "SELECT SUM(Dayoff) as used_days FROM form1 
                 WHERE user_id = ? AND input = 'Annual-Leave' AND Department = 'Approved' 
                 AND YEAR(PermitStartDate) = YEAR(CURDATE())";
    $stmt_used = $conn->prepare($sql_used);
    if ($stmt_used) {
        $stmt_used->bind_param("i", $user_id);
        $stmt_used->execute();
        $stmt_used->bind_result($used_days);
        $stmt_used->fetch();
        $stmt_used->close();
        
        $remaining_annual_days = 20 - ($used_days ? $used_days : 0);
        if ($remaining_annual_days < 0) {
            $remaining_annual_days = 0;
        }
    }
}

// Fetch all users for the dropdown
$users_list = [];
$sql_users = "SELECT user_id, fullName, user_name FROM users1 WHERE fullName IS NOT NULL AND fullName != '' ORDER BY fullName ASC";
$stmt_users = $conn->prepare($sql_users);
if ($stmt_users) {
    $stmt_users->execute();
    $result_users = $stmt_users->get_result();
    while ($row = $result_users->fetch_assoc()) {
        $users_list[] = $row;
    }
    $stmt_users->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($translator->getCurrentLanguage()); ?>" dir="<?php echo htmlspecialchars($translator->getTextDirection()); ?>"><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __("Application"); ?></title>
    <link rel="icon" href="../logo/logo1.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .error {
            color: red;
            font-size: 14px;
            margin-top: 5px;
        }
        input.error-border {
            border-color: red !important;
        }
        .SubmitButton1 {
            background-color: #141414;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            border-radius: 5%;
            
            margin-right: 10px;
        }
        @media (max-width: 430px) {
            .button {
                display: flex;
                justify-content: center;
                align-items: center;
            }
        }
        h5 { text-align: center; padding: 10px 0; }

        select.form-control {
    height: calc(2.25rem + 2px);
    padding: 0.375rem 0.75rem;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 16px 12px;
}
    </style>
</head>
<body>
<section class="main-content">
    <div class="container mt-5">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo __("Request submitted successfully! You’ll receive notifications on its status."); ?></div>
        <?php endif; ?>
        <?php if (!empty($form_errors['general'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($form_errors['general']); ?></div>
        <?php elseif (!empty($form_errors)): ?>
            <div class="alert alert-danger">
                <strong><?php echo __("Please correct the following errors:"); ?></strong>
                <ul>
                    <?php foreach ($form_errors as $field => $error): ?>
                        <?php if ($field !== 'general'): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            
        <?php endif; ?>
        <div id="annualLeaveInfo" class="alert alert-info" style="display: none;">
    <strong><?php echo __("Annual Leave Information"); ?>:</strong><br>
    <?php echo __("Annual leave period"); ?>: <?php echo htmlspecialchars($annual_leave_start); ?> <?php echo __("to"); ?> <?php echo htmlspecialchars($annual_leave_end); ?><br>
    <?php echo __("Remaining annual leave days"); ?>: <span id="remainingDays"><?php echo $remaining_annual_days; ?></span> <?php echo __("days"); ?>
</div>

<div id="annualLeaveError" class="alert alert-danger" style="display: none;">
    <span id="annualLeaveErrorText"></span>
</div>
         

        <form action="submit.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
            <div class="card mb-4">
                <div class="card-header"><?php echo __("Application"); ?></div>
                <div class="card-body">
                    <div class="row">
                        <div class="form-group col-md-12 mb-4">
                            <label for="fullName"><?php echo __("Full Name"); ?>:</label>
                            <input type="text" name="fullName" id="fullName" class="form-control" value="<?php echo htmlspecialchars($_SESSION['fullName']); ?>" readonly>
                        </div>
                        <div class="form-row align-items-center mb-4">
                            <div class="form-group col-md-6">
                                <label for="Passport_no"><?php echo __("Passport Number"); ?>:</label>
                                <input type="text" name="Passport_no" id="Passport_no" class="form-control <?php echo isset($form_errors['Passport_no']) ? 'error-border' : ''; ?>" value="<?php echo isset($form_data['Passport_no']) ? htmlspecialchars($form_data['Passport_no']) : ''; ?>" required pattern="[A-Za-z0-9]{6,9}" title="<?php echo __("Passport number must be 6 to 9 letters or digits"); ?>">
                                <?php if (isset($form_errors['Passport_no'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['Passport_no']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="faculty_name"><?php echo __("Faculty/Department"); ?>:</label>
                                <input type="text" name="faculty_name" id="faculty_name" class="form-control" value="<?php echo htmlspecialchars(__($_SESSION['faculty_name'])); ?>" readonly>
                            </div>
                        </div>
                        <p><?php echo __("Permissions"); ?></p>
                        <div class="form-row align-items-center mb-4">
                            <div class="form-group col-md-12">
                                <div class="row">
                                    <div class="col">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="input" id="radio1" value="Annual-Leave" <?php echo (isset($form_data['input']) && $form_data['input'] === 'Annual-Leave') ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="radio1"><?php echo __("Annual-Leave"); ?></label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="input" id="radio2" value="excuse-leave" <?php echo (isset($form_data['input']) && $form_data['input'] === 'excuse-leave') ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="radio2"><?php echo __("Excuse-leave"); ?></label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="input" id="radio3" value="unpaid-leave" <?php echo (isset($form_data['input']) && $form_data['input'] === 'unpaid-leave') ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="radio3"><?php echo __("Unpaid-leave"); ?></label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="input" id="radio4" value="sick-leave" <?php echo (isset($form_data['input']) && $form_data['input'] === 'sick-leave') ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="radio4"><?php echo __("Sick-leave"); ?></label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="input" id="radio5" value="other" <?php echo (isset($form_data['input']) && $form_data['input'] === 'other') ? 'checked' : ''; ?> required>
                                            <label class="form-check-label" for="radio5"><?php echo __("Other"); ?></label>
                                        </div>
                                        <?php if (isset($form_errors['input'])): ?>
                                            <div class="error"><?php echo htmlspecialchars($form_errors['input']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col">
                                <div class="form-group">
                                    <label for="RequestTest"><?php echo __("Specify"); ?></label>
                                    <input type="text" class="form-control" id="RequestTest" name="RequestTest" placeholder="<?php echo __("more details or other"); ?>" value="<?php echo isset($form_data['RequestTest']) ? htmlspecialchars($form_data['RequestTest']) : ''; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label for="PermitStartDate"><?php echo __("Permit start date"); ?></label>
                                <input type="date" class="form-control <?php echo isset($form_errors['PermitStartDate']) ? 'error-border' : ''; ?>" id="PermitStartDate" name="PermitStartDate" value="<?php echo isset($form_data['PermitStartDate']) ? htmlspecialchars($form_data['PermitStartDate']) : ''; ?>" onchange="updateDayoff()" required>
                                <?php if (isset($form_errors['PermitStartDate'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['PermitStartDate']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="LeaveExpiryDate"><?php echo __("Leave end date"); ?></label>
                                <input type="date" class="form-control <?php echo isset($form_errors['LeaveExpiryDate']) ? 'error-border' : ''; ?>" id="LeaveExpiryDate" name="LeaveExpiryDate" value="<?php echo isset($form_data['LeaveExpiryDate']) ? htmlspecialchars($form_data['LeaveExpiryDate']) : ''; ?>" onchange="updateDayoff()" required>
                                <?php if (isset($form_errors['LeaveExpiryDate'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['LeaveExpiryDate']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group col-md-6">
    <label for="PersonToRepresent"><?php echo __("Person to represent"); ?></label>
    <select class="form-control searchable-select <?php echo isset($form_errors['PersonToRepresent']) ? 'error-border' : ''; ?>" id="PersonToRepresent" name="PersonToRepresent" required>
        <option value=""><?php echo __("Select a person"); ?></option>
        <?php foreach ($users_list as $user): ?>
            <option value="<?php echo htmlspecialchars($user['fullName']); ?>" 
                <?php echo (isset($form_data['PersonToRepresent']) && $form_data['PersonToRepresent'] === $user['fullName']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($user['fullName']) . ' (' . htmlspecialchars($user['user_name']) . ')'; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($form_errors['PersonToRepresent'])): ?>
        <div class="error"><?php echo htmlspecialchars($form_errors['PersonToRepresent']); ?></div>
    <?php endif; ?>
</div>
                            <div class="form-group col-md-6">
                                <label for="Address"><?php echo __("Address"); ?></label>
                                <input type="text" class="form-control <?php echo isset($form_errors['Address']) ? 'error-border' : ''; ?>" id="Address" name="Address" value="<?php echo isset($form_data['Address']) ? htmlspecialchars($form_data['Address']) : ''; ?>" required>
                                <?php if (isset($form_errors['Address'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['Address']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="form-group col-md-12">
                                <label for="Phone"><?php echo __("Phone Number"); ?></label>
                                <input type="tel" name="Phone" id="Phone" class="form-control <?php echo isset($form_errors['Phone']) ? 'error-border' : ''; ?>" placeholder="099xxxxxxx" value="<?php echo isset($form_data['Phone']) ? htmlspecialchars($form_data['Phone']) : ''; ?>" required maxlength="15" pattern="[0-9]{10,15}" title="<?php echo __("Phone number must be between 10 and 15 digits"); ?>">
                                <?php if (isset($form_errors['Phone'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['Phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col">
                            <p><?php echo __("I have classes during my leave of absence"); ?></p>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="radioInput1" id="yes" value="yes" <?php echo (isset($form_data['radioInput1']) && $form_data['radioInput1'] === 'yes') ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="yes"><?php echo __("Yes"); ?></label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="radioInput1" id="no" value="no" <?php echo (isset($form_data['radioInput1']) && $form_data['radioInput1'] === 'no') ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="no"><?php echo __("No"); ?></label>
                            </div>
                            <?php if (isset($form_errors['radioInput1'])): ?>
                                <div class="error"><?php echo htmlspecialchars($form_errors['radioInput1']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="form-group" id="makeUpDays" style="display: none;">
                                <p><?php echo __("I'll make it up in these days:"); ?></p>
                                <input type="date" class="form-control <?php echo isset($form_errors['MakeUpDays']) ? 'error-border' : ''; ?>" id="makeUpText" name="MakeUpDays" value="<?php echo isset($form_data['MakeUpDays']) ? htmlspecialchars($form_data['MakeUpDays']) : ''; ?>">
                                <?php if (isset($form_errors['MakeUpDays'])): ?>
                                    <div class="error"><?php echo htmlspecialchars($form_errors['MakeUpDays']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group col-md-12 mt-4">
                            <label for="totalDays"><?php echo __("Total days"); ?></label>
                            <input type="text" id="totalDays" name="Dayoff" class="form-control <?php echo isset($form_errors['Dayoff']) ? 'error-border' : ''; ?>" value="<?php echo isset($form_data['Dayoff']) ? htmlspecialchars($form_data['Dayoff']) : ''; ?>" readonly>
                            <?php if (isset($form_errors['Dayoff'])): ?>
                                <div class="error"><?php echo htmlspecialchars($form_errors['Dayoff']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="row mb-5">
                            <div class="form-group col-md-6">
                                <label for="AdminApproval"><?php echo __("Admin Approval"); ?>:</label>
                                <input type="text" name="AdminApproval" id="AdminApproval" class="form-control" value="<?php echo htmlspecialchars($_SESSION['admin_name']); ?>" disabled>
                                <input type="hidden" name="AdminFaculty" value="<?php echo htmlspecialchars($_SESSION['faculty_id']); ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="currentSemester"><?php echo __("Semesters"); ?></label>
                                <input type="text" class="form-control" id="currentSemester" name="currentSemester" value="<?php echo htmlspecialchars(__($currentSemester)); ?>" readonly>
                            </div>
                            <input type="hidden" name="semester_id" value="<?php echo htmlspecialchars($currentSemesterId); ?>">
                        </div>
                    </div>
                    <div class="button">
                        <button type="submit" class="SubmitButton1" name="submit"><?php echo __("Save"); ?></button>
                        <button type="button" class="SubmitButton1" id="btn"><?php echo __("Back"); ?></button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</section>

<script>
(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();

document.addEventListener("DOMContentLoaded", function() {
    const yesRadioButton = document.getElementById("yes");
    const noRadioButton = document.getElementById("no");
    const makeUpText = document.getElementById("makeUpText");
    const makeUpDays = document.getElementById("makeUpDays");

    function toggleMakeUpText() {
        if (yesRadioButton.checked) {
            makeUpText.style.display = "block";
            makeUpDays.style.display = "block";
        } else {
            makeUpText.style.display = "none";
            makeUpDays.style.display = "none";
            if (!yesRadioButton.checked && !noRadioButton.checked) {
                makeUpText.value = 'Null';
            }
        }
    }
    yesRadioButton.addEventListener("change", toggleMakeUpText);
    noRadioButton.addEventListener("change", toggleMakeUpText);
    toggleMakeUpText();
    
    const textBox = document.getElementById('RequestTest');
    textBox.disabled = false;
    
    const makeUpDateInput = document.getElementById('makeUpText');
    makeUpDateInput.addEventListener('change', validateMakeUpDate);

    const radioInputs = document.getElementsByName("input");
    radioInputs.forEach(function(radio) {
        radio.addEventListener("change", function() {
            updateDayoff();
        });
    });
});

function validateAnnualLeave() {
    const radioInputs = document.getElementsByName("input");
    const dayoffInput = document.getElementById("totalDays");
    const totalDays = parseInt(dayoffInput.value) || 0;
    
    let selectedOption = null;
    for (let i = 0; i < radioInputs.length; i++) {
        if (radioInputs[i].checked) {
            selectedOption = radioInputs[i].value;
            break;
        }
    }
    
    if (selectedOption === "Annual-Leave" && totalDays > 20) {
        showAnnualLeaveError("Maximum leave duration for Annual-Leave is 20 business days. Current: " + totalDays + " days.");
        return false;
    } else {
        clearAnnualLeaveError();
        return true;
    }
}

function showAnnualLeaveError(message) {
    clearAnnualLeaveError();
    
    const dayoffInput = document.getElementById("totalDays");
    const errorDiv = document.createElement('div');
    errorDiv.id = 'annualLeaveError';
    errorDiv.className = 'error';
    errorDiv.style.color = 'red';
    errorDiv.style.fontSize = '14px';
    errorDiv.style.marginTop = '5px';
    errorDiv.textContent = message;
    
    dayoffInput.style.borderColor = 'red';
    dayoffInput.parentNode.appendChild(errorDiv);
}

function clearAnnualLeaveError() {
    const errorDiv = document.getElementById('annualLeaveError');
    const dayoffInput = document.getElementById("totalDays");
    
    if (errorDiv) {
        errorDiv.remove();
    }
    dayoffInput.style.borderColor = '';
}

function validateMakeUpDate() {
    const makeUpDateInput = document.getElementById('makeUpText');
    const startDateInput = document.querySelector("input[name='PermitStartDate']");
    const endDateInput = document.querySelector("input[name='LeaveExpiryDate']");
    
    if (!makeUpDateInput.value) return;
    
    const makeUpDate = new Date(makeUpDateInput.value);
    const startDate = new Date(startDateInput.value);
    const endDate = new Date(endDateInput.value);
    const currentDate = new Date();
    
    makeUpDate.setHours(0, 0, 0, 0);
    startDate.setHours(0, 0, 0, 0);
    endDate.setHours(0, 0, 0, 0);
    currentDate.setHours(0, 0, 0, 0);
    
    let errorMessage = '';
    
    if (makeUpDate < currentDate) {
        errorMessage = 'Make-up date cannot be in the past.';
    } else if (startDateInput.value && endDateInput.value) {
        if (makeUpDate >= startDate && makeUpDate <= endDate) {
            errorMessage = 'Make-up date cannot be during the leave period.';
        }
    }
    
    if (errorMessage) {
        alert(errorMessage);
        makeUpDateInput.value = '';
        makeUpDateInput.style.borderColor = 'red';
        let errorDiv = document.getElementById('makeUpDateError');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.id = 'makeUpDateError';
            errorDiv.className = 'error';
            makeUpDateInput.parentNode.appendChild(errorDiv);
        }
        errorDiv.textContent = errorMessage;
    } else {
        makeUpDateInput.style.borderColor = '';
        const errorDiv = document.getElementById('makeUpDateError');
        if (errorDiv) {
            errorDiv.remove();
        }
    }
}

function updateDayoff() {
    var startDateInput = document.querySelector("input[name='PermitStartDate']");
    var endDateInput = document.querySelector("input[name='LeaveExpiryDate']");
    var dayoffInput = document.getElementById("totalDays");

    var startDate = new Date(startDateInput.value);
    var endDate = new Date(endDateInput.value);

    const publicHolidays = [
        '2025-01-01', '2025-04-23', '2025-05-01', '2025-05-19', '2025-07-20',
        '2025-08-01', '2025-08-30', '2025-10-29', '2025-11-15', '2025-12-25',
        '2026-01-01', '2026-04-23', '2026-05-01', '2026-05-19', '2026-07-20',
        '2026-08-01', '2026-08-30', '2026-10-29', '2026-11-15', '2026-12-25',
        '2027-01-01', '2027-04-23', '2027-05-01', '2027-05-19', '2027-07-20',
        '2027-08-01', '2027-08-30', '2027-10-29', '2027-11-15', '2027-12-25',
        '2028-01-01', '2028-04-23', '2028-05-01', '2028-05-19', '2028-07-20',
        '2028-08-01', '2028-08-30', '2028-10-29', '2028-11-15', '2028-12-25',
        '2029-01-01', '2029-04-23', '2029-05-01', '2029-05-19', '2029-07-20',
        '2029-08-01', '2029-08-30', '2029-10-29', '2029-11-15', '2029-12-25',
        '2030-01-01', '2030-04-23', '2030-05-01', '2030-05-19', '2030-07-20',
        '2030-08-01', '2030-08-30', '2030-10-29', '2030-11-15', '2030-12-25'
    ];

    if (startDateInput.value && endDateInput.value) {
        var totalDays = 0;
        var current = new Date(startDate);

        while (current <= endDate) {
            var day = current.getDay();
            const formattedDate = current.toISOString().split('T')[0];

            if (day !== 0 && day !== 6 && !publicHolidays.includes(formattedDate)) {
                totalDays++;
            }

            current.setDate(current.getDate() + 1);
        }

        dayoffInput.value = totalDays;
        validateAnnualLeave();
    } else {
        dayoffInput.value = "";
        clearAnnualLeaveError();
    }
    
    if (document.getElementById('makeUpText').value) {
        validateMakeUpDate();
    }
    validateAnnualLeaveDates();
}

document.addEventListener("DOMContentLoaded", function() {
    var startDateInput = document.querySelector("input[name='PermitStartDate']");
    var endDateInput = document.querySelector("input[name='LeaveExpiryDate']");
    startDateInput.addEventListener("change", validateDates);
    endDateInput.addEventListener("change", validateDates);

    function validateDates() {
        var startDate = new Date(startDateInput.value);
        var endDate = new Date(endDateInput.value);
        var currentDate = new Date();
        
        currentDate.setHours(0, 0, 0, 0);
        startDate.setHours(0, 0, 0, 0);
        endDate.setHours(0, 0, 0, 0);
        
        var isValid = true;
        var errorMessage = '';
        
        clearDateErrors();
        
        if (startDateInput.value && startDate < currentDate) {
            isValid = false;
            errorMessage = "Start date cannot be in the past.";
            showDateError(startDateInput, errorMessage);
            startDateInput.value = '';
        }
        
        if (endDateInput.value && endDate < currentDate) {
            isValid = false;
            errorMessage = "End date cannot be in the past.";
            showDateError(endDateInput, errorMessage);
            endDateInput.value = '';
        }
        
        if (startDateInput.value && endDateInput.value && startDate > endDate) {
            isValid = false;
            errorMessage = "Start date cannot be after end date.";
            showDateError(endDateInput, errorMessage);
            endDateInput.value = '';
        }
        
        return isValid;
    }

    function showDateError(inputElement, message) {
        inputElement.style.borderColor = 'red';
        
        let errorId = inputElement.name + '_error';
        let errorDiv = document.getElementById(errorId);
        
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.id = errorId;
            errorDiv.className = 'error';
            errorDiv.style.color = 'red';
            errorDiv.style.fontSize = '14px';
            errorDiv.style.marginTop = '5px';
            inputElement.parentNode.appendChild(errorDiv);
        }
        
        errorDiv.textContent = message;
    }

    function clearDateErrors() {
        var startDateInput = document.querySelector("input[name='PermitStartDate']");
        var endDateInput = document.querySelector("input[name='LeaveExpiryDate']");
        
        startDateInput.style.borderColor = '';
        let startError = document.getElementById('PermitStartDate_error');
        if (startError) startError.remove();
        
        endDateInput.style.borderColor = '';
        let endError = document.getElementById('LeaveExpiryDate_error');
        if (endError) endError.remove();
    }

    const form = document.querySelector('form');
    form.addEventListener('submit', function(event) {
        const makeUpDateInput = document.getElementById('makeUpText');
        const yesRadio = document.getElementById('yes');
        const startDateInput = document.querySelector("input[name='PermitStartDate']");
        const endDateInput = document.querySelector("input[name='LeaveExpiryDate']");
        
        if (!validateDates()) {
            alert('Please correct the date errors before submitting.');
            event.preventDefault();
            return false;
        }
        
        if (!startDateInput.value || !endDateInput.value) {
            alert('Please select both start and end dates.');
            event.preventDefault();
            return false;
        }
        
        if (!validateAnnualLeave()) {
            alert('Cannot submit form: Annual Leave cannot exceed 20 business days.');
            event.preventDefault();
            return false;
        }

        if (!validateAnnualLeaveDates()) {
           event.preventDefault();
            return false;
        }
        
        if (yesRadio.checked && makeUpDateInput.style.display !== 'none') {
            if (!makeUpDateInput.value) {
                alert('Please enter a make-up date.');
                event.preventDefault();
                return false;
            }
            
            const errorDiv = document.getElementById('makeUpDateError');
            if (errorDiv) {
                alert('Please correct the make-up date error before submitting.');
                event.preventDefault();
                return false;
            }
        }
    });
});

document.getElementById("btn").addEventListener("click", function() {
    window.location.href = "../Dashboard/userDashboard.php";
});

// Simple search functionality for dropdown
document.getElementById('PersonToRepresent').addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    const options = this.options;
    
    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        const text = option.text.toLowerCase();
        option.style.display = text.includes(filter) ? '' : 'none';
    }
});

// Annual leave period variables
const annualLeaveStart = '<?php echo $annual_leave_start; ?>';
const annualLeaveEnd = '<?php echo $annual_leave_end; ?>';
const remainingDays = <?php echo $remaining_annual_days; ?>;

// Show/hide annual leave info when radio button changes
document.addEventListener("DOMContentLoaded", function() {
    const radioInputs = document.getElementsByName("input");
    radioInputs.forEach(function(radio) {
        radio.addEventListener("change", function() {
            handleAnnualLeaveSelection();
            updateDayoff();
        });
    });
});

function handleAnnualLeaveSelection() {
    const annualLeaveRadio = document.querySelector('input[value="Annual-Leave"]');
    const infoDiv = document.getElementById('annualLeaveInfo');
    const errorDiv = document.getElementById('annualLeaveError');
    
    if (annualLeaveRadio && annualLeaveRadio.checked) {
        infoDiv.style.display = 'block';
        document.getElementById('remainingDays').textContent = remainingDays;
    } else {
        infoDiv.style.display = 'none';
        errorDiv.style.display = 'none';
    }
}

function validateAnnualLeaveDates() {
    const annualLeaveRadio = document.querySelector('input[value="Annual-Leave"]');
    const startDate = document.querySelector("input[name='PermitStartDate']").value;
    const endDate = document.querySelector("input[name='LeaveExpiryDate']").value;
    const totalDays = parseInt(document.getElementById("totalDays").value) || 0;
    const errorDiv = document.getElementById('annualLeaveError');
    const errorText = document.getElementById('annualLeaveErrorText');
    
    if (!annualLeaveRadio || !annualLeaveRadio.checked) {
        errorDiv.style.display = 'none';
        return true;
    }
    
    let errorMessage = '';
    
    // Check if dates are within annual leave period
    if (startDate && endDate && annualLeaveStart && annualLeaveEnd) {
        const permitStart = new Date(startDate);
        const permitEnd = new Date(endDate);
        const allowedStart = new Date(annualLeaveStart);
        const allowedEnd = new Date(annualLeaveEnd);
        
        if (permitStart < allowedStart || permitEnd > allowedEnd) {
            errorMessage = '<?php echo __("Annual leave dates must be within the allowed period"); ?>: ' + 
                          annualLeaveStart + ' <?php echo __("to"); ?> ' + annualLeaveEnd;
        }
    }
    
    // Check remaining days
    if (totalDays > remainingDays) {
        if (errorMessage) errorMessage += '<br>';
        errorMessage += '<?php echo __("Requested days exceed remaining annual leave"); ?> (' + 
                       totalDays + ' > ' + remainingDays + ')';
    }
    
    if (errorMessage) {
        errorText.innerHTML = errorMessage;
        errorDiv.style.display = 'block';
        return false;
    } else {
        errorDiv.style.display = 'none';
        return true;
    }
}
</script>
</body>
</html>