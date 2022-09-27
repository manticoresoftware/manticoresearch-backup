<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ManticoreConfigTest extends TestCase {
  public function testParsingIsValid() {
    $tmp_dir = FileStorage::getTmpDir();
    $config_path = $tmp_dir . DIRECTORY_SEPARATOR . 'manticore.conf';
    // TODO: use windows paths for windows machine tests run
    file_put_contents($config_path, <<<"EOF"
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
    EOF);
    $Config = new ManticoreConfig($config_path);
    $this->assertEquals($config_path, $Config->path);
    $this->assertEquals('127.0.0.1', $Config->host);
    $this->assertEquals(9308, $Config->port);
    $this->assertEquals('/usr/local/var/manticore', $Config->data_dir);
    $this->assertEquals('/usr/local/lib/manticore', $Config->plugin_dir);
    $this->assertEquals('/usr/local/var/manticore/manticore.json', $Config->schema_path);
  }
}
