<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\InvalidPathException;

/**
 * Helper config parser for use in backup and client components
 */
class ManticoreConfig {
	public string $path;
	public string $host;
	public int $port;

	public string $dataDir;
	public string $sphinxqlState;
	public string $lemmatizerBase;
	public string $pluginDir;

	public string $schemaPath;

  /**
   * Initialization instance and parse the config
   *
   * @param string $configPath
   *  Path to manticore daemon searchd config
   */
	public function __construct(string $configPath) {
		$config = file_get_contents($configPath);
		if (false === $config) {
			metric('config_unreachable', 1);
			throw new \InvalidArgumentException('Failed to read config file: ' . $configPath);
		}

		$this->path = $configPath;
		$this->parse($config);
	}

  /**
   * Parse the Manticore searchd configuration file data and get required parameters from there
   *
   * @param string $config
   *  The content of the config file
   * @return void
   */
	protected function parse(string $config): void {
	  // Set defaults first
		$this->host = '127.0.0.1';
		$this->port = 9308;

	  // Try to parse and replace defaults
		preg_match_all('/^\s*(listen|data_dir|lemmatizer_base|sphinxql_state|plugin_dir)\s*=\s*(.*)$/ium', $config, $m);
		if ($m) {
			foreach ($m[1] as $n => $key) {
				$value = $m[2][$n];
				if ($key === 'listen') { // in case of we need to parse
					$this->parseHostPort($value);
				} else { // In this case we have path/file directive
					$property = match ($key) {
						'data_dir' => 'dataDir',
						'lemmatizer_base' => 'lemmatizerBase',
						'sphinxql_state' => 'sphinxqlState',
						'plugin_dir' => 'pluginDir',
						default => $key,
					};
					$this->$property = $value;
				}
			}
		}

		if (!isset($this->dataDir)) {
			metric('config_data_dir_missing', 1);
			throw new InvalidPathException('Failed to detect data_dir from config file');
		}

		if (!static::isDataDirValid($this->dataDir)) {
			$this->dataDir = realpath($this->dataDir) ?: $this->dataDir;
			metric('config_data_dir_is_relative', 1);
		}

		$this->schemaPath = $this->dataDir . '/manticore.json';

		echo PHP_EOL . 'Manticore config' . PHP_EOL
		. '  endpoint =  ' . $this->host . ':' . $this->port . PHP_EOL
		;
	}

	/**
	 * This is helper function that parses host and port from config directive
	 *
	 * @param string $value
	 * @return void
	 */
	protected function parseHostPort(string $value): void {
		$httpPos = strpos($value, ':http');
		if (false === $httpPos) {
			return;
		}
		$listen = substr($value, 0, $httpPos);
		if (!str_contains($listen, ':')) {
			$this->port = (int)$listen;
		} else {
			$this->host = strtok($listen, ':');
			$this->port = (int)strtok(':');
		}
	}

  /**
   * This functions returns global state files that we can backup
   *
   * @return array<string>
   *   List of absolute paths to each file/directory required to backup
   */
	public function getStatePaths(): array {
		$result = [];
		if (isset($this->sphinxqlState)) {
			$result[] = $this->sphinxqlState;
		}

		if (isset($this->lemmatizerBase)) {
			$result[] = $this->lemmatizerBase;
		}

		if (isset($this->pluginDir) && is_dir($this->pluginDir)) {
			$result[] = $this->pluginDir;
		}

		return $result;
	}

  /**
   * This functions validates that data_dir is valid and it contains absolute path
   *
   * @param string $dataDir
   *  platform related data dir path absolute or relative
   * @return bool
   *  If the data dir is an absolute path true otherwise false
   */
	public static function isDataDirValid(string $dataDir): bool {
		return OS::isWindows()
			? (bool)preg_match('|^[a-z]\:\\\\|ius', $dataDir)
			: $dataDir[0] === '/'
		;
	}
}
