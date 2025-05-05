<?php
// Include database connection and authentication helpers
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Redirect to dashboard if user is already logged in
if (isLoggedIn()) {
    header("location: dashboard.php");
    exit;
}

// Define variables and initialize with empty values
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }

    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Check input errors before authenticating
    if (empty($username_err) && empty($password_err)) {
        // Prepare a select statement
        $sql = "SELECT user_id, username, password FROM users WHERE username = ?";

        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("s", $param_username);

            // Set parameters
            $param_username = $username;

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Store result
                $stmt->store_result();

                // Check if username exists, if yes then verify password
                if ($stmt->num_rows == 1) {
                    // Bind result variables
                    $stmt->bind_result($id, $username, $hashed_password);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct, so start a new session
                            // session_start() is already called in includes/auth.php
                            // $_SESSION['loggedin'] = true; // Optional flag
                            $_SESSION['user_id'] = $id;
                            $_SESSION['username'] = $username;

                            // Redirect user to welcome page
                            header("location: dashboard.php");
                            exit();
                        } else {
                            // Password is not valid, display a generic error message
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    // Username doesn't exist, display a generic error message
                    $login_err = "Invalid username or password.";
                }
            } else {
                $login_err = "Oops! Something went wrong. Please try again later.";
                 // Log the error
                error_log("Database error on login: " . $stmt->error);
            }

            // Close statement
            $stmt->close();
        }
    }

    // Close connection (will be closed by includes/footer.php usually, but good practice)
    // $conn->close(); // No, let footer handle this
}

// Include the header
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
         <div class="card">
            <div class="card-header">Login</div>
            <div class="card-body">
                <?php
                if (!empty($login_err)) {
                    echo '<div class="alert alert-danger">' . htmlspecialchars($login_err) . '</div>';
                }
                ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>">
                        <span class="invalid-feedback"><?php echo $username_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                        <span class="invalid-feedback"><?php echo $password_err; ?></span>
                    </div>
                    <div class="d-grid">
                         <button type="submit" class="btn btn-primary">Login</button>
                    </div>
                    <p class="mt-3 text-center">Don't have an account? <a href="register.php">Register now</a>.</p>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Include the footer (handles closing connection if needed)
include 'includes/footer.php';
?>