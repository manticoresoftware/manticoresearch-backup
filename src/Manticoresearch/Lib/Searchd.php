<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Lib;

use Manticoresearch\Exception\InvalidPathException;

class Searchd {
	public static ?string $cmd;

	public static function init(): void {
		static::$cmd = OS::which('searchd');
	}

	public static function getConfigPath(): string {
		if (!isset(static::$cmd)) {
			throw new \InvalidArgumentException('You should run Searchd::init before trying to access static methods');
		}

		$output = shell_exec(static::$cmd . ' --status');
		if (!is_string($output)) {
			throw new \RuntimeException('Unable to get config path');
		}
		preg_match('/using config file \'([^\']+)\'/ium', $output, $m);
		if (!$m) {
			throw new InvalidPathException('Failed to find searchd config from command line');
		}
		return $m[1];
	}

  /**
   * Get the current status of the daemon if it's running or not
   *
   * @return bool
   */
	public static function isRunning(): bool {
		$result_code = 0;
		exec(static::$cmd . ' --status', result_code: $result_code);

		return $result_code === 0;
	}
}
