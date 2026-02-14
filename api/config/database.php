<?php
/**
 * Database Configuration for Wilayah API
 *
 * Resolution order:
 *   1. Environment variables (set by Docker Compose)
 *   2. Root .env file
 *   3. Built-in defaults
 */

class DatabaseConfig
{
    private static ?self $instance = null;
    private PDO $db;

    private function __construct()
    {
        $cfg = $this->loadConfig();

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            $this->db = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES {$cfg['charset']}",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    /** Singleton access */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->db;
    }

    /* ------------------------------------------------------------------ */

    private function loadConfig(): array
    {
        // 1. Environment variables (set by Docker Compose)
        if (getenv('DB_HOST') || getenv('DB_NAME')) {
            return [
                'host'    => getenv('DB_HOST')    ?: 'localhost',
                'name'    => getenv('DB_NAME')    ?: 'wilayah',
                'user'    => getenv('DB_USER')    ?: 'root',
                'pass'    => getenv('DB_PASS')    ?: '',
                'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
            ];
        }

        // 2. Root .env file
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            return $this->parseEnv($envFile);
        }

        // 3. Defaults
        return [
            'host'    => 'localhost',
            'name'    => 'wilayah',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ];
    }

    private function parseEnv(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
            $env[trim($key)] = trim($val);
        }

        return [
            'host'    => $env['DB_HOST']    ?? 'localhost',
            'name'    => $env['DB_NAME']    ?? 'wilayah',
            'user'    => $env['DB_USER']    ?? 'root',
            'pass'    => $env['DB_PASS']    ?? '',
            'charset' => $env['DB_CHARSET'] ?? 'utf8mb4',
        ];
    }
}