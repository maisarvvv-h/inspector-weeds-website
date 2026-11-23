<?php

/*
 * Shared database connection for the Inspector Weeds website.
 * Default XAMPP credentials are root with an empty password.
 */
$dbHost = "localhost";
$dbName = "inspector_weeds";
$dbUser = "root";
$dbPass = "";
$dbCharset = "utf8mb4";

/* MySQLi connection for existing pages that use $conn. */
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_errno) {
    exit(
        "Cannot connect to the Inspector Weeds database. " .
        "Please start MySQL in XAMPP and confirm that the inspector_weeds database exists."
    );
}

$conn->set_charset($dbCharset);

/* PDO connection for pages that use $pdo. */
$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    exit(
        "Cannot connect to the Inspector Weeds database. " .
        "Please start MySQL in XAMPP and confirm that the inspector_weeds database exists."
    );
}