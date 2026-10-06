<?php
$host = 'db';
$dbname = 'db';
$username = 'db';
$password = 'db';
$port = 3306;

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "Successfully connected to the DDEV database!";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}