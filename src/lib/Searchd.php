<?php declare(strict_types=1);

class Searchd {
  public static ?string $cmd;

  public static function init(): void {
    static::$cmd = OS::which('searchd');
  }

  public static function getConfigPath(): string {
    if (!isset(static::$cmd)) {
      throw new InvalidArgumentException('You should run Searchd::init before trying to access static methods');
    }

    $output = shell_exec(static::$cmd . ' --status');
    if (!is_string($output)) {
      throw new RuntimeException('Unable to get config path');
    }
    preg_match('/using config file \'([^\']+)\'/ium', $output, $m);
    if (!$m) {
      throw new InvalidPathException('Failed to find searchd config from command line');
    }
    return $m[1];
  }
}
