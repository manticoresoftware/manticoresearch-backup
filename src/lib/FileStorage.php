<?php declare(strict_types=1);

class FileStorage {
  const DIR_PERMISSION = 0755;

  /** @var string */
  protected string $target_dir;

  /**
   * We store paths for current backup here
   *
   * @var non-empty-array<'config'|'data'|'external'|'root'|'state',non-falsy-string>
   */
  protected array $backup_paths;

  /**
   * @param ?string $target_dir
   *  The root destination of all files to be copied
   * @param bool $use_compression
   *  The flag that shows if we should to use compression with zstd or not
   */
  public function __construct(?string $target_dir, protected bool $use_compression = false) {
    if (isset($target_dir)) {
      $this->setTargetDir($target_dir);
    }
  }

  /**
   * Getter for $this->target_dir
   *
   * @return string
   * @throws RuntimeException
   */
  public function getTargetDir(): ?string {
    if (!isset($this->target_dir)) {
      throw new RuntimeException('Target dir is not initialized.');
    }

    return $this->target_dir;
  }

  /**
   * We need it mostly for tests, but maybe in future also
   *
   * @param string $dir
   * @return static
   */
  public function setTargetDir(string $dir): static {
    $this->target_dir = rtrim($dir, DIRECTORY_SEPARATOR);
    return $this;
  }

  /**
   * Getter for $this->use_compression
   *
   * @return bool
   */
  public function getUseCompression(): bool {
    return $this->use_compression;
  }

  /**
   * Helper function to create directory base on original directory ownership
   *
   * @param string $dir
   *  Directory to create (absolute path)
   * @param ?string $origin
   *  Path to origin directory which ownership we need to preserve
   * @return void
   * @throws InvalidPathException
   */
  public static function createDir(string $dir, ?string $origin = null, bool $recursive = false): void {
    $result = mkdir($dir, static::DIR_PERMISSION, $recursive);
    if (false === $result) {
      throw new InvalidPathException('Failed to create directory – "' . $dir . '"');
    }

    if ($origin) {
      static::transferOwnership($origin, $dir);
    }
  }

  /**
   * This function transfer ownership and permissions from one to another path
   *
   * @param string $from
   *  The path which ownership we transfer from
   * @param string $to
   *  The path where we transfer ownership to
   * @return void
   * @throws RuntimeException
   */
  public static function transferOwnership(string $from, string $to): void {
    $file_uid = fileowner($from);
    $file_gid = filegroup($from);
    $file_perm = fileperms($from);
    if (false === $file_uid || false === $file_gid || false === $file_perm) {
      throw new RuntimeException('Failed to find out file ownership info for source path: ' . $from);
    }

    // Next functions works only on non windows systems
    if (!OS::isWindows()) {
      chown($to, $file_uid);
      chgrp($to, $file_gid);
      chmod($to, $file_perm);
    }
  }

  /**
   * Copy files from one directory to another in recursive way
   *
   * @param string $from
   *  From which directory we are going to copy files
   * @param string $to
   *  Destination directory where all files will go
   */
  protected function copyDir(string $from, string $to): bool {
    if (!is_dir($from) || !is_readable($from)) {
      throw new InvalidPathException('Cannot read from source directory - "' . $from . '"');
    }

    $root_dir = dirname($to);
    if (!is_dir($root_dir) || !is_writeable($root_dir)) {
      throw new InvalidPathException('Cannot write to target directory - "' . $root_dir . '"');
    }

    $result = true;

    $from_len = strlen($from) + 1;
    /** @var SplFileInfo $File */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from)) as $File) {
      $dest_dir = $to . DIRECTORY_SEPARATOR . substr($File->getPath(), $from_len);

      // Skip directories
      if ($File->isDir()) {
        // Create dir if it does not exist
        if (!is_dir($dest_dir)) {
          $this->createDir($dest_dir, $File->getPath());
        }
        continue;
      }

      $result = $result && $this->copyFile(
        $File->getPathname(),
        $dest_dir . DIRECTORY_SEPARATOR . $File->getBasename()
      );
    }

    return $result;
  }

  /**
   * Helpe function to copy file and preserve ownership of it
   *
   * @param string $from
   *  Original file to copy
   * @param string $to
   *  Destination file
   * @return bool
   *  The result of copying
   * @throws InvalidPathException
   * @throws ChecksumException
   */
  protected function copyFile(string $from, string $to): bool {
    // We copy in 3 steps
    // 1. Copy
    // 2. Validate checksum for consistency of write
    // 3. Transfer ownership
    if (!is_readable($from)) {
      throw new InvalidPathException(__FUNCTION__ . ': failed to read the path: ' . $from);
    }

    if (!is_writable(dirname($to))) {
      throw new InvalidPathException(__FUNCTION__ . ': the destination to copy to is not writable');
    }

    $zstd_prefix = '';
    if ($this->use_compression) {
      $to .= '.zst';
      if (!function_exists('zstd_compress')) {
        throw new RuntimeException(
          'Failed to find zstd_compress please make sure that you have ZSTD extensions compiled in'
        );
      }
      $zstd_prefix = 'compress.zstd://';
    }
    $result = copy($from, $zstd_prefix . $to);

    if (!$this->use_compression) {
      // If checksum missmatch we fail immediately
      if (md5_file($from, true) !== md5_file($to, true)) {
        throw new ChecksumException(
          'Failed to validate checksum for copying file from "' . $from . '" to "' . $to . '"'
        );
      }
    }

    static::transferOwnership($from, $to);
    return $result;
  }

  /**
   * Copy files from one directory to another in recursive way
   *
   * @param array<string> $paths
   *  List of files and/or directroies to copy
   * @param string $to
   *  Destination directory where all files will go
   * @param bool $preserve_path
   *  Should we preserve source folder structure or just copy files to destination
   * @return bool
   *  Result of the operation
   */
  public function copyPaths(array $paths, string $to, bool $preserve_path = false): bool {
    if (!is_dir($to) || !is_writeable($to)) {
      throw new InvalidPathException('Cannot write to target directory - "' . $to . '"');
    }

    $result = array_reduce($paths, function (bool $carry, string $path) use ($preserve_path, $to) {
      $dest = $to . ($preserve_path ? $path : (DIRECTORY_SEPARATOR . basename($path))); // $path - absolute path
      if ($preserve_path) {
        $this->createDir(is_file($path) ? dirname($dest) : $dest, dirname($path), true);
      }
      if (is_file($path)) {
        $is_ok = $this->copyFile($path, $dest);
      } else {
        $is_ok = $this->copyDir($path, $dest);
      }
      $carry = $carry && $is_ok;
      return $carry;
    }, true);

    return $result;
  }

  /**
   * This function helps us to calculate summary size of passed files list
   *
   * @param array<string> $files
   *  List of files to check total filesize
   * @return int
   *  Sum of all files sizes in bytes
   */
  public static function calculateFilesSize(array $files): int {
    return array_reduce($files, function (int $carry, string $file) {
      $carry += filesize($file);
      return $carry;
    }, 0);
  }

  /**
   * Get checksum of required directory files to check consistency of two folders
   *
   * @param string $path
   * @return string
   *  MD5 sum of all files in required directory
   */
  public static function getPathChecksum(string $path): string {
    $files = [];

    // In case the path is simple file we just return md5 of it
    if (is_file($path)) {
      $checksum = md5_file($path);
      if (false === $checksum) {
        throw new RuntimeException('Failed to get checksum for file: ' . $path);
      }
      return $checksum;
    }

    // In case if path is a directory, we do recursive check
    /** @var SplFileInfo $File */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $File) {
      if (!$File->isFile()) {
        continue;
      }
      $files[] = $File->getPathname();
    }

    $checksums = array_map(md5_file(...), $files);
    sort($checksums, SORT_ASC | SORT_STRING);
    return md5(implode('', $checksums));
  }

  /**
   * This method recursively deletes all files and directories inside specified one
   *
   * @param string $dir
   *  The directory we want to check and delete everything inside
   * @param bool $remove_self
   *  If we should remove passed dir also
   * @return void
   */
  public static function deleteDir(string $dir, bool $remove_self = true): void {
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );

    /** @var SplFileInfo $FileInfo */
    foreach ($files as $FileInfo) {
      $fn = ($FileInfo->isDir() ? 'rmdir' : 'unlink');
      $fn($FileInfo->getRealPath());
    }

    // If we should remove also own directory, just do it
    if ($remove_self) {
      rmdir($dir);
    }
  }

  /**
   * Get tmp directory for project related usage primarely in tests
   * @return string
   *  The path to the temporary dir that contains only files created by us
   */
  public static function getTmpDir(): string {
    $tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manticore-backup';
    if (!is_dir($tmp_dir)) {
      mkdir($tmp_dir, 0777);
    }

    return $tmp_dir;
  }

  /**
   * Get current file storage final backup destination
   *
   * @return non-empty-array<'config'|'data'|'external'|'root'|'state',non-falsy-string>
   *  Absolute paths for storing different data types
   * @throws InvalidPathException
   */
  public function getBackupPaths(): array {
    if (!isset($this->backup_paths)) {
      $destination = $this->target_dir . DIRECTORY_SEPARATOR . 'backup-' . gmdate('YmdHis');
      // Check that target dir is writable
      if (!is_writable($this->target_dir)) {
        throw new InvalidPathException('Target directory is not writable');
      }

      // Do not let backup in same existing directory
      if (is_dir($destination)) {
        throw new InvalidPathException(
          'Failed to create target directory for the backup, the dir already exists: ' . $destination
        );
      }

      $is_ok = mkdir($destination, 0755);
      if (false === $is_ok) {
        throw new InvalidPathException('Failed to create directory – "' . $destination . '"');
      }

      // Backup directory consists of next folders
      // data - tables stored here (from data dir and other files from there)
      // config – config related directory, we store there manticore.conf for all index backup
      // external – all external files for index settings are stored here
      // state – all global state files are stored here

      $result = [];
      $result['root'] = $destination;

      // Now lets create additional directories
      foreach (['data', 'config', 'external', 'state'] as $dir) {
        $path = $destination . DIRECTORY_SEPARATOR . $dir;
        $result[$dir] = $path;
        $is_ok = mkdir($path, 0755);
        if (false === $is_ok) {
          throw new InvalidPathException('Failed to create directory – "' . $path . '"');
        }
      }

      $this->backup_paths = $result;
    }

    return $this->backup_paths;
  }

  /**
   * Thie method is required to clean up partial failed backup
   *  due to we do not support incremental backups or continue it
   *
   * @return void
   */
  public function cleanUp(): void {
    // Do nothing if we have no backup paths at all
    if (!isset($this->backup_paths)) {
      return;
    }

    // Try to delete destination root path if it exists
    if (is_dir($this->backup_paths['root'])) {
      $this->deleteDir($this->backup_paths['root']);
    }
  }
}
