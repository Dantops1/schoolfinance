<?php
// Quick debugging helper - uncomment to display errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php_error_student_report.log');

ob_start(); // Start output buffering

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireLogin();

$current_user_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? null;

// --- Validate and Verify Student ---
if (!$student_id || !filter_var($student_id, FILTER_VALIDATE_INT)) {
    // If student ID is invalid or missing, output an error CSV
    ob_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="error_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ["Error: Invalid student ID provided."]);
    fclose($output);
    if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
    ob_end_flush();
    exit;
}

// Verify the student belongs to the current user and get their name and class info
$student_info = null;
$stmt_student = $conn->prepare("
    SELECT s.student_name, c.class_name, c.class_fee
    FROM students s
    JOIN classes c ON s.class_id = c.class_id
    WHERE s.student_id = ? AND s.user_id = ?
");
if ($stmt_student) {
    $stmt_student->bind_param("ii", $student_id, $current_user_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    if ($result_student && $result_student->num_rows > 0) {
        $student_info = $result_student->fetch_assoc();
         if ($result_student) $result_student->free();
    } else {
        // Student not found or doesn't belong to the user - output error CSV
        ob_clean();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="error_report.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ["Error: Student not found or does not belong to your school."]);
        fclose($output);
        if ($result_student) $result_student->free(); // Ensure result is freed
        if (isset($stmt_student)) $stmt_student->close(); // Ensure statement is closed
        if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
        ob_end_flush();
        exit;
    }
    $stmt_student->close();
} else {
     error_log("Database error preparing student verification query for history report (user " . $current_user_id . ", student " . $student_id . "): " . $conn->error);
     ob_clean();
     header('Content-Type: text/csv');
     header('Content-Disposition: attachment; filename="error_report.csv"');
     $output = fopen('php://output', 'w');
     fputcsv($output, ["Error: Database error preparing to verify student."]);
     fclose($output);
      if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
     ob_end_flush();
     exit;
}

// --- Fetch Payments for this Student ---
$payments = [];
$total_paid_for_student = 0;
$sql_payments = "SELECT payment_date, amount_paid, notes
                 FROM payments
                 WHERE student_id = ? AND user_id = ? -- Filter by student and user
                 ORDER BY payment_date ASC, payment_id ASC"; // Order chronologically
$stmt_payments = $conn->prepare($sql_payments);

if ($stmt_payments) {
    $stmt_payments->bind_param("ii", $student_id, $current_user_id);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();

    if($result_payments) {
        while($row = $result_payments->fetch_assoc()) {
            $payments[] = $row;
            $total_paid_for_student += $row['amount_paid'];
        }
         if ($result_payments) $result_payments->free();
    } else {
        error_log("Error fetching payments for student history report (user " . $current_user_id . ", student " . $student_id . "): " . $conn->error);
        // No need to exit immediately, can still generate report with error noted
    }
    $stmt_payments->close();
} else {
     error_log("Database error preparing payments query for student history report (user " . $current_user_id . ", student " . $student_id . "): " . $conn->error);
      // No need to exit immediately
}

// Calculate balance due
$balance_due = $student_info['class_fee'] - $total_paid_for_student;


// --- Close the database connection ---
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();


// --- Set Headers for CSV Download ---
ob_clean(); // Clear buffer again before sending headers
header('Content-Type: text/csv');
$filename = 'payment_history_' . str_replace(' ', '_', $student_info['student_name']) . '_' . date('Y-m-d') . '.csv';
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, "\xEF\xBB\xBF"); // UTF-8 BOM

// --- Write Report Title and Student Info ---
fputcsv($output, ["Payment History Report"]);
fputcsv($output, ["Student Name: " . $student_info['student_name']]);
fputcsv($output, ["Class: " . $student_info['class_name']]);
fputcsv($output, ["Class Fee: NGN" . number_format($student_info['class_fee'], 2, '.', '')]);
fputcsv($output, ["Total Paid (So Far): NGN" . number_format($total_paid_for_student, 2, '.', '')]);
fputcsv($output, ["Balance Due: NGN" . number_format($balance_due, 2, '.', '')]);
fputcsv($output, ["Report Date: " . date('Y-m-d H:i')]);
fputcsv($output, [""]); // Blank line

// --- Write Payment Details Header ---
fputcsv($output, ['--- PAYMENT DETAILS ---']);
fputcsv($output, ['Date', 'Amount (NGN)', 'Notes']); // CSV Header

// --- Write Payment Details ---
if (count($payments) > 0):
    foreach ($payments as $payment):
        $csv_row = [
            $payment['payment_date'],
            number_format($payment['amount_paid'], 2, '.', ''),
            $payment['notes']
        ];
        fputcsv($output, $csv_row);
    endforeach;
else:
    fputcsv($output, ["No payment records found for this student."]);
endif;


// --- Close Output Stream ---
fclose($output);

ob_end_flush();
exit;
?>