<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\InvalidPathException;
use RuntimeException;

/**
 * Helper config parser for use in backup and client components
 */
class ManticoreConfig {
	public string $path;
	public string $proto = 'http';
	public string $host;
	public int $port;

	public string $dataDir;
	public string $sphinxqlState;
	public string $lemmatizerBase;

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

		// If this is compressed config we decompress the data first
		if (str_ends_with($configPath, '.zst')) {
			$config = zstd_uncompress($config);
			if ($config === false) {
				throw new RuntimeException('Failed to decompress config file');
			}
		}

		// If this is shebang config execute it
		if (preg_match('/^#!(.*)$/ium', $config, $m)) {
			$config = shell_exec("{$m[1]} $configPath");
			if (!is_string($config)) {
				throw new RuntimeException('Failed to executed shebang config file');
			}
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
		preg_match_all('/^\s*(listen|data_dir|lemmatizer_base|sphinxql_state)\s*=\s*(.*)$/ium', $config, $m);
		if ($m) {
			$endpoints = [];
			foreach ($m[1] as $n => $key) {
				$value = trim($m[2][$n]);
				if ($key === 'listen') { // in case of we need to parse
					$endpoint = $this->parseHostPort($value);
					if ($endpoint) {
						$endpoints[] = $endpoint;
					}
				} else { // In this case we have path/file directive
					$property = match ($key) {
						'data_dir' => 'dataDir',
						'lemmatizer_base' => 'lemmatizerBase',
						'sphinxql_state' => 'sphinxqlState',
						default => $key,
					};
					$this->$property = $value;
				}
			}
			$this->setupEndpoints($endpoints);
		}

		if (!isset($this->dataDir)) {
			metric('config_data_dir_missing', 1);
			throw new InvalidPathException('Failed to detect data_dir from config file');
		}

		if (!static::isDataDirValid($this->dataDir)) {
			$this->dataDir = backup_realpath($this->dataDir);
			metric('config_data_dir_is_relative', 1);
		}

		$this->schemaPath = $this->dataDir . '/manticore.json';

		echo PHP_EOL . 'Manticore config' . PHP_EOL
			. '  endpoint =  ' . $this->proto . '://' . $this->host . ':' . $this->port . PHP_EOL
		;
	}

	/**
	 * This is just helper to find out endpoints from list of it and do priority logic
	 * @param array<array{host:string,port:int,proto:string}> $endpoints
	 * @return void
	 */
	protected function setupEndpoints(array $endpoints): void {
		$vipOnly = array_filter($endpoints, fn ($v) => $v['proto'] === '_vip' || $v['proto'] === 'http_vip');
		$httpOnly = array_filter($endpoints, fn ($v) => $v['proto'] === 'http');
		if ($vipOnly) {
			$endpoint = $vipOnly[array_key_first($vipOnly)];
		} elseif ($httpOnly) {
			$endpoint = $httpOnly[array_key_first($httpOnly)];
		} else {
			$endpoint = $endpoints[0] ?? null;
		}

		if (!$endpoint) {
			return;
		}

		['host' => $this->host, 'port' => $this->port] = $endpoint;
	}

	/**
	 * This is helper function that parses host and port from config directive
	 *
	 * @param string $value
	 * @return ?array{proto:string,host:string,port:int}
	 */
	protected function parseHostPort(string $value): ?array {
		$parts = explode(':', $value);
		$type = $parts[array_key_last($parts)];
		// If it's a port really, so we set default type
		if (is_numeric($type)) {
			$type = 'http';
		} else {
			unset($parts[array_key_last($parts)]);
		}

		if (!str_starts_with($type, 'http')) {
			return null;
		}
		$host = '127.0.0.1';
		$proto = $type;

		$listen = implode(':', $parts);
		if (false === strpos($listen, ':')) {
			$port = (int)$listen;
		} else {
			$host = strtok($listen, ':');
			$port = (int)strtok(':');
		}

		return compact('proto', 'host', 'port');
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

		if (isset($this->lemmatizerBase) && is_dir($this->lemmatizerBase)) {
			$result[] = $this->lemmatizerBase;
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
			? !!preg_match('|^[a-z]\:\\\\|ius', $dataDir)
			: $dataDir[0] === '/'
		;
	}
}
