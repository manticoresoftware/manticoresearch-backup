<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Backup\Lib\Searchd;
use PHPUnit\Framework\TestCase;

class ManticoreBackupRestoreTest extends TestCase {
	protected static string $backupDir;
	protected static ManticoreConfig $config;

	public static function setUpBeforeClass(): void {
		static::$backupDir = FileStorage::getTmpDir() . DIRECTORY_SEPARATOR . 'restore-test';
		mkdir(static::$backupDir, 0777);

		SearchdTestCase::setUpBeforeClass();

		static::$config = new ManticoreConfig(Searchd::getConfigPath());
		$client = new ManticoreClient([static::$config]);

		$fileStorage = new FileStorage(static::$backupDir);
		ManticoreBackup::run('store', [$client, $fileStorage]);
		SearchdTestCase::tearDownAfterClass();
	}

	public function tearDown(): void {
		$paths = [
			static::$config->path,
			static::$config->schemaPath,
			static::$config->dataDir,
			...static::$config->getStatePaths(),
		];

		foreach ($paths as $path) {
			$path = rtrim($path, DIRECTORY_SEPARATOR);
			$pathBak = $path . '.bak';
			if (!file_exists($pathBak)) {
				continue;
			}

			if (file_exists($path)) {
				if (is_file($pathBak) || is_link($pathBak)) {
					unlink($pathBak);
				} else {
					FileStorage::deleteDir($pathBak);
				}
			} else {
				shell_exec("mv '$pathBak' '$path'");
			}
		}
	}

	public function testRestoreFailedWhenSearchdLaunched(): void {
		[, $fileStorage] = $this->initTestEnv();

		SearchdTestCase::setUpBeforeClass();
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot initiate the restore process due to searchd daemon is running.');
		ManticoreBackup::run('restore', [$fileStorage]);
		SearchdTestCase::tearDownAfterClass();
	}

	public function testRestoreFailedWhenOriginalConfigExists(): void {
		SearchdTestCase::tearDownAfterClass();

		[, $fileStorage] = $this->initTestEnv();

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Destination file already exists: .*?$/');
		ManticoreBackup::run('restore', [$fileStorage]);
	}

	public function testRestoreFailedWhenOriginalSchemaExists(): void {
		SearchdTestCase::tearDownAfterClass();
		$this->safeDelete(static::$config->path);

		[, $fileStorage] = $this->initTestEnv();
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^Destination file already exists: .*?$/');
		ManticoreBackup::run('restore', [$fileStorage]);
	}

	public function testRestoreFailedWhenDataDirIsNotEmpty(): void {
		SearchdTestCase::tearDownAfterClass();

		$this->safeDelete(static::$config->path);
		$this->safeDelete(static::$config->schemaPath);

		[, $fileStorage] = $this->initTestEnv();
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/^The data dir to restore is not empty: .*?$/');
		ManticoreBackup::run('restore', [$fileStorage]);
	}

	// We do not have state now, cuz docker changed
	// public function testRestoreFailedWhenStatePathExists(): void {
	// 	SearchdTestCase::tearDownAfterClass();

	// 	$this->safeDelete(static::$config->path);
	// 	$this->safeDelete(static::$config->schemaPath);
	// 	$this->safeDelete(static::$config->dataDir);

	// 	[, $fileStorage] = $this->initTestEnv();
	// 	$this->expectException(Exception::class);
	// 	$this->expectExceptionMessageMatches('/^Destination file already exists: .*?$/');
	// 	ManticoreBackup::run('restore', [$fileStorage]);
	// }


	public function testRestoreIsOK(): void {
		SearchdTestCase::tearDownAfterClass();
		$this->safeDelete(static::$config->path);
		$this->safeDelete(static::$config->schemaPath);
		$this->safeDelete(static::$config->dataDir);
		array_map($this->safeDelete(...), static::$config->getStatePaths());
		mkdir(static::$config->dataDir, 755); // <- create fresh to restore

		[, $fileStorage] = $this->initTestEnv();
		ManticoreBackup::run('restore', [$fileStorage]);

		$this->assertFileExists(static::$config->path);
		$this->assertFileExists(static::$config->schemaPath);
		$this->assertDirectoryExists(static::$config->dataDir);
	}


  /**
   * @return array{0:string,1:FileStorage}
   */
	protected function initTestEnv(): array {
		$files = scandir(static::$backupDir);
		if (false === $files) {
			throw new Exception('Failed to scandir for files: ' . static::$backupDir);
		}
		$backups = array_slice($files, 2);
		$this->assertArrayHasKey(0, $backups);

		$fileStorage = new FileStorage(static::$backupDir);
		$backupPaths = $fileStorage->setBackupPathsUsingDir($backups[0])->getBackupPaths();

		return [$backupPaths['root'], $fileStorage];
	}

	protected function safeDelete(string $path): void {
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		if (!file_exists($path)) {
			return;
		}

		shell_exec("mv '$path' '$path.bak'");
	}
}
