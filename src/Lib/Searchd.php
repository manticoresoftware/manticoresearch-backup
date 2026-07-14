<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
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
	 * @return array<string>
	 */
	public static function getConfigPaths(): array {
		// First check env and if we have there, return config file from there
		$envConfig = getenv('MANTICORE_CONFIG');
		if ($envConfig) {
			return array_map(trim(...), explode('|', $envConfig));
		}

		$configs = array_filter(
			[
				'/etc/manticoresearch/manticore.conf',
				'/usr/local/etc/manticoresearch/manticore.conf',
				'/opt/homebrew/etc/manticoresearch/manticore.conf',
			],
			fn ($path) => is_file($path) && is_readable($path)
		);

		if (!$configs) {
			throw new InvalidPathException('Failed to find Manticore config file. Please pass --config explicitly');
		}

		return array_values($configs);
	}

	/**
	 * Get actual config path from all ocnfigs
	 * @return string
	 */
	public static function getConfigPath(): string {
		$configs = static::getConfigPaths();
		if (!isset($configs[0])) {
			throw new \RuntimeException('Failed to find actual config from the provided paths');
		}

		return $configs[0];
	}

  /**
   * Get the current status of the daemon if it's running or not
   *
   * @return bool
   */
	public static function isRunning(?ManticoreConfig $config = null): bool {
		try {
			$config ??= new ManticoreConfig(static::getConfigPath());
		} catch (\Throwable) {
			return false;
		}

		return static::isEndpointReachable($config);
	}

	public static function isEndpointReachable(ManticoreConfig $config): bool {
		$opts = [
			'http' => [
				'method' => 'POST',
				'header' => 'Content-type: application/x-www-form-urlencoded',
				'content' => http_build_query(['query' => 'SHOW STATUS LIKE \'uptime\'']),
				'ignore_errors' => false,
			],
		];
		$context = stream_context_create($opts);

		try {
			$result = @file_get_contents(
				$config->proto . '://' . $config->host . ':' . $config->port . ManticoreClient::API_PATH,
				false,
				$context
			);
		} catch (\Throwable) {
			return false;
		}

		return is_string($result) && $result !== '';
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
