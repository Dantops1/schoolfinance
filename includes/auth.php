<?php
// session_start() is crucial and must be at the very beginning of the script
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Basic Authentication Checks ---

// Function to check if user is logged in
function isLoggedIn() {
    // User is logged in if user_id and role are set in the session
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

// Function to get the current user's ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Function to get the current user's role
function getUserRole() {
    return $_SESSION['role'] ?? null;
}

// Function to check if the logged-in user is an owner
function isOwner() {
    return isLoggedIn() && getUserRole() === 'owner';
}

// Function to check if the logged-in user is a teacher
function isTeacher() {
    return isLoggedIn() && getUserRole() === 'teacher';
}

// Function to check if the logged-in user is a Super Admin
function isSuperAdmin() {
    return isLoggedIn() && getUserRole() === 'super_admin';
}


// Function to get the owner's user_id for a teacher
// This is the user_id of the school owner the teacher belongs to
function getTeacherOwnerId() {
    if (isTeacher()) {
        return $_SESSION['owner_id'] ?? null;
    }
    return null; // Only teachers have an owner_id in session
}

// Function to get the user_id that data operations should be filtered by
// Owner's data is filtered by their own user_id
// Teacher's data is filtered by their owner's user_id
// Super Admin does *not* have a data_owner_id in this context, they access data across owners (carefully)
function getDataOwnerId() {
    if (isOwner()) {
        return getCurrentUserId();
    }
    if (isTeacher()) {
        return getTeacherOwnerId();
    }
    return null; // Not logged in or Super Admin
}


// --- Licensing and Trial Checks ---

// Function to check if the user is currently licensed
function isAccountLicensed($license_expiry_date_str) {
    if ($license_expiry_date_str === null) return false;
    try {
        $expiry_date_obj = new DateTime($license_expiry_date_str);
        $today = new DateTime();
        return ($expiry_date_obj >= $today); // Licensed if expiry is in the future (or today)
    } catch (Exception $e) {
        error_log("Error parsing license expiry date: " . $license_expiry_date_str . " - " . $e->getMessage());
        return false; // Invalid date means not licensed
    }
}

// Function to check if the user is currently within their trial period
function isAccountTrialing($trial_start_date_str, $trial_duration_days) {
    if ($trial_start_date_str === null || $trial_duration_days <= 0) return false;
     try {
        $trial_start_date_obj = new DateTime($trial_start_date_str);
        $trial_end_date_obj = $trial_start_date_obj->modify('+' . $trial_duration_days . ' days');
        $today = new DateTime();
        return ($trial_end_date_obj >= $today); // Trialiing if trial end date is in the future (or today)
     } catch (Exception $e) {
         error_log("Error parsing trial dates: start=" . $trial_start_date_str . ", duration=" . $trial_duration_days . " - " . $e->getMessage());
         return false; // Invalid dates mean not trialing
     }
}


// Function to check if the logged-in user (Owner or Teacher) is Licensed or Trialing
// Super Admins are always considered "licensed" for access purposes, but this function is for non-SA users.
function isLicensed() {
    if (!isLoggedIn() || isSuperAdmin()) {
        // Not logged in, or is Super Admin (who doesn't follow this flow)
        return true;
    }

    // For Owners and Teachers, check the license or trial status (stored in session on login)
    // Teachers inherit the license/trial status from their owner
    // The login process for teachers must fetch and store owner's license/trial status in their session
    $is_licensed = $_SESSION['is_licensed'] ?? false;
    $is_trialing = $_SESSION['is_trialing'] ?? false;

    return $is_licensed || $is_trialing;
}

// Function to get the license expiry date from session (Owner/Teacher)
function getLicenseExpiryDate() {
    return $_SESSION['license_expiry_date'] ?? null;
}

// Function to get the trial end date from session (Owner/Teacher)
function getTrialEndDate() {
    return $_SESSION['trial_end_date'] ?? null;
}

// --- Permission Checks (Incorporating Licensing) ---

// Function to check if the user has a specific permission
// Owners and Super Admins implicitly have all permissions for their scope
// Teachers have specific permissions based on flags
// Owners and Teachers MUST be licensed to use features
function hasPermission($permission_key) {
    // Permission keys should match the column names in the users table for teachers
    // e.g., 'can_view_dashboard', 'can_record_attendance', etc.

    if (!isLoggedIn()) {
        return false; // Not logged in
    }

    if (isSuperAdmin()) {
        // Super Admins have all permissions for the purpose of navigating general features
        // (Specific admin actions are restricted by requireSuperAdmin)
        return true;
    }

    // For Owners and Teachers, they must be licensed AND have the specific permission
    // Note: isLicensed() is already checked by requirePermission, so we just check the specific flag/role here
    if (isOwner()) {
         // Owners implicitly have all permissions if licensed (which is handled by requireLicensed)
         // No need to check individual can_... flags for owners
        return true;
    }

    if (isTeacher()) {
        // Check if the teacher has the specific permission flag set in their session
        // Permission flags should be loaded into the session upon login
        return isset($_SESSION[$permission_key]) && $_SESSION[$permission_key] === 1;
    }

    return false; // Unknown role or other issues
}


// --- Redirection Functions ---

// Function to redirect to login if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("location: login.php");
        exit;
    }
    // Additional check: if user is logged in but essential session data is missing/invalid
     if (!getUserRole() || (isTeacher() && !getTeacherOwnerId())) {
         error_log("User ID " . getCurrentUserId() . " is logged in but essential session data is missing/invalid.");
         logout(); // Log them out for safety
     }
     // No license check here anymore, that's handled by requireLicensed below.
}

// Function to require the user is licensed (Owner or Teacher role)
// This function redirects unlicensed users to the unlicensed page.
function requireLicensed() {
    requireLogin(); // Ensure they are logged in first

    if (!isSuperAdmin()) { // Super Admin doesn't need a license check
         if (!isLicensed()) {
             // Redirect to the unlicensed page if not licensed
             $current_page = basename($_SERVER['PHP_SELF']);
              // Prevent redirect loop if already on the unlicensed page
             if ($current_page !== 'unlicensed.php') {
                 header("location: unlicensed.php");
                 exit;
             }
         }
    }
}


// Function to redirect if the user is not an owner
function requireOwner() {
    requireLicensed(); // Must be licensed to be an owner and access this page

    if (!isOwner()) {
         // Redirect others (teachers) to dashboard with access denied message
         header("location: dashboard.php?msg_type=danger&msg=" . urlencode("Access denied. Only school owners can access this page."));
        exit;
    }
}

// Function to redirect if the user is not a Super Admin
function requireSuperAdmin() {
    requireLogin(); // SA doesn't need licensing check here, but must be logged in

    if (!isSuperAdmin()) {
        // Redirect others (owners/teachers) to dashboard or a generic forbidden page
        header("location: dashboard.php?msg_type=danger&msg=" . urlencode("Access denied. Only administrators can access this page."));
        exit;
    }
}


// Function to redirect to dashboard or an access denied page if not authorized
// This function now implicitly requires the user to be licensed (via requireLicensed)
function requirePermission($permission_key) {
    requireLicensed(); // Ensure they are logged in AND licensed first

    if (!hasPermission($permission_key)) {
        // Redirect to dashboard or a specific access denied page
        header("location: dashboard.php?msg_type=danger&msg=" . urlencode("Access denied. You do not have permission to view this page or perform this action."));
        exit;
    }
}


// Function to log out user
function logout() {
    // Unset all session variables
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Redirect to login page
    header("location: login.php");
    exit;
}

// --- Login Logic Modification (Implemented in login.php) ---
// In login.php, after successful password verification, you must fetch
// the user's role, owner_id, trial and license details, and permission flags,
// calculate license/trial status, and store them all in the $_SESSION:

/*
// Example snippet for login.php (inside the if(password_verify) block)

if (password_verify($password, $hashed_password)) {
    // session_start() is already handled by auth.php include

    // Fetch user details including role, owner_id, trial and license fields, and all permission flags
    $sql_user_details = "SELECT user_id, username, role, owner_id,
                         trial_start_date, trial_duration_days, license_key, license_expiry_date, next_license_sequence,
                         can_view_dashboard, can_view_classes, can_view_payments, can_record_payments, can_view_expenses, can_record_attendance
                         FROM users WHERE user_id = ?";
    $stmt_user_details = $conn->prepare($sql_user_details);
    if ($stmt_user_details) {
        $stmt_user_details->bind_param("i", $id); // $id is the user_id fetched previously
        $stmt_user_details->execute();
        $result_user_details = $stmt_user_details->get_result();
        if ($result_user_details->num_rows > 0) {
            $user_details = $result_user_details->fetch_assoc();
             if ($result_user_details) $result_user_details->free(); // Free result set
             $stmt_user_details->close(); // Close statement early


            $_SESSION['user_id'] = $user_details['user_id'];
            $_SESSION['username'] = $user_details['username'];
            $_SESSION['role'] = $user_details['role'];
            $_SESSION['owner_id'] = $user_details['owner_id']; // Will be NULL for owners and SA

            // --- Store Trial and License Info in Session and Calculate Status ---
            $today = new DateTime();

            // --- For Owners and Teachers, check license and trial status ---
            if (!isSuperAdmin()) {
                 // Teachers inherit license/trial status from their owner
                 $licensed_owner_id = ($user_details['role'] === 'owner') ? $user_details['user_id'] : $user_details['owner_id'];

                 // Fetch license/trial details from the OWNER's record
                 $sql_owner_license_info = "SELECT trial_start_date, trial_duration_days, license_expiry_date FROM users WHERE user_id = ?";
                 $stmt_owner_license = $conn->prepare($sql_owner_license_info);
                 $owner_license_info = null;
                 if ($stmt_owner_license) {
                      $stmt_owner_license->bind_param("i", $licensed_owner_id);
                      $stmt_owner_license->execute();
                      $result_owner_license = $stmt_owner_license->get_result();
                      if($result_owner_license->num_rows > 0) {
                          $owner_license_info = $result_owner_license->fetch_assoc();
                           if ($result_owner_license) $result_owner_license->free();
                      } else {
                           error_log("Owner ID " . $licensed_owner_id . " not found during license status fetch for user " . $user_details['user_id']);
                           // This indicates a serious data inconsistency
                      }
                       $stmt_owner_license->close();
                 } else {
                     error_log("DB error preparing owner license info fetch: " . $conn->error);
                 }


                 if ($owner_license_info) {
                     $_SESSION['license_expiry_date'] = $owner_license_info['license_expiry_date'];
                     $_SESSION['is_licensed'] = isAccountLicensed($_SESSION['license_expiry_date']);

                     $_SESSION['trial_start_date'] = $owner_license_info['trial_start_date'];
                     $_SESSION['trial_duration_days'] = $owner_license_info['trial_duration_days'];
                     $_SESSION['trial_end_date'] = null; // Initialize trial end date

                      // Only check trialing status if not licensed AND trial details are present
                     if (!$_SESSION['is_licensed']) {
                          $_SESSION['is_trialing'] = isAccountTrialing($_SESSION['trial_start_date'], $_SESSION['trial_duration_days']);
                          if($_SESSION['is_trialing'] && $_SESSION['trial_start_date'] !== null) {
                              $trial_start_date_obj = new DateTime($_SESSION['trial_start_date']);
                              $trial_end_date_obj = $trial_start_date_obj->modify('+' . $_SESSION['trial_duration_days'] . ' days');
                              $_SESSION['trial_end_date'] = $trial_end_date_obj->format('Y-m-d');
                          }
                     } else {
                          $_SESSION['is_trialing'] = false; // Not trialing if licensed
                     }
                 } else {
                     // Could not fetch owner license info - treat as unlicensed/not trialing
                     $_SESSION['license_expiry_date'] = null; $_SESSION['is_licensed'] = false;
                     $_SESSION['trial_start_date'] = null; $_SESSION['trial_duration_days'] = 0; $_SESSION['is_trialing'] = false;
                     $_SESSION['trial_end_date'] = null;
                 }
            } else { // Super Admin - set license status to true
                 $_SESSION['is_licensed'] = true;
                 $_SESSION['is_trialing'] = false;
                 $_SESSION['license_expiry_date'] = null; // SA doesn't have an expiry date in this model
                 $_SESSION['trial_start_date'] = null; $_SESSION['trial_duration_days'] = 0; $_SESSION['trial_end_date'] = null;
            }


            // Store permission flags in session for teachers (Owners/SA implicitly have all functional perms)
            if ($user_details['role'] === 'teacher') {
                 $_SESSION['can_view_dashboard'] = $user_details['can_view_dashboard'];
                 $_SESSION['can_view_classes'] = $user_details['can_view_classes'];
                 $_SESSION['can_view_payments'] = $user_details['can_view_payments'];
                 $_SESSION['can_record_payments'] = $user_details['can_record_payments'];
                 $_SESSION['can_view_expenses'] = $user_details['can_view_expenses'];
                 $_SESSION['can_record_attendance'] = $user_details['can_record_attendance'];
                // Add other permission flags here
            }

            // Store next license sequence (relevant for owner on admin dashboard)
            // This is fetched from the owner's record, so it's correct for both owner and teacher
             $_SESSION['next_license_sequence'] = $user_details['next_license_sequence'] ?? 1;


            // --- Redirect based on Role ---
            if (isSuperAdmin()) {
                 header("location: super_admin_dashboard.php"); // Redirect Super Admin
            } else {
                 // Owners and Teachers redirect to dashboard (requireLicensed will handle the check upon landing)
                 header("location: dashboard.php");
            }
            exit(); // Essential after header
        } else {
            // User ID not found after initial fetch - critical error
            error_log("Logged in user ID " . $id . " not found in detailed fetch.");
            $login_err = "An internal error occurred during login. Please try again.";
             // Optionally log out here if the error is severe
             // logout(); // This would redirect to login again
        }
        // $stmt_user_details is closed inside the success block
    } else {
        error_log("Database error preparing user details query: " . $conn->error);
        $login_err = "An internal database error occurred during login.";
    }
}
*/
?>