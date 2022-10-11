<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use PHPUnit\Framework\TestCase;

class ManticoreBackupRestoreTest extends TestCase {
  protected static string $backup_dir;
  protected static ManticoreConfig $Config;

  public static function setUpBeforeClass(): void {
    static::$backup_dir = FileStorage::getTmpDir() . DIRECTORY_SEPARATOR . 'restore-test';
    mkdir(static::$backup_dir, 0777);

    SearchdTestCase::setUpBeforeClass();
    Searchd::init();
    static::$Config = new ManticoreConfig(Searchd::getConfigPath());
    $Client = new ManticoreClient(static::$Config);

    $FileStorage = new FileStorage(static::$backup_dir);
    ManticoreBackup::store($Client, $FileStorage);
    SearchdTestCase::tearDownAfterClass();
  }

  public function tearDown(): void {
    $paths = [
      static::$Config->path,
      static::$Config->schema_path,
      static::$Config->data_dir,
      ...static::$Config->getStatePaths(),
    ];

    foreach ($paths as $path) {
      $path = rtrim($path, DIRECTORY_SEPARATOR);
      $path_bak = $path . '.bak';
      if (file_exists($path_bak)) {
        if (file_exists($path)) {
          if (is_dir($path_bak)) {
            FileStorage::deleteDir($path_bak);
          } else {
            unlink($path_bak);
          }
        } else {
          shell_exec("mv '$path_bak' '$path'");
        }
      }
    }
  }

  public function testRestoreFailedWhenSearchdLaunched(): void {
    [, $FileStorage] = $this->initTestEnv();

    SearchdTestCase::setUpBeforeClass();
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Cannot initiate the restore process due to searchd daemon is running.');
    ManticoreBackup::restore($FileStorage);
    SearchdTestCase::tearDownAfterClass();
  }

  public function testRestoreFailedWhenOriginalConfigExists(): void {
    SearchdTestCase::tearDownAfterClass();

    [, $FileStorage] = $this->initTestEnv();

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Destination file already exists');
    ManticoreBackup::restore($FileStorage);
  }

  public function testRestoreFailedWhenOriginalSchemaExists(): void {
    SearchdTestCase::tearDownAfterClass();
    $this->safeDelete(static::$Config->path);

    [, $FileStorage] = $this->initTestEnv();
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Destination file already exists');
    ManticoreBackup::restore($FileStorage);
  }

  public function testRestoreFailedWhenDataDirIsNotEmpty(): void {
    SearchdTestCase::tearDownAfterClass();

    $this->safeDelete(static::$Config->path);
    $this->safeDelete(static::$Config->schema_path);

    [, $FileStorage] = $this->initTestEnv();
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Destination file already exists');
    ManticoreBackup::restore($FileStorage);
  }

  public function testRestoreFailedWhenStatePathExists(): void {
    SearchdTestCase::tearDownAfterClass();

    $this->safeDelete(static::$Config->path);
    $this->safeDelete(static::$Config->schema_path);
    $this->safeDelete(static::$Config->data_dir);

    [, $FileStorage] = $this->initTestEnv();
    $this->expectException(Exception::class);
    $this->expectExceptionMessage('Destination file already exists');
    ManticoreBackup::restore($FileStorage);
  }


  public function testRestoreIsOK(): void {
    SearchdTestCase::tearDownAfterClass();
    $this->safeDelete(static::$Config->path);
    $this->safeDelete(static::$Config->schema_path);
    $this->safeDelete(static::$Config->data_dir);
    array_map($this->safeDelete(...), static::$Config->getStatePaths());
    mkdir(static::$Config->data_dir, 755); // <- create fresh to restore

    [, $FileStorage] = $this->initTestEnv();
    ManticoreBackup::restore($FileStorage);

    $this->assertFileExists(static::$Config->path);
    $this->assertFileExists(static::$Config->schema_path);
    $this->assertDirectoryExists(static::$Config->data_dir);
  }


  /**
   * @return array{0:string,1:FileStorage}
   */
  protected function initTestEnv(): array {
    $files = scandir(static::$backup_dir);
    if (false === $files) {
      throw new Exception('Failed to scandir for files: ' . static::$backup_dir);
    }
    $backups = array_slice($files, 2);
    $this->assertArrayHasKey(0, $backups);

    $FileStorage = new FileStorage(static::$backup_dir);
    $backup_paths = $FileStorage->setBackupPathsUsingDir($backups[0])->getBackupPaths();

    return [$backup_paths['root'], $FileStorage];
  }

  protected function safeDelete(string $path): void {
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    if (file_exists($path)) {
      shell_exec("mv '$path' '$path.bak'");
    }
  }
}
