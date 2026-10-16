<?php 
use Dotenv\Dotenv;
use Model\ActiveRecord;
require __DIR__ . '/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Interfaces\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/../interfaces/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

require_once __DIR__ . '/funciones.php';
require __DIR__ . '/database.php';

// Conectarnos a la base de datos
ActiveRecord::setDB($db);
