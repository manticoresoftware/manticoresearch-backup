<?php declare(strict_types=1);

use Manticoresearch\Backup\Exception\SearchdException;
use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Backup\Lib\Searchd;

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class ManticoreClientTest extends SearchdTestCase {
	protected ManticoreClient $client;

	public function setUp(): void {

		$config = new ManticoreConfig(Searchd::getConfigPath());
		$this->client = new ManticoreClient([$config]);
	}

	public function testGetVersions(): void {
		$versions = $this->client->getVersions();
		$this->assertNotEquals('0.0.0', $versions['columnar']);
		$this->assertNotEquals('0.0.0', $versions['secondary']);
		$this->assertNotEquals('0.0.0', $versions['manticore']);
	}

	public function testGetTables(): void {
		$tables = array_keys($this->client->getTables());
		$expectedTables = ['movie', 'people', 'people_pq', 'people_dist_local', 'people_dist_agent'];
		foreach ($expectedTables as $table) {
			$this->assertContains($table, $tables, "Expected table '$table' not found");
		}
	}

	public function testExecuteThrowsSearchdExceptionWhenEndpointIsUnavailable(): void {
		$config = $this->createConfigWithUnavailableEndpoint();
		$client = $this->createClientWithoutVersionCheck($config);

		$this->expectException(SearchdException::class);
		$this->expectExceptionMessage('Failed to send query to the Manticore Search daemon.');
		$client->execute('SHOW TABLES');
	}

	protected function createConfigWithUnavailableEndpoint(): ManticoreConfig {
		$root = FileStorage::getTmpDir() . DIRECTORY_SEPARATOR . 'unreachable-client-' . uniqid();
		$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
		mkdir($dataDir, 0777, true);
		$configPath = $root . DIRECTORY_SEPARATOR . 'manticore.conf';
		file_put_contents(
			$configPath,
			"searchd {\n"
			. "    listen = 127.0.0.1:1:http\n"
			. "    data_dir = $dataDir\n"
			. "}\n"
		);

		return new ManticoreConfig($configPath);
	}

	protected function createClientWithoutVersionCheck(ManticoreConfig $config): ManticoreClient {
		return new ManticoreNoVersionCheckClient([$config]);
	}
}

// @codingStandardsIgnoreStart
class ManticoreNoVersionCheckClient extends ManticoreClient {
  // @codingStandardsIgnoreEnd
	/**
	 * @param array<ManticoreConfig> $configs
	 */
	public function __construct(array $configs) {
		$this->configs = $configs;
	}
}
