<?php declare(strict_types=1);

/**
 * 0. Check dependencies (? still no deps and thats good) and minimal PHP versions
 * 1. Parse the arguments
 * 2. Validate required and passed arguments
 * 3. Initialize backup
 */

include __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

// First fetch all supported args and their short versions
$args = getopt('hc:', ['help', 'config:', 'indexes:', 'target-dir:', 'compress', 'unlock', 'version']);
if (false === $args) {
  throw new InvalidArgumentException('Error while parsing the arguments');
}

// Show help in case we passed help arg
if (isset($args['h']) || isset($args['help'])) {
    echo <<<EOF
Manticore Backup Script

Required: --target-dir for backup destination.

--target-dir=path/to/backup
  This is the path to the target directory where a backup is stored.
  The direction must be created. The argument is required to pass,
  and it has no default value.
  On each backup run, the script will create a backup-[datetime] directory
  and copy all required indexes to it. So target-dir represents the
  container of all your backups, and it's safe to run the script multiple times.

--config=path/to/manticore.conf | -c=path/to/manticore.conf
  Path to manticore config. This is optional and in case if it's not passed
  we use default one for your platform. It's used to get the host
  and port to talk with the Manticore daemon.

--indexes=index1,index2,...
  A semicolon-separated list of indexes is required to backup.
  If you want to backup all, just pass skip passing this argument to the script.
  You cannot give unexisting indexes in your database to this argument.

--compress
  Should we compress our indexers or not. The default – no.
  We use lz4 for compression.

--unlock
  In case if something went wrong or indexes are still in lock state
  we can run the script with this argument to unlock it all.

--version
  Show the current backup script version.

--help | -h
  Show this help.

EOF;
    exit(0);
}

// Show version in case we passed version arg
if (isset($args['version'])) {
  echo 'Manticore Backup Script version: ' . ManticoreBackup::VERSION . PHP_EOL;
  echo 'Minimum PHP version required: ' . ManticoreBackup::MIN_PHP_VERSION . PHP_EOL;
  exit(0);
}

// Here the point when we start to check dependecies
// We do not check in the beginning of file just to let user read --help command
Searchd::init();

// OK, now gather all options in an array with default values
$options = validate_args($args);

// First we parse config from passed / default config file
$Config = new ManticoreConfig($options['config']);
$Client = new ManticoreClient($Config);

$versions = $Client->getVersions();
echo PHP_EOL . 'Manticore versions:' . PHP_EOL
  . '  manticore: ' . $versions['manticore'] . PHP_EOL
  . '  columnar: ' . $versions['columnar'] . PHP_EOL
  . '  secondary: ' . $versions['secondary'] . PHP_EOL
;

switch (true) {
  case isset($args['unlock']): // unlock
    $Client->unfreezeAll();
    break;

  default: // backup
    // In case of backing up it's important to install signal handler
    if (function_exists('pcntl_async_signals')) {
      pcntl_async_signals(true);
      $signal_handler = function ($signal) use ($Client) {
        echo 'Caught signal ' . $signal . PHP_EOL;
        $Client->unfreezeAll();
      };
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
    $Storage = new FileStorage($options['target-dir'], $options['compress']);
    ManticoreBackup::store($Client, $Storage, $options['indexes']);
}

echo 'Done' . PHP_EOL;
