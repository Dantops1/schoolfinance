<?php
// This file serves as the primary entry point for the application.
// It redirects users based on their authentication status.

// Includes authentication helpers to check session status
require_once 'includes/auth.php'; // This file contains session_start() and isLoggedIn()

// Check if the user is already logged in using the function from auth.php
if (isLoggedIn()) {
    // If logged in, redirect to the dashboard page
    header("location: dashboard.php");
    exit; // It's crucial to call exit() after header() to stop script execution
} else {
    // If not logged in, redirect to the login page
    header("location: login.php");
    exit; // Stop further script execution
}
?>