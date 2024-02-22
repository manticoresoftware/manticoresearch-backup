<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\SearchdException;
use Manticoresearch\Backup\Lib\OS;
use function println;

/**
 * This class is used for communication with manticore searchd HTTP protocol by using SQL endpoint
 */
class ManticoreClient {
	const API_PATH = '/sql?mode=raw';

	/**
	 * @var array<ManticoreConfig>
	 */
	protected array $configs;

	/**
	 * @param array<ManticoreConfig> $configs [description]
	 */
	public function __construct(array $configs) {
		$this->configs = $configs;

		$versions = $this->getVersions();
		$verNum = strtok($versions['manticore'], ' ');
		metric(labels: $versions);

		if ($verNum === false || version_compare($verNum, Searchd::MIN_VERSION) <= 0) {
			$verSfx = strtok(' ');
			if (false === $verSfx) {
				throw new \RuntimeException('Failed to find the version of the manticore searchd');
			}

			$isOld = $verNum < Searchd::MIN_VERSION;
			if (!$isOld) {
				[, $verDate] = explode('@', $verSfx);
				$isOld = $verDate < Searchd::MIN_DATE;
			}

			if ($isOld) {
				throw new \RuntimeException(
					'You are running old version of manticore searchd, minimum required: ' . Searchd::MIN_VERSION
				);
			}
		}

		echo PHP_EOL . 'Manticore versions:' . PHP_EOL
			. '  manticore: ' . $versions['manticore'] . PHP_EOL
			. '  columnar: ' . $versions['columnar'] . PHP_EOL
			. '  secondary: ' . $versions['secondary'] . PHP_EOL
			. '  knn: ' . $versions['knn'] . PHP_EOL
			. '  buddy: ' . $versions['buddy'] . PHP_EOL
		;

	  // Validate config path or fail
		$configPath = $this->getConfigPath();
		if (!OS::isSamePath($configPath, $this->getConfig()->path)) {
			throw new \RuntimeException(
				"Configs mismatched: '{$this->getConfig()->path} <> {$configPath}"
				. ', make sure the instance you are backing up is using the provided config'
			);
		}
	}

  /**
   * Little helper to get current config that is used in Client
   *
   * @return ManticoreConfig
   *  Structure with initialized config
   */
	public function getConfig(): ManticoreConfig {
		// This should never happen
		if (!isset($this->configs[0])) {
			throw new \RuntimeException('Failed to fetch config due to nothing presents');
		}
		return $this->configs[0];
	}

	/**
	 * Get all configs defined for backup
	 * @return array<ManticoreConfig>
	 */
	public function getConfigs(): array {
		return $this->configs;
	}

  /**
   * Helper function that we will use for first init of client and config
   *
   * @param array<string> $configPaths
   * @return self
   */
	public static function init(array $configPaths): self {
		$configs = [];
		foreach ($configPaths as $configPath) {
			$configs[] = new ManticoreConfig($configPath);
		}
		return new ManticoreClient($configs);
	}

  /**
   * This method freezes the index to perform safe copy of the data
   *
   * @param array<string>|string $tables
   *  Name of manticore index or list of tables
   * @return array<string>
   *  Return list of files for frozen index required to backup
   * @throws SearchdException
   */
	public function freeze(array|string $tables): array {
		if (is_string($tables)) {
			$tables = [$tables];
		}
		$allTables = implode(', ', $tables);
		$result = $this->execute('FREEZE ' . $allTables);
		if ($result[0]['error']) {
			throw new SearchdException('Failed to get lock for tables - ' . $allTables);
		}
		return array_map(backup_realpath(...), array_column($result[0]['data'], 'file'));
	}

  /**
   * This method unfreezes the index we fronzen before
   *
   * @param array<string>|string $tables
   *  Name of index to unfreeze or list of tables
   * @return bool
   *  Return the result of operation
   */
	public function unfreeze(array|string $tables): bool {
		if (is_string($tables)) {
			$tables = [$tables];
		}
		$result = $this->execute('UNFREEZE ' . implode(', ', $tables));
		return !$result[0]['error'];
	}

  /**
   * This is helper function run unfreeze all available tables
   *
   * @return bool
   *  The result of unfreezing
   */
	public function unfreezeAll(): bool {
		println(LogLevel::Info, PHP_EOL . 'Unfreezing all tables...');
		return array_reduce(
			array_keys($this->getTables()), function (bool $carry, string $index): bool {
				println(LogLevel::Info, '  ' . $index . '...');
				$isOk = $this->unfreeze($index);
				println(LogLevel::Info, '   ' . get_op_result($isOk));
				$carry = $carry && $isOk;
				return $carry;
			}, true
		);
	}

  /**
   * Query all tables that we have on instance
   *
   * @return array<string,string>
   *  array with index as a key and type as a value [ index => type ]
   */
	public function getTables(): array {
		$result = $this->execute('SHOW TABLES');
		$tables = array_combine(
			array_column($result[0]['data'], 'Index'),
			array_column($result[0]['data'], 'Type')
		);

		metric('tables', sizeof($tables));
		return $tables;
	}

  /**
   * Get manticore, protocol and columnar versions
   *
   * @return array{manticore:string,columnar:string,secondary:string,knn:string,buddy:string}
   *  Parsed list of versions available with keys of [manticore, columnar, secondary, knn, buddy]
   */
	public function getVersions(): array {
		$result = $this->execute('SHOW STATUS LIKE \'version\'');
		$version = $result[0]['data'][0]['Value'] ?? '';
		return static::parseVersions($version);
	}

	/**
	 * Get versions for manticore but using CLI instead of sql
	 * @return array{backup:string,manticore:string,columnar:string,secondary:string,knn:string,buddy:string}
	 *  Parsed list of versions available with keys of [backup, manticore, columnar, secondary]
	 */
	public static function getVersionsFromCli(): array {
		$output = [];
		exec('searchd --version 2>/dev/null', $output);
		$version = $output[0] ?? '';
		return static::parseVersions($version);
	}

	/**
	 * @param string $version
	 * @return array{backup:string,manticore:string,columnar:string,secondary:string,knn:string,buddy:string}
	 */
	protected static function parseVersions(string $version): array {
		$verPattern = '(\d+\.\d+\.\d+[^\(\)]+)';
		$matchExpr = "/^(?:Manticore\s)?{$verPattern}(?:[^\(]*\(columnar\s{$verPattern}\))?"
			. "(?:[^\(]*\(secondary\s{$verPattern}\))?"
			. "(?:[^\(]*\(knn\s{$verPattern}\))?"
			. '(?:[^\(]*\(buddy\sv?(\d+\.\d+\.\d+)\))?$/ius'
		;
		preg_match($matchExpr, $version, $m);
		return [
			'backup' => ManticoreBackup::getVersion(),
			'manticore' => trim($m[1] ?? '0.0.0'),
			'columnar' => trim($m[2] ?? '0.0.0'),
			'secondary' => trim($m[3] ?? '0.0.0'),
			'knn' => trim($m[4] ?? '0.0.0'),
			'buddy' => trim($m[5] ?? '0.0.0'),
		];
	}

	public function flushAttributes(): void {
		$this->execute('FLUSH ATTRIBUTES');
	}

  /**
   * This function is used to validate the config path of running daemon
   *
   * @return string
   *  Path to config from SHOW SETTINGS query
   */
	public function getConfigPath(): string {
		$result = $this->execute('SHOW SETTINGS');
		$configPath = backup_realpath($result[0]['data'][0]['Value']);

	  // Fix issue with //manticore.conf path
		if ($configPath[0] === '/') {
			$configPath = '/' . ltrim($configPath, '/');
		}

		return $configPath;
	}

  /**
   * Run SQL query via HTTP endpoint and return result set
   *
   * @param string $query
   *  SQL query to execute
   * @return array{0:array{data:array<array{Value:string}>,error:string}}
   *  The result of the query passed to be executed
   */
	public function execute(string $query): array {
		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => 'Content-type: application/x-www-form-urlencoded',
				'content' => http_build_query(compact('query')),
				'ignore_errors' => false,
			],
		];
		$context = stream_context_create($opts);
		$config = $this->getConfig();
		try {
			$result = file_get_contents(
				$config->proto . '://' . $config->host . ':' . $config->port . static::API_PATH,
				false,
				$context
			);
		} catch (\ErrorException) {
			throw new SearchdException(
				'Failed to send query to the Manticore Search daemon.'
				. ' Ensure that it is set up to listen for HTTP or HTTPS connections'
				. ' and has the appropriate certificates in place.'
				. ' Additionally, check the \'max_connections\' setting in the configuration'
				. ' file to ensure that it has not been exceeded. '
			);
		}

		if (!$result) { // can be null or false in failed cases so we check non strict here
			throw new SearchdException(__METHOD__ . ': failed to execute query: "' . $query . '"');
		}

	  // @phpstan-ignore-next-line
		return json_decode($result, true);
	}

  /**
   * Get signal handler for received signals on interruption
   *
   * @return \Closure
   */
	public function getSignalHandlerFn(FileStorage $storage): \Closure {
		return function (int $signal) use ($storage): void {
			println(LogLevel::Warn, 'Caught signal ' . $signal);
			metric('terminations', 1);
			metric("signal_$signal", 1);
			$storage->cleanUp();
			$this->unfreezeAll();
		};
	}
}
