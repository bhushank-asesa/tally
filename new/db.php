<?php
$config = require "config.php";

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']}",
        $config["user"],
        $config["password"],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false // prevent RAM overflow
        ]
    );
} catch (PDOException $e) {
    die("❌ DB Connection failed: " . $e->getMessage());
}

return $pdo;
