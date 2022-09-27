<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SearchdTest extends TestCase {
  public function testGetConfigPathWithoutInitFails() {
    if (isset(Searchd::$cmd)) {
      Searchd::$cmd = null;
    }
    $this->expectException(InvalidArgumentException::class);
    Searchd::getConfigPath();
  }

  public function testGetConfigPath() {
    Searchd::init();
    $config_path = Searchd::getConfigPath();
    $this->assertFileExists($config_path);
  }
}
