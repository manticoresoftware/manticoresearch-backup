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
    echo <<<EOF
Usage: manticore_backup --target-dir=path/to/backup [OPTIONS]

--target-dir=path/to/backup
  This is a path to the target directory where a backup is stored.  The
  directory must exist. This argument is required and has no default value.
  On each backup run, it will create directory `backup-[datetime]` in the
  provided directory and will copy all required tables to it. So the target-dir
  is a container of all your backups, and it's safe to run the script multiple
  times.

OPTIONS:

--config=path/to/manticore.conf | -c=path/to/manticore.conf
  Path to Manticore config. This is optional and in case it's not passed
  we use a default one for your operating system. It's used to get the host
  and port to talk with the Manticore daemon.

--tables=table1,table2,...
  Semicolon-separated list of tables that you want to backup.
  If you want to backup all, just skip this argument. All the provided tables
  are supposed to exist in the Manticore instance you are backing up from.

--compress
  Whether the backed up files should be compressed. Not by default.

--unlock
  In rare cases when something goes wrong the tables can be left in
  locked state. Using this argument you can unlock them.

--version
  Show the current version.

--help | -h
  Show this help.

EOF;
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
    $Storage = new FileStorage($options['target-dir'], $options['compress']);

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
