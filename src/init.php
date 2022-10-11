<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

// Initialize autoloading
$dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src';
include_once $dir . DIRECTORY_SEPARATOR . 'func.php';
spl_autoload_register(function ($class_name) use ($dir) {
	$ns = (false === strpos($class_name, 'Exception') ? 'lib' : 'exception');
	$class_file = $dir . DIRECTORY_SEPARATOR . $ns . DIRECTORY_SEPARATOR . $class_name . '.php';
	if (file_exists($class_file)) {
		include_once $class_file;
	}
});
unset($dir);

set_exception_handler(exception_handler(...));
set_error_handler(error_handler(...)); // @phpstan-ignore-line

// Validate minimum php version
if (version_compare(PHP_VERSION, ManticoreBackup::MIN_PHP_VERSION) < 0) {
	throw new Exception('Minimum require PHP version is: ' . ManticoreBackup::MIN_PHP_VERSION);
}

echo 'Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)'
  . PHP_EOL . PHP_EOL
;
