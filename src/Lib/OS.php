<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

/**
 * This is the little helper class to detect which os we run on
 */
class OS {
  /**
   * @return bool
   *  True in case if we use windows as running OS
   */
	public static function isWindows(): bool {
		return PHP_OS_FAMILY === 'Windows';
	}

  /**
   * @return bool
   *  True in case if we use Linux as running OS
   */
	public static function isLinux(): bool {
		return PHP_OS_FAMILY === 'Linux';
	}

  /**
   * Little helper to find the real path to executoable depending on running os
   *
   * @param string $program
   * @return string
   *  The path to found executoable
   * @throws \Exception
   */
	public static function which(string $program): string {
		$searchd = getenv('MANTICORE_SEARCHD') ?: null;
		$result = $searchd ?? match (static::isWindows()) {
			true => exec('where ' . $program),
			false => exec('which ' . $program . ' 2> /dev/null'),
		};

		if (!$result) {
			throw new \Exception(__METHOD__  . ': failed to find "' . $program . '" in search path');
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public static function isRoot(): bool {
		return !static::isWindows() && function_exists('posix_getuid') && posix_getuid() === 0;
	}

	/**
	 * OS depended path comparision if two strings point to the same path
	 *
	 * @param string $path1
	 * @param string $path2
	 * @return bool
	 */
	public static function isSamePath(string $path1, string $path2): bool {
		return match (static::isWindows()) {
			true => strcasecmp($path1, $path2) === 0,
			false => $path1 === $path2,
		};
	}
}
