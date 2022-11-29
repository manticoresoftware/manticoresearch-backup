<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\InvalidPathException;

class Searchd {
	const MIN_VERSION = '5.0.3';
	const MIN_DATE = '221012';

	public static ?string $cmd;

	/**
	 * @return string
	 */
	public static function getConfigPath(): string {
		// First check env and if we have there, return config file from there
		$envConfig = getenv('MANTICORE_CONFIG');
		if ($envConfig) {
			return $envConfig;
		}

		$output = shell_exec(static::getCmd() . ' --status');
		if (!is_string($output)) {
			throw new \RuntimeException('Unable to get config path');
		}
		preg_match('/using config file \'([^\']+)\'/ium', $output, $m);
		if (!$m) {
			throw new InvalidPathException('Failed to find searchd config from command line');
		}

		$configPath = realpath($m[1]);
		if (false === $configPath) {
			throw new \RuntimeException('Unable to get config path');
		}
		return $configPath;
	}

  /**
   * Get the current status of the daemon if it's running or not
   *
   * @return bool
   */
	public static function isRunning(): bool {
		$resultCode = 0;
		exec(static::getCmd() . ' --status', result_code: $resultCode);

		return $resultCode === 0;
	}

	/**
	 * Launch daemon in case if it's not running
	 */
	public static function run(): void {
		if (static::isRunning()) {
			return;
		}
		shell_exec(static::getCmd());
	}

	/**
	 * Helper method to get cmd for searchd to execute in command line
	 *
	 * @return string
	 */
	protected static function getCmd(): string {
		if (!isset(static::$cmd)) {
			static::$cmd = OS::which('searchd');
		}

		return static::$cmd;
	}
}
