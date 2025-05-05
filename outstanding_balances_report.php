<?php
// Quick debugging helper - uncomment to display errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php_error_report.log');

ob_start(); // Start output buffering

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireLogin();

$current_user_id = $_SESSION['user_id'];

// --- Fetch User and School Name ---
$school_name = 'School Finance App'; // Default
$stmt_user = $conn->prepare("SELECT school_name FROM users WHERE user_id = ?");
if ($stmt_user) {
    $stmt_user->bind_param("i", $current_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user && $result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        if (!empty($user_data['school_name'])) {
             $school_name = $user_data['school_name'];
        }
         if ($result_user) $result_user->free();
    } else {
         error_log("Could not fetch school name for outstanding balances report (user " . $current_user_id . ").");
    }
    $stmt_user->close();
} else {
     error_log("Database error preparing school name query for outstanding balances report (user " . $current_user_id . "): " . $conn->error);
}


// --- Set Headers for CSV Download ---
ob_clean();
header('Content-Type: text/csv');
$filename = 'outstanding_balances_' . str_replace(' ', '_', $school_name) . '_' . date('Y-m-d') . '.csv';
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, "\xEF\xBB\xBF"); // UTF-8 BOM

// --- Write Report Title and School Name ---
fputcsv($output, ["Outstanding Balances Report"]);
fputcsv($output, ["School Name: " . $school_name]);
fputcsv($output, ["Report Date: " . date('Y-m-d H:i')]);
fputcsv($output, [""]); // Blank line

// --- Fetch and Write Outstanding Balances Data ---

fputcsv($output, ['--- STUDENTS OWING ---']);
fputcsv($output, ['Student Name', 'Class', 'Class Fee (NGN)', 'Total Paid (NGN)', 'Balance Due (NGN)']); // CSV Header

// Fetch students who owe money for this user
// Select students, join classes to get fee, left join payments to sum payments, group by student/fee, filter where sum is < fee (or no payments made)
$sql_owing = "
    SELECT
        s.student_name,
        c.class_name,
        c.class_fee,
        COALESCE(SUM(p.amount_paid), 0) AS total_paid, -- Use COALESCE to treat NULL (no payments) as 0
        (c.class_fee - COALESCE(SUM(p.amount_paid), 0)) AS balance_due
    FROM students s
    JOIN classes c ON s.class_id = c.class_id -- Inner join assumes students are in a class
    LEFT JOIN payments p ON s.student_id = p.student_id -- Left join to include students with no payments
    WHERE s.user_id = ? -- Filter by user
    GROUP BY s.student_id, s.student_name, c.class_name, c.class_fee
    HAVING (c.class_fee - COALESCE(SUM(p.amount_paid), 0)) > 0 -- Condition: Balance Due > 0
    ORDER BY s.student_name ASC
";
$stmt_owing = $conn->prepare($sql_owing);

if ($stmt_owing) {
    $stmt_owing->bind_param("i", $current_user_id);
    $stmt_owing->execute();
    $result_owing = $stmt_owing->get_result();

    if($result_owing) {
        if ($result_owing->num_rows > 0) {
            while($row = $result_owing->fetch_assoc()) {
                $csv_row = [
                    $row['student_name'],
                    $row['class_name'],
                    number_format($row['class_fee'], 2, '.', ''),
                    number_format($row['total_paid'], 2, '.', ''),
                    number_format($row['balance_due'], 2, '.', '')
                ];
                fputcsv($output, $csv_row);
            }
            if ($result_owing) $result_owing->free();
        } else {
            fputcsv($output, ["No students found with an outstanding balance."]);
        }
    } else {
        error_log("Error fetching outstanding balances for CSV report (user " . $current_user_id . "): " . $conn->error);
        fputcsv($output, ["Error fetching outstanding balances."]);
    }
    $stmt_owing->close();
} else {
     error_log("Database error preparing outstanding balances query for CSV report (user " . $current_user_id . "): " . $conn->error);
     fputcsv($output, ["Database error preparing outstanding balances query."]);
}


// --- Close Output Stream ---
fclose($output);

// --- Close the database connection ---
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();

ob_end_flush();
exit;
?>