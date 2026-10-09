<?php
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = parse_ini_file(__DIR__ . '/.env');

if ($env === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not read .env file']);
    exit;
}

$host = $env['DB_HOST'];
$user = $env['DB_USER'];
$pass = $env['DB_PASS'];
$db   = $env['DB_NAME'];

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Databasanslutning misslyckades: ' . $conn->connect_error]);
    exit;
}

$conn->set_charset("utf8mb4");