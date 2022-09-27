<?php declare(strict_types=1);

/**
 * This is the little helper class to detect which os we run on
 */
class OS {
  /**
   * @return bool
   *  True in case if we use windows as running OS
   */
  public static function isWindows(): bool {
    return strncasecmp(PHP_OS, 'WIN', 3) == 0;
  }

  /**
   * @return bool
   *  True in case if we use Linux as running OS
   */
  public static function isLinux(): bool {
    return PHP_OS === 'Linux';
  }

  /**
   * Little helper to find the real path to executoable depending on running os
   *
   * @param string $program
   * @return string
   *  The path to found executoable
   * @throws Exception
   */
  public static function which(string $program): string {
    $result = match (static::isWindows()) {
      true => exec('where ' . $program),
      false => exec('which ' . $program . ' 2> /dev/null'),
    };

    if (!$result) {
      throw new Exception(__METHOD__  . ': failed to find "' . $program . '" in search path');
    }

    return $result;
  }
}