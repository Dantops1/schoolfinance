<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
requireLogin(); // Ensure user is logged in to view receipts

$current_user_id = $_SESSION['user_id']; // Get the current user's ID
$payment_id = $_GET['payment_id'] ?? null;

if (!$payment_id || !filter_var($payment_id, FILTER_VALIDATE_INT)) {
    // Use a more user-friendly error or redirect
    die("Invalid payment ID provided.");
}

// Fetch Payment, Student, Class, AND User (for school name) details - FILTERED BY USER_ID
$receipt_data = null;
$stmt = $conn->prepare("
    SELECT
        p.*,
        s.student_name,
        c.class_name,
        c.class_fee,
        u.school_name -- Select the school name from the users table
    FROM payments p
    JOIN students s ON p.student_id = s.student_id
    JOIN classes c ON s.class_id = c.class_id
    JOIN users u ON p.user_id = u.user_id -- Join with users table to get the school name
    WHERE p.payment_id = ? AND p.user_id = ? -- Ensure the payment belongs to the current user
");
$stmt->bind_param("ii", $payment_id, $current_user_id); // i=int (payment_id), i=int (user_id)
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $receipt_data = $result->fetch_assoc();

    // Calculate total paid for this student for this user's records
    // This query is already correct as it filters by student_id and user_id implicitly through the payment fetch above
    $stmt_total = $conn->prepare("SELECT SUM(amount_paid) AS total_paid FROM payments WHERE student_id = ? AND user_id = ?");
    $stmt_total->bind_param("ii", $receipt_data['student_id'], $current_user_id);
    $stmt_total->execute();
    $result_total = $stmt_total->get_result();
    $total_paid_row = $result_total->fetch_assoc();
    $total_paid_for_student = $total_paid_row['total_paid'] ?? 0;
    $stmt_total->close();

    $balance_due = $receipt_data['class_fee'] - $total_paid_for_student;

} else {
    // Payment not found OR does not belong to the current user
    die("Payment receipt not found or you do not have permission to view it."); // Or redirect to an error page
}

$stmt->close();
$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
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
            color: #007bff; /* Can change for print */
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
        .amount-paid {
            font-size: 1.8em;
            font-weight: bold;
            color: #28a745; /* Can change for print */
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px dashed #ccc;
            background-color: #e9ffe9; /* Can change for print */
        }
        .total-summary {
            border-top: 1px dashed #ccc;
            padding-top: 20px;
            margin-top: 20px;
        }
         .total-summary div {
             margin-bottom: 10px;
             display: flex;
             justify-content: space-between;
         }
         .total-summary div strong {
              flex-basis: 180px;
             flex-shrink: 0;
             margin-right: 10px;
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
                 /* page-break-after: always; /* Optional: forces a page break after each receipt if multiple were on one page */
            }
             .no-print {
                 display: none !important; /* Hide print button and back link */
             }
             /* Ensure text colors are black for readability on print */
             body, .receipt-details, .total-summary, .footer-notes {
                 color: #000 !important;
             }
             .receipt-header h2 {
                 color: #000 !important; /* Darken header color for print */
             }
             .amount-paid {
                 color: #000 !important; /* Darken paid amount color for print */
                 background-color: transparent !important; /* Remove background in print */
             }
             .total-summary .text-danger {
                 color: #dc3545 !important; /* Keep danger color or change to black */
                 -webkit-print-color-adjust: exact; /* Ensure color prints */
                 color-adjust: exact;
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
        <h2>Payment Receipt</h2>
        <!-- Display the fetched school name or a default -->
        <p><?php echo htmlspecialchars($receipt_data['school_name'] ?: 'Your School Name'); ?></p>
        <p>Date Printed: <?php echo date('Y-m-d H:i'); ?></p>
        <p>Receipt ID: #<?php echo htmlspecialchars($receipt_data['payment_id']); ?></p>
    </div>

    <div class="receipt-details">
        <div><strong>Student Name:</strong> <span><?php echo htmlspecialchars($receipt_data['student_name']); ?></span></div>
        <div><strong>Class:</strong> <span><?php echo htmlspecialchars($receipt_data['class_name']); ?></span></div>
        <div><strong>Fee Per Class:</strong> <span>₦<?php echo number_format($receipt_data['class_fee'], 2); ?></span></div>
         <div><strong>Payment Date:</strong> <span><?php echo htmlspecialchars($receipt_data['payment_date']); ?></span></div>
    </div>

     <div class="amount-paid">
         Amount Paid: ₦<?php echo number_format($receipt_data['amount_paid'], 2); ?>
     </div>

    <div class="total-summary">
         <div><strong>Total Paid (So Far):</strong> <span>₦<?php echo number_format($total_paid_for_student, 2); ?></span></div>
         <div><strong>Balance Due:</strong> <span class="fw-bold text-danger">₦<?php echo number_format($balance_due, 2); ?></span></div>
    </div>

    <?php if (!empty($receipt_data['notes'])): ?>
    <div class="footer-notes">
        <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($receipt_data['notes'])); ?>
    </div>
     <?php endif; ?>

     <div class="text-center mt-4 no-print">
         <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print Receipt</button>
         <a href="payments.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Payments</a>
     </div>
</div>

</body>
</html>