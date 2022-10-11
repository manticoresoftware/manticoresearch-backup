<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class FileStorage {
  const DIR_PERMISSION = 0755;

  /** @var string */
  protected string $backup_dir;

  /**
   * We store paths for current backup here
   *
   * @var non-empty-array<'config'|'data'|'root'|'state',non-falsy-string>
   */
  protected array $backup_paths;

  /**
   * @param ?string $backup_dir
   *  The root destination of all files to be copied
   * @param bool $use_compression
   *  The flag that shows if we should to use compression with zstd or not
   */
  public function __construct(?string $backup_dir, protected bool $use_compression = false) {
    if (isset($backup_dir)) {
      $this->setTargetDir($backup_dir);
    }
  }

  /**
   * Getter for $this->backup_dir
   *
   * @return string
   * @throws RuntimeException
   */
  public function getBackupDir(): ?string {
    if (!isset($this->backup_dir)) {
      throw new RuntimeException('Backup dir is not initialized.');
    }

    return $this->backup_dir;
  }

  /**
   * We need it mostly for tests, but maybe in future also
   *
   * @param string $dir
   * @return static
   */
  public function setTargetDir(string $dir): static {
    $this->backup_dir = rtrim($dir, DIRECTORY_SEPARATOR);
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
    if (is_dir($dir)) {
      throw new InvalidPathException("Failed to create directory because it exists already: $dir");
    }

    $result = mkdir($dir, static::DIR_PERMISSION, $recursive);
    if (false === $result) {
      throw new InvalidPathException('Failed to create directory – "' . $dir . '"');
    }

    if ($origin) {
      static::transferOwnership($origin, $dir, $recursive);
    }
  }

  /**
   * This function transfer ownership and permissions from one to another path
   *
   * @param string $from
   *  The path which ownership we transfer from
   * @param string $to
   *  The path where we transfer ownership to
   * @param bool $recursive
   *  If we should transfer it in recursive way folder by folder
   * @return void
   * @throws RuntimeException
   */
  public static function transferOwnership(string $from, string $to, bool $recursive = false): void {
    if (basename($from) !== basename($to)) {
      return;
    }

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

    // In case we need to transfer recursive we do self function call
    // and it goes while the directory name matches exactly in name
    if ($recursive) {
      $from_pos = strrpos($from, DIRECTORY_SEPARATOR);
      $to_pos = strrpos($to, DIRECTORY_SEPARATOR);
      if (false !== $from_pos && false !== $to_pos) {
        static::transferOwnership(
          substr($from, 0, $from_pos),
          substr($to, 0, $to_pos)
        );
      }
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
      throw new InvalidPathException('Cannot write to backup directory - "' . $root_dir . '"');
    }

    $result = true;

    $from_len = strlen($from) + 1;
    $FileIterator = static::getFileIterator($from);
    /** @var SplFileInfo $File */
    foreach ($FileIterator as $File) {
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
      // If checksum mismatch we fail immediately
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
      throw new InvalidPathException('Cannot write to backup directory - "' . $to . '"');
    }

    $result = array_reduce($paths, function (bool $carry, string $path) use ($preserve_path, $to) {
      $dest = $to . ($preserve_path ? $path : (DIRECTORY_SEPARATOR . basename($path))); // $path - absolute path
      if ($preserve_path) {
        $dir = is_file($path) ? dirname($dest) : $dest;
        if (!is_dir($dir)) {
          $this->createDir($dir, dirname($path), true);
        }
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
    $FileIterator = static::getFileIterator($path);
    /** @var SplFileInfo $File */
    foreach ($FileIterator as $File) {
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
    $FileIterator = static::getFileIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);

    /** @var SplFileInfo $FileInfo */
    foreach ($FileIterator as $FileInfo) {
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
   * This methods sets the current backup path to use
   * @param string $dir
   *  Just directory that belongs to backup_dir path
   * @return static
   */
  public function setBackupPathsUsingDir(string $dir): static {
    $destination = $this->backup_dir . DIRECTORY_SEPARATOR . $dir;
    // state – all global state files are stored here

    $result = [];
    $result['root'] = $destination;

    // Now lets create additional directories
    foreach (['data', 'config', 'state'] as $dir) {
      $path = $destination . DIRECTORY_SEPARATOR . $dir;
      $result[$dir] = $path;

      if (!is_dir($path)) {
        throw new InvalidArgumentException("Cannot find '$dir' in '$destination'");
      }
    }

    $this->backup_paths = $result;
    return $this;
  }

  /**
   * Get current file storage final backup destination
   *
   * @return non-empty-array<'config'|'data'|'root'|'state',non-falsy-string>
   *  Absolute paths for storing different data types
   * @throws InvalidPathException
   */
  public function getBackupPaths(): array {
    if (!isset($this->backup_paths)) {
      $destination = $this->backup_dir . DIRECTORY_SEPARATOR . 'backup-' . gmdate('YmdHis');
      // Check that backup dir is writable
      if (!is_writable($this->backup_dir)) {
        throw new InvalidPathException('Backup directory is not writable');
      }

      // Do not let backup in same existing directory
      if (is_dir($destination)) {
        throw new InvalidPathException(
          'Failed to create backup directory for the backup, the dir already exists: ' . $destination
        );
      }

      $is_ok = mkdir($destination, 0755);
      if (false === $is_ok) {
        throw new InvalidPathException('Failed to create directory – "' . $destination . '"');
      }

      // Backup directory consists of next folders
      // data - tables stored here (from data dir and other files from there)
      // config – config related directory, we store there manticore.conf for all index backup
      // state – all global state files are stored here

      $result = [];
      $result['root'] = $destination;

      // Now lets create additional directories
      foreach (['data', 'config', 'state'] as $dir) {
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
   * This is helper func to extract full qualified original path from
   *  the backup path we have
   * @param string $backup_path
   * @return string
   *  Extracted original preserved path
   */
  public function getOriginRealPath(string $backup_path): string {
    $backup_paths = $this->getBackupPaths();
    $root_len = strlen($backup_paths['root']) + 1; // + 1 for dir separator
    $real_path = substr($backup_path, $root_len);
    $preserved_path = str_replace(['config', 'state'], '', $real_path, $count);
    if ($count > 0) {
      return $preserved_path;
    }

    return substr($real_path, 5); // strlen of "data/" = 5
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

  /**
   * Get recursive iterator for all paths inside wanted dir
   *
   * @param string $dir
   *  The directory where we should look into
   * @param int $flags
   * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
   */
  public static function getFileIterator(
      string $dir,
      int $flags = FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO
  ): RecursiveIteratorIterator {
    return new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, $flags),
      RecursiveIteratorIterator::CHILD_FIRST
    );
  }
}
