<?php
// Start or resume session and provide authentication functions
require_once __DIR__ . '/auth.php';
// No need to include db.php here unless every page needs it right away
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Finance App</title>

    <!-- PWA: Manifest File Link -->
    <link rel="manifest" href="/school_finance/manifest.json">

    <!-- Link to Bootstrap CSS (Using CDN for Bootstrap 5.3.2) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- Link to Font Awesome for icons (Using CDN for Font Awesome 6.5.1) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Link to Custom CSS - Ensure this is AFTER Bootstrap CSS -->
    <link rel="stylesheet" href="css/style.css">

    <!-- PWA: Theme Color Meta Tag -->
    <meta name="theme-color" content="#007bff"/>

    <style>
        /* Add padding to body to prevent content from being hidden by fixed header */
        body {
            padding-top: 70px; /* Adjust based on your navbar height */
            padding-bottom: 40px; /* Space for footer */
        }

        /* Styles for the licensing notification bar */
        .license-notification {
            /* Use Bootstrap utility classes for colors/padding/margin */
            position: sticky; /* Makes it sticky at the top below the navbar */
            top: 56px; /* Adjust to be right below the fixed navbar - typical BS5 navbar height is 56px */
            left: 0;
            right: 0;
            z-index: 1030; /* Ensure it's above content, below navbar (navbar is ~1050) */
            padding: 0.5rem 1rem; /* Bootstrap standard padding */
            text-align: center;
            /* Background/color handled by Bootstrap alert classes below */
            border-bottom: 1px solid transparent; /* Border handled by alert classes */
            font-weight: bold;
             box-shadow: 0 2px 4px rgba(0,0,0,0.05); /* Subtle shadow */
        }
        /* Use Bootstrap alert styles */
         .license-notification.alert-warning {
             background-color: var(--bs-alert-bg); /* Use Bootstrap variables if available */
             color: var(--bs-alert-color);
             border-color: var(--bs-alert-border);
         }

         /* Style for the link/button inside the notification */
         .license-notification a,
         .license-notification button {
            margin-left: 10px;
            font-weight: normal; /* Less bold than the main text */
            text-decoration: underline; /* Make links clear */
         }
          /* Remove margin from the alert itself */
         .license-notification.alert {
             margin-bottom: 0 !important;
             border-radius: 0; /* Make it a full-width bar */
         }


         /* Hide the notification bar when printing */
        @media print {
            .license-notification {
                display: none !important;
            }
             body {
                 padding-top: 0 !important; /* Remove padding for print */
             }
        }
    </style>

</head>
<body>
    <!-- The fixed-top class makes the navbar stay at the top -->
<nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
    <div class="container">
        <!-- Link to dashboard is common, but access is restricted by requirePermission -->
        <!-- Make navbar-brand link to dashboard if licensed, or login if not, or admin dashboard if SA -->
         <?php
         $brand_link = 'login.php';
         if(isLoggedIn()) {
             if (isSuperAdmin()) {
                 $brand_link = 'super_admin_dashboard.php';
             } elseif (isLicensed()) {
                 $brand_link = 'dashboard.php';
             } else {
                 $brand_link = 'unlicensed.php'; // Link to unlicensed page if not licensed
             }
         }
         ?>
        <a class="navbar-brand" href="<?php echo $brand_link; ?>">
            <i class="fas fa-chart-pie me-2"></i> School Finance
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php if (isLoggedIn() && isLicensed()): // Main nav visible only if logged in AND licensed ?>
                    <?php if (hasPermission('can_view_dashboard')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
                        </li>
                    <?php endif; ?>

                    <?php if (hasPermission('can_view_classes')): ?>
                         <li class="nav-item">
                            <a class="nav-link" href="classes.php"><i class="fas fa-chalkboard-teacher me-1"></i> Classes</a>
                        </li>
                    <?php endif; ?>

                    <?php // Assuming 'can_view_payments' permission covers access to the payments page
                         if (hasPermission('can_view_payments')): ?>
                         <li class="nav-item">
                            <a class="nav-link" href="payments.php"><i class="fas fa-money-bill-wave me-1"></i> Payments</a>
                        </li>
                    <?php endif; ?>

                    <?php if (hasPermission('can_view_expenses')): ?>
                         <li class="nav-item">
                            <a class="nav-link" href="expenses.php"><i class="fas fa-hand-holding-usd me-1"></i> Expenses</a>
                        </li>
                    <?php endif; ?>

                     <?php if (isOwner()): // Only Owners manage teachers - check role directly ?>
                        <li class="nav-item">
                            <a class="nav-link" href="teachers.php"><i class="fas fa-chalkboard-teacher me-1"></i> Teachers</a>
                        </li>
                    <?php endif; ?>

                     <?php if (hasPermission('can_record_attendance')): // Owners and authorized teachers ?>
                         <li class="nav-item">
                            <a class="nav-link" href="attendance.php"><i class="fas fa-user-check me-1"></i> Attendance</a>
                        </li>
                    <?php endif; ?>

                    <?php /* Add other navigation links here based on new features and permissions */ ?>

                <?php endif; // End check for isLoggedIn && isLicensed ?>

                <?php if (isSuperAdmin()): // Super Admin has a different set of links ?>
                    <li class="nav-item">
                        <a class="nav-link" href="super_admin_dashboard.php"><i class="fas fa-user-shield me-1"></i> Admin Dashboard</a>
                    </li>
                     <?php /* Add other SA-specific links here if needed */ ?>
                <?php endif; ?>

            </ul>
            <ul class="navbar-nav ms-auto">
                 <?php if (isLoggedIn()): ?>
                     <li class="nav-item">
                        <a class="nav-link" href="profile.php"><i class="fas fa-user me-1"></i> Profile</a>
                    </li>
                    <li class="nav-item d-flex align-items-center">
                        <span class="navbar-text me-2">
                           <i class="fas fa-user-circle me-1"></i> Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', getUserRole()))); ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
                    </li>
                <?php else: ?>
                     <li class="nav-item">
                        <a class="nav-link" href="login.php"><i class="fas fa-sign-in-alt me-1"></i> Login</a>
                    </li>
                     <li class="nav-item">
                        <a class="nav-link" href="register.php"><i class="fas fa-user-plus me-1"></i> Register</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<?php
// --- Display License Notification Bar ---
// Show this bar if the user is logged in, is NOT a Super Admin, AND is NOT licensed
// It should *not* be displayed on the unlicensed page itself
$current_page = basename($_SERVER['PHP_SELF']);
$is_unlicensed_page = ($current_page === 'unlicensed.php'); // Check if we are already on the unlicensed page

if (isLoggedIn() && !isSuperAdmin() && !isLicensed() && !$is_unlicensed_page): ?>
    <div class="license-notification alert alert-warning" role="alert">
        <?php
        $trial_end_date_str = getTrialEndDate();
        $license_expiry_date_str = getLicenseExpiryDate();

        if (!empty($trial_end_date_str)) {
             echo "Your free trial ended on " . htmlspecialchars($trial_end_date_str) . ". ";
        } elseif (!empty($license_expiry_date_str)) {
             // This case should ideally not be hit if isLicensed() is false, but as a fallback:
             echo "Your license expired on " . htmlspecialchars($license_expiry_date_str) . ". ";
        } else {
             echo "Your account is currently unlicensed. ";
        }
        ?>
        Please <a href="unlicensed.php" class="alert-link">get a license</a> from the administrator to continue using the app.
    </div>
<?php endif; ?>


<!-- Main content container -->
<!-- The padding-top on the body (in style.css) will push this container down below the fixed header -->
<div class="container mt-4">
    <!-- Page specific content will be inserted here -->