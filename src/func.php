<?php declare(strict_types=1);

/**
 * Validate args and return parsed options to use
 *
 * @param array<string,string> $args
 *  Parsed args with getopt
 * @return array{config:string,backup-dir:?string,compress:bool,tables:array<string>,restore:string|false}
 *  Options that we can use for access with predefined keys: config, backup-dir, all, tables
 */
function validate_args(array $args): array {
  $options = [
    'config' => $args['config'] ?? ($args['c'] ?? (isset($args['restore']) ? '' : Searchd::getConfigPath())),
    'backup-dir' => $args['backup-dir'] ?? null,
    'compress' => isset($args['compress']),
    'tables' => array_filter(array_map('trim', explode(',', $args['tables'] ?? ''))),
    'restore' => $args['restore'] ?? false,
  ];

  // Validate arguments
  if (!isset($args['restore'])) {
    if (!is_file($options['config']) || !is_readable($options['config'])) {
      throw new InvalidArgumentException('Failed to find passed config: ' . $options['config']);
    }
  }

  // Run checks only if we really need it
  if (!isset($args['unlock'])) {
    if (!isset($options['backup-dir']) || !
      is_dir($options['backup-dir']) ||
      !is_writeable($options['backup-dir'])) {
      throw new InvalidArgumentException(
        'Failed to find target dir to store backup: ' . ($options['backup-dir'] ?? 'none')
      );
    }
  }

  if ($options['compress'] && !function_exists('zstd_compress')) {
    throw new RuntimeException(
      'Failed to find ZSTD in PHP build. Please enable the ZSTD extension if you want to use compression'
    );
  }

  return $options;
}

/**
 * Little helper to conver bytes to human readable size
 *
 * @param int $bytes
 * @param int $precision
 * @return string
 *  The result in format [value]G
 */
function format_bytes(int $bytes, int $precision = 3): string {
  if ($bytes <= 0) {
    return '0B';
  }

  $base = log($bytes, 1024);
  $sfx = ['B', 'K', 'M', 'G', 'T'];

  return round(pow(1024, $base - floor($base)), $precision) . $sfx[floor($base)];
}

/**
 * Extract passed arguments and check for known only
 *
 * @return array<string,array<int,mixed>|string|false>
 *  Parsed options
 */
function get_input_args(): array {
  $args = getopt('', ['help', 'config:', 'tables:', 'backup-dir:', 'compress', 'restore::', 'unlock', 'version']);
  if (false === $args) {
    throw new InvalidArgumentException('Error while parsing the arguments');
  }

  // Do not let user to pass non supported options to script
  $supported_args = '!--help!--config!--tables!--backup-dir!--compress!--restore!--unlock!--version!';
  $argv = $_SERVER['argv'];
  array_shift($argv);

  foreach ($argv as $arg) {
    $arg = strtok($arg, '=');
    if (false === strpos($supported_args, '!' . $arg . '!')) {
      throw new InvalidArgumentException('Unknown option: ' . $arg);
    }
  }
  return $args;
}

/**
 * This is helper to log message to stdout or stderr
 *
 * @param LogLevel $level
 * @param string $message
 * @param string $eol
 * @return void
 */
function println(LogLevel $level, string $message, string $eol = PHP_EOL): void {
  $ts = colored(date('Y-m-d H:i:s'), TextColor::LightYellow);
  $colored_level = match ($level) {
    LogLevel::Error => colored($level->name, TextColor::Red),
    default => $level->name,
  };

  fwrite(
    // TODO: find the way how to assert stderr in phpunit
    // $level === LogLevel::Error ? STDERR : STDOUT,
    STDOUT,
    "$ts [$colored_level] {$message}{$eol}"
  );
}

/**
 * This is helper to get colored output for logging in case if the console support its
 * @param string $message
 * @param TextColor $color
 * @return string
 */
function colored(string $message, TextColor $color): string {
  return stream_isatty(STDOUT)
    ? "\033[{$color->value}m{$message}\033[0m"
    : $message
  ;
}

/**
 * We use this helper function to display emoji or non-emoji ok/false messages
 *
 * @param bool $is_ok
 * @return string
 */
function get_op_result(bool $is_ok): string {
  return ($is_ok
    ? colored('OK', TextColor::LightGreen)
    : colored('Error', TextColor::LightRed)
  );
}

/**
 * Helper to display help doc on --help arg
 *
 * @return void
 */
function show_help(): void {
  $nl = PHP_EOL;
  echo colored('Usage:', TextColor::LightYellow) . $nl
    . "  manticore_backup --backup-dir=path/to/backup [OPTIONS]$nl$nl"
    . colored('--backup-dir', TextColor::LightGreen)
      . '='
      . colored('path/to/backup', TextColor::LightBlue)
      . $nl
    . "  This is a path to the target directory where a backup is stored.  The$nl"
    . "  directory must exist. This argument is required and has no default value.$nl"
    . "  On each backup run, it will create directory `backup-[datetime]` in the$nl"
    . "  provided directory and will copy all required tables to it. So the backup-dir$nl"
    . "  is a container of all your backups, and it's safe to run the script multiple$nl"
    . "  times.$nl$nl"
    . colored('OPTIONS:', TextColor::LightYellow) . $nl . $nl
    . colored('--config', TextColor::LightGreen)
      . '='
      . colored('path/to/manticore.conf', TextColor::LightBlue)
      . $nl
    . "  Path to Manticore config. This is optional and in case it's not passed$nl"
    . "  we use a default one for your operating system. It's used to get the host$nl"
    . "  and port to talk with the Manticore daemon.$nl$nl"
    . colored('--tables', TextColor::LightGreen)
      . '='
      . colored('table1,table2,...', TextColor::LightBlue)
      . $nl
    . "  Semicolon-separated list of tables that you want to backup.$nl"
    . "  If you want to backup all, just skip this argument. All the provided tables$nl"
    . "  are supposed to exist in the Manticore instance you are backing up from.$nl$nl"
    . colored('--compress', TextColor::LightGreen) . $nl
    . "  Whether the backed up files should be compressed. Not by default.$nl$nl"
    . colored('--restore', TextColor::LightGreen) . $nl
    . "  Whether we should restore files from the passed backup version.$nl$nl"
    . colored('--unlock', TextColor::LightGreen) . $nl
    . "  In rare cases when something goes wrong the tables can be left in$nl"
    . "  locked state. Using this argument you can unlock them.$nl$nl"
    . colored('--version', TextColor::LightGreen) . $nl
    . "  Show the current version.$nl$nl"
    . colored('--help', TextColor::LightGreen) . $nl
    . "  Show this help.$nl"
  ;
}

function exception_handler(Throwable $E): void {
  println(LogLevel::Error, $E->getMessage());
  exit(1); // ? we can add method and fetch custom exit code on any exception
}

function error_handler(int $errno, string $errstr, string $errfile, int $errline): void {
  if (!(error_reporting() & $errno)) {
    // This error code is not included in error_reporting
    return;
  }

  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
