<?php
/**
 * Create/upgrade the database schema:  php bin/migrate.php
 * Idempotent (CREATE TABLE IF NOT EXISTS), so it is safe on a database that
 * the old Laravel app already migrated.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require __DIR__ . '/../bootstrap/app.php';

use App\Core\DB;

$sql = file_get_contents(base_path('database/schema.sql'));
// strip "-- comment" lines, then split on statement terminators
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(";\n", $sql)));

$created = 0;
foreach ($statements as $statement) {
    $statement = rtrim($statement, "; \n\r\t");
    if ($statement === '') {
        continue;
    }
    DB::pdo()->exec($statement);
    if (preg_match('/CREATE TABLE IF NOT EXISTS (`?\w+`?)/i', $statement, $m)) {
        echo "ok  {$m[1]}\n";
        $created++;
    }
}

echo "\nSchema up to date ({$created} tables checked).\n";
