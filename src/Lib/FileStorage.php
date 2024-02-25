<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use FilesystemIterator;
use Manticoresearch\Backup\Exception\ChecksumException;
use Manticoresearch\Backup\Exception\InvalidPathException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class FileStorage {
	const DIR_PERMISSION = 0755;

  /** @var string */
	protected string $backupDir;

  /**
   * We store paths for current backup here
   *
   * @var non-empty-array<'config'|'data'|'root'|'state',string>
   */
	protected array $backupPaths;

  /**
   * @param ?string $backupDir
   *  The root destination of all files to be copied
   * @param bool $useCompression
   *  The flag that shows if we should to use compression with zstd or not
   */
	public function __construct(?string $backupDir, protected bool $useCompression = false) {
		if (!isset($backupDir)) {
			return;
		}

		$this->setTargetDir($backupDir);
	}

  /**
   * Getter for $this->backupDir
   *
   * @return string
   * @throws \RuntimeException
   */
	public function getBackupDir(): ?string {
		if (!isset($this->backupDir)) {
			throw new \RuntimeException('Backup dir is not initialized.');
		}

		return $this->backupDir;
	}

  /**
   * We need it mostly for tests, but maybe in future also
   *
   * @param string $dir
   * @return static
   */
	public function setTargetDir(string $dir): static {
		$this->backupDir = rtrim(backup_realpath($dir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $this;
	}

  /**
   * Getter for $this->useCompression
   *
   * @return bool
   */
	public function getUseCompression(): bool {
		return $this->useCompression;
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


		try {
			mkdir($dir, static::DIR_PERMISSION, $recursive);
		} catch (Throwable) {
			throw new InvalidPathException('Failed to create directory – "' . $dir . '"');
		}

		if (!$origin) {
			return;
		}

		static::transferOwnership($origin, $dir, $recursive);
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
   * @throws \RuntimeException
   */
	public static function transferOwnership(string $from, string $to, bool $recursive = false): void {
		// if we are not root there's nothing we can do
		if (!OS::isRoot()) {
			return;
		}

		if (basename($from) !== basename($to)) {
			return;
		}

		$fileUid = fileowner($from);
		$fileGId = filegroup($from);
		$filePerm = fileperms($from);
		if (false === $fileUid || false === $fileGId || false === $filePerm) {
			throw new \RuntimeException('Failed to find out file ownership info for source path: ' . $from);
		}

	  // Next functions works only on non windows systems
		if (!OS::isWindows()) {
			chown($to, $fileUid);
			chgrp($to, $fileGId);
			chmod($to, $filePerm);
		}

	  // In case we need to transfer recursive we do self function call
	  // and it goes while the directory name matches exactly in name
		if (!$recursive) {
			return;
		}

		$fromPos = strrpos($from, DIRECTORY_SEPARATOR);
		$toPos = strrpos($to, DIRECTORY_SEPARATOR);
		if (false === $fromPos || false === $toPos) {
			return;
		}

		static::transferOwnership(
			substr($from, 0, $fromPos),
			substr($to, 0, $toPos)
		);
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

		$rootDir = dirname($to);
		if (!is_dir($rootDir) || !is_writeable($rootDir)) {
			throw new InvalidPathException('Cannot write to backup directory - "' . $rootDir . '"');
		}

		$result = true;

		$fromLen = strlen($from);
		$fileIterator = static::getFileIterator($from);
	  /** @var \SplFileInfo $file */
		foreach ($fileIterator as $file) {
			$destDir = rtrim($to, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . substr($file->getPath(), $fromLen);

			// Create dir if it does not exist
			if (!is_dir($destDir)) {
				$this->createDir($destDir, $file->getPath(), true);
			}

			// Skip directories
			if ($file->isDir()) {
				continue;
			}

			$result = $result && $this->copyFile(
				$file->getPathname(),
				$destDir . DIRECTORY_SEPARATOR . $file->getBasename()
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

		$validateChecksum = true;
		$zstdPrefix = '';
		if ($this->useCompression) {
			$validateChecksum = false;
			$to .= '.zst';
			static::validateZstdInstalled();
			$zstdPrefix = 'compress.zstd://';
		}

		if (str_ends_with($from, '.zst')) {
			$validateChecksum = false;
			$result = static::decompress($from, $to);
			$zstdPrefix = 'compress.zstd://';
		} else {
			$result = copy($from, $zstdPrefix . $to);
		}


		if ($validateChecksum) {
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
   * @param bool $preservePath
   *  Should we preserve source folder structure or just copy files to destination
   * @return bool
   *  Result of the operation
   */
	public function copyPaths(array $paths, string $to, bool $preservePath = false): bool {
		if (!is_dir($to) || !is_writeable($to)) {
			throw new InvalidPathException('Cannot write to backup directory - "' . $to . '"');
		}

		return array_reduce(
			$paths, function (bool $carry, string $path) use ($preservePath, $to) {
				$dest = $to . (
					$preservePath
						? static::normalizeAbsolutePath($path)
						: (DIRECTORY_SEPARATOR . basename($path))
				); // $path - absolute path
				if ($preservePath) {
					$dir = is_file($path) ? dirname($dest) : $dest;
					if (!is_dir($dir)) {
						$this->createDir($dir, dirname($path), true);
					}
				}
				if (is_file($path)) {
					if (str_ends_with($dest, '.zst')) {
						$dest = substr($dest, 0, -4);
					}
					$isOk = $this->copyFile($path, $dest);
				} else {
					$isOk = $this->copyDir($path, $dest);
				}
				$carry = $carry && $isOk;
				return $carry;
			}, true
		);
	}

	/**
	 * @param string $path
	 * @Return string
	 */
	protected static function normalizeAbsolutePath(string $path): string {
		if ($path[0] !== '/') {
			return DIRECTORY_SEPARATOR . substr($path, 2);
		}

		return $path;
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
		return array_reduce(
			$files, function (int $carry, string $file) {
				$carry += filesize($file);
				return $carry;
			}, 0
		);
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
				throw new \RuntimeException('Failed to get checksum for file: ' . $path);
			}
			return $checksum;
		}

	  // In case if path is a directory, we do recursive check
		$fileIterator = static::getFileIterator($path);
	  /** @var \SplFileInfo $file */
		foreach ($fileIterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$files[] = $file->getPathname();
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
   * @param bool $removeSelf
   *  If we should remove passed dir also
   * @return void
   */
	public static function deleteDir(string $dir, bool $removeSelf = true): void {
		$fileIterator = static::getFileIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);

	  /** @var \SplFileInfo $fileInfo */
		foreach ($fileIterator as $fileInfo) {
			$fn = ($fileInfo->isDir() ? 'rmdir' : 'unlink');
			$fn($fileInfo->getRealPath());
		}

	  // If we should remove also own directory, just do it
		if (!$removeSelf) {
			return;
		}

		rmdir($dir);
	}

  /**
   * Get tmp directory for project related usage primarely in tests
   * @return string
   *  The path to the temporary dir that contains only files created by us
   */
	public static function getTmpDir(): string {
		$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manticore-backup';
		if (!is_dir($tmpDir)) {
			mkdir($tmpDir, 0777);
		}

		return $tmpDir;
	}

  /**
   * This methods sets the current backup path to use
   * @param string $dir
   *  Just directory that belongs to backup_dir path
   * @return static
   */
	public function setBackupPathsUsingDir(string $dir): static {
		$destination = $this->backupDir . $dir;
	  // state – all global state files are stored here

		$result = [];
		$result['root'] = $destination;
	  // Now lets create additional directories
		foreach (['data', 'config', 'state'] as $dir) {
			$path = $destination . DIRECTORY_SEPARATOR . $dir;
			$result[$dir] = $path;

			if (!is_dir($path)) {
				throw new \InvalidArgumentException("Cannot find '$dir' in '$destination'");
			}
		}

		$this->backupPaths = $result;
		return $this;
	}

  /**
   * Get current file storage final backup destination
   *
   * @return non-empty-array<'config'|'data'|'root'|'state',string>
   *  Absolute paths for storing different data types
   * @throws InvalidPathException
   */
	public function getBackupPaths(): array {
		if (!isset($this->backupPaths)) {
			$destination = $this->backupDir . 'backup-' . gmdate('YmdHis');
		  // Check that backup dir is writable
			if (!is_writable($this->backupDir)) {
				throw new InvalidPathException('Backup directory is not writable');
			}

		  // Do not let backup in same existing directory
			if (is_dir($destination)) {
				throw new InvalidPathException(
					'Failed to create backup directory for the backup, the dir already exists: ' . $destination
				);
			}

			$isOk = mkdir($destination, 0755);
			if (false === $isOk) {
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
				$isOk = mkdir($path, 0755);
				if (false === $isOk) {
					throw new InvalidPathException('Failed to create directory – "' . $path . '"');
				}
			}

			$this->backupPaths = $result;
		}

		return $this->backupPaths;
	}

  /**
   * This is helper func to extract full qualified original path from
   *  the backup path we have
   * @param string $backupPath
   * @return string
   *  Extracted original preserved path
   */
	public function getOriginRealPath(string $backupPath): string {
		$backupPaths = $this->getBackupPaths();
		$rootLen = strlen($backupPaths['root']) + 1; // + 1 for dir separator
		$realPath = substr($backupPath, $rootLen);
		if (str_ends_with($realPath, '.zst')) {
			$realPath = substr($realPath, 0, -4);
		}
		$preservedPath = str_replace(['config', 'state'], '', $realPath, $count);
		if ($count > 0) {
			return $preservedPath;
		}

		return substr($realPath, 5); // strlen of "data/" = 5
	}

  /**
   * Thie method is required to clean up partial failed backup
   *  due to we do not support incremental backups or continue it
   *
   * @return void
   */
	public function cleanUp(): void {
	  // Do nothing if we have no backup paths at all
		if (!isset($this->backupPaths)) {
			return;
		}

	  // Try to delete destination root path if it exists
		if (!is_dir($this->backupPaths['root'])) {
			return;
		}

		$this->deleteDir($this->backupPaths['root']);
	}

  /**
   * Get recursive iterator for all paths inside wanted dir
   *
   * @param string $dir
   *  The directory where we should look into
   * @param int $flags
   * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
   */
	public static function getFileIterator(
		string $dir,
		int $flags = \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO
	): \RecursiveIteratorIterator {
		return new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, $flags),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
	}

  /**
   * Same as iterator bu sorted
   * @param array{0:string,1:int} $args
   * @return FileSortingIterator<\RecursiveDirectoryIterator>
   */
	public static function getSortedFileIterator(...$args): FileSortingIterator {
		// @phpstan-ignore-next-line
		return new FileSortingIterator(static::getFileIterator(...$args));
	}


	/**
	 * Check if we have some files in dir that is not hidden (.)
	 * @param  string  $dir
	 * @return bool
	 */
	public static function hasFiles(string $dir): bool {
		$directory = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
		$iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
		$iterator->setMaxDepth(0);

		/** @var \SplFileInfo $fileinfo */
		foreach ($iterator as $fileinfo) {
			if ($fileinfo->isFile() && strpos($fileinfo->getFilename(), '.') !== 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Simple helper to validate that we have installed zstd extension
	 * @return void
	 * @throws RuntimeException
	 */
	protected static function validateZstdInstalled(): void {
		if (!function_exists('zstd_compress')) {
			throw new RuntimeException(
				'Failed to find zstd_compress please make sure that you have ZSTD extensions compiled in'
			);
		}
	}

	/**
	 * Helper function to read the compressed file with all checks and return its contents
	 * @param string $source compressed file name
	 * @param string $target destination to uncompress
	 * @return bool
	 * @throws RuntimeException
	 */
	protected static function decompress(string $source, string $target): bool {
		static::validateZstdInstalled();
		$sourceStream = fopen("compress.zstd://{$source}", 'rb');
		$targetStream = fopen($target, 'wb');
		if (!$sourceStream || !$targetStream) {
			return false;
		}

		while (!feof($sourceStream)) {
			$buffer = fread($sourceStream, 4096);
			if (!$buffer) {
				break;
			}

			$written = fwrite($targetStream, $buffer);
			if (!$written) {
				return false;
			}
		}

	  // Close the streams when we're done
		fclose($sourceStream);
		fclose($targetStream);

		return true;
	}
}
