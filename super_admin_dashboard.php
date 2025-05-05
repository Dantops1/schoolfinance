<?php
// Quick debugging helper
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php_error_super_admin.log');

require_once 'includes/auth.php';
require_once 'includes/db.php';
requireSuperAdmin(); // Only Super Admins can access this page

// Get the current Super Admin user's ID (not strictly needed for data filtering here, as SA sees all)
$current_super_admin_id = getCurrentUserId();

$message = "";
$message_type = "";

// Variables for settings forms
$default_trial_days = 0;
$license_validity_days = 365; // Default days a license is valid for

// --- Fetch Global Settings ---
$stmt_settings = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('default_trial_days', 'license_validity_days')"); // Added license_validity_days
if ($stmt_settings) {
    $stmt_settings->execute();
    $result_settings = $stmt_settings->get_result();
    if ($result_settings) {
        while ($row = $result_settings->fetch_assoc()) {
            if ($row['setting_key'] === 'default_trial_days') {
                $default_trial_days = intval($row['setting_value']);
            } elseif ($row['setting_key'] === 'license_validity_days') { // Fetch the new setting
                 $license_validity_days = intval($row['setting_value']);
            }
        }
         if ($result_settings) $result_settings->free();
    } else {
         error_log("Error fetching settings: " . $conn->error);
         $message = "Error loading settings.";
         $message_type = "danger";
    }
    $stmt_settings->close();
} else {
    error_log("Database error preparing settings query: " . $conn->error);
     $message = "Database error preparing settings query.";
     $message_type = "danger";
}


// --- Handle POST Requests ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Handle Update Settings Form ---
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        $new_default_trial_days = filter_var($_POST['default_trial_days'] ?? 0, FILTER_VALIDATE_INT);
        $new_license_validity_days = filter_var($_POST['license_validity_days'] ?? 0, FILTER_VALIDATE_INT); // Get new validity days

        if ($new_default_trial_days === false || $new_default_trial_days < 0) {
            $message = "Invalid value for default trial days.";
            $message_type = "warning";
        } elseif ($new_license_validity_days === false || $new_license_validity_days <= 0) { // Validity must be > 0
             $message = "Invalid value for license validity days (must be positive).";
             $message_type = "warning";
        } else {
            // Update settings in the database
            $conn->begin_transaction();
            try {
                $stmt_update_trial = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'default_trial_days'");
                 if (!$stmt_update_trial) throw new Exception("DB error preparing trial update: " . $conn->error);
                $val_trial = strval($new_default_trial_days); // Bind as string
                $stmt_update_trial->bind_param("s", $val_trial);
                 if (!$stmt_update_trial->execute()) throw new Exception("DB error executing trial update: " . $stmt_update_trial->error);
                $stmt_update_trial->close();

                $stmt_update_validity = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'license_validity_days'");
                 if (!$stmt_update_validity) throw new Exception("DB error preparing validity update: " . $conn->error);
                $val_validity = strval($new_license_validity_days); // Bind as string
                $stmt_update_validity->bind_param("s", $val_validity);
                 if (!$stmt_update_validity->execute()) throw new Exception("DB error executing validity update: " . $stmt_update_validity->error);
                $stmt_update_validity->close();


                $conn->commit();
                $message = "Settings updated successfully!";
                $message_type = "success";
                $default_trial_days = $new_default_trial_days; // Update displayed value
                $license_validity_days = $new_license_validity_days; // Update displayed value

            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error updating settings: " . $e->getMessage();
                $message_type = "danger";
                 error_log("Error updating settings by SA " . $current_super_admin_id . ": " . $e->getMessage());
            }
        }
        // Redirect after POST
        header("location: super_admin_dashboard.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
        exit;

    } // --- End Handle Update Settings Form ---

    // --- Handle Issue License Action ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'issue_license' && isset($_POST['owner_user_id'])) {
        $owner_user_id = filter_var($_POST['owner_user_id'], FILTER_VALIDATE_INT);
        $license_validity_days_issue = filter_var($_POST['license_validity_days_issue'] ?? $license_validity_days, FILTER_VALIDATE_INT); // Days for this specific license

        if (!$owner_user_id) {
            $message = "Invalid user selected for licensing.";
            $message_type = "danger";
        } elseif ($license_validity_days_issue === false || $license_validity_days_issue <= 0) {
             $message = "Invalid license validity days specified for issuing.";
             $message_type = "warning";
        } else {
            // Fetch the owner user's details and current license sequence
            $stmt_owner = $conn->prepare("SELECT username, next_license_sequence FROM users WHERE user_id = ? AND role = 'owner'");
             if ($stmt_owner) {
                $stmt_owner->bind_param("i", $owner_user_id);
                $stmt_owner->execute();
                $result_owner = $stmt_owner->get_result();
                if ($result_owner->num_rows > 0) {
                    $owner_details = $result_owner->fetch_assoc();
                    $username = $owner_details['username'];
                    $sequence = $owner_details['next_license_sequence'];
                    if ($result_owner) $result_owner->free();
                    $stmt_owner->close();

                    // Get the license phrase from settings
                    $license_phrase = 'dantops'; // Default fallback if setting not found
                    $stmt_phrase = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'license_phrase'");
                    if ($stmt_phrase) {
                         $stmt_phrase->execute();
                         $result_phrase = $stmt_phrase->get_result();
                         if ($result_phrase && $row_phrase = $result_phrase->fetch_assoc()) {
                             if (!empty($row_phrase['setting_value'])) {
                                 $license_phrase = $row_phrase['setting_value'];
                             }
                             if ($result_phrase) $result_phrase->free();
                         } else {
                              error_log("License phrase setting not found.");
                         }
                         $stmt_phrase->close();
                    } else {
                         error_log("DB error preparing license phrase setting query: " . $conn->error);
                    }

                    // Generate the license key
                    $raw_key_part = $username . ':' . $license_phrase;
                    // Add sequence number if it's greater than 1
                    if ($sequence > 1) {
                        $raw_key_part .= $sequence;
                    }
                    $generated_license_key = base64_encode($raw_key_part);

                    // Calculate the license expiry date
                    $expiry_date = new DateTime();
                    $expiry_date->modify('+' . $license_validity_days_issue . ' days');
                    $license_expiry_date_str = $expiry_date->format('Y-m-d');

                    // Update the owner user's record with the new license and increment sequence
                    $conn->begin_transaction();
                    try {
                        $stmt_update_license = $conn->prepare("UPDATE users SET license_key = ?, license_expiry_date = ?, next_license_sequence = next_license_sequence + 1 WHERE user_id = ?");
                        if (!$stmt_update_license) throw new Exception("DB error preparing license update: " . $conn->error);

                        $stmt_update_license->bind_param("ssi", $generated_license_key, $license_expiry_date_str, $owner_user_id);
                        if (!$stmt_update_license->execute()) throw new Exception("DB error executing license update: " . $stmt_update_license->error);
                        $stmt_update_license->close();

                        $conn->commit();
                        $message = "License issued successfully for " . htmlspecialchars($username) . ". Key: <code>" . htmlspecialchars($generated_license_key) . "</code> (Expires: " . $license_expiry_date_str . ")";
                        $message_type = "success";

                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Error issuing license: " . $e->getMessage();
                        $message_type = "danger";
                         error_log("Error issuing license by SA " . $current_super_admin_id . " for owner " . $owner_user_id . ": " . $e->getMessage());
                    }

                } else {
                    // Owner user not found or not an owner
                    $message = "User not found or is not a school owner.";
                    $message_type = "danger";
                     if (isset($stmt_owner)) $stmt_owner->close();
                }
             } else {
                 error_log("DB error preparing owner fetch for licensing: " . $conn->error);
                 $message = "Database error fetching user details for licensing.";
                 $message_type = "danger";
             }
        }
         // Redirect after POST
        header("location: super_admin_dashboard.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
        exit;
    } // --- End Handle Issue License Action ---

    // --- Handle Update Trial Action ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_trial' && isset($_POST['owner_user_id'])) {
         $owner_user_id = filter_var($_POST['owner_user_id'], FILTER_VALIDATE_INT);
         $new_trial_duration_days = filter_var($_POST['new_trial_duration_days'] ?? $default_trial_days, FILTER_VALIDATE_INT); // Days for this specific trial

         if (!$owner_user_id) {
             $message = "Invalid user selected for trial update.";
             $message_type = "danger";
         } elseif ($new_trial_duration_days === false || $new_trial_duration_days < 0) {
              $message = "Invalid value for trial duration (must be non-negative).";
              $message_type = "warning";
         } else {
             // Fetch owner user's details
             $stmt_owner = $conn->prepare("SELECT username FROM users WHERE user_id = ? AND role = 'owner'");
             if ($stmt_owner) {
                 $stmt_owner->bind_param("i", $owner_user_id);
                 $stmt_owner->execute();
                 $result_owner = $stmt_owner->get_result();
                 if ($result_owner->num_rows > 0) {
                     $owner_details = $result_owner->fetch_assoc();
                     $username = $owner_details['username'];
                      if ($result_owner) $result_owner->free();
                     $stmt_owner->close();

                     // Update trial fields
                     // If setting trial duration > 0, set trial_start_date to today.
                     // If setting trial duration to 0, clear trial_start_date.
                     $trial_start_date_val = ($new_trial_duration_days > 0) ? date('Y-m-d') : NULL; // Use NULL if duration is 0

                     $stmt_update_trial = $conn->prepare("UPDATE users SET trial_start_date = ?, trial_duration_days = ? WHERE user_id = ?");
                      if (!$stmt_update_trial) throw new Exception("DB error preparing trial update: " . $conn->error);
                     $stmt_update_trial->bind_param("sii", $trial_start_date_val, $new_trial_duration_days, $owner_user_id);

                     if ($stmt_update_trial->execute()) {
                         $message = "Trial period updated for " . htmlspecialchars($username) . ". New duration: " . $new_trial_duration_days . " days.";
                         $message_type = "success";
                          if ($new_trial_duration_days > 0) {
                              $message .= " Trial starts today.";
                          } else {
                              $message .= " Trial ended/removed.";
                          }

                     } else {
                         $message = "Error updating trial period: " . $conn->error;
                         $message_type = "danger";
                          error_log("Error updating trial by SA " . $current_super_admin_id . " for owner " . $owner_user_id . ": " . $conn->error);
                     }
                      $stmt_update_trial->close();

                 } else {
                     // Owner user not found or not an owner
                     $message = "User not found or is not a school owner.";
                     $message_type = "danger";
                      if (isset($stmt_owner)) $stmt_owner->close();
                 }
             } else {
                 error_log("DB error preparing owner fetch for trial update: " . $conn->error);
                 $message = "Database error fetching user details for trial update.";
                 $message_type = "danger";
             }
         }
          // Redirect after POST
         header("location: super_admin_dashboard.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
         exit;
    } // --- End Handle Update Trial Action ---


    // --- Handle Delete User Action (Only Super Admin can delete any user) ---
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_user' && isset($_POST['delete_user_id'])) {
        $user_id_to_delete = filter_var($_POST['delete_user_id'], FILTER_VALIDATE_INT);

        if (!$user_id_to_delete) {
            $message = "Invalid user ID for deletion.";
            $message_type = "danger";
        } elseif ($user_id_to_delete === $current_super_admin_id) {
             $message = "You cannot delete your own Super Admin account.";
             $message_type = "warning";
        } else {
             // Verify the user exists
            $stmt_verify = $conn->prepare("SELECT user_id, username FROM users WHERE user_id = ?");
            if ($stmt_verify) {
                 $stmt_verify->bind_param("i", $user_id_to_delete);
                 $stmt_verify->execute();
                 $result_verify = $stmt_verify->get_result();
                 if ($result_verify->num_rows > 0) {
                     $user_details = $result_verify->fetch_assoc();
                     $username_to_delete = $user_details['username'];
                     if ($result_verify) $result_verify->free();
                     $stmt_verify->close();

                     // Proceed with deletion
                     // ON DELETE CASCADE handles deleting associated data (classes, students, payments, expenses, teacher assignments)
                     $stmt_delete = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                      if ($stmt_delete) {
                         $stmt_delete->bind_param("i", $user_id_to_delete);

                         if ($stmt_delete->execute()) {
                              $message = "User '" . htmlspecialchars($username_to_delete) . "' deleted successfully.";
                              $message_type = "success";
                         } else {
                             $message = "Error deleting user: " . $conn->error;
                             $message_type = "danger";
                              error_log("Error deleting user_id " . $user_id_to_delete . " by SA " . $current_super_admin_id . ": " . $conn->error);
                         }
                          $stmt_delete->close();
                     } else {
                         $message = "Database error preparing delete user statement.";
                         $message_type = "danger";
                          error_log("Database error preparing delete user statement by SA " . $current_super_admin_id . ": " . $conn->error);
                     }
                 } else {
                     $message = "User not found.";
                     $message_type = "warning";
                      if (isset($stmt_verify)) $stmt_verify->close();
                 }
            } else {
                 $message = "Database error preparing user verification for deletion.";
                 $message_type = "danger";
                  error_log("Database error preparing verify user for deletion by SA " . $current_super_admin_id . ": " . $conn->error);
            }
        }
        header("location: super_admin_dashboard.php?msg_type=" . $message_type . "&msg=" . urlencode($message));
        exit;
    } // --- End Handle Delete User Action ---

} // --- End Handle POST Requests ---


// --- Check for messages after GET redirect ---
if (isset($_GET['msg']) && isset($_GET['msg_type'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['msg_type'];
}

// --- Fetch All Users (Owners, Teachers, other SAs) ---
$all_users = [];
$sql_fetch_users = "SELECT user_id, username, role, school_name, created_at, owner_id,
                    trial_start_date, trial_duration_days, license_key, license_expiry_date, next_license_sequence
                   FROM users ORDER BY role, username";
$stmt_fetch_users = $conn->prepare($sql_fetch_users);
if ($stmt_fetch_users) {
    $stmt_fetch_users->execute();
    $result_fetch_users = $stmt_fetch_users->get_result();
    if ($result_fetch_users) {
        while ($row = $result_fetch_users->fetch_assoc()) {
            $all_users[] = $row;
        }
         if ($result_fetch_users) $result_fetch_users->free();
    } else {
         error_log("Error fetching all users by SA " . $current_super_admin_id . ": " . $conn->error);
         if (empty($message)) {
             $message = "Error loading users list.";
             $message_type = "danger";
         }
    }
    $stmt_fetch_users->close();
} else {
     error_log("Database error preparing fetch all users query by SA " . $current_super_admin_id . ": " . $conn->error);
     if (empty($message)) {
         $message = "Error preparing to load users list.";
         $message_type = "danger";
     }
}

// --- Map Owner IDs to Usernames for Display ---
$owner_usernames = [];
foreach ($all_users as $user) {
    if ($user['role'] === 'owner' || $user['role'] === 'super_admin') {
        $owner_usernames[$user['user_id']] = $user['username'];
    }
}


// Close the database connection
if ($conn && is_object($conn) && method_exists($conn, 'close')) $conn->close();

?>
<?php include 'includes/header.php'; ?>

<h1>Super Admin Dashboard</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; // Message might contain HTML code like <code> ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Global Settings Card -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-cog me-1"></i> Global Settings</div>
            <div class="card-body">
                <form action="super_admin_dashboard.php" method="post">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="mb-3">
                        <label for="default_trial_days" class="form-label">Default Free Trial Duration (Days)</label>
                        <input type="number" class="form-control" id="default_trial_days" name="default_trial_days" value="<?php echo htmlspecialchars($default_trial_days); ?>" min="0" required>
                    </div>
                     <div class="mb-3">
                         <label for="license_validity_days" class="form-label">License Validity (Days from Issuance)</label>
                         <input type="number" class="form-control" id="license_validity_days" name="license_validity_days" value="<?php echo htmlspecialchars($license_validity_days); ?>" min="1" required>
                     </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Update Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Instructions Card -->
     <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle me-1"></i> Instructions</div>
            <div class="card-body">
                <p><strong>License Key Format:</strong> <code>[username]:[phrase][sequence]</code> encoded in Base64.</p>
                <p>The secret phrase is stored in settings (currently `<?php echo htmlspecialchars($license_phrase ?? 'dantops'); ?>`).</p>
                <p>Sequence starts at 1 for the first license issued to an owner. Each subsequent license issued to the same owner increments the sequence.</p>
                <p>Issue licenses only to users with the 'Owner' role. Teachers share their owner's license.</p>
            </div>
        </div>
    </div>
</div>

<h2><i class="fas fa-users me-1"></i> All Users (<?php echo count($all_users); ?>)</h2>

<?php if (count($all_users) > 0): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>School Name</th>
                     <th>Owner</th> <!-- For teachers -->
                    <th>Created At</th>
                    <th>Trial Ends</th>
                    <th>License Key</th>
                    <th>License Expires</th>
                    <th>Next License Sequence</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $user):
                     $is_licensed = ($user['license_expiry_date'] && new DateTime($user['license_expiry_date']) >= new DateTime());
                     $trial_end_date = null;
                     $is_trialing = false;
                     if ($user['trial_start_date'] && $user['trial_duration_days'] > 0) {
                         $trial_start_date_obj = new DateTime($user['trial_start_date']);
                         $trial_end_date_obj = $trial_start_date_obj->modify('+' . $user['trial_duration_days'] . ' days');
                         $trial_end_date = $trial_end_date_obj->format('Y-m-d');
                         $is_trialing = (!$is_licensed && $trial_end_date_obj >= new DateTime()); // Only trialing if not licensed AND trial is active
                     }

                     $status_text = 'Unlicensed';
                     $status_class = 'text-danger fw-bold';
                     if ($is_licensed) {
                         $status_text = 'Licensed';
                         $status_class = 'text-success fw-bold';
                     } elseif ($is_trialing) {
                         $status_text = 'Trialing';
                         $status_class = 'text-warning text-dark fw-bold';
                     }

                     $display_owner = $user['owner_id'] ? ($owner_usernames[$user['owner_id']] ?? 'Unknown') : '-';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?></td>
                        <td><?php echo htmlspecialchars($user['school_name'] ?: '-'); ?></td>
                         <td><?php echo htmlspecialchars($display_owner); ?></td>
                        <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                        <td><?php echo $trial_end_date ?: '-'; ?></td>
                        <td><code style="font-size: 0.8em;"><?php echo htmlspecialchars($user['license_key'] ?: '-'); ?></code></td>
                        <td><?php echo $user['license_expiry_date'] ?: '-'; ?></td>
                        <td><?php echo htmlspecialchars($user['next_license_sequence'] ?? 1); ?></td>
                         <td class="<?php echo $status_class; ?>"><?php echo $status_text; ?></td>
                        <td>
                             <?php if ($user['role'] === 'owner'): // Actions for Owners ?>
                                 <!-- Issue License Form/Button -->
                                 <form action="super_admin_dashboard.php" method="post" class="d-inline-block" onsubmit="return confirm('Issue next license (Seq: <?php echo $user['next_license_sequence'] ?? 1; ?>) for <?php echo htmlspecialchars($user['username']); ?>?');">
                                     <input type="hidden" name="action" value="issue_license">
                                     <input type="hidden" name="owner_user_id" value="<?php echo $user['user_id']; ?>">
                                      <!-- Optional: Allow setting custom validity for this license issuance -->
                                     <!-- <input type="number" name="license_validity_days_issue" value="<?php echo $license_validity_days; ?>" style="width: 60px;"> days -->
                                     <button type="submit" class="btn btn-success btn-sm me-1" title="Issue License"><i class="fas fa-key"></i> Issue License</button>
                                 </form>
                                  <!-- Update Trial Form/Button -->
                                 <form action="super_admin_dashboard.php" method="post" class="d-inline-block" onsubmit="return confirm('Set trial period for <?php echo htmlspecialchars($user['username']); ?>?');">
                                     <input type="hidden" name="action" value="update_trial">
                                     <input type="hidden" name="owner_user_id" value="<?php echo $user['user_id']; ?>">
                                      <input type="number" name="new_trial_duration_days" value="<?php echo $default_trial_days; ?>" style="width: 60px;"> days
                                     <button type="submit" class="btn btn-warning btn-sm" title="Update Trial"><i class="fas fa-hourglass-half"></i> Update Trial</button>
                                 </form>

                            <?php endif; ?>

                             <?php if ($user['user_id'] !== $current_super_admin_id): // Cannot delete self ?>
                                 <!-- Delete User Form/Button -->
                                 <form action="super_admin_dashboard.php" method="post" class="d-inline-block" onsubmit="return confirm('Are you SURE you want to delete user \'<?php echo htmlspecialchars($user['username']); ?>\'? This will delete all their data (classes, students, payments, expenses, teachers, assignments)! This action cannot be undone!');">
                                     <input type="hidden" name="action" value="delete_user">
                                     <input type="hidden" name="delete_user_id" value="<?php echo $user['user_id']; ?>">
                                     <button type="submit" class="btn btn-danger btn-sm" title="Delete User"><i class="fas fa-trash"></i> Delete</button>
                                 </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info" role="alert">
        No users found in the system.
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>