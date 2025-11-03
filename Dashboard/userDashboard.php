<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Check for delete success parameter and set session message
if (isset($_GET['deleted']) && $_GET['deleted'] === 'success') {
    $_SESSION['success_message'] = __("Request deleted successfully.");
}

require_once "../lang.php";
require_once "../navbar.php";
include "../configuration/configuration.UserDashboard.php";

if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['success_message']);
    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
    echo '<span aria-hidden="true">&times;</span>';
    echo '</button>';
    echo '</div>';
    unset($_SESSION['success_message']);
}

// Display error message
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['error_message']);
    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
    echo '<span aria-hidden="true">&times;</span>';
    echo '</button>';
    echo '</div>';
    unset($_SESSION['error_message']);
}

if (!isset($_SESSION["user_name"]) && !isset($_SESSION["Admin_name"])) {
    header("Location: ../index.php");
    exit();
}

$user_id_for_query = $_SESSION["user_id"];

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$totalPendingCount = 0;
$totalApprovedCount = 0;
$totalRejectedCount = 0;

$sqlCountForms = "
    SELECT
        COALESCE(SUM(CASE WHEN Department = 'Approved' AND HumanResource = 'Approved' AND Rectorate = 'Approved' THEN 1 ELSE 0 END), 0) AS approved_count,
        COALESCE(SUM(CASE WHEN Department = 'Rejected' OR HumanResource = 'Rejected' OR Rectorate = 'Rejected' THEN 1 ELSE 0 END), 0) AS rejected_count,
        COALESCE(SUM(CASE WHEN Department = 'Pending' OR HumanResource = 'Pending' OR Rectorate = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count
    FROM
        form1
    WHERE
        user_id = ?
";

$stmtCountForms = $conn->prepare($sqlCountForms);
$stmtCountForms->bind_param("i", $user_id_for_query);

if (!$stmtCountForms->execute()) {
    die("Error executing statement: " . $stmtCountForms->error);
}

$stmtCountForms->bind_result(
    $totalApprovedCount,
    $totalRejectedCount,
    $totalPendingCount
);

$stmtCountForms->fetch();
$stmtCountForms->close();

$sqlFetchForms = "SELECT submission_number, user_id, Department, HumanResource, Rectorate, PermitStartDate, LeaveExpiryDate, Dayoff, semester FROM form1 WHERE user_id = ?";
$stmtFetchForms = $conn->prepare($sqlFetchForms);
$stmtFetchForms->bind_param("i", $user_id_for_query);

if (!$stmtFetchForms->execute()) {
    die("Error executing statement: " . $stmtFetchForms->error);
}

$stmtFetchForms->bind_result(
    $submission_number,
    $user_id,
    $Department,
    $HumanResource,
    $Rectorate,
    $PermitStartDate,
    $LeaveExpiryDate,
    $Dayoff,
    $semester
);

$rows = [];
while ($stmtFetchForms->fetch()) {
    $rows[] = [
        "submission_number" => $submission_number,
        "user_id" => $user_id,
        "Department" => $Department,
        "HumanResource" => $HumanResource,
        "Rectorate" => $Rectorate,
        "PermitStartDate" => $PermitStartDate,
        "LeaveExpiryDate" => $LeaveExpiryDate,
        "Dayoff" => $Dayoff,
        "semester" => $semester,
    ];
}

$stmtFetchForms->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($translator->getCurrentLanguage()); ?>" dir="<?php echo htmlspecialchars($translator->getTextDirection()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        #addSemesterBtn {
            background-color: #141414;
            padding: 5px 10px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            border-radius: 5%;
        }
        #example {
            visibility: visible !important;
        }
        .btn btn-link {
            color: #007bff;
            text-decoration: none;
        }
        .dataTables_wrapper {
            min-height: 300px;
        }
        .card-title {
            text-align: center;
        }
        .confirm-buttons {
            margin-left: 10px;
            display: inline-block;
        }
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        #example {
    visibility: visible !important;
    display: table !important;
}

.toast {
    min-width: 250px;
}

.alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}
    </style>
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
</head>
<body>
<section class="main-content">
    <div class="toast-container">
        <div id="deleteToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header">
                <strong class="mr-auto"><?php echo __("Success"); ?></strong>
                <button type="button" class="close" data-dismiss="toast" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                <?php echo __("Request deleted successfully."); ?>
            </div>
        </div>
    </div>
    <div class="card mx-auto" style="background-color: #f8f9fa; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); border-radius: 10px;">
        <div class="card-body">
            <div class="row">
                <div class="col-lg-4 col-sm-6 col-12">
                    <div class="dash-widget dash4" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                        <div class="dash-widgetimg">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div class="dash-widgetcontent">
                            <h5>
                                <span class="counters" style="text-align: center;"><?php echo $totalPendingCount; ?></span>
                            </h5>
                            <h6 style="text-align: center;"><?php echo __("Pending"); ?></h6>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-sm-6 col-12">
                    <div class="dash-widget dash3" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                        <div class="dash-widgetimg">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <div class="dash-widgetcontent">
                            <h5>
                                <span class="counters" style="text-align: center;"><?php echo $totalApprovedCount; ?></span>
                            </h5>
                            <h6 style="text-align: center;"><?php echo __("Approved"); ?></h6>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-sm-6 col-12">
                    <div class="dash-widget dash2" style="box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); border-radius: 8px;">
                        <div class="dash-widgetimg">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="dash-widgetcontent">
                            <h5>
                                <span class="counters" style="text-align: center;"><?php echo $totalRejectedCount; ?></span>
                            </h5>
                            <h6 style="text-align: center;"><?php echo __("Rejected"); ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card mt-4 mb-4" style="background-color: #f8f9fa; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); border-radius: 10px;">
        <div class="card-body">
            <div class="row">
                <div class="col-lg-12">
                    <h5 class="card-title"><?php echo __("Status Table"); ?></h5>
                    <div class="container">
                        <table id="example" class="table table-striped nowrap" style="width:100%;">
                            <thead>
                                <tr>
                                    <th><?php echo __('AppNo'); ?></th>
                                    <th><?php echo __('User ID'); ?></th>
                                    <th><?php echo __('Department'); ?></th>
                                    <th><?php echo __('Human Resources'); ?></th>
                                    <th><?php echo __('Rectorate'); ?></th>
                                    <th><?php echo __('Start Date'); ?></th>
                                    <th><?php echo __('End Date'); ?></th>
                                    <th><?php echo __('Days off'); ?></th>
                                    <th><?php echo __('Semester'); ?></th>
                                    <th><?php echo __('Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rows)): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td>
                                                <button class="btn btn-link p-0 text-decoration-none" 
                                                        onclick="window.location.href='../Form_1/formReview.php?id=<?php echo htmlspecialchars($row['submission_number']); ?>'">
                                                    <?php echo htmlspecialchars($row['submission_number']); ?>
                                                </button>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['user_id']); ?></td>
                                            <td><?php 
                                                $deptStatus = $row['Department'];
                                                switch($deptStatus) {
                                                    case '0': echo __('Pending'); break;
                                                    case '1': echo __('Approved'); break;
                                                    case '2': echo __('Rejected'); break;
                                                    default: echo __($deptStatus);
                                                }
                                            ?></td>
                                            <td><?php 
                                                $hrStatus = $row['HumanResource'];
                                                switch($hrStatus) {
                                                    case '0': echo __('Pending'); break;
                                                    case '1': echo __('Approved'); break;
                                                    case '2': echo __('Rejected'); break;
                                                    default: echo __($hrStatus);
                                                }
                                            ?></td>
                                            <td><?php 
                                                $rectStatus = $row['Rectorate'];
                                                switch($rectStatus) {
                                                    case '0': echo __('Pending'); break;
                                                    case '1': echo __('Approved'); break;
                                                    case '2': echo __('Rejected'); break;
                                                    default: echo __($rectStatus);
                                                }
                                            ?></td>
                                            <td><?php echo htmlspecialchars($row['PermitStartDate']); ?></td>
                                            <td><?php echo htmlspecialchars($row['LeaveExpiryDate']); ?></td>
                                            <td><?php echo htmlspecialchars($row['Dayoff']); ?></td>
                                            <td><?php echo htmlspecialchars($row['semester']); ?></td>
                                            <td class="action-buttons">
                                                <div class="button-wrapper">
                                                    <button class="btn btn-primary custom-btn">
                                                        <a href="../Form_1/edit.php?id=<?php echo htmlspecialchars($row['submission_number']); ?>" class="text-light">
                                                            <i class="fa-solid fa-pen-to-square"></i>
                                                        </a>
                                                    </button>
                                                    <button class="btn btn-primary custom-btn">
                                                        <a href="../Print-details/Print-details.php?id=<?php echo htmlspecialchars($row['submission_number']); ?>" class="text-light">
                                                            <i class="fa-solid fa-print"></i>
                                                        </a>
                                                    </button>
                                                    <button class="btn btn-danger custom-btn delete-btn" 
                                                            data-id="<?php echo htmlspecialchars($row['submission_number']); ?>" 
                                                            onclick="showConfirmButtons(this, 'form1')">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                    <span class="confirm-buttons" style="display:none;">
                                                        <button class="btn btn-danger btn-sm confirm-btn button-spacing" 
                                                                onclick="confirmDelete(this, 'form1')"><?php echo __("Confirm"); ?></button>
                                                        <button class="btn btn-secondary btn-sm cancel-btn" 
                                                                onclick="cancelDelete(this)"><?php echo __("Cancel"); ?></button>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center">No data found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
    const currentLang = "<?php echo $_SESSION['lang']; ?>";
</script>

<!-- Use FULL jQuery, not slim! -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.1/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="../DataTable/datatable.js"></script>
<script>
    // Delete functionality
    function showConfirmButtons(button, table) {
        $('.confirm-buttons').hide();
        $('.delete-btn').show();
        $(button).hide();
        $(button).next('.confirm-buttons').show();
    }

    function cancelDelete(button) {
        $(button).closest('.confirm-buttons').hide();
        $(button).closest('.confirm-buttons').prev('.delete-btn').show();
    }

    function confirmDelete(button, table) {
        const id = $(button).closest('.confirm-buttons').prev('.delete-btn').data('id');
        console.log('Deleting ID:', id);
        $(button).prop('disabled', true).text('Deleting...');
        
        fetch(`../Form_1/delete.php?table=${table}&id=${id}`, {
            method: 'GET'
        })
        .then(response => response.text())
        .then(data => {
            console.log('Server response:', data);
            if (data.includes('successful') || data.includes('SUCCESS')) {
                window.location.href = window.location.pathname + '?deleted=success';
            } else {
                $(button).prop('disabled', false).text('<?php echo __("Confirm"); ?>');
                alert('Error deleting request: ' + data);
                cancelDelete(button);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $(button).prop('disabled', false).text('<?php echo __("Confirm"); ?>');
            alert('Network error while deleting request');
            cancelDelete(button);
        });
    }

    // Show toast after page reload
    $(document).ready(function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('deleted') === 'success') {
            const newUrl = window.location.pathname;
            window.history.replaceState({}, document.title, newUrl);
            setTimeout(function() {
                $('#deleteToast').toast('show');
            }, 200);
        }
        
        $('.toast').toast({ 
            delay: 4000,
            autohide: true 
        });
        
        $('#example').css('visibility', 'visible').show();
    });
</script>
</body>
</html>