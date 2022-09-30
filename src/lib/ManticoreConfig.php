<?php declare(strict_types=1);

/**
 * Helper config parser for use in backup and client components
 */
class ManticoreConfig {
  public string $path;
  public string $host;
  public int $port;

  public string $data_dir;
  public string $sphinxql_state;
  public string $lemmatizer_base;
  public string $plugin_dir;

  public string $schema_path;

  /**
   * Initialization instance and parse the config
   *
   * @param string $config_path
   *  Path to manticore daemon searchd config
   */
  public function __construct(string $config_path) {
    $config = file_get_contents($config_path);
    if (false === $config) {
        throw new InvalidArgumentException('Failed to read config file: ' . $config_path);
    }

    $this->path = $config_path;
    $this->parse($config);
  }

  /**
   * Parse the Manticore searchd configuration file data and get required parameters from there
   *
   * @param string $config
   *  The content of the config file
   * @return void
   */
  protected function parse(string $config): void {
    // Set defaults first
    $this->host = '127.0.0.1';
    $this->port = 9308;

    // Try to parse and replace defaults
    preg_match_all('/^\s*(listen|data_dir|lemmatizer_base|sphinxql_state|plugin_dir)\s*=\s*(.*)$/ium', $config, $m);
    if ($m) {
      foreach ($m[1] as $n => $key) {
        $value = $m[2][$n];
        if ($n === 'listen') { // in case of we need to parse
          $http_pos = strpos($value, ':http');
          if (false === $http_pos) {
            continue;
          }
          $listen = substr($value, 0, $http_pos);
          if (false === strpos($listen, ':')) {
            $this->port = intval($listen);
          } else {
            $this->host = strtok($listen, ':');
            $this->port = intval(strtok(':'));
          }
        } else { // In this case we have path/file directive
          $this->$key = $value;
        }
      }
    }

    if (!isset($this->data_dir)) {
      throw new InvalidPathException('Failed to detect data_dir from config file');
    }

    $this->schema_path = $this->data_dir . '/manticore.json';

    echo PHP_EOL . 'Manticore config' . PHP_EOL
      . '  endpoint =  ' . $this->host . ':' . $this->port . PHP_EOL
    ;
  }


  /**
   * This functions returns global state files that we can backup
   *
   * @return array<string>
   *   List of absolute paths to each file/directory required to backup
   */
  public function getStatePaths(): array {
    $result = [];
    if (isset($this->sphinxql_state)) {
      $result[] = $this->sphinxql_state;
    }

    if (isset($this->lemmatizer_base)) {
      $result[] = $this->lemmatizer_base;
    }

    if (isset($this->plugin_dir) && is_dir($this->plugin_dir)) {
      $result[] = $this->plugin_dir;
    }

    return $result;
  }
}
