<?php declare(strict_types=1);

class SearchdTest extends SearchdTestCase {
  public function testGetConfigPathWithoutInitFails(): void {
    if (isset(Searchd::$cmd)) {
      Searchd::$cmd = null;
    }
    $this->expectException(InvalidArgumentException::class);
    Searchd::getConfigPath();
  }

  public function testGetConfigPath(): void {
    Searchd::init();
    $config_path = Searchd::getConfigPath();
    $this->assertFileExists($config_path);
  }
}
