<?php
// Quick debugging helper - uncomment to display errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php_error_viewclass.log');

require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin(); // Protect this page

$current_user_id = $_SESSION['user_id']; // Get the current user's ID

$class_id = $_GET['class_id'] ?? null;

// --- Input Validation for class_id ---
if (!$class_id || !filter_var($class_id, FILTER_VALIDATE_INT)) {
    // Redirect or show error if class_id is missing or invalid integer
    header("location: classes.php?msg_type=danger&msg=" . urlencode("Invalid class ID provided."));
    exit;
}

// Fetch Class Details - FILTERED BY USER_ID
$class = null;
// ADD user_id to the WHERE clause
$stmt_class = $conn->prepare("SELECT * FROM classes WHERE class_id = ? AND user_id = ?");
if ($stmt_class) {
    $stmt_class->bind_param("ii", $class_id, $current_user_id); // i=int, i=int
    $stmt_class->execute();
    $result_class = $stmt_class->get_result();
    if ($result_class->num_rows > 0) {
        $class = $result_class->fetch_assoc();
        if ($result_class) $result_class->free();
    } else {
        // Class not found OR does not belong to the current user
        error_log("Attempted access to class_id " . $class_id . " by user_id " . $current_user_id . " failed.");
        header("location: classes.php?msg_type=danger&msg=" . urlencode("Class not found or you do not have permission to access it."));
        exit;
    }
    $stmt_class->close();
} else {
    error_log("Database error preparing class fetch statement for user " . $current_user_id . ": " . $conn->error);
    // Handle error gracefully
     header("location: classes.php?msg_type=danger&msg=" . urlencode("Error loading class details."));
     exit;
}


// Variables for messages (initialized after potential redirect)
$message = "";
$message_type = "";


// --- Handle Add Student POST Request ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_student'])) {
    $studentName = trim($_POST['student_name'] ?? ''); // Trim whitespace

    // Basic validation
    if (empty($studentName)) {
        $message = "Student name cannot be empty.";
        $message_type = "warning";
    } else {
         // Check if student name already exists in this class *for this user* (optional but good)
         $stmt_check = $conn->prepare("SELECT student_id FROM students WHERE class_id = ? AND student_name = ? AND user_id = ?"); // ADD user_id
          if ($stmt_check) {
             $stmt_check->bind_param("isi", $class_id, $studentName, $current_user_id); // i=int, s=string, i=int
             $stmt_check->execute();
             $stmt_check->store_result();

             if ($stmt_check->num_rows > 0) {
                 $message = "A student with this name already exists in this class for your school.";
                 $message_type = "warning";
             } else {
                $stmt_check->close();

                 // Insert the new student - INCLUDE user_id
                $stmt_add = $conn->prepare("INSERT INTO students (class_id, student_name, user_id) VALUES (?, ?, ?)");
                if ($stmt_add) {
                    $stmt_add->bind_param("isi", $class_id, $studentName, $current_user_id); // i=int, s=string, i=int

                    if ($stmt_add->execute()) {
                        $message = "Student '" . htmlspecialchars($studentName) . "' added successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error adding student: " . $conn->error;
                        $message_type = "danger";
                        error_log("Error adding student for user_id " . $current_user_id . ": " . $conn->error);
                    }
                    $stmt_add->close();
                } else {
                     $message = "Database error preparing add student statement.";
                     $message_type = "danger";
                     error_log("Database error preparing add student statement for user_id " . $current_user_id . ": " . $conn->error);
                }
             }
             if (isset($stmt_check) && is_object($stmt_check)) $stmt_check->close(); // Ensure closed
          } else {
              $message = "Database error preparing student uniqueness check.";
              $message_type = "danger";
              error_log("Database error preparing student uniqueness check for user_id " . $current_user_id . ": " . $conn->error);
          }
    }
    // Redirect to prevent form resubmission on refresh, pass message via GET
    header("location: view_class.php?class_id=" . $class_id . "&msg_type=" . $message_type . "&msg=" . urlencode($message));
    exit;
}

// --- Handle Delete Student POST Request ---
// We use POST for deletion buttons for better security against CSRF and crawlers
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_student_id'])) {
     $student_id_to_delete = $_POST['delete_student_id'];

    // Basic validation
    if (!filter_var($student_id_to_delete, FILTER_VALIDATE_INT)) {
        $message = "Invalid student ID for deletion.";
        $message_type = "danger";
    } else {
         // Prepare DELETE statement - ADD USER_ID FILTER for security
         // This prevents deleting a student belonging to another user, even if the student_id was somehow guessed.
         // We also explicitly check class_id to ensure it's the student from *this* class.
         $stmt_delete = $conn->prepare("DELETE FROM students WHERE student_id = ? AND class_id = ? AND user_id = ?"); // ADD user_id and class_id
         if ($stmt_delete) {
             $stmt_delete->bind_param("iii", $student_id_to_delete, $class_id, $current_user_id); // i=int, i=int, i=int

            if ($stmt_delete->execute()) {
                 // Check if any row was actually deleted
                 if ($stmt_delete->affected_rows > 0) {
                    $message = "Student deleted successfully.";
                    $message_type = "success";
                 } else {
                     // This could mean the student ID was invalid, didn't belong to this class, or didn't belong to the current user
                     $message = "Student not found or already deleted, or does not belong to this class/school.";
                     $message_type = "warning";
                 }
            } else {
                $message = "Error deleting student: " . $conn->error;
                $message_type = "danger";
                 error_log("Error deleting student_id " . $student_id_to_delete . " for user_id " . $current_user_id . ": " . $conn->error);
            }
            $stmt_delete->close();
         } else {
              $message = "Database error preparing delete statement.";
              $message_type = "danger";
              error_log("Database error preparing delete student statement for user_id " . $current_user_id . ": " . $conn->error);
         }
    }
     // Redirect after POST, pass message via GET
     header("location: view_class.php?class_id=" . $class_id . "&msg_type=" . $message_type . "&msg=" . urlencode($message));
     exit;
}


// --- Check for messages after GET redirect ---
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}


// --- Fetch Students in this Class - FILTERED BY USER_ID ---
// Also fetch total amount paid by each student to display status
$students = [];
$sql_students = "
    SELECT
        s.student_id,
        s.student_name,
        COALESCE(SUM(p.amount_paid), 0) AS total_paid -- Use COALESCE to show 0 if no payments
    FROM students s
    LEFT JOIN payments p ON s.student_id = p.student_id -- Left join to include students with no payments
    WHERE s.class_id = ? AND s.user_id = ? -- FILTER students by class_id AND user_id
    GROUP BY s.student_id, s.student_name -- Group to sum payments per student
    ORDER BY s.student_name
";
$stmt_students = $conn->prepare($sql_students);
if ($stmt_students) {
    $stmt_students->bind_param("ii", $class_id, $current_user_id); // i=int, i=int
    $stmt_students->execute();
    $result_students = $stmt_students->get_result();

    if ($result_students) {
        while ($row = $result_students->fetch_assoc()) {
            $students[] = $row;
        }
        if ($result_students) $result_students->free();
    } else {
         error_log("Error fetching students list for user_id " . $current_user_id . ", class_id " . $class_id . ": " . $conn->error);
         if (empty($message)) { // Only set message if no other message is pending
             $message = "Error loading students.";
             $message_type = "danger";
         }
    }
    $stmt_students->close();
} else {
    error_log("Database error preparing students query for user_id " . $current_user_id . ": " . $conn->error);
     if (empty($message)) {
         $message = "Error preparing to load students.";
         $message_type = "danger";
     }
}


// Close connection after all operations
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();
?>
<?php include 'includes/header.php'; ?>

<h1>Class: <?php echo htmlspecialchars($class['class_name']); ?></h1>
<p class="lead">Class Fee: ₦<?php echo number_format($class['class_fee'], 2); ?></p>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><i class="fas fa-user-plus me-1"></i> Add New Student to <?php echo htmlspecialchars($class['class_name']); ?></div>
    <div class="card-body">
        <form action="view_class.php?class_id=<?php echo $class_id; ?>" method="post">
             <input type="hidden" name="add_student" value="1">
            <div class="mb-3">
                <label for="studentName" class="form-label">Student Name</label>
                <input type="text" class="form-control" id="studentName" name="student_name" required maxlength="150">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-1"></i> Add Student</button>
        </form>
    </div>
</div>

<h2><i class="fas fa-users me-1"></i> Students in <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo count($students); ?>)</h2>

<?php if (count($students) > 0): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Student Name</th>
                     <th>Total Paid</th>
                     <th>Balance Due</th>
                     <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student):
                    // Use class fee from the $class variable fetched at the top
                    $total_paid_for_student = $student['total_paid'] ?? 0; // Use 0 if no payments
                    $balance_due = $class['class_fee'] - $total_paid_for_student;
                    $status_class = ($balance_due <= 0) ? 'text-success' : 'text-warning text-dark'; // Added text-dark for warning
                    $status_text = ($balance_due <= 0) ? 'Paid' : 'Owing';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                         <td>₦<?php echo number_format($total_paid_for_student, 2); ?></td>
                         <td class="<?php echo $status_class; ?> fw-bold">₦<?php echo number_format($balance_due, 2); ?></td>
                         <td class="<?php echo $status_class; ?> fw-bold"><?php echo $status_text; ?></td>
                        <td>
                            <a href="payments.php?student_id=<?php echo $student['student_id']; ?>" class="btn btn-success btn-sm me-1" title="Record Payment"><i class="fas fa-money-bill-wave"></i> Record Payment</a>
                            <!-- Link to Student Payment History Report CSV -->
                             <a href="student_payment_history_report.php?student_id=<?php echo $student['student_id']; ?>" class="btn btn-secondary btn-sm me-1" title="Download Payment History CSV" target="_blank"><i class="fas fa-download"></i> History CSV</a>

                            <!-- Delete form for each student -->
                            <form action="view_class.php?class_id=<?php echo $class_id; ?>" method="post" class="d-inline-block" onsubmit="return confirm('Are you SURE you want to delete student \'<?php echo htmlspecialchars($student['student_name']); ?>\'? This will also delete ALL their payment records.');">
                                <input type="hidden" name="delete_student_id" value="<?php echo $student['student_id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Delete Student"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info" role="alert">
        No students added to this class yet for your school.
    </div>
<?php endif; ?>

<p class="mt-4"><a href="classes.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Classes</a></p>

<?php include 'includes/footer.php'; ?>