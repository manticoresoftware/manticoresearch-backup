<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SearchdTestCase extends TestCase {
  const SEARCHD_PID_FILE = '/var/run/manticore/searchd.pid';

  public static function setUpBeforeClass(): void {
    Searchd::init();
    if (!Searchd::isRunning()) {
      shell_exec(Searchd::$cmd);
    }
  }

  public static function tearDownAfterClass(): void {
    if (is_file(static::SEARCHD_PID_FILE)) {
      $pid = intval(file_get_contents(static::SEARCHD_PID_FILE));
      posix_kill($pid, 9);
    }
  }
}
