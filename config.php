
<?php

$host = "localhost";
$dbname = "u436098853_event_platform";
$username = "u436098853_event_admin";
$password = "A2m6i4t8@";

try {
    $pdo = new PDO(
        "mysql:host=" . $host . ";dbname=" . $dbname . ";charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());

    http_response_code(500);
    exit("Website temporarily unavailable. Please try again later.");
}
