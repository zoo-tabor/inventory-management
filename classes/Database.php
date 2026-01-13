<?php
/**
 * Database Class
 * Singleton PDO wrapper for database connections
 */

class Database {
    private static $instance = null;
    private $pdo;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
        } catch (PDOException $e) {
            // Log error and show user-friendly message
            error_log("Database connection failed: " . $e->getMessage());

            if (defined('APP_DEBUG') && APP_DEBUG === 'true') {
                die("Chyba připojení k databázi: " . $e->getMessage());
            } else {
                die("Chyba připojení k databázi. Kontaktujte administrátora.");
            }
        }
    }

    /**
     * Get singleton instance
     *
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance->pdo;
    }

    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Execute a query and return statement
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return PDOStatement
     */
    public static function query($sql, $params = []) {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return array|false
     */
    public static function fetchOne($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return array
     */
    public static function fetchAll($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert record and return last insert ID
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return string Last insert ID
     */
    public static function insert($sql, $params = []) {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $db->lastInsertId();
    }

    /**
     * Update or delete record and return affected rows
     *
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return int Number of affected rows
     */
    public static function execute($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction() {
        $db = self::getInstance();
        $db->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit() {
        $db = self::getInstance();
        $db->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback() {
        $db = self::getInstance();
        $db->rollBack();
    }
}
