<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\LogLevel;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\TextColor;

/**
 * 0. Check dependencies (? still no deps and thats good) and minimal PHP versions
 * 1. Parse the arguments
 * 2. Validate required and passed arguments
 * 3. Initialize backup
 */
include_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
	. 'vendor' .  DIRECTORY_SEPARATOR . 'autoload.php'
;

// This fix issue when we get "sh: 1: cd: can't cd to" error
// while running buddy inside directory that are not allowed for us
putenv('CWD=' . getcwd());
chdir(sys_get_temp_dir());

set_exception_handler(exception_handler(...));
set_error_handler(error_handler(...)); // @phpstan-ignore-line

echo 'Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)'
  . PHP_EOL . PHP_EOL
;

// First fetch all supported args and their short versions
$args = get_input_args();

putenv('BACKUP_AS_TOOL=1');
putenv('TELEMETRY=' . sprintf('%d', !isset($args['disable-telemetry'])));
metric(
	'invocation', 1, [
		'mode' => 'tool',
	]
);
// Send arguments usage first
foreach (array_keys($args) as $arg) {
	metric('arg_' . str_replace('-', '_', $arg), 1);
}

// Show help in case we passed help arg
if (isset($args['h']) || isset($args['help'])) {
	show_help();
	exit(0);
}

// Show version in case we passed version arg
if (isset($args['version'])) {
	echo 'Manticore Backup version: ' . ManticoreBackup::getVersion() . PHP_EOL;
	exit(0);
}

// Here the point when we start to check dependecies
// We do not check in the beginning of file just to let user read --help command

// OK, now gather all options in an array with default values
$options = validate_args($args); // @phpstan-ignore-line
$config = $options['configs'][0] ?? '';
echo 'Manticore config file: ' . $config . PHP_EOL
  . (
	  isset($args['restore'])
		? ''
		: 'Tables to backup: ' . ($options['tables'] ? implode(', ', $options['tables']) : 'all tables') . PHP_EOL
  )
  . 'Backup dir: ' . ($options['backup-dir'] ?? 'none') . PHP_EOL
;

switch (true) {
	case isset($args['unlock']): // unlock
		$client = ManticoreClient::init($options['configs']);
		$client->unfreezeAll();
	break;

	case isset($args['restore']): // restore
		$storage = new FileStorage($options['backup-dir']);

		if ($options['restore'] === false) {
			$backupDir = $storage->getBackupDir();
			if (!$backupDir) {
				throw new InvalidArgumentException('There is no backup-dir detected');
			}

			$backups = glob($backupDir . 'backup-*');
			if ($backups) {
				$prefixLen = strlen($backupDir);
				echo PHP_EOL . 'Available backups: ' . sizeof($backups) . PHP_EOL;
				foreach ($backups as $path) {
					$dir = substr($path, $prefixLen);
					$ts = strtotime(explode('-', $dir)[1] ?? '0');
					$date = $ts ? date('M d Y H:i:s', $ts) : '?';
					echo '  ' . $dir . ' (' . colored($date, TextColor::LightYellow) . ')' . PHP_EOL;
				}
			} else {
				echo PHP_EOL . 'There are no backups available to restore' .  PHP_EOL;
			}
			exit(0);
		}

		$storage->setBackupPathsUsingDir($options['restore']);
		// Validate versions if no force passed
		$versionsEqual = ManticoreBackup::validateVersions($storage);
		if (!$versionsEqual) {
			println(
				LogLevel::Info,
				'Warning: You are trying to restore a backup from a different version. '
				. 'Use --force to bypass this restriction.'
			);

			if (!$options['force']) {
				$continue = false;
				if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
					// Ask user for input
					echo PHP_EOL . 'Do you wish to continue? (y or n): ';
					$response = strtolower(trim(fgets(STDIN) ?: ''));
					$continue = $response === 'y';
				}

				if (!$continue) {
					exit(0);
				}
			}
		}

	  // Here is when real restore is starting
		ManticoreBackup::run('restore', [$storage]);
	break;

	default: // backup
		$client = ManticoreClient::init($options['configs']);

		$storage = new FileStorage($options['backup-dir'], $options['compress']);

	  // In case of backing up it's important to install signal handler
		if (function_exists('pcntl_async_signals')) {
			pcntl_async_signals(true);
			$signalHandler = $client->getSignalHandlerFn($storage);
			pcntl_signal(SIGQUIT, $signalHandler);
			pcntl_signal(SIGINT, $signalHandler);
			pcntl_signal(SIGTERM, $signalHandler);
			pcntl_signal(SIGSEGV, $signalHandler);
		} else {
			echo PHP_EOL . 'WARNING: you should install pcntl extension'
				. ' for proper interruption signal handling'
				. PHP_EOL
			;
		}

	  // Check if we run as root otherwise show warning
	  // ! getmyuid returns different uid in docker image
		if (function_exists('posix_getuid') && posix_getuid() !== 0) {
			echo PHP_EOL . 'WARNING: we couldn\'t fully preserve permissions of the files'
				. ' you\'ve backed up. Be careful when you restore from the backup or'
				. ' re-run the backup as root' . PHP_EOL
			;
		}

		ManticoreBackup::run('store', [$client, $storage, $options['tables']]);
}

metric('done', 1);
println(LogLevel::Info, 'Done');
