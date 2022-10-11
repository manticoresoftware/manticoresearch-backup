<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class ManticoreClientTest extends SearchdTestCase {
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

	public function testGetTables(): void {
		$tables = array_keys($this->Client->getTables());
		$this->assertEquals(5, sizeof($tables));
		$this->assertContains('movie', $tables);
		$this->assertContains('people', $tables);
		$this->assertContains('people_pq', $tables);
		$this->assertContains('people_dist_local', $tables);
		$this->assertContains('people_dist_agent', $tables);
	}
}
