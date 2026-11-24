<?php

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // sql_mode TETAP & PORTABEL — agar perilaku sama di semua host
                // (MySQL 8 vs MariaDB, hosting apa pun). Sengaja TANPA
                // STRICT_TRANS_TABLES & ONLY_FULL_GROUP_BY (dua default MySQL 8
                // yang bikin "jalan di dev/MariaDB, rusak di MySQL 8 strict").
                // Nilai kolom yang benar tetap dijaga oleh skema (bukan andalkan
                // strict). Dijalankan tiap connect (termasuk reconnect).
                // PHP 8.4+ memakai class constant Pdo\Mysql::ATTR_INIT_COMMAND; nama lama
                // PDO::MYSQL_ATTR_INIT_COMMAND deprecated sejak 8.5 (warning tiap connect).
                // Pakai nama baru bila ada, fallback ke lama untuk PHP < 8.4. Nilai identik (1002).
                (defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) =>
                    "SET SESSION sql_mode='NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('Koneksi database gagal. Periksa konfigurasi.');
        }
    }

    return $pdo;
}
