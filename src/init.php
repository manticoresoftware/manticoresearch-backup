<?php declare(strict_types=1);

// Initialize autoloading
$dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src';
include_once $dir . DIRECTORY_SEPARATOR . 'func.php';
spl_autoload_register(function ($class_name) use ($dir) {
  $ns = (false === strpos($class_name, 'Exception') ? 'lib' : 'exception');
  include_once  $dir . DIRECTORY_SEPARATOR . $ns . DIRECTORY_SEPARATOR . $class_name . '.php';
});
unset($dir);

set_exception_handler('exception_handler');

// Validate minimum php version
if (version_compare(PHP_VERSION, ManticoreBackup::MIN_PHP_VERSION) < 0) {
  throw new Exception('Minimum require PHP version is: ' . ManticoreBackup::MIN_PHP_VERSION);
}
