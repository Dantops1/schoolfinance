<?php
// Quick debugging helper - uncomment the following lines to display errors in the browser
// Make sure these lines are at the very top, after the opening <?php tag
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1); // Also log errors to a file
// ini_set('error_log', __DIR__ . '/php_error_profile.log'); // Log to a file in the same directory as profile.php

// Include authentication helpers and database connection
require_once 'includes/auth.php'; // Includes session_start() and requireLogin()
require_once 'includes/db.php';
requireLogin(); // Protect this page - only logged-in users can access

// Get the current user's ID from the session
$current_user_id = $_SESSION['user_id'];
// Get username from session initially, but fetch actual data below
$current_username_session = $_SESSION['username'];

// Fetch user details including school_name and password hash from the database
$user_data = null;
$user_password_hash = null; // Store hash separately for verification
$current_school_name = '';   // Initialize school name
$current_username_db = '';   // Initialize username from DB

$stmt_user = $conn->prepare("SELECT username, password, school_name FROM users WHERE user_id = ?");
if ($stmt_user) {
    $stmt_user->bind_param("i", $current_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        $current_username_db = $user_data['username'];
        $user_password_hash = $user_data['password'];
        $current_school_name = $user_data['school_name'] ?? ''; // Get school name, default to empty string if NULL
        if ($result_user) $result_user->free();
    } else {
        // This shouldn't happen if requireLogin works, but handle as a critical safety check
        error_log("Critical Error: Logged-in User ID " . $current_user_id . " not found in database.");
        // Close connection before potentially redirecting/exiting
         if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
        logout(); // Log the user out as their session is invalid
        exit;
    }
    $stmt_user->close();
} else {
    error_log("Database error preparing user fetch statement for user " . $current_user_id . ": " . $conn->error);
     // Close connection before potentially redirecting/exiting
     if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
    // Handle severe error, maybe redirect to a general error page
    die("An application error occurred. Please try again later."); // Or header("location: error_page.php");
}


// Variables for form messages and errors
$message = "";
$message_type = ""; // success, danger, warning

// Variables for specific form errors (reset on each page load/post)
$username_err = "";
$password_err = ""; // Generic password error if needed
$current_password_err_username = ""; // Specific error for current password field in username form
$current_password_err_password = ""; // Specific error for current password field in password form
$new_password_err = "";
$confirm_new_password_err = "";
$school_name_err = "";


// --- Handle POST Requests ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Handle Change Username Form ---
    if (isset($_POST['action']) && $_POST['action'] === 'change_username') {
        $new_username = trim($_POST['new_username'] ?? '');
        $current_password_for_username = trim($_POST['current_password_username'] ?? '');

        // Validation
        if (empty($new_username)) {
            $username_err = "Please enter a new username.";
        } elseif ($new_username === $current_username_db) { // Compare against DB username
             $username_err = "New username cannot be the same as your current username.";
        }

        if (empty($current_password_for_username)) {
            $current_password_err_username = "Please enter your current password.";
        }

        // Only proceed with database checks if basic validation passes
        if (empty($username_err) && empty($current_password_err_username)) {
            // Verify the current password against the fetched hash
            if (password_verify($current_password_for_username, $user_password_hash)) {
                // Current password is correct, now check new username uniqueness (excluding current user)
                $sql_check_username = "SELECT user_id FROM users WHERE username = ? AND user_id != ?";
                if ($stmt_check = $conn->prepare($sql_check_username)) {
                    $param_username = $new_username;
                    $stmt_check->bind_param("si", $param_username, $current_user_id);
                    if ($stmt_check->execute()) {
                        $stmt_check->store_result();
                        if ($stmt_check->num_rows > 0) {
                            $username_err = "This username is already taken by another user.";
                        } else {
                            // Username is unique, proceed with update
                            $sql_update = "UPDATE users SET username = ? WHERE user_id = ?";
                            if ($stmt_update = $conn->prepare($sql_update)) {
                                $stmt_update->bind_param("si", $new_username, $current_user_id);
                                if ($stmt_update->execute()) {
                                    // Update successful
                                    $message = "Username updated successfully!";
                                    $message_type = "success";
                                    // Update session username and the variable holding DB username
                                    $_SESSION['username'] = $new_username;
                                    $current_username_db = $new_username; // Update local variable too
                                    // Redirect to refresh page and display message
                                    header("location: profile.php?msg_type=success&msg=" . urlencode($message));
                                    exit;
                                } else {
                                    $message = "Error updating username. Please try again.";
                                    $message_type = "danger";
                                    error_log("Error updating username for user_id " . $current_user_id . ": " . $stmt_update->error);
                                }
                                $stmt_update->close();
                            } else {
                                $message = "Database error preparing update statement.";
                                $message_type = "danger";
                                error_log("Database error preparing username update statement: " . $conn->error);
                            }
                        }
                    } else {
                         $message = "Oops! Something went wrong checking username uniqueness.";
                         $message_type = "danger";
                         error_log("Database error executing username uniqueness check: " . $stmt_check->error);
                    }
                    $stmt_check->close();
                } else {
                    $message = "Database error preparing username uniqueness check.";
                    $message_type = "danger";
                    error_log("Database error preparing username uniqueness check statement: " . $conn->error);
                }
            } else {
                // Current password does not match
                $current_password_err_username = "Incorrect current password.";
            }
        }

        // If we reached here without redirecting, there was an error or validation failed.
         if (empty($message)) { // Don't override a message already set by DB errors
             $message_type = "warning";
             $message = "Please fix the errors below for username update.";
         }


    } // --- End Handle Change Username Form ---

    // --- Handle Change Password Form ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_password') {
         $current_password = trim($_POST['current_password'] ?? '');
         $new_password = trim($_POST['new_password'] ?? '');
         $confirm_new_password = trim($_POST['confirm_new_password'] ?? '');

        // Validation
        if (empty($current_password)) {
            $current_password_err_password = "Please enter your current password.";
        }

        if (empty($new_password)) {
            $new_password_err = "Please enter a new password.";
        } elseif (strlen($new_password) < 6) { // Reuse minimum length logic
            $new_password_err = "New password must have at least 6 characters.";
        }

        if (empty($confirm_new_password)) {
            $confirm_new_password_err = "Please confirm the new password.";
        } elseif (!empty($new_password) && ($new_password !== $confirm_new_password)) {
            $confirm_new_password_err = "New password and confirmation do not match.";
        }


         // Only proceed with database checks if basic validation passes
        if (empty($current_password_err_password) && empty($new_password_err) && empty($confirm_new_password_err)) {
             // Verify the current password against the fetched hash
            if (password_verify($current_password, $user_password_hash)) {
                 // Current password is correct, proceed with update
                  $sql_update = "UPDATE users SET password = ? WHERE user_id = ?";
                  if ($stmt_update = $conn->prepare($sql_update)) {
                      $param_new_password = password_hash($new_password, PASSWORD_DEFAULT); // Hash the new password
                      $stmt_update->bind_param("si", $param_new_password, $current_user_id);
                      if ($stmt_update->execute()) {
                          // Update successful
                          $message = "Password updated successfully!";
                          $message_type = "success";
                          // Re-fetch the user data on the next page load will get the new hash.
                          // Redirect after successful password change
                          header("location: profile.php?msg_type=success&msg=" . urlencode($message));
                          exit;
                      } else {
                           $message = "Error updating password. Please try again.";
                           $message_type = "danger";
                            error_log("Error updating password for user_id " . $current_user_id . ": " . $stmt_update->error);
                      }
                      $stmt_update->close();
                  } else {
                      $message = "Database error preparing update statement.";
                      $message_type = "danger";
                       error_log("Database error preparing password update statement: " . $conn->error);
                  }
             } else {
                  // Current password does not match
                  $current_password_err_password = "Incorrect current password.";
             }
        }

         // If we reached here without redirecting, there was an error or validation failed.
         if (empty($message)) { // Don't override a message already set by DB errors
            $message_type = "warning";
            $message = "Please fix the errors below for password update.";
        }
    } // --- End Handle Change Password Form ---

     // --- Handle Change School Name Form ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_school_name') {
        $new_school_name = trim($_POST['new_school_name'] ?? '');
        // Optional: Require current password for school name change? Decision: No, keeping it simple for now.

        // Validation (allow empty string if user wants to clear it)
        if (strlen($new_school_name) > 150) { // Match DB column length
             $school_name_err = "School name is too long (max 150 characters).";
        }

        // If validation passes (only length check here)
        if (empty($school_name_err)) {
             // Prepare update statement
             $sql_update = "UPDATE users SET school_name = ? WHERE user_id = ?";
             if ($stmt_update = $conn->prepare($sql_update)) {
                 // Bind empty string if new_school_name is empty, as DB column is NULLABLE.
                 // bind_param handles passing empty string which MySQL stores as empty string,
                 // but NULL is often better if the field is truly optional. Let's stick to empty string for simplicity now.
                 $stmt_update->bind_param("si", $new_school_name, $current_user_id); // s=string, i=int

                 if ($stmt_update->execute()) {
                     // Update successful
                     $message = "School name updated successfully!";
                     $message_type = "success";
                     // Update the $current_school_name variable so it displays immediately
                     $current_school_name = $new_school_name; // Update local variable
                      // Redirect after successful update
                     header("location: profile.php?msg_type=success&msg=" . urlencode($message));
                     exit;
                 } else {
                      $message = "Error updating school name. Please try again.";
                      $message_type = "danger";
                       error_log("Error updating school name for user_id " . $current_user_id . ": " . $stmt_update->error);
                 }
                 $stmt_update->close();
             } else {
                  $message = "Database error preparing school name update.";
                  $message_type = "danger";
                  error_log("Database error preparing school name update statement: " . $conn->error);
             }
        }

         // If we reached here without redirecting, there was an error or validation failed.
         if (empty($message)) { // Don't override a message already set by DB errors
            $message_type = "warning";
            $message = "Please fix the errors below for school name update.";
        }
    } // --- End Handle Change School Name Form ---

     // --- Handle Clear All Transactions Action ---
     // This is a POST request triggered by a dedicated form
    elseif (isset($_POST['action']) && $_POST['action'] === 'clear_all_transactions') {
         // Require current password to clear transactions for security? Recommended but adds complexity.
         // Let's keep it simple for now, relying on session authentication and confirmation dialog.

        // Proceed with deletion for the current user
        // We need the connection object here, ensure it's not closed yet if an earlier POST failed
        if (!$conn) { // Check if connection is still valid after other POST handlers
            // Re-establish connection if necessary (less common but safer)
             require_once __DIR__ . '/includes/db.php';
             // Need to re-fetch user data/hash if connection was lost before
             $stmt_user = $conn->prepare("SELECT username, password, school_name FROM users WHERE user_id = ?");
             $stmt_user->bind_param("i", $current_user_id);
             $stmt_user->execute();
             $result_user = $stmt_user->get_result();
             if ($result_user->num_rows > 0) {
                 $user_data = $result_user->fetch_assoc();
                 $current_username_db = $user_data['username'] ?? $current_username_session;
                 $user_password_hash = $user_data['password'];
                 $current_school_name = $user_data['school_name'] ?? '';
             }
             $stmt_user->close();
        }


        // Ensure connection is valid before starting transaction
        if ($conn && is_object($conn) && method_exists($conn, 'begin_transaction')) {
            $conn->begin_transaction(); // Start a transaction for safety
        } else {
             $message = "Database connection error before clearing transactions.";
             $message_type = "danger";
             error_log("Database connection was not valid before begin_transaction for user_id " . $current_user_id);
             // Redirect with error message
             header("location: profile.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
             exit;
        }


        try {
            // Delete all payments for this user
            $sql_delete_payments = "DELETE FROM payments WHERE user_id = ?";
            $stmt_delete_payments = $conn->prepare($sql_delete_payments);
            if (!$stmt_delete_payments) throw new Exception("Database error preparing payment delete statement: " . $conn->error);
            $stmt_delete_payments->bind_param("i", $current_user_id);
            if (!$stmt_delete_payments->execute()) throw new Exception("Database error deleting payments: " . $stmt_delete_payments->error);
            $deleted_payments_count = $stmt_delete_payments->affected_rows;
            $stmt_delete_payments->close();

            // Delete all expenses for this user
            $sql_delete_expenses = "DELETE FROM expenses WHERE user_id = ?";
            $stmt_delete_expenses = $conn->prepare($sql_delete_expenses);
            if (!$stmt_delete_expenses) throw new Exception("Database error preparing expense delete statement: " . $conn->error);
            $stmt_delete_expenses->bind_param("i", $current_user_id);
            if (!$stmt_delete_expenses->execute()) throw new Exception("Database error deleting expenses: " . $stmt_delete_expenses->error);
            $deleted_expenses_count = $stmt_delete_expenses->affected_rows;
            $stmt_delete_expenses->close();

            // If all deletions were successful, commit the transaction
            $conn->commit();

            $message = "Successfully cleared " . $deleted_payments_count . " payment(s) and " . $deleted_expenses_count . " expense(s).";
            $message_type = "success";

        } catch (Exception $e) {
            // If any error occurred, roll back the transaction
             if ($conn && is_object($conn) && method_exists($conn, 'rollback')) {
                 $conn->rollback();
             }
            $message = "Error clearing transactions: " . $e->getMessage(); // Show the caught exception message
            $message_type = "danger";
             error_log("Error clearing transactions for user_id " . $current_user_id . ": " . $e->getMessage());
        }

        // Redirect after POST to show the message
        header("location: profile.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
        exit;
    } // --- End Handle Clear All Transactions Action ---


    // --- Re-fetch user data after any POST if not redirected and there were errors ---
    // This block is primarily for repopulating fields and displaying errors on the *same* page.
    // We only need to re-fetch if there's a specific field error set, indicating no redirect occurred.
    if (!headers_sent()) { // Only re-fetch if a redirect hasn't already happened
        if (!empty($username_err) || !empty($current_password_err_username) || !empty($password_err) || !empty($current_password_err_password) || !empty($new_password_err) || !empty($confirm_new_password_err) || !empty($school_name_err)) {
            // Re-fetch the data to make sure we show the most current version if updates happened on other forms
             $stmt_user_re = $conn->prepare("SELECT username, password, school_name FROM users WHERE user_id = ?");
             if ($stmt_user_re) { // Check if prepare was successful
                 $stmt_user_re->bind_param("i", $current_user_id);
                 $stmt_user_re->execute();
                 $result_user_re = $stmt_user_re->get_result();
                 if ($result_user_re && $result_user_re->num_rows > 0) {
                     $user_data_re = $result_user_re->fetch_assoc();
                     $current_username_db = $user_data_re['username'] ?? $current_username_session; // Update variable
                     $current_school_name = $user_data_re['school_name'] ?? ''; // Update variable
                     $user_password_hash = $user_data_re['password']; // Get potentially new hash
                 } else {
                      error_log("Re-fetch of user data failed after POST errors for user_id " . $current_user_id);
                 }
                  if ($result_user_re) $result_user_re->free();
                 $stmt_user_re->close();
             } else {
                 error_log("Database error preparing re-fetch user statement after POST errors for user " . $current_user_id . ": " . $conn->error);
             }
        }
         // If no field errors, but a general message was set (e.g., DB error on clear), the initial fetch is sufficient.
    }


} // --- End Handle POST Requests ---


// --- Check for messages after GET redirect ---
// This happens after a successful POST redirect
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}


// --- Fetch List of Students Owing ---
$owing_students = [];
$sql_owing = "
    SELECT
        s.student_id, -- Include ID just in case you want to link to student history later
        s.student_name,
        c.class_name,
        c.class_fee,
        COALESCE(SUM(p.amount_paid), 0) AS total_paid, -- Use COALESCE to treat NULL (no payments) as 0
        (c.class_fee - COALESCE(SUM(p.amount_paid), 0)) AS balance_due
    FROM students s
    JOIN classes c ON s.class_id = c.class_id -- Inner join assumes students are in a class
    LEFT JOIN payments p ON s.student_id = p.student_id -- Left join to include students with no payments
    WHERE s.user_id = ? -- Filter by user
    GROUP BY s.student_id, s.student_name, c.class_name, c.class_fee -- Group by student and related details
    HAVING (c.class_fee - COALESCE(SUM(p.amount_paid), 0)) > 0 -- Condition: Balance Due > 0
    ORDER BY s.student_name ASC
";
$stmt_owing = $conn->prepare($sql_owing);

if ($stmt_owing) {
    $stmt_owing->bind_param("i", $current_user_id);
    $stmt_owing->execute();
    $result_owing = $stmt_owing->get_result();

    if($result_owing) {
        while($row = $result_owing->fetch_assoc()) {
            $owing_students[] = $row; // Store owing students data
        }
        if ($result_owing) $result_owing->free(); // Free result set
    } else {
        error_log("Error fetching outstanding balances for user " . $current_user_id . ": " . $conn->error);
         // Handle error gracefully, maybe set a message
         if (empty($message)) {
             $message = "Error loading students with outstanding balances.";
             $message_type = "danger";
         }
    }
    $stmt_owing->close(); // Close statement
} else {
     error_log("Database error preparing outstanding balances query for user " . $current_user_id . ": " . $conn->error);
      // Handle error gracefully
     if (empty($message)) {
         $message = "Error preparing to load students with outstanding balances.";
         $message_type = "danger";
     }
}


// Close the database connection
// Rely on PHP's automatic connection closing at the end of the script
// Or close explicitly here if preferred, ensure $conn is valid:
if ($conn && is_object($conn) && method_exists($conn, 'close')) {
    // $conn->close(); // Removed based on previous error analysis
}

?>
<?php include 'includes/header.php'; ?>

<h1>User Profile</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Current User Info Card -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-user me-1"></i> Current User Information</div>
            <div class="card-body">
                <p><strong>Username:</strong> <?php echo htmlspecialchars($current_username_db); ?></p>
                 <p><strong>School Name:</strong> <?php echo htmlspecialchars($current_school_name ?: 'Not Set'); ?></p>
            </div>
        </div>
    </div>

    <!-- Change School Name Card -->
    <div class="col-md-6 mb-4">
         <div class="card">
             <div class="card-header"><i class="fas fa-graduation-cap me-1"></i> Change School Name</div>
             <div class="card-body">
                 <form action="profile.php" method="post">
                     <input type="hidden" name="action" value="change_school_name">
                     <div class="mb-3">
                         <label for="new_school_name" class="form-label">School Name</label>
                         <!-- Pre-fill with submitted value on error, otherwise with current school name -->
                         <input type="text" name="new_school_name" id="new_school_name" class="form-control <?php echo (!empty($school_name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['new_school_name'] ?? $current_school_name); ?>" maxlength="150">
                         <span class="invalid-feedback"><?php echo $school_name_err; ?></span>
                         <div class="form-text text-muted">This name will appear on receipts. Leave empty to remove.</div>
                     </div>
                     <button type="submit" class="btn btn-info"><i class="fas fa-save me-1"></i> Update School Name</button>
                 </form>
             </div>
         </div>
    </div>
</div>

<div class="row">
     <!-- Change Username Card -->
     <div class="col-md-6 mb-4">
         <div class="card">
             <div class="card-header"><i class="fas fa-edit me-1"></i> Change Username</div>
             <div class="card-body">
                 <form action="profile.php" method="post">
                     <input type="hidden" name="action" value="change_username">
                     <div class="mb-3">
                         <label for="new_username" class="form-label">New Username</label>
                         <!-- Pre-fill with submitted value on error -->
                         <input type="text" name="new_username" id="new_username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['new_username'] ?? ''); ?>" maxlength="50">
                         <span class="invalid-feedback"><?php echo $username_err; ?></span>
                     </div>
                     <div class="mb-3">
                         <label for="current_password_username" class="form-label">Current Password <small>(Required to change username)</small></label>
                         <input type="password" name="current_password_username" id="current_password_username" class="form-control <?php echo (!empty($current_password_err_username)) ? 'is-invalid' : ''; ?>">
                          <span class="invalid-feedback"><?php echo $current_password_err_username; ?></span>
                     </div>
                     <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Update Username</button>
                 </form>
             </div>
         </div>
    </div>

    <!-- Change Password Card -->
    <div class="col-md-6 mb-4">
         <div class="card">
             <div class="card-header"><i class="fas fa-lock me-1"></i> Change Password</div>
             <div class="card-body">
                 <form action="profile.php" method="post">
                     <input type="hidden" name="action" value="change_password">
                     <div class="mb-3">
                         <label for="current_password" class="form-label">Current Password</label>
                         <input type="password" name="current_password" id="current_password" class="form-control <?php echo (!empty($current_password_err_password)) ? 'is-invalid' : ''; ?>">
                         <span class="invalid-feedback"><?php echo $current_password_err_password; ?></span>
                     </div>
                     <div class="mb-3">
                         <label for="new_password" class="form-label">New Password <small>(Min 6 characters)</small></label>
                         <input type="password" name="new_password" id="new_password" class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>">
                         <span class="invalid-feedback"><?php echo $new_password_err; ?></span>
                     </div>
                     <div class="mb-3">
                         <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                         <input type="password" name="confirm_new_password" id="confirm_new_password" class="form-control <?php echo (!empty($confirm_new_password_err)) ? 'is-invalid' : ''; ?>">
                         <span class="invalid-feedback"><?php echo $confirm_new_password_err; ?></span>
                     </div>
                     <button type="submit" class="btn btn-warning"><i class="fas fa-key me-1"></i> Update Password</button>
                 </form>
             </div>
         </div>
    </div>
</div>

<div class="row">
    <!-- Outstanding Balances List Card -->
    <div class="col-md-12 mb-4"> <!-- Full width column -->
        <div class="card">
            <div class="card-header"><i class="fas fa-users me-1"></i> Students with Outstanding Balances (<?php echo count($owing_students); ?>)</div>
            <div class="card-body">
                <?php if (count($owing_students) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Class Fee</th>
                                    <th>Total Paid</th>
                                    <th>Balance Due</th>
                                    <th>Actions</th> <!-- Optional: Link to payments page -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($owing_students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                        <td>₦<?php echo number_format($student['class_fee'], 2); ?></td>
                                        <td>₦<?php echo number_format($student['total_paid'], 2); ?></td>
                                        <td class="text-danger fw-bold">₦<?php echo number_format($student['balance_due'], 2); ?></td>
                                        <td>
                                             <!-- Link to record payment for this student -->
                                            <a href="payments.php?student_id=<?php echo $student['student_id']; ?>" class="btn btn-success btn-sm me-1" title="Record Payment"><i class="fas fa-money-bill-wave"></i> Pay</a>
                                             <!-- Link to download history for this student -->
                                             <a href="student_payment_history_report.php?student_id=<?php echo $student['student_id']; ?>" class="btn btn-secondary btn-sm" title="Download History CSV" target="_blank"><i class="fas fa-download"></i> History CSV</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" role="alert">
                        No students currently have an outstanding balance.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Download Reports Card -->
    <div class="col-md-6 mb-4">
         <div class="card">
             <div class="card-header"><i class="fas fa-file-csv me-1"></i> Download Reports</div>
             <div class="card-body">
                 <p>Download financial reports in CSV format.</p>
                 <!-- Link to the All Transactions CSV generation script -->
                 <a href="download_transactions.php" class="btn btn-secondary mb-2"><i class="fas fa-download me-1"></i> All Transactions CSV</a><br>
                 <!-- Link to the Outstanding Balances CSV generation script -->
                 <a href="outstanding_balances_report.php" class="btn btn-secondary"><i class="fas fa-file-csv me-1"></i> Outstanding Balances CSV</a>
             </div>
         </div>
    </div>

     <!-- Clear Transactions Card -->
    <div class="col-md-6 mb-4">
         <div class="card border-danger"> <!-- Use border-danger for visual warning -->
             <div class="card-header text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Danger Zone</div>
             <div class="card-body">
                 <p class="card-text text-danger">Warning: This action will permanently delete ALL payment and expense records for your school. This action cannot be undone!</p>
                 <!-- Form to trigger deletion via POST -->
                 <form action="profile.php" method="post" onsubmit="return confirm('Are you ABSOLUTELY SURE you want to clear ALL transactions for your school? This action cannot be undone!');">
                     <input type="hidden" name="action" value="clear_all_transactions">
                     <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i> Clear All Transactions</button>
                 </form>
             </div>
         </div>
    </div>
</div>


<?php
// Rely on PHP's automatic connection closing at the end of the script
// The explicit $conn->close(); call was removed from the end of the PHP block above
include 'includes/footer.php';
?>