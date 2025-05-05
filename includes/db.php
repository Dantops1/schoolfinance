<?php
// Database credentials
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'climaxne_school');
define('DB_PASSWORD', 'T{X;pb2(X.dY');
define('DB_NAME', 'climaxne_school');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($conn === false){
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Set character set to utf8mb4
$conn->set_charset("utf8mb4");
?>