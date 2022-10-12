<?php declare(strict_types=1);

use Manticoresearch\Exception\InvalidPathException;
use Manticoresearch\Lib\FileStorage;
use Manticoresearch\Lib\ManticoreBackup;
use Manticoresearch\Lib\ManticoreClient;
use Manticoresearch\Lib\ManticoreConfig;
use Manticoresearch\Lib\Searchd;

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


class ManticoreBackupTest extends SearchdTestCase {
	public function testStoreAllTables(): void {
		[$Config, $Storage, $backup_dir] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

	  // Backup of all tables
		ManticoreBackup::store($Client, $Storage, []);
		$this->assertBackupIsOK(
			$Client,
			$backup_dir,
			[
				'movie' => 'rt',
				'people' => 'rt',
				'people_pq' => 'percolate',
				'people_dist_local' => 'distributed',
				'people_dist_agent' => 'distributed',
			]
		);
	}

	public function testStoreOnlyTwoTables(): void {
		[$Config, $Storage, $backup_dir] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

		ManticoreBackup::store($Client, $Storage, ['movie', 'people']);
		$this->assertBackupIsOK($Client, $backup_dir, ['movie' => 'rt', 'people' => 'rt']);
	}

	public function testStoreOnlyOneIndex(): void {
		[$Config, $Storage, $backup_dir] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

	  // Backup only one
		ManticoreBackup::store($Client, $Storage, ['people']);
		$this->assertBackupIsOK($Client, $backup_dir, ['people' => 'rt']);
	}

	public function testStoreUnexistingIndexOnly(): void {
		[$Config, $Storage] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

		$this->expectException(InvalidArgumentException::class);
		ManticoreBackup::store($Client, $Storage, ['unknown']);
	}

	public function testStoreExistingAndUnexistingTablesTogether(): void {
		[$Config, $Storage] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

		$this->expectException(InvalidArgumentException::class);
		ManticoreBackup::store($Client, $Storage, ['people', 'unknown']);
	}

	public function testStoreFailsInCaseNoPermissionsToWriteTargetDir(): void {
		[$Config, $Storage, $backup_dir] = $this->initTestEnv();
		$Client = new ManticoreClient($Config);

	  // Create read only dir and modify it in FileStorage
		$ro_backup_dir = '/mnt' . $backup_dir . '-ro';
		$this->mount($backup_dir, $ro_backup_dir, 'ro');
		$Storage->setTargetDir($ro_backup_dir);

	  // Run test
		$this->expectException(InvalidPathException::class);
		ManticoreBackup::store($Client, $Storage, ['people']);
	}

	public function testStoreAbortedOnSignalCaught(): void {
		[$Config, $Storage] = $this->initTestEnv();
		$Client = new ManticoreMockedClient($Config);
		$Client->setTimeout(1);
		$Client->setTimeoutFn(
			function () use ($Client, $Storage): bool {
				static $count = 0;
				++$count;
				if ($count < 3) {
					return false;
				}

				$fn = $Client->getSignalHandlerFn($Storage);
				$fn(15);

				return true;
			}
		);

	  // Run test
		$this->expectException(Exception::class);
		ManticoreBackup::store($Client, $Storage, ['people', 'movie']);
		$this->expectOutputRegex('/Caught signal 15/');
		$this->expectOutputRegex('/Unfreezing all tables/');
		$this->expectOutputRegex('/movie...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_dist_agent...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_dist_local...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_pq...' . PHP_EOL . '[^\r\n]+✓ OK/');

		$backup_paths = $Storage->getBackupPaths();
		$this->assertDirectoryDoesNotExist($backup_paths['root']);
	}

	public function testStoreAbortedOnPermissionChanges(): void {
		[$Config, $Storage] = $this->initTestEnv();
		$Client = new ManticoreMockedClient($Config);

		$Client->setTimeout(1);
		$Client->setTimeoutFn(
			function () use ($Storage): bool {
				static $count = 0, $is_processed = false;
				++$count;
				if ($count < 3) {
					return false;
				}

				if (!$is_processed) {
					echo 'processing';
				  // Get current backup paths and make it read only after 1st index copied
					$backup_paths = $Storage->getBackupPaths();

					$rw_data_dir = $backup_paths['data'] . '-rw';
					rename($backup_paths['data'], $rw_data_dir);

				  // Create read only dir and modify it in FileStorage
					$this->mount($rw_data_dir, $backup_paths['root'], 'ro');
					$is_processed = true;
				}

				return false;
			}
		);

		$this->expectException(Throwable::class);
		ManticoreBackup::store($Client, $Storage, ['people', 'movie']);

		$backup_paths = $Storage->getBackupPaths();
		$this->assertDirectoryDoesNotExist($backup_paths['root']);
	}

  /**
   * Helper to initialize initial configuration for testing
   * @return array{0:ManticoreConfig,1:FileStorage,2:string}
   */
	public function initTestEnv(): array {
	  // Initialize all
		Searchd::init();

		$tmp_dir = FileStorage::getTmpDir();
		$backup_dir = $tmp_dir . DIRECTORY_SEPARATOR . 'backup-test-' . uniqid();
		mkdir($backup_dir, 0755);

		$options = validate_args(
			[
				'backup-dir' => $backup_dir,
			]
		);

		return [
			new ManticoreConfig($options['config']),
			new FileStorage($options['backup-dir']),
			$backup_dir,
		];
	}

  /**
   * Helper function to assert that backup is done in proper mode
   *
   * @param ManticoreClient $Client
   * @param string $backup_dir
   * @param array<string,string> $tables
   * @return void
   */
	protected function assertBackupIsOK(ManticoreClient $Client, string $backup_dir, array $tables) {
		$dirs = glob($backup_dir . DIRECTORY_SEPARATOR . '*');
		$this->assertIsArray($dirs);
	  // @phpstan-ignore-next-line
		$basedir = $dirs[0];

		$Config = $Client->getConfig();

	  // Check that we created all required dirs
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'config');
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data');
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'state');
		$this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'versions.json');

		$origin_config_path = $Config->path;
		$target_config_path = $basedir . DIRECTORY_SEPARATOR . 'config' . $Config->path;
		$this->assertFileExists($target_config_path);
		$this->assertOwnershipIsOK($origin_config_path, $target_config_path);

		$origin_schema_path = $Config->schema_path;
		$target_schema_path = $basedir . DIRECTORY_SEPARATOR . 'config' . $Config->schema_path;
		$this->assertFileExists($target_schema_path);
		$this->assertOwnershipIsOK($origin_schema_path, $target_schema_path);

	  // Validate consistency of stored tables
		foreach ($tables as $index => $type) {
		  // Distributed tables do not have directory to backup
			if ($type === 'distributed') {
				continue;
			}

			$origin_index_dir = $Config->data_dir . DIRECTORY_SEPARATOR . $index;
			$target_index_dir = $basedir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $index;
			$this->assertDirectoryExists($target_index_dir);

			$this->assertOwnershipIsOK($origin_index_dir, $target_index_dir, true);

		  // Remove lock file to fix issue with checksums validations cuz we do not move this file
			$lock_file = $Config->data_dir . DIRECTORY_SEPARATOR .  $index . DIRECTORY_SEPARATOR . $index . '.lock';
			if (file_exists($lock_file)) {
				unlink($lock_file);
			}

			$this->assertEquals(
				FileStorage::getPathChecksum($Config->data_dir . DIRECTORY_SEPARATOR .  $index),
				FileStorage::getPathChecksum($basedir . DIRECTORY_SEPARATOR . 'data'. DIRECTORY_SEPARATOR . $index)
			);
		}

	  // Check that the config file is valid
		$dst_conf = $basedir . DIRECTORY_SEPARATOR . 'config' . $Config->path;
		$this->assertEquals(
			FileStorage::getPathChecksum($dst_conf),
			FileStorage::getPathChecksum($Config->path)
		);

		$dst_conf = $basedir . DIRECTORY_SEPARATOR . 'config' . $Config->schema_path;
		$this->assertEquals(
			FileStorage::getPathChecksum($dst_conf),
			FileStorage::getPathChecksum($Config->schema_path)
		);

	  // State files
		[$is_all] = ManticoreBackup::validateTables(array_keys($tables), $Client);
		$check_fn = $is_all ? 'assertFileExists' : 'assertFileDoesNotExist';
		foreach ($Config->getStatePaths() as $state_path) {
			$dest_path = $basedir . DIRECTORY_SEPARATOR . 'state' . $state_path;
			$this->$check_fn($dest_path);
			if (!$is_all) {
				continue;
			}

			$this->assertOwnershipIsOK($state_path, $dest_path);
			$this->assertEquals(
				FileStorage::getPathChecksum($state_path),
				FileStorage::getPathChecksum($dest_path)
			);
		}
	}

  /**
   * Helper function to validate that ownership is successfuly transferred
   *
   * @param string $source
   * @param string $target
   * @return void
   */
	protected function assertOwnershipIsOK(string $source, string $target, bool $recursive = true): void {
		$this->assertEquals(fileperms($source), fileperms($target));
		$this->assertEquals(fileowner($source), fileowner($target));
		$this->assertEquals(filegroup($source), filegroup($target));

	  // In case we pass dir and have recursive = true, check all folder
		if (!$recursive || !is_dir($target)) {
			return;
		}

		$files = scandir($target);
		if (false === $files) {
			throw new Exception("Failed to scan dir '$target' for files");
		}

		foreach (array_slice($files, 2) as $file) {
			$this->assertOwnershipIsOK(
				$source . DIRECTORY_SEPARATOR . $file,
				$target . DIRECTORY_SEPARATOR . $file
			);
		}
	}

  /**
   * This is helper function to do mount in readonly mode and clean ups
   *
   * @param string $source
   *  Source directory that will be used as mount source
   * @param string $target
   *  Which directory to bind
   * @param string $opt
   */
	protected function mount(string $source, string $target, string $opt): void {
		mkdir($target, 0444, true);
		shell_exec("mount '$source' '$target' -o 'bind,noload,$opt'");
		register_shutdown_function(
			function () use ($target): void {
				shell_exec("umount '$target'");
			}
		);
	}
}

/**
 * We use mocked client class to test some rare cases on interruptions
 */
// @codingStandardsIgnoreStart
class ManticoreMockedClient extends ManticoreClient {
  // @codingStandardsIgnoreEnd
	protected int $timeout_sec = 0;
	protected Closure $timeout_fn;

  /**
   * Set delay timeout that we will use in case of calling freeze tables
   *
   * @param int $timeout_sec
   * @return static
   */
	public function setTimeout(int $timeout_sec): static {
		$this->timeout_sec = $timeout_sec;
		return $this;
	}

  /**
   * Set timeout function that will be called on each timeout
   * @param Closure $fn
   * @return static
   */
	public function setTimeoutFn(Closure $fn): static {
		$this->timeout_fn = $fn;
		return $this;
	}

  /**
   * @inheritdoc
   */
	public function freeze(array|string $tables): array {
		if ($this->timeout_sec > 0) {
			if (isset($this->timeout_fn)) {
				$fn = $this->timeout_fn;
				$should_interrupt = $fn();
				if ($should_interrupt) {
					throw new Exception('Interrupted');
				}
			}
			sleep($this->timeout_sec);
		}
		return parent::freeze($tables);
	}
}
