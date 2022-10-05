<?php declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

class ManticoreBackupTest extends TestCase {
  public function testStoreAllTables(): void {
    [$Config, $Storage, $backup_dir] = $this->initTestEnv();
    $Client = new ManticoreClient($Config);

    // Backup of all tables
    ManticoreBackup::store($Client, $Storage, []);
    $this->assertBackupIsOK($Client,
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
    $Client->setTimeoutFn(function () use ($Client, $Storage): bool {
      static $count = 0;
      ++$count;
      if ($count < 3) {
        return false;
      }

      $fn = $Client->getSignalHandlerFn($Storage);
      $fn(15);

      return true;
    });

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
    $Client->setTimeoutFn(function () use ($Storage): bool {
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
    });

    $this->expectException(Throwable::class);
    ManticoreBackup::store($Client, $Storage, ['people', 'movie']);

    $backup_paths = $Storage->getBackupPaths();
    $this->assertDirectoryDoesNotExist($backup_paths['root']);
  }


  // public function testStoreInterruption(): void {
  //   [$Config, $Storage, $backup_dir] = $this->initTestEnv();

  //   $Client = new ManticoreClient($Config);
  // }

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

    $options = validate_args([
      'target-dir' => $backup_dir,
    ]);

    return [
      new ManticoreConfig($options['config']),
      new FileStorage($options['target-dir']),
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
   * @throws InvalidArgumentException
   * @throws ExpectationFailedException
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

    $this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.json');
    $this->assertFileExists($basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.conf');

    // Validate consistency of stored tables
    foreach ($tables as $index => $type) {
      // Distributed tables do not have directory to backup
      if ($type === 'distributed') {
        continue;
      }

      $this->assertDirectoryExists($basedir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $index);

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
    $dst_conf = $basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.conf';
    $this->assertEquals(
      FileStorage::getPathChecksum($dst_conf),
      FileStorage::getPathChecksum($Config->path)
    );

    $dst_conf = $basedir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'manticore.json';
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
      if ($is_all) {
        $this->assertEquals(
          FileStorage::getPathChecksum($state_path),
          FileStorage::getPathChecksum($dest_path)
        );
      }
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
    register_shutdown_function(function () use ($target): void {
      shell_exec("umount '$target'");
    });
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
