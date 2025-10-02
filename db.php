<?php
// db.php - Professional Database Handler
$config = require __DIR__ . '/config.php';

class DB {
    private static $pdo = null;
    
    public static function pdo() {
        global $config;
        if (self::$pdo === null) {
            try {
                $c = $config['db'];
                $dsn = "mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}";
                self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false
                ]);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new Exception("Database connection error. Please try again later.");
            }
        }
        return self::$pdo;
    }
    
    public static function beginTransaction() {
        return self::pdo()->beginTransaction();
    }
    
    public static function commit() {
        return self::pdo()->commit();
    }
    
    public static function rollback() {
        return self::pdo()->rollback();
    }
}