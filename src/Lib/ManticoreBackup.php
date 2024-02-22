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

use function println;

/**
 * This class is used to initialize config, parse it and launch the backup process
 */
class ManticoreBackup {
	/**
	 * Get current version of the backup tool
	 *
	 * @return string
	 */
	public static function getVersion(): string {
		return trim(
			(string)file_get_contents(
				__DIR__ . DIRECTORY_SEPARATOR
				. '..' . DIRECTORY_SEPARATOR
				. '..' . DIRECTORY_SEPARATOR
				. 'APP_VERSION'
			)
		);
	}

	/**
	 * Main entry point to make it easier to collect metrics when in package mode
	 *
	 * Available commands:
	 * store: ManticoreClient $client, FileStorage $storage, array $tables = []
	 * restore: FileStorage $storage
	 *
	 * @param string $command
	 * @param array{}|array{0:ManticoreClient,1:FileStorage,2?:array<string>}|array{0:FileStorage} $args
	 * @return void
	 */
	public static function run(string $command, array $args = []): void {
		$isPackage = !is_used_as_tool();
		if ($isPackage) {
			metric(
				'invocation', 1, [
					'mode' => 'lib',
				]
			);
		}
		static::$command(...$args);
		if (!$isPackage) {
			metric('done', 1);
		}

		// Return metric and send it
		metric()?->send();
	}

	/**
	 * Validate if versions that we want to restore is the same
	 * @param  FileStorage     $storage
	 * @return bool
	 */
	public static function validateVersions(FileStorage $storage): bool {
		$currentVersions = ManticoreClient::getVersionsFromCli();
		$backupPaths = $storage->getBackupPaths();
		$storedVersions = ManticoreBackup::readVersions($backupPaths['root']);
		println(LogLevel::Info, 'Stored versions: ' . json_encode($storedVersions));
		println(LogLevel::Info, 'Current versions: ' . json_encode($currentVersions));
		$versionsEqual = true;
		foreach ($currentVersions as $k => $v) {
			if ($k === 'buddy') {
				continue;
			}
			if ($k === 'backup') {
				$currentMajor = (int)strtok($currentVersions[$k], '.');
				$storedMajor = (int)strtok($v, '.');
				if ($currentMajor !== $storedMajor) {
					$versionsEqual = false;
					break;
				}
				continue;
			}
			if ($storedVersions[$k] !== $v) {
				$versionsEqual = false;
				break;
			}
		}
		return $versionsEqual;
	}

	/**
   * Store the wanted tables in backup dir as backup
   *
   * @param ManticoreClient $client
   *  Initialized client to interract with manticore search daemon
   * @param FileStorage $storage
   *  The instance of the storage with initialize directories to use
   * @param array<string> $tables
   *  List of tables to store. In case if its empty array we store all tables
   * @return void
   * @throws \RuntimeException
   */
	protected static function store(ManticoreClient $client, FileStorage $storage, array $tables = []): void {
		println(LogLevel::Info, 'Starting the backup...');
		$t = microtime(true);
		$destination = $storage->getBackupPaths();

	  // First store current versions in file
		$versions = $client->getVersions();
		$isOk = static::storeVersions($versions, $destination['root']);
		if (false === $isOk) {
			metric('backup_store_versions_fails', 1);
			throw new InvalidPathException('Failed to save the versions in "' . $destination['root'] . '"');
		}

	  // TODO: add progress bar / backup status reporting

	  // If we have no tables passed we should to query the client and get all tables we have
		[$isAll, $tables] = static::validateTables($tables, $client);

	  // - backup config files
		println(LogLevel::Info, 'Backing up config files...');
		$isOk = $storage->copyPaths(
			[
				...array_map(fn ($v) => $v->path, $client->getConfigs()),
				$client->getConfig()->schemaPath,
			], $destination['config'], true
		);
		println(LogLevel::Info, '  config files - ' . get_op_result($isOk));

		$result = true;

	  // We back up state first because they are usually small enough
		if ($isAll) {
		  // - Backup global state files
			println(LogLevel::Info, 'Backing up global state files...');
			$files = $client->getConfig()->getStatePaths();
			$isOk = $storage->copyPaths($files, $destination['state'], true);
			println(LogLevel::Info, '  global state files – ' . get_op_result($isOk));

		  // @phpstan-ignore-next-line
			$result = $result && $isOk;
		}

	  // - Lock all tables to make sure that we will have no new data there
	  // Make sure that in case any exception or whatever we will unlock all indexes
		$isDone = false;
		$unfreezeFn = function (ManticoreClient $client) use (&$isDone) {
			if ($isDone) { // @phpstan-ignore-line
				return;
			}

			if (!Searchd::isRunning()) {
				return;
			}

			$client->unfreezeAll();
		};
		register_shutdown_function($unfreezeFn, $client);

	  // And run FLUSH ATTRIBUTES
	  // We do lock twice just to keep logic for crawling one by one for each index
		$client->freeze(array_keys($tables));
		$client->flushAttributes();

		$config = $client->getConfig();

	  // - First backup index data
	  // Lets copy index one by one with freeze
		$totalTableSize = 0;
		$totalTableCount = 0;
		println(LogLevel::Info, 'Backing up tables...');
		foreach ($tables as $index => $type) {
		  // We will have no directory for distributed indexes and so should not back it up
			if ($type === 'distributed') {
				println(
					LogLevel::Info,
					'  ' . $index . ' ('  . $type . ')...'
				);
				println(LogLevel::Info, '  ' . colored('SKIP', TextColor::LightYellow));
				continue;
			}

			$files = $client->freeze($index);
			$tableSize = $storage::calculateFilesSize($files);
			$totalTableSize += $tableSize;
			++$totalTableCount;
			metric('backup_table_size', $tableSize);
			println(
				LogLevel::Info,
				'  ' . $index . ' ('  . $type . ') [' . format_bytes($tableSize) . ']...'
			);

			$backupPath = $destination['data'] . DIRECTORY_SEPARATOR . $index;
			$storage->createDir(
				$backupPath,
				$config->dataDir . DIRECTORY_SEPARATOR . $index
			);

			$isOk = $storage->copyPaths($files, $backupPath);
			println(LogLevel::Info, '   ' . get_op_result($isOk));
			$result = $result && $isOk;
			$client->unfreeze($index);
		}

		if (false === $result) {
			metric('backup_no_permissions', 1);
			throw new \Exception(
				'Failed to make backup of tables. '
				. 'Please check that you have rights to access the source and destinations directories'
			);
		}
		metric('backup_total_count', $totalTableCount);
		metric('backup_total_size', $totalTableSize);

		static::fsync();

	  // 3. Done
		$t = round(microtime(true) - $t, 2);
		metric('backup_time', $t);
		println(LogLevel::Info, 'You can find backup here: ' . $destination['root']);
		println(LogLevel::Info, 'Elapsed time: ' . $t . 's');
	}

  /**
   * This method executes restore flow and moving backed up files to original destination
   *
   * @param FileStorage $storage
   * @return void
   */
	protected static function restore(FileStorage $storage): void {
		println(LogLevel::Info, 'Starting to restore...');
		$t = microtime(true);
		$backup = $storage->getBackupPaths();

	  // First, validate that searchd is not running, otherwise we cannot replace directories
		if (Searchd::isRunning()) {
			metric('restore_searchd_running', 1);
			throw new \Exception(
				'Cannot initiate the restore process due to searchd daemon is running.'
			);
		}

	  /** @var ?ManticoreConfig $config */
		$config = null;

	  // Second, lets check that destination is available to move files and we have nothing there
		static::validateRestore(
			$storage, $backup['config'], function (\SplFileInfo $file) use (&$config): bool {
				$fileName = $file->getFilename();
				// TODO: remove this hardcode, we can store the path to config when doing backup
				if (strpos('manticore.conf|manticore.conf.zst', $fileName) !== false) {
					$config = new ManticoreConfig($file->getRealPath());
				}

				return false;
			}
		);

		if (!isset($config)) {
			metric('restore_no_config_file', 1);
			throw new \Exception('Failed to find config file in original backup');
		}

		static::validateRestore($storage, $backup['state']);

	  // Valdiate indexes
		if (!is_dir($config->dataDir)) {
			metric('restore_config_dir_missing', 1);
			throw new \Exception('Failed to find data dir, make sure that it exists: ' . $config->dataDir);
		}

		$hasFiles = $storage::hasFiles($config->dataDir);
		if ($hasFiles) {
			throw new \Exception('The data dir to restore is not empty: ' . $config->dataDir);
		}

	  // All checks are done here, so we can safely start to move all files
	  // Restore configs first
		println(LogLevel::Info, 'Restoring config files...');
		$configIterator = $storage->getFileIterator($backup['config']);
		$isOk = true;
	  /** @var \SplFileInfo $file */
		foreach ($configIterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			$from = $file->getRealPath();
			$to = dirname($storage->getOriginRealPath($from));
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$isOk = $isOk && $storage->copyPaths([$from], $to);
		}
		println(LogLevel::Info, '  config files - ' . get_op_result($isOk));

	  // Now restore states
		println(LogLevel::Info, 'Restoring state files...');
		$isOk = static::restoreState($storage, $backup['state']);
		println(LogLevel::Info, '  state files - ' . get_op_result($isOk));

	  // And the final piece – indexes (data dir)
		println(LogLevel::Info, 'Restoring data files...');
		$isOk = static::restoreData($storage, $config, $backup['data']);
		println(LogLevel::Info, '  tables\' files - ' . get_op_result($isOk));

	  // Done
		$t = round(microtime(true) - $t, 2);
		metric('restore_time', $t);

		println(LogLevel::Info, "The backup '{$backup['root']}' was successfully restored.");
		println(LogLevel::Info, 'Elapsed time: ' . $t . 's');
	}

	/**
	 * @param FileStorage $storage
	 * @param string $path
	 * @return bool
	 */
	protected static function restoreState(FileStorage $storage, string $path): bool {
		$stateIterator = $storage->getFileIterator($path);
		$isOk = true;
	  /** @var \SplFileInfo $file */
		foreach ($stateIterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			$from = $file->getRealPath();
			$to = dirname($storage->getOriginRealPath($from));
			if (!is_dir($to)) {
				FileStorage::createDir($to, $from);
			}
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$isOk = $isOk && $storage->copyPaths([$from], $to);
		}

		return $isOk;
	}

	/**
	 * @param FileStorage $storage
	 * @param ManticoreConfig $config
	 * @param string $path
	 * @return bool
	 */
	protected static function restoreData(FileStorage $storage, ManticoreConfig $config, string $path): bool {
		$dataIterator = $storage->getFileIterator($path);
		$isOk = true;
	  /** @var \SplFileInfo $file */
		foreach ($dataIterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			$from = $file->getRealPath();
			$to = $config->dataDir . DIRECTORY_SEPARATOR . dirname($storage->getOriginRealPath($file->getRealPath()));
			if (!is_dir($to)) {
				$storage->createDir($to, $file->getPath(), true);
			}
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$isOk = $isOk && $storage->copyPaths([$from], $to);
		}

		return $isOk;
	}

  /**
   * Store versions for current bakcup in file of root directory passed as argument
   *
   * @param array<string,string> $versions
   * @param string $backupDir
   *  Directory where we will put versions.json file with verions
   * @return bool
   *  Result of storing versions
   */
	protected static function storeVersions(array $versions, string $backupDir): bool {
		$filePath = $backupDir . DIRECTORY_SEPARATOR . 'versions.json';
		return !!file_put_contents($filePath, json_encode($versions));
	}

	/**
	 * Read the versions from file
	 *
	 * @param  string $backupDir
	 * @return array<string,string>
	 */
	protected static function readVersions(string $backupDir): array {
		$filePath = $backupDir . DIRECTORY_SEPARATOR . 'versions.json';
		$data = file_get_contents($filePath);
		if ($data === false) {
			throw new \RuntimeException("Failed to read versions file: $filePath");
		}
		/** @var array<string,string> $versions */
		$versions = json_decode($data, true);
		if (!$versions) {
			throw new \RuntimeException("Failed to decode versions from file: $filePath");
		}

		return $versions;
	}

  /**
   * Validate and adapt tables to final format or exit with error
   *
   * @param array<string> $tables
   *  list of tables to validate that they exist
   * @param ManticoreClient $client
   *  initialized client to interact with
   * @return array{0: bool, 1: array<string,string>}
   *  flag that points if we are in all backup state and list of tables after validation
   */
	public static function validateTables(array $tables, ManticoreClient $client): array {
		$result = [];
		$allTables = $client->getTables();
		$allTableNames = array_keys($allTables);
		if ($tables) {
			$indexDiff = array_diff($tables, $allTableNames);
			if ($indexDiff) {
				throw new \InvalidArgumentException('Can\'t find some of the tables: ' . implode(', ', $indexDiff));
			}
			unset($indexDiff);
			$result = array_intersect_key(
				$allTables,
				array_flip($tables)
			);
		} else {
			$result = $allTables;
		}

		$isAll = !$tables || !array_diff($allTableNames, $tables);
	  // If we have no tables in our database – we should stop
		if (!$result) {
			throw new \RuntimeException('You have no tables to backup.');
		}
		return [$isAll, $result];
	}

  /**
   * This functions flushes buffers and attributes to the disk to make sure
   * that we are safe after backup is done
   */
	protected static function fsync(): void {
		// Do nothing in windows
		if (OS::isWindows()) {
			return;
		}
		println(LogLevel::Info, 'Running sync');
		$t = microtime(true);
		system('sync');
		$t = round(microtime(true) - $t, 2);
		metric('fsync_time', $t);
		println(LogLevel::Info, ' ' . get_op_result(true));
	}

  /**
   * This method helps us to reduce copy paste and validate
   *  required path of original backup: config, state - etc
   *
   * @param FileStorage $storage
   * @param string $backupPath
   * @param ?\Closure $fn
   *  It receives SplFileInfo as argument
   *  It returns true for skip next logic in cycle or false otherwise
   * @return void
   * @throws \Exception
   */
	protected static function validateRestore(FileStorage $storage, string $backupPath, ?\Closure $fn = null): void {
		$fileIterator = $storage->getSortedFileIterator($backupPath);
	  /** @var \SplFileInfo $file */
		foreach ($fileIterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			if (isset($fn)) {
				$result = $fn($file);
			  // If we returned true, we continue
				if (true === $result) {
					continue;
				}
			}

			$preservedPath = $storage->getOriginRealPath($file->getRealPath());

			if (is_file($preservedPath)) {
				metric('restore_target_exists', 1);
				throw new \Exception('Destination file already exists: ' . $preservedPath);
			}
		}
	}
}
