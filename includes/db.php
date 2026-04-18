<?php
$host = "localhost";
$user = "root";
$pass = "1234";
$dbname = "campus_resource_sharing";
$port = 3306;

$conn = new mysqli($host, $user, $pass, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>