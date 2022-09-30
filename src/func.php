<?php declare(strict_types=1);

/**
 * Validate args and return parsed options to use
 *
 * @param array<string,string> $args
 *  Parsed args with getopt
 * @return array{config:string,target-dir:?string,compress:bool,indexes:array<string>}
 *  Options that we can use for access with predefined keys: config, target-dir, all, indexes
 */
function validate_args(array $args): array {
  $options = [
    'config' => $args['config'] ?? ($args['c'] ?? Searchd::getConfigPath()),
    'target-dir' => $args['target-dir'] ?? null,
    'compress' => isset($args['compress']),
    'indexes' => array_filter(array_map('trim', explode(',', $args['indexes'] ?? ''))),
  ];

  // Validate arguments
  if (!is_file($options['config']) || !is_readable($options['config'])) {
    throw new InvalidArgumentException('Failed to find passed config: ' . $options['config']);
  }

  // Run checks only if we really need it
  if (!isset($args['unlock'])) {
    if (!isset($options['target-dir']) || !
      is_dir($options['target-dir']) ||
      !is_writeable($options['target-dir'])) {
      throw new InvalidArgumentException(
        'Failed to find target dir to store backup: ' . ($options['target-dir'] ?? 'none')
      );
    }
  }

  if ($options['compress'] && !function_exists('lz4_compress')) {
    throw new RuntimeException(
      'Failed to find lz4 in PHP build. Please enable the LZ4 extension if you want to use compression'
    );
  }

  echo 'Manticore config file: ' . $options['config'] . PHP_EOL
    . 'Indexes to backup: ' . ($options['indexes'] ? implode(', ', $options['indexes']) : 'all indexes') . PHP_EOL
    . 'Target dir: ' . ($options['target-dir'] ?? 'none') . PHP_EOL
  ;

  return $options;
}

/**
 * Little helper to conver bytes to human readable Gb
 *
 * @param int $bytes
 * @return string
 *  The result in format [value]G
 */
function bytes_to_gb(int $bytes): string {
  return round($bytes / (1024 * 1024 * 1024), 3). 'G';
}

/**
 * Extract passed arguments and check for known only
 *
 * @return array<string,array<int,mixed>|string|false>
 *  Parsed options
 */
function get_input_args(): array {
  $args = getopt('hc:', ['help', 'config:', 'indexes:', 'target-dir:', 'compress', 'unlock', 'version']);
  if (false === $args) {
    throw new InvalidArgumentException('Error while parsing the arguments');
  }

  // Do not let user to pass non supported options to script
  $supported_args = '!-h!-c!--help!--config!--indexes!--target-dir!--compress!--unlock!--version!';
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

function exception_handler(Throwable $E): void {
  echo $E->getMessage() . PHP_EOL;
  exit(1); // ? we can add method and fetch custom exit code on any exception
}

function error_handler(int $errno, string $errstr, string $errfile, int $errline): void {
  if (!(error_reporting() & $errno)) {
    // This error code is not included in error_reporting
    return;
  }
  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
