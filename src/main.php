<?php declare(strict_types=1);

/**
 * 0. Check dependencies (? still no deps and thats good) and minimal PHP versions
 * 1. Parse the arguments
 * 2. Validate required and passed arguments
 * 3. Initialize backup
 */

include_once __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

// First fetch all supported args and their short versions
$args = get_input_args();

// Show help in case we passed help arg
if (isset($args['h']) || isset($args['help'])) {
  show_help();
  exit(0);
}

// Show version in case we passed version arg
if (isset($args['version'])) {
  echo 'Manticore Backup version: ' . ManticoreBackup::VERSION . PHP_EOL;
  echo 'Minimum PHP version required: ' . ManticoreBackup::MIN_PHP_VERSION . PHP_EOL;
  exit(0);
}

// Here the point when we start to check dependecies
// We do not check in the beginning of file just to let user read --help command
Searchd::init();

// OK, now gather all options in an array with default values
$options = validate_args($args); // @phpstan-ignore-line

echo 'Manticore config file: ' . $options['config'] . PHP_EOL
  . (
      isset($args['restore'])
        ? ''
        : 'Tables to backup: ' . ($options['tables'] ? implode(', ', $options['tables']) : 'all tables') . PHP_EOL
  )
  . 'Backup dir: ' . ($options['backup-dir'] ?? 'none') . PHP_EOL
;

switch (true) {
  case isset($args['unlock']): // unlock
    $Client = ManticoreClient::init($options['config']);
    $Client->unfreezeAll();
    break;

  case isset($args['restore']): // restore
    $Storage = new FileStorage($options['backup-dir']);

    if ($options['restore'] === false) {
      $backup_dir = $Storage->getBackupDir();
      if (!$backup_dir) {
        throw new InvalidArgumentException('There is no backup-dir detected');
      }

      $backups = glob($backup_dir . DIRECTORY_SEPARATOR . 'backup-*');
      if ($backups) {
        $prefix_len = strlen($backup_dir) + 1;
        echo PHP_EOL . 'Available backups: ' . sizeof($backups) . PHP_EOL;
        foreach ($backups as $path) {
          echo '  ' . substr($path, $prefix_len) . PHP_EOL;
        }
      } else {
        echo PHP_EOL . 'There are no backups available to restore' .  PHP_EOL;
      }
      exit(0);
    }

    $Storage->setBackupPathsUsingDir($options['restore']);

    // Here is when real restore is starting
    ManticoreBackup::restore($Storage);
    break;

  default: // backup
    $Client = ManticoreClient::init($options['config']);

    $Storage = new FileStorage($options['backup-dir'], $options['compress']);

    // In case of backing up it's important to install signal handler
    if (function_exists('pcntl_async_signals')) {
      pcntl_async_signals(true);
      $signal_handler = $Client->getSignalHandlerFn($Storage);
      pcntl_signal(SIGQUIT, $signal_handler);
      pcntl_signal(SIGINT, $signal_handler);
      pcntl_signal(SIGTERM, $signal_handler);
      pcntl_signal(SIGSEGV, $signal_handler);
    }
    // Check if we run as root otherwise show warning
    // ! getmyuid returns different uid in docker image
    if (!OS::isWindows() && posix_getuid() !== 0) {
      echo PHP_EOL . 'WARNING: we couldn\'t fully preserve permissions of the files'
        . ' you\'ve backed up. Be careful when you restore from the backup or'
        . ' re-run the backup as root' . PHP_EOL
      ;
    }

    ManticoreBackup::store($Client, $Storage, $options['tables']);
}

println(LogLevel::Info, 'Done');
