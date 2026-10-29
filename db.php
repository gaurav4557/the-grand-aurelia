<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host = "sql207.infinityfree.com";
$username = "if0_43156810";
$password = "thegrandaurelia";
$database = "if0_43156810_hotel";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    exit("Website temporarily unavailable. Please try again later.");
}

$conn->set_charset("utf8mb4");
?>