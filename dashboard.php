<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin(); // Protect this page

$current_user_id = $_SESSION['user_id']; // Get the current user's ID

// --- Date Range Filter Handling ---
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// Basic validation for dates - ensure they are valid date formats
if (!empty($start_date) && !strtotime($start_date)) {
    $start_date = null; // Ignore invalid start date
}
if (!empty($end_date) && !strtotime($end_date)) {
    $end_date = null; // Ignore invalid end date
}

// Prepare WHERE clauses and parameters based on dates
$date_filter_payments = "";
$date_filter_expenses = "";
$date_filter_params = []; // Array to hold parameters for prepared statements
$date_filter_types = ""; // String to hold parameter types

if (!empty($start_date) && !empty($end_date)) {
    $date_filter_payments = " AND payment_date BETWEEN ? AND ?";
    $date_filter_expenses = " AND expense_date BETWEEN ? AND ?";
    $date_filter_params = [$start_date, $end_date];
    $date_filter_types = "ss"; // s = string for dates

} elseif (!empty($start_date)) {
    $date_filter_payments = " AND payment_date >= ?";
    $date_filter_expenses = " AND expense_date >= ?";
    $date_filter_params = [$start_date];
    $date_filter_types = "s";

} elseif (!empty($end_date)) {
    $date_filter_payments = " AND payment_date <= ?";
    $date_filter_expenses = " AND expense_date <= ?";
    $date_filter_params = [$end_date];
    $date_filter_types = "s";
}

// Base query parameters always include user_id
$query_params = [$current_user_id];
$query_types = "i"; // i = integer for user_id

// Add date filter parameters if they exist
if (!empty($date_filter_params)) {
    $query_params = array_merge([$current_user_id], $date_filter_params);
    $query_types .= $date_filter_types;
}


// Fetch Dashboard Data - FILTERED BY USER_ID AND DATE RANGE
$total_income = 0;
$total_expenses = 0;
$total_students = 0; // This should probably be total students *registered* by the user, regardless of payment/date range
$students_paid = 0; // This calculation still reflects who has paid their FULL class fee over *all* time
$students_owing = 0; // This calculation still reflects who has *not* paid their FULL class fee over *all* time

// Calculate Total Income for this user and date range
$sql_income = "SELECT SUM(amount_paid) AS total_income FROM payments WHERE user_id = ?" . $date_filter_payments;
$stmt_income = $conn->prepare($sql_income);
if ($stmt_income) {
    $stmt_income->bind_param($query_types, ...$query_params); // Use ...$query_params for variable number of params
    $stmt_income->execute();
    $result_income = $stmt_income->get_result();
    if ($result_income && $row_income = $result_income->fetch_assoc()) {
        $total_income = $row_income['total_income'] ?? 0;
    }
    if ($result_income) $result_income->free();
    $stmt_income->close();
} else {
    error_log("Database error preparing income query for user " . $current_user_id . ": " . $conn->error);
}


// Calculate Total Expenses for this user and date range
$sql_expenses = "SELECT SUM(amount) AS total_expenses FROM expenses WHERE user_id = ?" . $date_filter_expenses;
$stmt_expenses = $conn->prepare($sql_expenses);
if ($stmt_expenses) {
     $stmt_expenses->bind_param($query_types, ...$query_params); // Use ...$query_params for variable number of params
    $stmt_expenses->execute();
    $result_expenses = $stmt_expenses->get_result();
    if ($result_expenses && $row_expenses = $result_expenses->fetch_assoc()) {
        $total_expenses = $row_expenses['total_expenses'] ?? 0;
    }
     if ($result_expenses) $result_expenses->free();
    $stmt_expenses->close();
} else {
     error_log("Database error preparing expenses query for user " . $current_user_id . ": " . $conn->error);
}

// Calculate Balance and Profit/Loss for the date range
$balance = $total_income - $total_expenses;
$profit_loss_status = ($balance > 0) ? 'Profit' : (($balance < 0) ? 'Loss' : 'Balanced');


// Calculate Total Students for this user (regardless of date range)
$sql_total_students = "SELECT COUNT(*) AS total_students FROM students WHERE user_id = ?";
$stmt_total_students = $conn->prepare($sql_total_students);
if ($stmt_total_students) {
    $stmt_total_students->bind_param("i", $current_user_id);
    $stmt_total_students->execute();
    $result_total_students = $stmt_total_students->get_result();
    if ($result_total_students && $row_total_students = $result_total_students->fetch_assoc()) {
        $total_students = $row_total_students['total_students'] ?? 0;
    }
     if ($result_total_students) $result_total_students->free();
    $stmt_total_students->close();
} else {
    error_log("Database error preparing total students query for user " . $current_user_id . ": " . $conn->error);
}


// Calculate Students Paid and Owing for this user (overall status, regardless of date range)
// Count students whose *total* payments >= their class fee over *all* time
$sql_students_with_enough_payment = "
    SELECT COUNT(DISTINCT s.student_id) AS count_paid
    FROM students s
    JOIN classes c ON s.class_id = c.class_id -- Join to get the class fee
    LEFT JOIN payments p ON s.student_id = p.student_id -- Left join payments to sum them
    WHERE s.user_id = ? -- Filter students by user
    GROUP BY s.student_id, c.class_fee -- Group by student and class fee to sum payments per student
    HAVING COALESCE(SUM(p.amount_paid), 0) >= c.class_fee -- Condition: total paid >= class fee (use COALESCE for students with no payments)
";
 $stmt_count_paid = $conn->prepare($sql_students_with_enough_payment);
 if ($stmt_count_paid) {
     $stmt_count_paid->bind_param("i", $current_user_id);
     $stmt_count_paid->execute();
     $result_count_paid = $stmt_count_paid->get_result();
     if ($result_count_paid) {
         // Count the rows returned by the HAVING clause
          $students_paid = $result_count_paid->num_rows;
          if ($result_count_paid) $result_count_paid->free();
     } else {
         error_log("Error fetching count of paid students for user " . $current_user_id . ": " . $conn->error);
     }
     $stmt_count_paid->close();
 } else {
     error_log("Database error preparing count paid students query for user " . $current_user_id . ": " . $conn->error);
 }


 // Students Owing = Total Students - Students Paid (overall status)
 $students_owing = $total_students - $students_paid;


$conn->close();
?>
<?php include 'includes/header.php'; ?>

<h1>Dashboard</h1>

<!-- Date Range Filter Form -->
<div class="card mb-4">
    <div class="card-header"><i class="fas fa-calendar-alt me-1"></i> Filter Financial Overview by Date</div>
    <div class="card-body">
        <form action="dashboard.php" method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i> Apply Filter</button>
                 <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-sync-alt me-1"></i> Reset Filter</a>
            </div>
        </form>
    </div>
</div>


<div class="row dashboard">
    <!-- Financial Overview Cards -->
    <div class="col-md-4 mb-4">
        <div class="card text-white bg-success">
            <div class="card-body">
                <h5 class="card-title">Total Income <?php echo (!empty($start_date) || !empty($end_date)) ? '(Filtered)' : '(All Time)'; ?></h5>
                <p class="card-text display-4">₦<?php echo number_format($total_income, 2); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card text-white bg-danger">
            <div class="card-body">
                <h5 class="card-title">Total Expenses <?php echo (!empty($start_date) || !empty($end_date)) ? '(Filtered)' : '(All Time)'; ?></h5>
                <p class="card-text display-4">₦<?php echo number_format($total_expenses, 2); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card <?php echo ($balance >= 0) ? 'bg-primary text-white' : 'bg-warning text-dark'; ?>"> <!-- Added text-dark for warning -->
            <div class="card-body">
                <h5 class="card-title">Balance <?php echo (!empty($start_date) || !empty($end_date)) ? '(Filtered)' : '(All Time)'; ?></h5>
                <p class="card-text display-4">₦<?php echo number_format($balance, 2); ?></p>
                <p class="card-text h6"><?php echo $profit_loss_status; ?></p>
            </div>
        </div>
    </div>

    <!-- Student Status Cards (All Time) -->
     <div class="col-md-4 mb-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h5 class="card-title">Total Students (All Time)</h5>
                <p class="card-text display-4"><?php echo $total_students; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h5 class="card-title">Students Paid (All Time)</h5>
                <p class="card-text display-4"><?php echo $students_paid; ?></p>
            </div>
        </div>
    </div>
     <div class="col-md-4 mb-4">
        <div class="card bg-warning text-dark">
            <div class="card-body">
                <h5 class="card-title">Students Owing (All Time)</h5>
                 <p class="card-text display-4"><?php echo $students_owing; ?></p>
            </div>
        </div>
    </div>

</div>

<!-- Section for Charts (Future Implementation) -->
<!--
<div class="card mb-4">
    <div class="card-header">Financial Trends</div>
    <div class="card-body">
        <canvas id="incomeExpenseChart"></canvas>
    </div>
</div>
-->


<?php include 'includes/footer.php'; ?>
<?php
// --- JavaScript for Charting (Outline - Requires Chart.js library) ---
/*
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // PHP prepares data for JavaScript (example - fetch monthly data or category data)
    // $monthly_income_data = ... fetch data ...
    // $monthly_expense_data = ... fetch data ...
    // $labels = ... month names or categories ...

    // const incomeData = <?php // echo json_encode($monthly_income_data); ?>;
    // const expenseData = <?php // echo json_encode($monthly_expense_data); ?>;
    // const labels = <?php // echo json_encode($labels); ?>;

    // const ctx = document.getElementById('incomeExpenseChart').getContext('2d');
    // const incomeExpenseChart = new Chart(ctx, {
    //     type: 'bar', // or 'line'
    //     data: {
    //         labels: labels,
    //         datasets: [{
    //             label: 'Income',
    //             data: incomeData,
    //             backgroundColor: 'rgba(40, 167, 69, 0.6)', // Bootstrap success green
    //             borderColor: 'rgba(40, 167, 69, 1)',
    //             borderWidth: 1
    //         },
    //         {
    //             label: 'Expenses',
    //             data: expenseData,
    //             backgroundColor: 'rgba(220, 53, 69, 0.6)', // Bootstrap danger red
    //             borderColor: 'rgba(220, 53, 69, 1)',
    //             borderWidth: 1
    //         }]
    //     },
    //     options: {
    //         // ... chart options ...
    //     }
    // });
</script>
*/
?>