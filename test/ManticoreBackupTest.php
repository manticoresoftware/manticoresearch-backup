<?php declare(strict_types=1);

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


class ManticoreBackupTest extends SearchdTestCase {
	public function testStoreAllTables(): void {
		[$config, $storage, $backupDir] = $this->initTestEnv();
		$client = new ManticoreClient([$config]);

	  // Backup of all tables
		ManticoreBackup::run('store', [$client, $storage, []]);
		$this->assertBackupIsOK(
			$client,
			$backupDir,
			[
				'movie' => 'rt',
				'people' => 'rt',
				'people_pq' => 'percolate',
				'people_dist_local' => 'distributed',
				'people_dist_agent' => 'distributed',
			]
		);
	}

	public function testStoreAllTablesToSymlinkPath(): void {
		[$config, $storage, $backupDir] = $this->initTestEnv();
		$uniq = uniqid();
		$tmpDir = $storage->getTmpDir();
		$baseDir = basename($backupDir);
		$realPath = "$tmpDir-$uniq/first/second/$baseDir";
		mkdir($realPath, 0755, true);
		rename($backupDir, $realPath);
		shell_exec("ln -s '$realPath' '$backupDir'");

		$client = new ManticoreClient([$config]);

	  // Backup of all tables
		ManticoreBackup::run('store', [$client, $storage, []]);
		$this->assertBackupIsOK(
			$client,
			$backupDir,
			[
				'movie' => 'rt',
				'people' => 'rt',
				'people_pq' => 'percolate',
				'people_dist_local' => 'distributed',
				'people_dist_agent' => 'distributed',
			]
		);
		unlink($backupDir);
		rename($realPath, $backupDir);
	}

	public function testStoreOnlyTwoTables(): void {
		[$config, $storage, $backupDir] = $this->initTestEnv();
		$client = new ManticoreClient([$config]);

		ManticoreBackup::run('store', [$client, $storage, ['movie', 'people']]);
		$this->assertBackupIsOK($client, $backupDir, ['movie' => 'rt', 'people' => 'rt']);
	}

	public function testStoreOnlyOneIndex(): void {
		[$config, $storage, $backupDir] = $this->initTestEnv();
		$client = new ManticoreClient([$config]);

	  // Backup only one
		ManticoreBackup::run('store', [$client, $storage, ['people']]);
		$this->assertBackupIsOK($client, $backupDir, ['people' => 'rt']);
	}

	public function testStoreUnexistingIndexOnly(): void {
		[$config, $storage] = $this->initTestEnv();
		$client = new ManticoreClient([$config]);

		$this->expectException(InvalidArgumentException::class);
		ManticoreBackup::run('store', [$client, $storage, ['unknown']]);
	}

	public function testStoreExistingAndUnexistingTablesTogether(): void {
		[$config, $storage] = $this->initTestEnv();
		$client = new ManticoreClient([$config]);

		$this->expectException(InvalidArgumentException::class);
		ManticoreBackup::run('store', [$client, $storage, ['people', 'unknown']]);
	}

	// Skip this test for now because it does not work on Alpine due to some issue and bug
	// https://github.com/alpinelinux/docker-alpine/issues/156
	// public function testStoreFailsInCaseNoPermissionsToWriteTargetDir(): void {
	// 	[$config, $storage, $backupDir] = $this->initTestEnv();
	// 	$client = new ManticoreClient([$config]);

	//   // Create read only dir and modify it in FileStorage
	// 	$roBackupDir = '/mnt' . $backupDir . '-ro';
	// 	$this->mount($backupDir, $roBackupDir, 'ro');
	// 	$storage->setTargetDir($roBackupDir);

	//   // Run test
	// 	$this->expectException(InvalidPathException::class);
	// 	ManticoreBackup::run('store', [$client, $storage, ['people']]);
	// }

	public function testStoreAbortedOnSignalCaught(): void {
		[$config, $storage] = $this->initTestEnv();
		$client = new ManticoreMockedClient([$config]);
		$client->setTimeout(1);
		$client->setTimeoutFn(
			function () use ($client, $storage): bool {
				static $count = 0;
				++$count;
				if ($count < 3) {
					return false;
				}

				$fn = $client->getSignalHandlerFn($storage);
				$fn(15);

				return true;
			}
		);

	  // Run test
		$this->expectException(Exception::class);
		ManticoreBackup::run('store', [$client, $storage, ['people', 'movie']]);
		$this->expectOutputRegex('/Caught signal 15/');
		$this->expectOutputRegex('/Unfreezing all tables/');
		$this->expectOutputRegex('/movie...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_dist_agent...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_dist_local...' . PHP_EOL . '[^\r\n]+✓ OK/');
		$this->expectOutputRegex('/people_pq...' . PHP_EOL . '[^\r\n]+✓ OK/');

		$backupPaths = $storage->getBackupPaths();
		$this->assertDirectoryDoesNotExist($backupPaths['root']);
	}

	public function testStoreAbortedOnPermissionChanges(): void {
		[$config, $storage] = $this->initTestEnv();
		$client = new ManticoreMockedClient([$config]);

		$client->setTimeout(1);
		$client->setTimeoutFn(
			function () use ($storage): bool {
				static $count = 0;
				++$count;
				if ($count < 3) {
					return false;
				}

				// Get current backup paths and make it read only after 1st index copied
				$backupPaths = $storage->getBackupPaths();
				if (is_writable($backupPaths['root'])) {
					echo 'processing';

					$rwDataDir = $backupPaths['data'] . '-rw';
					rename($backupPaths['data'], $rwDataDir);

				  // Create read only dir and modify it in FileStorage
					$this->mount($rwDataDir, $backupPaths['root'], 'ro');
				}

				return false;
			}
		);

		$this->expectException(Throwable::class);
		ManticoreBackup::run('store', [$client, $storage, ['people', 'movie']]);

		$backupPaths = $storage->getBackupPaths();
		$this->assertDirectoryDoesNotExist($backupPaths['root']);
	}

  /**
   * Helper to initialize initial configuration for testing
   * @return array{0:ManticoreConfig,1:FileStorage,2:string}
   */
	public function initTestEnv(): array {
	  // Initialize all


		$tmpDir = FileStorage::getTmpDir();
		$backupDir = $tmpDir . DIRECTORY_SEPARATOR . 'backup-test-' . uniqid();
		mkdir($backupDir, 0755);

		$options = validate_args(
			[
				'backup-dir' => $backupDir,
			]
		);

		return [
			new ManticoreConfig($options['configs'][0]),
			new FileStorage($options['backup-dir']),
			$backupDir,
		];
	}

  /**
   * Helper function to assert that backup is done in proper mode
   *
   * @param ManticoreClient $client
   * @param string $backupDir
   * @param array<string,string> $tables
   * @return void
   */
	protected function assertBackupIsOK(ManticoreClient $client, string $backupDir, array $tables) {
		$dirs = glob($backupDir . DIRECTORY_SEPARATOR . '*');
		$this->assertIsArray($dirs);

		$basedir = $dirs[0];

		$config = $client->getConfig();

	  // Check that we created all required dirs
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'config');
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data');
		$this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'state');
		$this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'versions.json');

		$originConfigPath = $config->path;
		$targetConfigPath = $basedir . DIRECTORY_SEPARATOR . 'config' . $config->path;
		$this->assertFileExists($targetConfigPath);
		$this->assertOwnershipIsOK($originConfigPath, $targetConfigPath);

		$originSchemaPath = $config->schemaPath;
		$targetSchemaPath = $basedir . DIRECTORY_SEPARATOR . 'config' . $config->schemaPath;
		$this->assertFileExists($targetSchemaPath);
		$this->assertOwnershipIsOK($originSchemaPath, $targetSchemaPath);

	  // Validate consistency of stored tables
		foreach ($tables as $index => $type) {
		  // Distributed tables do not have directory to backup
			if ($type === 'distributed') {
				continue;
			}

			$originIndexDir = $config->dataDir . DIRECTORY_SEPARATOR . $index;
			$targetIndexDir = $basedir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $index;
			$this->assertDirectoryExists($targetIndexDir);

			$this->assertOwnershipIsOK($originIndexDir, $targetIndexDir, true);

		  // Remove lock file to fix issue with checksums validations cuz we do not move this file
			$lockFile = $config->dataDir . DIRECTORY_SEPARATOR .  $index . DIRECTORY_SEPARATOR . $index . '.lock';
			if (file_exists($lockFile)) {
				unlink($lockFile);
			}

			$this->assertEquals(
				FileStorage::getPathChecksum($config->dataDir . DIRECTORY_SEPARATOR .  $index),
				FileStorage::getPathChecksum($basedir . DIRECTORY_SEPARATOR . 'data'. DIRECTORY_SEPARATOR . $index)
			);
		}

	  // Check that the config file is valid
		$dstConf = $basedir . DIRECTORY_SEPARATOR . 'config' . $config->path;
		$this->assertEquals(
			FileStorage::getPathChecksum($dstConf),
			FileStorage::getPathChecksum($config->path)
		);

		$dstConf = $basedir . DIRECTORY_SEPARATOR . 'config' . $config->schemaPath;
		$this->assertEquals(
			FileStorage::getPathChecksum($dstConf),
			FileStorage::getPathChecksum($config->schemaPath)
		);

	  // State files
		[$isAll] = ManticoreBackup::validateTables(array_keys($tables), $client);
		$checkFn = $isAll ? 'assertFileExists' : 'assertFileDoesNotExist';
		foreach ($config->getStatePaths() as $statePath) {
			$destPath = $basedir . DIRECTORY_SEPARATOR . 'state' . $statePath;
			$this->$checkFn($destPath);
			if (!$isAll) {
				continue;
			}

			$this->assertOwnershipIsOK($statePath, $destPath);
			$this->assertEquals(
				FileStorage::getPathChecksum($statePath),
				FileStorage::getPathChecksum($destPath)
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
		if (!is_dir($target)) {
			mkdir($target, 0444, true);
		}
		shell_exec("mount '$source' '$target' -o 'bind,noload,$opt'");
		register_shutdown_function(
			function () use ($target): void {
				shell_exec("umount -f '$target'");
				shell_exec("rm -fr '$target'");
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
	protected int $timeoutSec = 0;
	protected Closure $timeoutFn;

  /**
   * Set delay timeout that we will use in case of calling freeze tables
   *
   * @param int $timeoutSec
   * @return static
   */
	public function setTimeout(int $timeoutSec): static {
		$this->timeoutSec = $timeoutSec;
		return $this;
	}

  /**
   * Set timeout function that will be called on each timeout
   * @param Closure $fn
   * @return static
   */
	public function setTimeoutFn(Closure $fn): static {
		$this->timeoutFn = $fn;
		return $this;
	}

  /**
   * @inheritdoc
   */
	public function freeze(array|string $tables): array {
		if ($this->timeoutSec > 0) {
			if (isset($this->timeoutFn)) {
				$fn = $this->timeoutFn;
				$shouldInterrupt = $fn();
				if ($shouldInterrupt) {
					throw new Exception('Interrupted');
				}
			}
			sleep($this->timeoutSec);
		}
		return parent::freeze($tables);
	}
}
