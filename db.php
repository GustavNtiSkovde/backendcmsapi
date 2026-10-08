<?php
$host = 'db'; // DDEV host
$user = 'db'; // DDEV användare
$pass = 'db'; // DDEV lösenord
$db   = 'db'; // DDEV databasnamn

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Databasanslutning misslyckades: ' . $conn->connect_error]);
    exit;
}

$conn->set_charset("utf8mb4");