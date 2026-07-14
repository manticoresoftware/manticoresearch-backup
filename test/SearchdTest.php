<?php declare(strict_types=1);

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Backup\Lib\Searchd;

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class SearchdTest extends SearchdTestCase {
	public function testGetConfigPath(): void {

		$configPath = Searchd::getConfigPath();
		$this->assertFileExists($configPath);
	}

	public function testGetConfigPathsFromEnvironment(): void {
		$previous = getenv('MANTICORE_CONFIG');
		putenv('MANTICORE_CONFIG=/tmp/first.conf| /tmp/second.conf ');

		try {
			$this->assertSame(['/tmp/first.conf', '/tmp/second.conf'], Searchd::getConfigPaths());
		} finally {
			$previous === false ? putenv('MANTICORE_CONFIG') : putenv('MANTICORE_CONFIG=' . $previous);
		}
	}

	public function testIsEndpointReachable(): void {
		$config = new ManticoreConfig(Searchd::getConfigPath());

		$this->assertTrue(Searchd::isEndpointReachable($config));
		$this->assertTrue(Searchd::isRunning($config));
	}

	public function testIsEndpointReachableReturnsFalseWhenEndpointIsUnavailable(): void {
		$config = $this->createConfigWithUnavailableEndpoint();

		$this->assertFalse(Searchd::isEndpointReachable($config));
		$this->assertFalse(Searchd::isRunning($config));
	}

	protected function createConfigWithUnavailableEndpoint(): ManticoreConfig {
		$root = FileStorage::getTmpDir() . DIRECTORY_SEPARATOR . 'unreachable-config-' . uniqid();
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
}
