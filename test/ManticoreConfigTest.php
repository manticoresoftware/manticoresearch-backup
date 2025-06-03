<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use PHPUnit\Framework\TestCase;

class ManticoreConfigTest extends TestCase {
	public function testParsingIsValid(): void {
		$tmpDir = FileStorage::getTmpDir();
		$configPath = $tmpDir . DIRECTORY_SEPARATOR . 'manticore.conf';
	  // TODO: use windows paths for windows machine tests run
		file_put_contents(
			$configPath, <<<"EOF"
      common {
        plugin_dir = /usr/local/lib/manticore
      }

      searchd {
          listen = 127.0.0.1:9312
          listen = 127.0.0.1:9306:mysql
          listen = 127.0.0.1:9308:http
          log = /usr/local/var/log/manticore/searchd.log
          query_log = /usr/local/var/log/manticore/query.log
          pid_file = /usr/local/var/run/manticore/searchd.pid
          data_dir = /usr/local/var/manticore
          query_log_format = sphinxql
      }
EOF
		);
		$config = new ManticoreConfig($configPath);
		$this->assertEquals($configPath, $config->path);
		$this->assertEquals('127.0.0.1', $config->host);
		$this->assertEquals(9312, $config->port);
		$this->assertEquals('/usr/local/var/manticore', $config->dataDir);
		$this->assertEquals('/usr/local/var/manticore/manticore.json', $config->schemaPath);
	}
}
