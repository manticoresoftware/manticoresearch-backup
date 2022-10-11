<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

/**
 * This class is used to initialize config, parse it and launch the backup process
 */
class ManticoreBackup {
	const VERSION = '0.0.1';
	const MIN_PHP_VERSION = '8.1';

  /**
   * Store the wanted tables in backup dir as backup
   *
   * @param ManticoreClient $Client
   *  Initialized client to interract with manticore search daemon
   * @param FileStorage $Storage
   *  The instance of the storage with initialize directories to use
   * @param array<string> $tables
   *  List of tables to store. In case if its empty array we store all tables
   * @return void
   * @throws RuntimeException
   */
	public static function store(ManticoreClient $Client, FileStorage $Storage, array $tables = []): void {
		println(LogLevel::Info, 'Starting the backup...');
		$t = microtime(true);
		$destination = $Storage->getBackupPaths();

	  // First store current versions in file
		$versions = $Client->getVersions();
		$is_ok = static::storeVersions($versions, $destination['root']);
		if (false === $is_ok) {
			throw new InvalidPathException('Failed to save the versions in "' . $destination['root'] . '"');
		}

	  // TODO: add progress bar / backup status reporting

	  // If we have no tables passed we should to query the client and get all tables we have
		[$is_all, $tables] = static::validateTables($tables, $Client);

	  // - backup config files
		println(LogLevel::Info, 'Backing up config files...');
		$is_ok = $Storage->copyPaths(
			[
				$Client->getConfig()->path,
				$Client->getConfig()->schema_path,
			], $destination['config'], true
		);
		println(LogLevel::Info, '  config files - ' . get_op_result($is_ok));

		$result = true;

	  // We back up state first because they are usually small enough
		if ($is_all) {
		  // - Backup global state files
			println(LogLevel::Info, 'Backing up global state files...');
			$files = $Client->getConfig()->getStatePaths();
			$is_ok = $Storage->copyPaths($files, $destination['state'], true);
			println(LogLevel::Info, '  global state files – ' . get_op_result($is_ok));

		  // @phpstan-ignore-next-line
			$result = $result && $is_ok;
		}

	  // - Lock all tables to make sure that we will have no new data there
	  // Make sure that in case any exception or whatever we will unlock all indexes
		$is_done = false;
		$unfreeze_fn = function (ManticoreClient $Client) use (&$is_done) {
			if ($is_done) { // @phpstan-ignore-line
				return;
			}

			if (!Searchd::isRunning()) {
				return;
			}

			$Client->unfreezeAll();
		};
		register_shutdown_function($unfreeze_fn, $Client);

	  // And run FLUSH ATTRIBUTES
	  // We do lock twice just to keep logic for crawling one by one for each index
		$Client->freeze(array_keys($tables));
		$Client->flushAttributes();

		$Config = $Client->getConfig();

	  // - First backup index data
	  // Lets copy index one by one with freeze
		println(LogLevel::Info, 'Backing up tables...');
		foreach ($tables as $index => $type) {
			$files = $Client->freeze($index);
			println(
				LogLevel::Info,
				'  ' . $index . ' ('  . $type . ') [' . format_bytes($Storage::calculateFilesSize($files)) . ']...'
			);

		  // We will have no directory for distributed indexes and so should not back it up
			if ($type === 'distributed') {
				  println(LogLevel::Info, '  ' . colored('SKIP', TextColor::LightYellow));
				  continue;
			}

			$backup_path = $destination['data'] . DIRECTORY_SEPARATOR . $index;
			$Storage->createDir(
				$backup_path,
				$Config->data_dir . DIRECTORY_SEPARATOR . $index
			);

			$is_ok = $Storage->copyPaths($files, $backup_path);
			println(LogLevel::Info, '   ' . get_op_result($is_ok));
			$result = $result && $is_ok;
			$Client->unfreeze($index);
		}

		$is_done = true;

		if (false === $result) {
			throw new Exception(
				'Failed to make backup of tables. '
				. 'Please check that you have rights to access the source and destinations directories'
			);
		}

		static::fsync();

	  // 3. Done
		$t = round(microtime(true) - $t, 2);

		println(LogLevel::Info, 'You can find backup here: ' . $destination['root']);
		println(LogLevel::Info, 'Elapsed time: ' . $t . 's');
	}

  /**
   * This method executes restore flow and moving backed up files to original destination
   *
   * @param FileStorage $Storage
   * @return void
   */
	public static function restore(FileStorage $Storage): void {
		println(LogLevel::Info, 'Starting to restore...');
		$t = microtime(true);
		$backup = $Storage->getBackupPaths();

	  // First, validate that searchd is not running, otherwise we cannot replace directories
		if (Searchd::isRunning()) {
			throw new Exception(
				'Cannot initiate the restore process due to searchd daemon is running.'
			);
		}

	  /** @var ?ManticoreConfig $Config */
		$Config = null;

	  // Second, lets check that destination is available to move files and we have nothing there
		static::validateRestore(
			$Storage, $backup['config'], function (SplFileInfo $File) use (&$Config): bool {
			// TODO: remove this hardcode, we can store the path to config when doing backup
				if ($File->getFilename() === 'manticore.conf') {
					$Config = new ManticoreConfig($File->getRealPath());
				}

				return false;
			}
		);

		if (!isset($Config)) {
			throw new Exception('Failed to find config file in original backup');
		}

		static::validateRestore($Storage, $backup['state']);

	  // Valdiate indexes
		if (!is_dir($Config->data_dir)) {
			throw new Exception('Failed to find data dir, make sure that it exists: ' . $Config->data_dir);
		}

		$DataIterator = $Storage->getFileIterator($Config->data_dir);
		$has_files = $DataIterator->valid() && iterator_count($DataIterator) > 2;

		if ($has_files) {
			throw new Exception('The data dir to restore is not empty: ' . $Config->data_dir);
		}

	  // All checks are done here, so we can safely start to move all files
	  // Restore configs first
		println(LogLevel::Info, 'Restoring config files...');
		$ConfigIterator = $Storage->getFileIterator($backup['config']);
		$is_ok = true;
	  /** @var SplFileInfo $File */
		foreach ($ConfigIterator as $File) {
			if (!$File->isFile()) {
				continue;
			}

			$from = $File->getRealPath();
			$to = dirname($Storage->getOriginRealPath($from));
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$is_ok = $is_ok && $Storage->copyPaths([$from], $to);
		}
		println(LogLevel::Info, '  config files - ' . get_op_result($is_ok));

	  // Now restore states
		println(LogLevel::Info, 'Restoring state files...');
		$StateIterator = $Storage->getFileIterator($backup['state']);
		$is_ok = true;
	  /** @var SplFileInfo $File */
		foreach ($StateIterator as $File) {
			if (!$File->isFile()) {
				continue;
			}

			$from = $File->getRealPath();
			$to = dirname($Storage->getOriginRealPath($from));
			if (!is_dir($to)) {
				FileStorage::createDir($to, $from);
			}
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$is_ok = $is_ok && $Storage->copyPaths([$from], $to);
		}
		println(LogLevel::Info, '  config files - ' . get_op_result($is_ok));

	  // And the final piece – indexes (data dir)
		println(LogLevel::Info, 'Restoring data files...');
		$DataIterator = $Storage->getFileIterator($backup['data']);
		$is_ok = true;
	  /** @var SplFileInfo $File */
		foreach ($DataIterator as $File) {
			if (!$File->isFile()) {
				continue;
			}

			$from = $File->getRealPath();
			$to = $Config->data_dir . DIRECTORY_SEPARATOR . dirname($Storage->getOriginRealPath($File->getRealPath()));
			if (!is_dir($to)) {
				$Storage->createDir($to, $File->getPath(), true);
			}
			println(LogLevel::Debug, '  ' . $from . ' -> ' . $to);

			$is_ok = $is_ok && $Storage->copyPaths([$from], $to);
		}
		println(LogLevel::Info, '  config files - ' . get_op_result($is_ok));


	  // Done
		$t = round(microtime(true) - $t, 2);

		println(LogLevel::Info, "The backup '{$backup['root']}' was successfully restored.");
		println(LogLevel::Info, 'Elapsed time: ' . $t . 's');
	}

  /**
   * Store versions for current bakcup in file of root directory passed as argument
   *
   * @param array<string,string> $versions
   * @param string $backup_dir
   *  Directory where we will put versions.json file with verions
   * @return bool
   *  Result of storing versions
   */
	protected static function storeVersions(array $versions, string $backup_dir): bool {
		$file_path = $backup_dir . DIRECTORY_SEPARATOR . 'versions.json';
		return !!file_put_contents($file_path, json_encode($versions));
	}

  /**
   * Validate and adapt tables to final format or exit with error
   *
   * @param array<string> $tables
   *  list of tables to validate that they exist
   * @param ManticoreClient $Client
   *  initialized client to interact with
   * @return array{0: bool, 1: array<string,string>}
   *  flag that points if we are in all backup state and list of tables after validation
   */
	public static function validateTables(array $tables, ManticoreClient $Client): array {
		$result = [];
		$all_tables = $Client->getTables();
		$all_table_names = array_keys($all_tables);
		if ($tables) {
			$index_diff = array_diff($tables, $all_table_names);
			if ($index_diff) {
				throw new InvalidArgumentException('Can\'t find some of the tables: ' . implode(', ', $index_diff));
			}
			unset($index_diff);
			$result = array_intersect_key(
				$all_tables,
				array_flip($tables)
			);
		} else {
			$result = $all_tables;
		}

		$is_all = !$tables || !array_diff($all_table_names, $tables);
	  // If we have no tables in our database – we should stop
		if (!$result) {
			throw new RuntimeException('You have no tables to backup.');
		}
		return [$is_all, $result];
	}

  /**
   * This functions flushes buffers and attributes to the disk to make sure
   * that we are safe after backup is done
   */
	protected static function fsync(): void {
		println(LogLevel::Info, 'Running sync');
		system('sync');
		println(LogLevel::Info, ' ' . get_op_result(true));
	}

  /**
   * This method helps us to reduce copy paste and validate
   *  required path of original backup: config, state - etc
   *
   * @param FileStorage $Storage
   * @param string $backup_path
   * @param ?Closure $fn
   *  It receives SplFileInfo as argument
   *  It returns true for skip next logic in cycle or false otherwise
   * @return void
   * @throws Exception
   */
	protected static function validateRestore(FileStorage $Storage, string $backup_path, ?Closure $fn = null): void {
		$FileIterator = $Storage->getFileIterator($backup_path);
	  /** @var SplFileInfo $File */
		foreach ($FileIterator as $File) {
			if (!$File->isFile()) {
				continue;
			}

			if (isset($fn)) {
				$result = $fn($File);
			  // If we returned true, we continue
				if (true === $result) {
					continue;
				}
			}

			$preserved_path = $Storage->getOriginRealPath($File->getRealPath());
			if (is_file($preserved_path)) {
				throw new Exception('Destination file already exists: ' . $preserved_path);
			}
		}
	}
}
