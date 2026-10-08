<?php
/**
 * Seed reference data and demo accounts:  php bin/seed.php [--reference-only]
 *
 * --reference-only  skip the demo admin/farmer/consumer accounts (use this in production:
 *                   the demo passwords are public in the repository).
 * Re-running is safe; existing rows are left alone.
 * (The /setup page runs the same seeder.)
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require __DIR__ . '/../bootstrap/app.php';

$referenceOnly = in_array('--reference-only', $argv ?? [], true);

foreach ((new App\Services\Seeder())->run(null, !$referenceOnly) as $line) {
    echo $line . "\n";
}

echo "\nSeeding done" . ($referenceOnly ? ' (reference data only).' : '.') . "\n";
