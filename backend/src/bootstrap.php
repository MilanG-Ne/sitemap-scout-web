<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ScoutWeb\\')) {
        $file = __DIR__ . '/' . substr($class, 9) . '.php';
        if (is_file($file)) require $file;
    }
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'AltchaOrg\\Altcha\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/vendor/altcha/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});
