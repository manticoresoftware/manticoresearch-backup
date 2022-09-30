<?php declare(strict_types=1);

/**
 * This class is used to initialize config, parse it and launch the backup process
 */
class ManticoreBackup {
  const VERSION = '0.0.1';
  const MIN_PHP_VERSION = '8.1';

  /**
   * Store the wanted indexes in target dir as backup
   *
   * @param ManticoreClient $Client
   *  Initialized client to interract with manticore search daemon
   * @param FileStorage $Storage
   *  The instance of the storage with initialize directories to use
   * @param array<string> $indexes
   *  List of indexes to store. In case if its empty array we store all indexes
   * @return void
   */
  public static function store(ManticoreClient $Client, FileStorage $Storage, array $indexes = []): void {
    echo PHP_EOL . 'Starting the backup…' . PHP_EOL;
    $t = microtime(true);
    $destination = $Storage->getBackupPaths();

    // First store current versions in file
    $versions = $Client->getVersions();
    $is_ok = static::storeVersions($versions, $destination['root']);
    if (false === $is_ok) {
      throw new InvalidPathException('Failed to save the versions in "' . $destination['root'] . '"');
    }

    // TODO: add progress bar / backup status reporting

    // If we have no indexes passed we should to query the client and get all indexes we have
    [$is_all, $indexes] = static::validateIndexes($indexes, $Client);


    // 0. backup config files
    echo 'Backing up config files…' . PHP_EOL;
    $is_ok = $Storage->copyPaths([
      $Client->getConfig()->path,
      $Client->getConfig()->schema_path,
    ], $destination['config']);
    echo '  config files – ' . ($is_ok ? 'OK' : 'FAIL') . PHP_EOL;

    // 0.5 Lock all indexes to make sure that we will have no new data there
    // And run FLUSH ATTRIBUTES
    // We do lock twice just to keep logic for crawling one by one for each index
    $Client->freeze(array_keys($indexes));
    $Client->flushAttributes();

    // 1. First backup index data
    // Lets copy index one by one with freeze
    echo 'Backing up indexes…' . PHP_EOL;
    $result = true;
    foreach ($indexes as $index => $type) {
      $files = $Client->freeze($index);
      echo '  ' . $index . ' ('  . $type . ') [' . bytes_to_gb($Storage::calculateFilesSize($files)) . '] – ';

      $backup_path = $destination['data'] . DIRECTORY_SEPARATOR . $index;
      $is_ok = mkdir($backup_path, 0755);
      if (false === $is_ok) {
        $Client->unfreeze($index);
        throw new SearchdException(
          'Failed to create target directory for index – "' . $backup_path . '"'
        );
      }

      $is_ok = $Storage->copyPaths($files, $backup_path);
      echo ($is_ok ? 'OK' : 'FAIL') . PHP_EOL;
      $result = $result && $is_ok;
      $Client->unfreeze($index);
    }

    // 2. Second, if we are in backup all state we need to do some extra job and backup external files and config
    if ($is_all) {
      // 2.1 backup external files for each index
      echo 'Backing up external index files…' . PHP_EOL;
      foreach ($indexes as $index => $type) {
        echo '  ' . $index . ' (' . $type . ') – ';
        // Show settings on distributed indexes returns error 500 so we simply skip it
        if ($type === 'distributed') {
          echo 'SKIP' . PHP_EOL;
        } else {
          $files = $Client->getIndexExternalFiles($index);
          $is_ok = $Storage->copyPaths($files, $destination['external'], true);
          echo ($is_ok ? 'OK' : 'FAIL') . PHP_EOL;
          $result = $result && $is_ok;
        }
      }

      // 2.2 Backup global state files
      echo 'Backing up global state files…' . PHP_EOL;
      $files = $Client->getConfig()->getStatePaths();
      $is_ok = $Storage->copyPaths($files, $destination['state']);
      echo '  global state files – ' . ($is_ok ? 'OK' : 'FAIL') . PHP_EOL;

      $result = $result && $is_ok;
    }

    if (false === $result) {
      throw new Exception(
        'Failed to make backup of indexes. '
          . 'Please check that you have rights to access the source and destinations directories'
      );
    }

    static::fsync();

    // 3. Done
    $t = round(microtime(true) - $t, 2);

    echo 'You can find backup here: ' . $destination['root'] . PHP_EOL
      . 'Elapsed time: ' . $t . 's' . PHP_EOL
    ;
  }

  /**
   * Store versions for current bakcup in file of root directory passed as argument
   *
   * @param array<string,string> $versions
   * @param string $target_dir
   *  Directory where we will put versions.json file with verions
   * @return bool
   *  Result of storing versions
   */
  protected static function storeVersions(array $versions, string $target_dir): bool {
    $file_path = $target_dir . DIRECTORY_SEPARATOR . 'versions.json';
    return !!file_put_contents($file_path, json_encode($versions));
  }

  /**
   * Validate and adapt indexes to final format or exit with error
   *
   * @param array<string> $indexes
   *  list of indexes to validate that they exist
   * @param ManticoreClient $Client
   *  initialized client to interact with
   * @return array{0: bool, 1: array<string,string>}
   *  flag that points if we are in all backup state and list of indexes after validation
   */
  protected static function validateIndexes(array $indexes, ManticoreClient $Client): array {
    $result = [];
    $all_indexes = $Client->getIndexes();
    if ($indexes) {
      $is_all = false;
      $index_diff = array_diff($indexes, array_keys($all_indexes));
      if ($index_diff) {
        throw new InvalidArgumentException('Can\'t find some of the indexes: ' . implode(', ', $index_diff));
      }
      unset($index_diff);
      $result = array_intersect_key(
        $all_indexes,
        array_flip($indexes)
      );
    } else {
      $is_all = true;
      $result = $all_indexes;
    }
    return [$is_all, $result];
  }

  /**
   * This functions flushes buffers and attributes to the disk to make sure
   * that we are safe after backup is done
   */
  protected static function fsync(): void {
    system('sync');
  }
}
