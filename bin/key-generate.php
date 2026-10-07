<?php
/** Generate APP_KEY and write it into .env:  php bin/key-generate.php */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$envFile = __DIR__ . '/../.env';
if (!is_file($envFile)) {
    copy(__DIR__ . '/../.env.example', $envFile);
}

$key  = 'base64:' . base64_encode(random_bytes(32));
$body = file_get_contents($envFile);
$body = preg_match('/^APP_KEY=.*$/m', $body)
    ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $body)
    : $body . "\nAPP_KEY={$key}\n";
file_put_contents($envFile, $body);

echo "APP_KEY set in .env\n";
