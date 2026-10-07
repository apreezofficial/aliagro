<?php

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
| Defines paths, registers the PSR-4 style autoloader (App\ => src/),
| loads the .env file and sets sane PHP runtime defaults. No Composer.
*/

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/src/Core/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Turn PHP warnings/notices into exceptions so they are logged and rendered
// as JSON 500s instead of leaking half-rendered output.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
