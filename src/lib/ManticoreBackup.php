<?php declare(strict_types=1);

/**
 * This class is used to initialize config, parse it and launch the backup process
 */
class ManticoreBackup {
  const VERSION = '0.0.1';
  const MIN_PHP_VERSION = '8.1';

  /**
   * Store the wanted tables in target dir as backup
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
    $is_ok = $Storage->copyPaths([
      $Client->getConfig()->path,
      $Client->getConfig()->schema_path,
    ], $destination['config']);
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
    // And run FLUSH ATTRIBUTES
    // We do lock twice just to keep logic for crawling one by one for each index
    $Client->freeze(array_keys($tables));
    $Client->flushAttributes();

    // - First backup index data
    // Lets copy index one by one with freeze
    println(LogLevel::Info, 'Backing up tables...');
    foreach ($tables as $index => $type) {
      $files = $Client->freeze($index);
      println(
        LogLevel::Info,
        '  ' . $index . ' ('  . $type . ') [' . format_bytes($Storage::calculateFilesSize($files)) . ']...'
      );

      $backup_path = $destination['data'] . DIRECTORY_SEPARATOR . $index;
      $is_ok = mkdir($backup_path, 0755);
      if (false === $is_ok) {
        $Client->unfreeze($index);
        throw new SearchdException(
          'Failed to create target directory for index – "' . $backup_path . '"'
        );
      }

      $is_ok = $Storage->copyPaths($files, $backup_path);
      println(LogLevel::Info, '   ' . get_op_result($is_ok));
      $result = $result && $is_ok;
      $Client->unfreeze($index);
    }

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

    $is_all = !array_diff($all_table_names, array_keys($tables));
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
}
