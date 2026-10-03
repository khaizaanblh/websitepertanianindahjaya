<?php

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'kasir_pertanian';
$port = getenv('DB_PORT') ?: 3306;

$conn = new mysqli(
    $host,
    $user,
    $password,
    $database,
    (int) $port
);

if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

function get_base_url(): string
{
    if (getenv('VERCEL')) {
        return '';
    }

    return '/kasir_pertanian';
}
