<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin(); // Protect this page

$current_user_id = $_SESSION['user_id']; // Get the current user's ID

// Variables for form messages and errors
$message = "";
$message_type = "";

// --- Handle POST Requests ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Handle Add Class Form ---
    if (isset($_POST['add_class'])) {
        $className = trim($_POST['class_name'] ?? ''); // Trim whitespace
        $classFee = trim($_POST['class_fee'] ?? '');   // Trim whitespace

        // Basic validation
        if (empty($className)) {
            $message = "Class name cannot be empty.";
            $message_type = "warning";
        } elseif (!is_numeric($classFee) || $classFee < 0) {
             $message = "Invalid fee amount. Please enter a valid non-negative number.";
             $message_type = "warning";
        } else {
            // Prepare a select statement to check if class name already exists *for this user*
            $sql_check = "SELECT class_id FROM classes WHERE class_name = ? AND user_id = ?";
            $stmt_check = $conn->prepare($sql_check); // Prepare outside the if/else for closing
            if ($stmt_check) {
                 $stmt_check->bind_param("si", $param_className, $current_user_id);
                 $param_className = $className;
                 if ($stmt_check->execute()) {
                     $stmt_check->store_result();
                     if ($stmt_check->num_rows > 0) {
                         // Found an existing class with this name for this user
                         $message = "A class with this name already exists for your school.";
                         $message_type = "warning";
                     } else {
                         // Class name is unique for this user, proceed with insert
                         $sql_add = "INSERT INTO classes (class_name, class_fee, user_id) VALUES (?, ?, ?)";
                         if ($stmt_add = $conn->prepare($sql_add)) {
                             $stmt_add->bind_param("sdi", $className, $classFee, $current_user_id);

                             if ($stmt_add->execute()) {
                                 $message = "Class '" . htmlspecialchars($className) . "' added successfully!";
                                 $message_type = "success";
                             } else {
                                 // Check for specific duplicate key error (SQLSTATE 23000, MySQL Error 1062)
                                 if ($conn->errno == 1062) {
                                     $message = "A class with this name already exists for your school."; // Provide user-friendly message
                                     $message_type = "warning";
                                 } else {
                                     $message = "Error adding class: " . $conn->error; // Generic DB error
                                     $message_type = "danger";
                                     error_log("Error adding class for user_id " . $current_user_id . ": " . $conn->error);
                                 }
                             }
                              $stmt_add->close();
                         } else {
                             $message = "Database error preparing add statement.";
                             $message_type = "danger";
                             error_log("Database error preparing add statement: " . $conn->error);
                         }
                     }
                 } else {
                      $message = "Oops! Something went wrong checking existing class name.";
                      $message_type = "danger";
                      error_log("Database error executing class name check for user_id " . $current_user_id . ": " . $stmt_check->error);
                 }
                 $stmt_check->close(); // Close check statement regardless of the outcome inside
            } else {
                 $message = "Database error preparing check statement.";
                 $message_type = "danger";
                 error_log("Database error preparing class name check statement: " . $conn->error);
            }
        }
        // Redirect to prevent form resubmission on refresh, pass message via GET
        header("location: classes.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
        exit;

    } // --- End Handle Add Class Form ---

    // --- Handle Delete Class Form ---
    elseif (isset($_POST['delete_class_id'])) {
        $class_id_to_delete = $_POST['delete_class_id'];

        if (!filter_var($class_id_to_delete, FILTER_VALIDATE_INT)) {
            $message = "Invalid class ID for deletion.";
            $message_type = "danger";
        } else {
            // Prepare DELETE statement - ADD USER_ID FILTER
            $sql_delete = "DELETE FROM classes WHERE class_id = ? AND user_id = ?";
            if ($stmt_delete = $conn->prepare($sql_delete)) {
                $stmt_delete->bind_param("ii", $class_id_to_delete, $current_user_id);

                if ($stmt_delete->execute()) {
                     if ($stmt_delete->affected_rows > 0) {
                        $message = "Class deleted successfully. Students assigned to this class and their payments have also been deleted.";
                        $message_type = "success";
                     } else {
                         $message = "Class not found or already deleted, or does not belong to your school.";
                         $message_type = "warning";
                     }
                } else {
                    $message = "Error deleting class: " . $conn->error;
                    $message_type = "danger";
                     error_log("Error deleting class for user_id " . $current_user_id . ": " . $conn->error);
                }
                 $stmt_delete->close();
            } else {
                $message = "Database error preparing delete statement.";
                $message_type = "danger";
                 error_log("Database error preparing delete statement: " . $conn->error);
            }
        }
         header("location: classes.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
         exit;
    }
}


// --- Check for messages after GET redirect ---
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}


// --- Fetch Classes for Display - FILTERED BY USER_ID ---
$classes = [];
$sql_fetch = "SELECT * FROM classes WHERE user_id = ? ORDER BY class_name";
$stmt_fetch = $conn->prepare($sql_fetch);
if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $current_user_id);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    if ($result_fetch) {
        while ($row = $result_fetch->fetch_assoc()) {
            $classes[] = $row;
        }
         $result_fetch->free();
    } else {
         error_log("Error fetching classes list for user_id " . $current_user_id . ": " . $conn->error);
         if (empty($message)) {
             $message = "Error loading classes.";
             $message_type = "danger";
         }
    }
    $stmt_fetch->close();
} else {
    error_log("Database error preparing class fetch statement for user_id " . $current_user_id . ": " . $conn->error);
     if (empty($message)) {
         $message = "Error preparing to load classes.";
         $message_type = "danger";
     }
}


$conn->close();
?>
<?php include 'includes/header.php'; ?>

<h1>Classes Management</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><i class="fas fa-chalkboard-teacher me-1"></i> Add New Class</div>
    <div class="card-body">
        <form action="classes.php" method="post">
            <input type="hidden" name="add_class" value="1">
            <div class="mb-3">
                <label for="className" class="form-label">Class Name</label>
                <input type="text" class="form-control" id="className" name="class_name" required maxlength="100">
            </div>
            <div class="mb-3">
                <label for="classFee" class="form-label">Class Fee (₦)</label>
                <input type="number" class="form-control" id="classFee" name="class_fee" step="0.01" min="0" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle me-1"></i> Add Class</button>
        </form>
    </div>
</div>

<h2><i class="fas fa-list-alt me-1"></i> Existing Classes (<?php echo count($classes); ?>)</h2>

<?php if (count($classes) > 0): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Class Name</th>
                    <th>Fee</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                        <td>₦<?php echo number_format($class['class_fee'], 2); ?></td>
                        <td>
                            <a href="view_class.php?class_id=<?php echo $class['class_id']; ?>" class="btn btn-info btn-sm me-1" title="Manage Students"><i class="fas fa-users"></i> Manage Students</a>

                             <form action="classes.php" method="post" class="d-inline-block" onsubmit="return confirm('Are you SURE you want to delete class \'<?php echo htmlspecialchars($class['class_name']); ?>\'? ALL students assigned to this class AND their payment records will be permanently deleted.');">
                                 <input type="hidden" name="delete_class_id" value="<?php echo $class['class_id']; ?>">
                                 <button type="submit" class="btn btn-danger btn-sm" title="Delete Class"><i class="fas fa-trash"></i> Delete Class</button>
                             </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info" role="alert">
        No classes added yet for your school.
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>