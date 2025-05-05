<?php
// Quick debugging helper - uncomment the following lines to display errors for debugging CSV generation
// Make sure these lines are at the very top, after the opening <?php tag, with NO leading whitespace
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1); // Log errors to a file
// ini_set('error_log', __DIR__ . '/php_error_download.log'); // Specify a log file path the web server can write to
?>
<?php

// Ensure no accidental whitespace/output before headers
ob_start(); // Start output buffering


// Include authentication helpers and database connection
// Use __DIR__ for safer includes relative to the current file's directory
require_once __DIR__ . '/includes/auth.php'; // Includes session_start() and requireLogin()
require_once __DIR__ . '/includes/db.php';

// Protect this script - only logged-in users can access
// This will redirect to login.php if not logged in, which sends headers,
// but requireLogin() should exit immediately, preventing headers from being sent twice in this script.
requireLogin();

// Get the current user's ID from the session
$current_user_id = $_SESSION['user_id'];

// --- Fetch User and School Name (Optional for CSV content, but good practice) ---
// We can include school name in the CSV filename or a header row.
$school_name = 'School Finance App'; // Default
$stmt_user = $conn->prepare("SELECT school_name FROM users WHERE user_id = ?");
if ($stmt_user) {
    $stmt_user->bind_param("i", $current_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user && $result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        if (!empty($user_data['school_name'])) {
             $school_name = $user_data['school_name']; // Use raw name here, fputcsv will handle quoting
        }
         if ($result_user) $result_user->free(); // Free result set
    } else {
         error_log("Could not fetch school name for user " . $current_user_id . " for CSV.");
    }
    $stmt_user->close(); // Close statement
} else {
     error_log("Database error preparing school name query for CSV (user " . $current_user_id . "): " . $conn->error);
}


// --- Set Headers for CSV Download ---
// Clear any accidental output buffered so far before sending headers
ob_clean();

// Tell the browser this is a CSV file download
header('Content-Type: text/csv');
// Suggest a filename for the downloaded file
$filename = 'financial_transactions_' . str_replace(' ', '_', $school_name) . '_' . date('Y-m-d') . '.csv';
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"'); // Use rawurlencode for safety in filename
// Prevent caching
header('Pragma: no-cache');
header('Expires: 0');


// --- Open Output Stream ---
// php://output is a write-only stream that allows you to write directly to the output buffer.
$output = fopen('php://output', 'w');

// Add UTF-8 BOM (optional but recommended for correct display in Excel)
// This is output *after* headers are sent.
fprintf($output, "\xEF\xBB\xBF");

// --- Write Report Title and School Name ---
fputcsv($output, ["Financial Transactions Report"]);
fputcsv($output, ["School Name: " . $school_name]);
fputcsv($output, ["Report Date: " . date('Y-m-d H:i')]);
fputcsv($output, [""]); // Blank line


// --- Fetch and Write Payments Data ---

// Write the header for the Payments section
fputcsv($output, ['--- PAYMENTS ---']); // Corrected comment above this line in previous response
fputcsv($output, ['Date', 'Amount (NGN)', 'Student Name', 'Class Name', 'Notes']); // CSV Header Row for Payments

// Fetch all payments for this user
$sql_payments = "SELECT p.payment_date, p.amount_paid, s.student_name, c.class_name, p.notes
                 FROM payments p
                 JOIN students s ON p.student_id = s.student_id
                 JOIN classes c ON s.class_id = c.class_id
                 WHERE p.user_id = ?
                 ORDER BY payment_date ASC, p.payment_id ASC"; // Order chronologically
$stmt_payments = $conn->prepare($sql_payments);

if ($stmt_payments) {
    $stmt_payments->bind_param("i", $current_user_id);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();

    if($result_payments) {
        while($row = $result_payments->fetch_assoc()) {
            // Format the row for CSV
            $csv_row = [
                $row['payment_date'],
                number_format($row['amount_paid'], 2, '.', ''), // Format number, use '.' for decimal, no thousands separator
                $row['student_name'],
                $row['class_name'],
                $row['notes']
            ];
            fputcsv($output, $csv_row); // Write the row to the CSV
        }
         if ($result_payments) $result_payments->free(); // Free result set
    } else {
        error_log("Error fetching payments for CSV report (user " . $current_user_id . "): " . $conn->error);
        // Optionally write an error line to the CSV or trigger an error message before headers are sent
         fputcsv($output, ["Error fetching payments."]);
    }
    $stmt_payments->close(); // Close statement
} else {
     error_log("Database error preparing payments query for CSV report (user " . $current_user_id . "): " . $conn->error);
      // Optionally write an error line
      fputcsv($output, ["Database error preparing payments query."]);
}

// Add a blank row for separation
fputcsv($output, [""]);
fputcsv($output, [""]); // Another blank line

// --- Fetch and Write Expenses Data ---

// Write the header for the Expenses section
fputcsv($output, ['--- EXPENSES ---']); // <-- CORRECTED LINE (was ~132 in previous code)
fputcsv($output, ['Date', 'Amount (NGN)', 'Description', 'Notes']); // CSV Header Row for Expenses

// Fetch all expenses for this user
$sql_expenses = "SELECT expense_date, amount, expense_description, notes
                 FROM expenses
                 WHERE user_id = ?
                 ORDER BY expense_date ASC, expense_id ASC"; // Order chronologically
$stmt_expenses = $conn->prepare($sql_expenses);

if ($stmt_expenses) {
    $stmt_expenses->bind_param("i", $current_user_id);
    $stmt_expenses->execute();
    $result_expenses = $stmt_expenses->get_result();

    if($result_expenses) {
        while($row = $result_expenses->fetch_assoc()) {
             // Format the row for CSV
            $csv_row = [
                $row['expense_date'],
                number_format($row['amount'], 2, '.', ''), // Format number
                $row['expense_description'],
                $row['notes']
            ];
            fputcsv($output, $csv_row); // Write the row to the CSV
        }
         if ($result_expenses) $result_expenses->free(); // Free result set
    } else {
        error_log("Error fetching expenses for CSV report (user " . $current_user_id . "): " . $conn->error);
         // Optionally write an error line
         fputcsv($output, ["Error fetching expenses."]);
    }
    $stmt_expenses->close(); // Close statement
} else {
     // Database error preparing expenses query
     error_log("Database error preparing expenses query for CSV report (user " . $current_user_id . "): " . $conn->error);
      // Optionally write an error line
      fputcsv($output, ["Database error preparing expenses query."]);
}


// --- Close Output Stream ---
fclose($output);

// --- Close the database connection ---
// Check if connection is valid before closing
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();


// End output buffering and flush output
ob_end_flush(); // Or ob_end_clean() if you don't want to flush remaining buffer content (less common for direct CSV output)

// Exit script to prevent further execution
exit;
?>