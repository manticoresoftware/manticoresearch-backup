<?php declare(strict_types=1);

/**
 * Validate args and return parsed options to use
 *
 * @param array $args
 *  Parsed args with getopt
 * @return array
 *  Options that we can use for access with predefined keys: config, target-dir, all, indexes
 */
function validate_args(array $args): array {
  $options = [
    'config' => $args['config'] ?? ($args['c'] ?? Searchd::getConfigPath()),
    'target-dir' => $args['target-dir'] ?? null,
    'compress' => isset($args['compress']) ?? false,
    'indexes' => array_filter(array_map('trim', explode(',', $args['indexes'] ?? ''))),
  ];

  // Validate arguments
  if (!isset($options['config']) || !is_file($options['config']) || !is_readable($options['config'])) {
    throw new InvalidArgumentException('Failed to find passed config: ' . ($options['config'] ?? 'none'));
  }

  // Run checks only if we really need it
  if (!isset($args['unlock'])) {
    if (!isset($options['target-dir']) || !is_dir($options['target-dir']) || !is_writeable($options['target-dir'])) {
      throw new InvalidArgumentException('Failed to find target dir to store backup: ' . ($options['target-dir'] ?? 'none'));
    }
  }

  if ($options['compress'] && !function_exists('lz4_compress')) {
    throw new RuntimeException('Failed to find lz4 in PHP build. Please enable the LZ4 extension if you want to use compression');
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

function exception_handler(Throwable $E) {
  echo $E->getMessage() . PHP_EOL;
  exit(1); // ? we can add method and fetch custom exit code on any exception
}

set_exception_handler('exception_handler');
