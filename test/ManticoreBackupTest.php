<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ManticoreBackupTest extends TestCase {

  public function testStore() {
    // Initialize all
    Searchd::init();

    $tmp_dir = FileStorage::getTmpDir();
    $backup_dir = $tmp_dir . DIRECTORY_SEPARATOR . 'backup-test-' . uniqid();
    mkdir($backup_dir, 0755);

    $options = validate_args([
      'target-dir' => $backup_dir,
    ]);

    $Config = new ManticoreConfig($options['config']);
    $Client = new ManticoreClient($Config);
    $Storage = new FileStorage($options['target-dir']);

    ManticoreBackup::store($Client, $Storage, $options['indexes']);

    $basedir = glob($backup_dir . DIRECTORY_SEPARATOR . '*')[0];

    // Check that we created all reaquired dirs
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'config');
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data');
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'external');
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'state');
    $this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'versions.json');

    // Check indexes directories
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'movie');
    $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'people');

    $this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.json');
    $this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.conf');

    // Validate consistency of stored indexes
    foreach (['movie', 'people'] as $index) {
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
    $this->assertEquals(
      FileStorage::getPathChecksum($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.conf'),
      FileStorage::getPathChecksum($Config->path)
    );

    $this->assertEquals(
      FileStorage::getPathChecksum($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.json'),
      FileStorage::getPathChecksum($Config->schema_path)
    );
  }
}