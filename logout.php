<?php
// Include authentication helpers
require_once 'includes/auth.php';

// Call the logout function from auth.php
// This function will unset session variables, destroy the session, and redirect to login page
logout();

// The logout() function contains exit(), so nothing after this should execute.
?>