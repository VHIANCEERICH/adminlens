<?php

declare(strict_types=1);

/**
 * Shared PDO connection for the application.
 * Update the database name, username, or password here if your local setup differs.
 */
if (!function_exists('adminlens_db')) {
    function adminlens_db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        try {
            $pdo = new PDO(
                'mysql:host=localhost;dbname=adminlens;charset=utf8mb4',
                'root',
                '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Please check config/db.php.');
        }

        return $pdo;
    }
}

return adminlens_db();
