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
include_once $dir . DIRECTORY_SEPARATOR . 'const.php';

spl_autoload_register(
	function ($className) use ($dir) {
		if (!str_starts_with($className, APP_NS_PREFIX)) {
			return;
		}
		// @phpstan-ignore-next-line
		$className = substr($className, APP_NS_PREFIX_LEN);

		$filePath = $dir . DIRECTORY_SEPARATOR
			. str_replace('\\', DIRECTORY_SEPARATOR, $className)
			. '.php'
		;
		if (!file_exists($filePath)) {
			return;
		}
		include_once $filePath;
	}
);
include_once $dir . DIRECTORY_SEPARATOR . 'func.php';
unset($dir);

set_exception_handler(exception_handler(...));
set_error_handler(error_handler(...)); // @phpstan-ignore-line

echo 'Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)'
  . PHP_EOL . PHP_EOL
;
