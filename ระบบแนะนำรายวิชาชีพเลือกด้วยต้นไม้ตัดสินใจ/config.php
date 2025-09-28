<?php
// config.php
$host = 'localhost';
$user = 'aprdt';
$password = 'aprdt1234';
$database = 'studentregistration';

$conn = mysqli_connect($host, $user, $password);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Create database and table if not exists
mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS $database");
mysqli_select_db($conn, $database);

$table_sql = "CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    birthdate DATE,
    gender VARCHAR(10),
    citizen_id VARCHAR(20),
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    faculty VARCHAR(100),
    major VARCHAR(100),
    education_level VARCHAR(50),
    curriculum_name VARCHAR(100),
    program_type VARCHAR(50),
    curriculum_year INT,
    student_group VARCHAR(50),
    gpa DECIMAL(3,2),
    status VARCHAR(50),
    education_term VARCHAR(10),
    education_year VARCHAR(10),
    password VARCHAR(255) NOT NULL,
    last_login_time DATETIME
)";

mysqli_query($conn, $table_sql);
?>