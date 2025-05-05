<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin();

$current_user_id = $_SESSION['user_id']; // Get the current user's ID
$expense_id = $_GET['expense_id'] ?? null;

if (!$expense_id || !filter_var($expense_id, FILTER_VALIDATE_INT)) {
     // Use a more user-friendly error or redirect
    die("Invalid expense ID provided.");
}

// Fetch Expense AND User (for school name) details - FILTERED BY USER_ID
$expense_data = null;
$stmt = $conn->prepare("
    SELECT
        e.*,
        u.school_name -- Select the school name from the users table
    FROM expenses e
    JOIN users u ON e.user_id = u.user_id -- Join with users table to get the school name
    WHERE e.expense_id = ? AND e.user_id = ? -- Ensure the expense belongs to the current user
");
$stmt->bind_param("ii", $expense_id, $current_user_id); // i=int (expense_id), i=int (user_id)
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $expense_data = $result->fetch_assoc();
} else {
    // Expense not found OR does not belong to the current user
    die("Expense receipt not found or you do not have permission to view it."); // Or redirect
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Receipt</title>
    <!-- Using local CSS for receipts is often better for print consistency -->
    <link rel="stylesheet" href="css/style.css">
     <style>
        /* Add any specific styles for the receipt that are not in style.css here,
           or ensure style.css covers all receipt styling */
         body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa; /* Not visible in print */
            padding: 20px; /* Not visible in print */
        }
        .receipt-container {
            max-width: 600px;
            margin: 20px auto; /* Not visible in print */
            background-color: #fff;
            padding: 30px;
            border: 1px solid #ddd; /* Visible in print */
            box-shadow: 0 0 10px rgba(0,0,0,0.1); /* Not always visible in print */
            border-radius: 0.75rem; /* Not always visible in print */
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px dashed #ccc;
            padding-bottom: 20px;
        }
        .receipt-header h2 {
            margin: 0;
            color: #dc3545; /* Danger color for expenses - Can change for print */
        }
         .receipt-header p {
             margin-bottom: 5px;
         }
        .receipt-details {
            margin-bottom: 30px;
        }
        .receipt-details div {
            margin-bottom: 10px;
             display: flex;
             justify-content: space-between;
        }
         .receipt-details div strong {
             flex-basis: 180px;
             flex-shrink: 0;
             margin-right: 10px;
         }
        .amount {
            font-size: 1.8em;
            font-weight: bold;
            color: #dc3545; /* Can change for print */
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px dashed #ccc;
            background-color: #ffe9e9; /* Can change for print */
        }

        .footer-notes {
            margin-top: 30px;
            font-size: 0.9em;
            color: #666; /* Can change for print */
            text-align: center;
            border-top: 1px dashed #ccc;
            padding-top: 20px;
        }
         /* Print specific styles */
         @media print {
            body {
                background-color: #fff; /* Ensure white background */
                padding: 0; /* Remove body padding */
                margin: 0; /* Remove body margin */
            }
            .receipt-container {
                 max-width: 100% !important; /* Occupy full width */
                 margin: 0 !important; /* Remove container margin */
                 padding: 0 !important; /* Remove container padding */
                 border: none !important; /* Remove container border */
                 box-shadow: none !important; /* Remove shadow */
            }
             .no-print {
                 display: none !important; /* Hide print button and back link */
             }
             /* Ensure text colors are black for readability on print */
             body, .receipt-details, .amount, .footer-notes {
                 color: #000 !important;
                 background-color: transparent !important; /* Remove background color */
             }
              .receipt-header h2 {
                 color: #000 !important; /* Darken header color for print */
             }
             .text-muted {
                  color: #666 !important;
              }

             /* Optional: Adjust print margins */
             /* @page { margin: 1cm; } */
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <div class="receipt-header">
        <h2>Expense Receipt</h2>
         <!-- Display the fetched school name or a default -->
         <p><?php echo htmlspecialchars($expense_data['school_name'] ?: 'Your School Name'); ?></p>
        <p>Date: <?php echo htmlspecialchars($expense_data['expense_date']); ?></p>
         <p>Receipt ID: #<?php echo htmlspecialchars($expense_data['expense_id']); ?></p>
    </div>

    <div class="receipt-details">
        <div><strong>Description:</strong> <span><?php echo htmlspecialchars($expense_data['expense_description']); ?></span></div>
        <div><strong>Amount:</strong> <span>₦<?php echo number_format($expense_data['amount'], 2); ?></span></div>
        <div><strong>Date Recorded:</strong> <span><?php echo date('Y-m-d H:i:s', strtotime($expense_data['created_at'])); ?></span></div>
    </div>

     <div class="amount">
         Amount: ₦<?php echo number_format($expense_data['amount'], 2); ?>
     </div>

    <?php if (!empty($expense_data['notes'])): ?>
    <div class="footer-notes">
        <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($expense_data['notes'])); ?>
    </div>
     <?php endif; ?>

     <div class="text-center mt-4 no-print">
         <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print Receipt</button>
         <a href="expenses.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Expenses</a>
     </div>
</div>

</body>
</html>