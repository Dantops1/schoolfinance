<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

$current_user_id = $_SESSION['user_id']; // Get the current user's ID

// Variables for messages (initialized after potential redirect)
$message = "";
$message_type = "";

// --- Handle Add Expense POST Request ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_expense'])) {
    $description = trim($_POST['expense_description'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $expense_date = trim($_POST['expense_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Basic validation
    if (empty($description)) {
        $message = "Description cannot be empty.";
        $message_type = "warning";
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $message = "Invalid amount. Please enter a positive number.";
        $message_type = "warning";
    } elseif (empty($expense_date)) {
        $message = "Expense date is required.";
        $message_type = "warning";
    } else {
        // Prepare an insert statement - INCLUDE user_id
        $stmt_add = $conn->prepare("INSERT INTO expenses (expense_description, amount, expense_date, notes, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt_add->bind_param("sdssi", $description, $amount, $expense_date, $notes, $current_user_id); // s=string, d=double, s=string, s=string, i=int

        if ($stmt_add->execute()) {
             $expense_id = $stmt_add->insert_id;
            $message = "Expense added successfully!";
            $message_type = "success";
            // Redirect to expense receipt page
             header("location: print_expense_receipt.php?expense_id=" . $expense_id);
             exit;

        } else {
            $message = "Error adding expense: " . $conn->error;
            $message_type = "danger";
             error_log("Error adding expense: " . $conn->error);
        }
        $stmt_add->close();
    }
    // If not redirected to receipt, display message on this page
    // header("location: expenses.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
    // exit;
}

// --- Check for messages after GET redirect ---
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}


// Fetch Expenses - FILTERED BY USER_ID
$expenses = [];
$sql_fetch = "SELECT * FROM expenses WHERE user_id = ? ORDER BY expense_date DESC"; // ADD user_id filter
$stmt_fetch = $conn->prepare($sql_fetch);
$stmt_fetch->bind_param("i", $current_user_id);
$stmt_fetch->execute();
$result_fetch = $stmt_fetch->get_result();


if ($result_fetch) {
    while ($row = $result_fetch->fetch_assoc()) {
        $expenses[] = $row;
    }
     $result_fetch->free();
} else {
     error_log("Error fetching expenses list: " . $conn->error);
     if (empty($message)) {
         $message = "Error loading expenses.";
         $message_type = "danger";
     }
}

$conn->close();
?>
<?php include 'includes/header.php'; ?>

<h1>Expenses Management</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><i class="fas fa-hand-holding-usd me-1"></i> Add New Expense</div>
    <div class="card-body">
        <form action="expenses.php" method="post">
            <input type="hidden" name="add_expense" value="1">
            <div class="mb-3">
                <label for="expense_description" class="form-label">Description</label>
                <input type="text" class="form-control" id="expense_description" name="expense_description" required maxlength="255">
            </div>
            <div class="mb-3">
                <label for="amount" class="form-label">Amount (₦)</label>
                <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" required>
            </div>
             <div class="mb-3">
                <label for="expense_date" class="form-label">Date</label>
                <input type="date" class="form-control" id="expense_date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
             <div class="mb-3">
                <label for="notes" class="form-label">Notes (Optional)</label>
                <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-danger"><i class="fas fa-plus-circle me-1"></i> Add Expense</button>
        </form>
    </div>
</div>

<h2><i class="fas fa-history me-1"></i> Recent Expenses</h2>

<?php if (count($expenses) > 0): ?>
    <div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Notes</th>
                 <th>Receipt</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expenses as $expense): ?>
                <tr>
                    <td><?php echo htmlspecialchars($expense['expense_date']); ?></td>
                    <td><?php echo htmlspecialchars($expense['expense_description']); ?></td>
                    <td>₦<?php echo number_format($expense['amount'], 2); ?></td> <!-- Changed currency symbol -->
                    <td><?php echo htmlspecialchars($expense['notes']); ?></td>
                     <td><a href="print_expense_receipt.php?expense_id=<?php echo $expense['expense_id']; ?>" class="btn btn-secondary btn-sm" target="_blank"><i class="fas fa-print"></i> Print</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php else: ?>
    <div class="alert alert-info" role="alert">
        No expenses added yet for your school.
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>