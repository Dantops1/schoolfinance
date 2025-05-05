<?php
// Quick debugging helper - uncomment the following lines to display errors in the browser
// Make sure these lines are at the very top, after the opening <?php tag
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1); // Also log errors to a file
// ini_set('error_log', __DIR__ . '/php_error.log'); // Log to a file in the same directory as payments.php

// Include authentication helpers and database connection
require_once 'includes/auth.php'; // Includes session_start() and requireLogin()
require_once 'includes/db.php';
requireLogin(); // Protect this page - only logged-in users can access

$current_user_id = $_SESSION['user_id']; // Get the current user's ID from the session

// --- Variables for Messages ---
$message = "";
$message_type = ""; // success, danger, warning


// --- Handle AJAX Request for Students by Class ---
// This block will execute if the request includes ?action=get_students
// It runs *before* any standard page rendering logic.
if (isset($_GET['action']) && $_GET['action'] === 'get_students') {
    // Ensure this is an AJAX request and the user is authenticated.
    // requireLogin() ensures authentication at the top of the script.

    $class_id = $_GET['class_id'] ?? null;

    // Set content type to JSON for the AJAX response
    header('Content-Type: application/json');

    // Validate class_id
    if (!$class_id || !filter_var($class_id, FILTER_VALIDATE_INT)) {
        // Return an empty array or error message for invalid input
        echo json_encode([]);
        // Important: Close connection and exit after sending AJAX response
        // Check if connection is valid before trying to close
        if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
        exit; // Stop script execution after AJAX response
    }

    // Fetch students belonging to this class AND the current user
    $sql_students = "SELECT student_id, student_name FROM students WHERE class_id = ? AND user_id = ? ORDER BY student_name";
    $stmt_students = $conn->prepare($sql_students);

    if ($stmt_students) {
        // Bind parameters: i=integer, i=integer
        $stmt_students->bind_param("ii", $class_id, $current_user_id);
        $stmt_students->execute();
        $result_students = $stmt_students->get_result();

        $students_list = [];
        if ($result_students) {
            while ($row = $result_students->fetch_assoc()) {
                $students_list[] = $row; // Add student data to the list
            }
            if ($result_students) $result_students->free(); // Free result set
        } else {
             // Log error but return empty list or error JSON
             error_log("Error fetching students for class " . $class_id . " (user " . $current_user_id . "): " . $conn->error);
        }
        $stmt_students->close(); // Close statement

        echo json_encode($students_list); // Output the student list as JSON

    } else {
         // Log error and return error JSON
         error_log("Database error preparing get_students query for user " . $current_user_id . ": " . $conn->error);
         echo json_encode(['error' => 'Database error fetching students']);
    }

    // Important: Close connection and exit after sending AJAX response
     if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
    exit; // Stop script execution after AJAX request is handled
}


// --- Handle Standard Page Load and POST Requests ---
// This code runs if it's NOT an AJAX request for students lists.

// --- Handle POST Requests (e.g., record payment, delete payment) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Handle Record New Payment Form ---
    if (isset($_POST['record_payment'])) {
        // Note: Class ID is selected in the form but not stored in the payments table itself.
        // The student_id is the direct link to the student, who is linked to a class.
        // We still need to ensure the selected student belongs to the current user.
        $student_id = $_POST['student_id']; // Student ID from the dynamic dropdown
        $amount = trim($_POST['amount_paid'] ?? '');
        $payment_date = trim($_POST['payment_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if (empty($student_id) || !filter_var($student_id, FILTER_VALIDATE_INT)) {
            $message = "Invalid student selected.";
            $message_type = "danger";
        } elseif (!is_numeric($amount) || $amount <= 0) {
            $message = "Invalid amount. Please enter a positive number.";
            $message_type = "warning";
        } elseif (empty($payment_date)) {
            $message = "Payment date is required.";
            $message_type = "warning";
        } else {
             // Before inserting, verify the student exists AND belongs to the current user
            $stmt_check_student = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND user_id = ?");
            if ($stmt_check_student) {
                $stmt_check_student->bind_param("ii", $student_id, $current_user_id);
                $stmt_check_student->execute();
                $stmt_check_student->store_result(); // Use store_result to check num_rows

                if ($stmt_check_student->num_rows > 0) {
                    $stmt_check_student->close(); // Close the check statement

                    // Student is valid and belongs to the user, proceed with insert
                    $stmt_add = $conn->prepare("INSERT INTO payments (student_id, amount_paid, payment_date, notes, user_id) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt_add) {
                         $stmt_add->bind_param("idssi", $student_id, $amount, $payment_date, $notes, $current_user_id);

                        if ($stmt_add->execute()) {
                            $payment_id = $stmt_add->insert_id;
                            $message = "Payment recorded successfully!";
                            $message_type = "success";
                            // Redirect to the receipt page
                            header("location: print_receipt.php?payment_id=" . $payment_id);
                            exit; // Stop script execution after redirect

                        } else {
                            $message = "Error recording payment: " . $conn->error;
                            $message_type = "danger";
                             error_log("Error recording payment for user " . $current_user_id . ": " . $conn->error); // Log the actual DB error
                        }
                        $stmt_add->close(); // Close the add statement
                    } else {
                         $message = "Database error preparing add payment statement.";
                         $message_type = "danger";
                         error_log("Database error preparing add payment statement for user " . $current_user_id . ": " . $conn->error);
                    }
                } else {
                    // Student ID was provided but does not exist or does not belong to this user
                    $message = "Selected student not found or does not belong to your school.";
                    $message_type = "danger";
                     if (isset($stmt_check_student)) $stmt_check_student->close();
                }
            } else {
                 $message = "Database error preparing student verification statement.";
                 $message_type = "danger";
                 error_log("Database error preparing student verification statement for user " . $current_user_id . ": " . $conn->error);
            }
        }
         // If code execution reaches here, redirect back to payments page with message
         header("location: payments.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
         exit; // Stop script execution after redirect

    } // --- End Handle Record New Payment Form ---

    // --- Handle Delete Payment Form ---
    // This handles deletions from the Recent Payments section
    elseif (isset($_POST['delete_payment_id'])) {
        $payment_id_to_delete = $_POST['delete_payment_id'];

        if (!filter_var($payment_id_to_delete, FILTER_VALIDATE_INT)) {
            $message = "Invalid payment ID for deletion.";
            $message_type = "danger";
        } else {
            // Prepare DELETE statement - ADD USER_ID FILTER for crucial security
            // This prevents a user from deleting a payment that doesn't belong to them
            $stmt_delete = $conn->prepare("DELETE FROM payments WHERE payment_id = ? AND user_id = ?");
            if ($stmt_delete) {
                 $stmt_delete->bind_param("ii", $payment_id_to_delete, $current_user_id);

                if ($stmt_delete->execute()) {
                     if ($stmt_delete->affected_rows > 0) {
                        $message = "Payment deleted successfully.";
                        $message_type = "success";
                     } else {
                         // This case happens if the payment_id was valid but didn't belong to the user,
                         // or if it was already deleted.
                         $message = "Payment not found or already deleted, or does not belong to your school.";
                         $message_type = "warning";
                     }
                } else {
                    $message = "Error deleting payment: " . $conn->error;
                    $message_type = "danger";
                     error_log("Error deleting payment_id " . $payment_id_to_delete . " for user " . $current_user_id . ": " . $conn->error); // Log the actual DB error
                }
                $stmt_delete->close(); // Close the delete statement
            } else {
                $message = "Database error preparing delete statement.";
                $message_type = "danger";
                error_log("Database error preparing delete payment statement for user " . $current_user_id . ": " . $conn->error);
            }
        }
         // Redirect back to payments page to show the message
         header("location: payments.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
         exit;
    } // --- End Handle Delete Payment Form ---

} // --- End Handle POST Requests ---


// --- Check for messages after GET redirect ---
// This block runs if the page was accessed via a GET request, including redirects from POST
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}


// --- Fetch list of ALL classes for the "Add Payment" dropdown ---
// Filtered by current user
$classes_for_dropdown = [];
$sql_classes_dropdown = "SELECT class_id, class_name FROM classes WHERE user_id = ? ORDER BY class_name";
$stmt_classes_dropdown = $conn->prepare($sql_classes_dropdown);
if ($stmt_classes_dropdown) {
    $stmt_classes_dropdown->bind_param("i", $current_user_id);
    $stmt_classes_dropdown->execute();
    $result_classes_dropdown = $stmt_classes_dropdown->get_result();
    if ($result_classes_dropdown) {
        while ($row = $result_classes_dropdown->fetch_assoc()) {
            $classes_for_dropdown[] = $row;
        }
        if ($result_classes_dropdown) $result_classes_dropdown->free();
    } else {
         error_log("Error fetching classes for payments dropdown for user " . $current_user_id . ": " . $conn->error);
    }
    $stmt_classes_dropdown->close();
} else {
    error_log("Database error preparing classes dropdown query for user " . $current_user_id . ": " . $conn->error);
}


// --- Fetch Recent 5 Payments for this user ---
$recent_payments = [];
// Join with students to get student name
$sql_recent = "SELECT p.*, s.student_name
               FROM payments p
               JOIN students s ON p.student_id = s.student_id
               WHERE p.user_id = ? -- Filter payments by the current user
               ORDER BY payment_date DESC, payment_id DESC LIMIT 5"; // Get the last 5 payments
$stmt_recent = $conn->prepare($sql_recent);
if ($stmt_recent) {
    $stmt_recent->bind_param("i", $current_user_id);
    $stmt_recent->execute();
    $result_recent = $stmt_recent->get_result();
    if($result_recent) {
        while($row_recent = $result_recent->fetch_assoc()) {
            $recent_payments[] = $row_recent; // Store recent payments
        }
         if ($result_recent) $result_recent->free();
    } else {
        error_log("Error fetching recent payments for user " . $current_user_id . ": " . $conn->error);
    }
    $stmt_recent->close();
} else {
     error_log("Database error preparing recent payments query for user " . $current_user_id . ": " . $conn->error);
}


// --- Close the database connection ---
// Ensure this is the last database interaction before closing
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();

?>

<?php include 'includes/header.php'; ?>

<h1>Payments Management</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Add New Payment Card -->
<div class="card mb-4">
    <div class="card-header"><i class="fas fa-money-bill-wave me-1"></i> Add New Payment</div>
    <div class="card-body">
        <!-- The form action targets the same page -->
        <form action="payments.php" method="post">
            <input type="hidden" name="record_payment" value="1">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="class_id" class="form-label">Select Class</label>
                    <select class="form-select" id="class_id" name="class_id" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes_for_dropdown as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>">
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="student_id" class="form-label">Select Student</label>
                    <!-- This dropdown will be populated dynamically by JavaScript -->
                    <select class="form-select" id="student_id" name="student_id" required disabled>
                        <option value="">-- Select Class First --</option>
                    </select>
                </div>
            </div>
            <div class="row">
                 <div class="col-md-6 mb-3">
                    <label for="amount_paid" class="form-label">Amount Paid (₦)</label>
                    <input type="number" class="form-control" id="amount_paid" name="amount_paid" step="0.01" min="0.01" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="payment_date" class="form-label">Payment Date</label>
                    <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
             <div class="mb-3">
                <label for="notes" class="form-label">Notes (Optional)</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-success"><i class="fas fa-check-circle me-1"></i> Record Payment</button>
        </form>
    </div>
</div>


<!-- Recent 5 Payments Section -->
<div class="card mb-4">
    <div class="card-header"><i class="fas fa-clock-rotate-left me-1"></i> Recent 5 Payments</div>
    <div class="card-body">
        <?php if (count($recent_payments) > 0): ?>
             <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Amount</th>
                            <th>Notes</th>
                            <th>Receipt</th>
                             <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                <td>₦<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payment['notes']); ?></td>
                                <td><a href="print_receipt.php?payment_id=<?php echo $payment['payment_id']; ?>" class="btn btn-secondary btn-sm" target="_blank" title="Print Receipt"><i class="fas fa-print"></i></a></td>
                                <td>
                                    <!-- Delete form for each recent payment -->
                                    <form action="payments.php" method="post" class="d-inline-block" onsubmit="return confirm('Are you SURE you want to delete this payment record (ID: <?php echo $payment['payment_id']; ?>)?');">
                                        <input type="hidden" name="delete_payment_id" value="<?php echo $payment['payment_id']; ?>">
                                        <!-- No specific redirect_to_history_student_id needed here -->
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Payment"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
             </div>
        <?php else: ?>
            <div class="alert alert-info" role="alert">No recent payments recorded for your school.</div>
        <?php endif; ?>
    </div>
</div>


<?php
// Removed Search/History and All Payments/Pagination sections
?>

<?php include 'includes/footer.php'; ?>

<!-- Add JavaScript to handle class-student dropdown dependency -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const classDropdown = document.getElementById('class_id');
    const studentDropdown = document.getElementById('student_id');

    // Function to update student dropdown based on selected class
    function updateStudentDropdown() {
        const selectedClassId = classDropdown.value;

        // Clear and disable student dropdown if no class is selected
        if (!selectedClassId) {
            studentDropdown.innerHTML = '<option value="">-- Select Class First --</option>';
            studentDropdown.disabled = true;
            return;
        }

        // Disable student dropdown and show loading state
        studentDropdown.innerHTML = '<option value="">Loading students...</option>';
        studentDropdown.disabled = true;

        // Make an AJAX request to fetch students for the selected class
        fetch('payments.php?action=get_students&class_id=' + selectedClassId)
            .then(response => {
                if (!response.ok) {
                     // Check if the response status is not 200 (e.g., 500 Internal Server Error)
                    console.error('HTTP error! Status:', response.status, response.statusText);
                    return response.text().then(text => { // Attempt to get response text for debugging
                         console.error('Response text:', text);
                         throw new Error('Network response was not ok: ' + response.statusText);
                    });
                }
                 // Check if the response is valid JSON before parsing
                 const contentType = response.headers.get("content-type");
                 if (contentType && contentType.indexOf("application/json") !== -1) {
                    return response.json(); // Parse the JSON response
                 } else {
                     // Response is not JSON, log it and throw an error
                     return response.text().then(text => {
                         console.error('Received non-JSON response:', text);
                         throw new Error('Did not receive JSON response');
                     });
                 }
            })
            .then(students => {
                // Clear existing options
                studentDropdown.innerHTML = '<option value="">-- Select Student --</option>';

                if (students.length > 0) {
                    // Populate the student dropdown with fetched data
                    students.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.student_id;
                        option.textContent = student.student_name; // Use student_name from the AJAX response
                        studentDropdown.appendChild(option);
                    });
                    studentDropdown.disabled = false; // Enable the dropdown
                } else {
                    // No students found for this class
                     studentDropdown.innerHTML = '<option value="">-- No Students in Class --</option>';
                     studentDropdown.disabled = true;
                }
            })
            .catch(error => {
                // Handle any errors during the fetch or JSON parsing
                console.error('Error fetching students:', error);
                studentDropdown.innerHTML = '<option value="">-- Error loading students --</option>';
                studentDropdown.disabled = true;
                // You might want to show a user-facing error message here as well
            });
    }

    // Add event listener to the class dropdown
    classDropdown.addEventListener('change', updateStudentDropdown);


    // --- Initial Load Logic ---
    // If the page loads and a class is already selected (e.g., due to form submission with errors),
    // attempt to populate the student dropdown based on that class.
    // We need to check if the selected value is not the default empty option.
    if (classDropdown.value) {
        // We cannot pre-select a specific student here easily without more info passed via GET/POST
        // But we can at least populate the student dropdown based on the pre-selected class.
        updateStudentDropdown();
    } else {
        // Ensure student dropdown is correctly initialized if no class is selected on load
        studentDropdown.innerHTML = '<option value="">-- Select Class First --</option>';
        studentDropdown.disabled = true;
    }


    // Note: If you linked from view_class.php with student_id AND class_id,
    // you would need more complex JS here to set the class dropdown value,
    // wait for updateStudentDropdown to complete its fetch, and then set the student dropdown value.
    // This code handles the simple case of loading the page or selecting a class.

});
</script>