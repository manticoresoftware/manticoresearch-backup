<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ManticoreClientTest extends TestCase {
  protected ManticoreClient $Client;

  public function setUp(): void {
    Searchd::init();
    $Config = new ManticoreConfig(Searchd::getConfigPath());
    $this->Client = new ManticoreClient($Config);
  }

  public function testGetVersions(): void {
    $versions = $this->Client->getVersions();
    $this->assertEquals('0.0.0', $versions['columnar']);
    $this->assertEquals('0.0.0', $versions['secondary']);
    $this->assertNotEquals('0.0.0', $versions['manticore']);
  }

  public function testGetIndexes(): void {
    $indexes = array_keys($this->Client->getIndexes());
    $this->assertEquals(5, sizeof($indexes));
    $this->assertContains('movie', $indexes);
    $this->assertContains('people', $indexes);
    $this->assertContains('people_pq', $indexes);
    $this->assertContains('people_dist_local', $indexes);
    $this->assertContains('people_dist_agent', $indexes);
  }

  public function testGetIndexExternalFiles(): void {
    $files = $this->Client->getIndexExternalFiles('movie');
    $this->assertEquals([], $files);
  }
}
