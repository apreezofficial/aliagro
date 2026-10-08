<?php

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use PDO;

/**
 * First-run installer behind the /setup page: verifies the MySQL credentials,
 * optionally creates the database, builds the schema, seeds, writes .env and
 * finally drops storage/installed.lock so the page can never be used again.
 */
class Installer
{
    public static function lockFile(): string
    {
        return storage_path('installed.lock');
    }

    /**
     * Installed = lock file present, or an already working .env + schema
     * (e.g. set up from the CLI), in which case the lock is written for next time.
     */
    public static function isInstalled(): bool
    {
        if (is_file(self::lockFile())) {
            return true;
        }
        if (!env('APP_KEY') || !is_file(base_path('.env'))) {
            return false;
        }

        try {
            $pdo = self::connect(
                (string) env('DB_HOST', '127.0.0.1'), (int) env('DB_PORT', 3306),
                (string) env('DB_USERNAME', 'root'), (string) env('DB_PASSWORD', ''), (string) env('DB_DATABASE', '')
            );
            $has = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }

        if ($has) {
            @file_put_contents(self::lockFile(), gmdate('c') . " (detected existing install)\n");
        }
        return $has;
    }

    /** True when SETUP_KEY is configured and must be supplied on the form. */
    public static function requiresKey(): bool
    {
        return (string) env('SETUP_KEY', '') !== '';
    }

    public static function connect(string $host, int $port, string $user, string $pass, ?string $database = null): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4" . ($database ? ";dbname={$database}" : '');
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT         => 8,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @param array $in validated form values
     * @return string[] progress log
     * @throws \RuntimeException with a user-presentable message
     */
    public function install(array $in): array
    {
        // Building ~30 InnoDB tables can take a while on slow/shared hosts.
        @set_time_limit(300);
        ignore_user_abort(true);

        $log = [];

        // 1. server reachable + credentials valid
        try {
            $server = self::connect($in['db_host'], (int) $in['db_port'], $in['db_username'], $in['db_password']);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Could not connect to MySQL: ' . $this->clean($e->getMessage()));
        }
        $log[] = "Connected to MySQL at {$in['db_host']}:{$in['db_port']}";

        // 2. database
        $db = $in['db_database'];
        if (!empty($in['create_database'])) {
            try {
                $server->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $log[] = "Database `{$db}` ready";
            } catch (\PDOException $e) {
                throw new \RuntimeException("Could not create database `{$db}` (on shared hosting create it in cPanel first and untick \"create database\"): " . $this->clean($e->getMessage()));
            }
        }
        try {
            self::connect($in['db_host'], (int) $in['db_port'], $in['db_username'], $in['db_password'], $db);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Could not open database `{$db}`: " . $this->clean($e->getMessage()));
        }

        // 3. point the app at it for the rest of this request
        foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => $in['db_host'], 'DB_PORT' => $in['db_port'],
                  'DB_DATABASE' => $db, 'DB_USERNAME' => $in['db_username'], 'DB_PASSWORD' => $in['db_password']] as $k => $v) {
            putenv("{$k}={$v}");
        }
        $key = (string) env('APP_KEY') ?: 'base64:' . base64_encode(random_bytes(32));
        putenv("APP_KEY={$key}");
        Config::flush();
        DB::disconnect();

        // 4. schema
        try {
            $log[] = 'Schema: ' . $this->migrate() . ' tables checked/created';

            // 5. seed
            $admin = ['name' => $in['admin_name'], 'email' => $in['admin_email'], 'password' => $in['admin_password']];
            array_push($log, ...(new Seeder())->run($admin, !empty($in['demo_data'])));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database setup failed: ' . $this->clean($e->getMessage()));
        }

        // 6. persist configuration, then lock
        $this->writeEnv([
            'APP_NAME'       => 'AliAgro',
            'APP_ENV'        => 'production',
            'APP_DEBUG'      => 'false',
            'APP_KEY'        => $key,
            'APP_URL'        => $in['app_url'],
            'FRONTEND_URL'   => $in['frontend_url'],
            'DB_CONNECTION'  => 'mysql',
            'DB_HOST'        => $in['db_host'],
            'DB_PORT'        => (string) $in['db_port'],
            'DB_DATABASE'    => $db,
            'DB_USERNAME'    => $in['db_username'],
            'DB_PASSWORD'    => $in['db_password'],
        ]);
        $log[] = '.env written';

        if (@file_put_contents(self::lockFile(), gmdate('c') . "\n") === false) {
            throw new \RuntimeException('Installed, but storage/installed.lock could not be written. Make the storage/ folder writable and create that file by hand to lock this page.');
        }
        $log[] = 'Setup locked';

        return $log;
    }

    private function migrate(): int
    {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(base_path('database/schema.sql')));
        $n = 0;
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
            $statement = rtrim($statement, "; \n\r\t");
            if ($statement !== '') {
                DB::pdo()->exec($statement);
                $n += preg_match('/^CREATE TABLE/i', $statement);
            }
        }
        return $n;
    }

    /** Update/insert KEY=value lines in .env, starting from .env.example on first run. */
    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $body = is_file($path) ? file_get_contents($path) : (is_file(base_path('.env.example')) ? file_get_contents(base_path('.env.example')) : '');

        foreach ($values as $key => $value) {
            $line = $key . '=' . $this->quote($value);
            $re   = '/^' . preg_quote($key, '/') . '=.*$/m';
            $body = preg_match($re, $body)
                ? preg_replace_callback($re, fn() => $line, $body, 1)
                : rtrim($body) . "\n" . $line . "\n";
        }

        if (@file_put_contents($path, $body) === false) {
            throw new \RuntimeException('The database was set up, but .env could not be written. Make the project folder writable, or create .env by hand with: ' . implode(', ', array_keys($values)));
        }
        @chmod($path, 0640);
    }

    private function quote(string $v): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/:@+=,-]*$/', $v)) {
            return $v;
        }
        if (!str_contains($v, "'") && !preg_match('/[\r\n]/', $v)) {
            return "'" . $v . "'";   // single quotes: taken literally by Core\Env
        }
        return '"' . str_replace(['"', "\r", "\n"], ['\\"', '', '\\n'], $v) . '"';
    }

    /** Drop the "SQLSTATE[HY000] [1045]" noise but keep the useful part. */
    private function clean(string $m): string
    {
        return trim(preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $m));
    }
}
