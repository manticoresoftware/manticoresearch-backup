<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\LogLevel;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\Searchd;
use Manticoresearch\Backup\Lib\TextColor;
use Manticoresoftware\Telemetry\Metric;

/**
 * Validate args and return parsed options to use
 *
 * @param array<string,string> $args
 *  Parsed args with getopt
 * @return array{configs:array<string>,backup-dir:?string,compress:bool,tables:array<string>,restore:string|false,disable-telemetry:bool,force:bool}
 *  Options that we can use for access with predefined keys: config, backup-dir, all, tables
 */
function validate_args(array $args): array {
	$options = [
		'configs' => validate_get_configs($args),
		'backup-dir' => $args['backup-dir'] ?? null,
		'compress' => isset($args['compress']),
		'tables' => array_filter(array_map('trim', explode(',', $args['tables'] ?? ''))),
		'restore' => $args['restore'] ?? false,
		'force' => isset($args['force']),
		'disable-telemetry' => isset($args['disable-telemetry']),
	];

  // Validate arguments
	if (!isset($args['restore'])) {
		$options['configs'] = array_map(
			fn ($v) => backup_realpath($v),
			$options['configs']
		);
		foreach ($options['configs'] as $n => $config) {
			if (!is_file($config) || !is_readable($config)) {
				throw new InvalidArgumentException("Failed to find passed config[{$n}]: {$config}");
			}
		}
	}

  // Run checks only if we really need it
	$backupDir = isset($options['backup-dir']) ? backup_realpath($options['backup-dir']) : null;
	if (!isset($args['unlock'])) {
		if (!isset($backupDir) || !is_dir($backupDir)) {
			throw new InvalidArgumentException(
				'Failed to find backup dir to store backup: ' . ($backupDir ?? 'none')
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
 * @param array<string,string> $args
 * @return  array<string>
 */
function validate_get_configs(array $args): array {
	return (array)($args['config'] ?? ($args['c'] ?? (isset($args['restore']) ? '' : Searchd::getConfigPaths())));
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

// @codingStandardsIgnoreStart
/**
 * Extract passed arguments and check for known only
 *
 * @return array<string,array<int,mixed>|string|false>
 *  Parsed options
 */
// @codingStandardsIgnoreEnd
function get_input_args(): array {
	$args = getopt(
		'', [
			'help', 'config::', 'tables:', 'backup-dir:',
			'compress', 'restore::', 'unlock', 'version', 'disable-telemetry',
			'force',
		]
	);
	if (false === $args) {
		throw new InvalidArgumentException('Error while parsing the arguments');
	}

  // Do not let user to pass non supported options to script
	$supportedArgs = '!--help!--config!--tables!--backup-dir!--compress!--restore!'
		. '--unlock!--version!--disable-telemetry!--force!'
	;
	$argv = $_SERVER['argv'];
	array_shift($argv);

	foreach ($argv as $arg) {
		$arg = strtok($arg, '=');
		if (false === strpos($supportedArgs, '!' . $arg . '!')) {
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
  // TODO: add --debug parameter? but now just skip it
	if ($level === LogLevel::Debug) {
		return;
	}
	$ts = colored(date('Y-m-d H:i:s'), TextColor::LightYellow);
	$coloredLevel = match ($level) {
		LogLevel::Error => colored($level->name, TextColor::Red),
		default => $level->name,
	};

	fwrite(
	// TODO: find the way how to assert stderr in phpunit
	// $level === LogLevel::Error ? STDERR : STDOUT,
		STDOUT,
		"$ts [$coloredLevel] {$message}{$eol}"
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
 * @param bool $isOk
 * @return string
 */
function get_op_result(bool $isOk): string {
	return ($isOk
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
	. "  manticore-backup --backup-dir=path/to/backup [OPTIONS]$nl$nl"
	. colored('--backup-dir', TextColor::LightGreen)
	  . '='
	  . colored('path/to/backup', TextColor::LightBlue)
	  . $nl
	. "  This is a path to the backup directory where a backup is stored.  The$nl"
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
	. "  and port to talk with the Manticore daemon.$nl"
	. "  You can use --config path1 --config path2 ... --config pathN$nl"
	. "  to include all of the provided paths in the backup, but only$nl"
	. "  the first one will be used for communication with the daemon.$nl$nl"
	. colored('--tables', TextColor::LightGreen)
	  . '='
	  . colored('table1,table2,...', TextColor::LightBlue)
	  . $nl
	. "  Semicolon-separated list of tables that you want to backup.$nl"
	. "  If you want to backup all, just skip this argument. All the provided tables$nl"
	. "  are supposed to exist in the Manticore instance you are backing up from,$nl"
	. "  otherwise the backup will fail.$nl$nl"
	. colored('--compress', TextColor::LightGreen) . $nl
	. "  Whether the backed up files should be compressed. Not by default.$nl$nl"
	. colored('--restore[=backup]', TextColor::LightGreen) . $nl
	. "  Restore from --backup-dir. Just --restore lists available backups.$nl"
	. "  --restore=backup will restore from <--backup-dir>/backup.$nl$nl"
	. colored('--force', TextColor::LightGreen) . $nl
	. "  Skip versions check on restore and gracefully restore the backup.$nl"
	. colored('--disable-telemetry', TextColor::LightGreen) . $nl
	. '  Pass this flag in case you want to disable sending anonymized metrics '
		. " to Manticore. You can also use environment variable TELEMETRY=0.$nl$nl"
	. colored('--unlock', TextColor::LightGreen) . $nl
	. "  In rare cases when something goes wrong the tables can be left in$nl"
	. "  locked state. Using this argument you can unlock them.$nl$nl"
	. colored('--version', TextColor::LightGreen) . $nl
	. "  Show the current version.$nl$nl"
	. colored('--help', TextColor::LightGreen) . $nl
	. "  Show this help.$nl"
	;
}

/**
 * Emit the metric action and handle it in the way when we need it
 *
 * @param ?string $name
 * @param null|int|float $value
 * @param array<string,string> $labels
 * @return ?Metric
 */
function metric(?string $name = null, null|int|float $value = null, array $labels = []): ?Metric {
	// No telemetry enabled?
	if (getenv('TELEMETRY', true) !== '1') {
		return null;
	}

	static $metric;
	if (!isset($metric)) {
		// Initialize the metric component with base labels
		$metric = new Metric(
			[
				'backup_version' => ManticoreBackup::getVersion(),
				'collector' => 'backup',
			]
		);

		// Register function to run when script will stop
		// Only in case we use it as tool
		if (is_used_as_tool()) {
			register_shutdown_function($metric->send(...));
		}
	}

	if ($labels) {
		$metric->addLabelList($labels);
	}

	if ($name && $value) {
		$metric->add($name, $value);
	}

	return $metric;
}

/**
 * @return bool
 */
function is_used_as_tool(): bool {
	return getenv('BACKUP_AS_TOOL', true) !== '1';
}

/**
 * @param Throwable $e
 * @return void
 */
function exception_handler(Throwable $e): void {
	metric('failed', 1);
	println(LogLevel::Error, $e->getMessage());
	exit(1); // ? we can add method and fetch custom exit code on any exception
}

/**
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @return void
 */
function error_handler(int $errno, string $errstr, string $errfile, int $errline): void {
	metric('failed', 1);
	if (!(error_reporting() & $errno)) {
	  // This error code is not included in error_reporting
		return;
	}

	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

/**
 * Wrapper to bypass changing chdir to solve running not in allowed directory issue
 * @param string $path
 * @return string
 */
function backup_realpath(string $path): string {
	// This is phar hack
	$realCwd = getenv('REALCWD');

	// We do change trick to original dir we launched from to get realpath
	$originalCwd = getcwd();
	if (!$realCwd) {
		$realCwd = getenv('CWD', true);
	}

	if ($realCwd) {
		chdir($realCwd);
	}
	$realpath = realpath($path) ?: $path;
	if ($originalCwd) {
		chdir($originalCwd);
	}

	return $realpath;
}
