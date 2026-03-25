<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

/**
 * Interface for backup storage backends (local filesystem, S3, etc.)
 */
interface StorageInterface {
	/**
	 * Get the backup directory path
	 *
	 * @return string|null
	 * @throws \RuntimeException
	 */
	/**
	 * Get the backup directory path
	 *
	 * @return string|null
	 * @throws \RuntimeException
	 */
	public function getBackupDir(): ?string;

	/**
	 * Get full backup path with protocol prefix (e.g., s3://bucket/path or /local/path)
	 *
	 * @return string
	 */
	public function getFullBackupPath(): string;
	/**
	 * Set the target directory for backup/restore
	 *
	 * @param string $dir
	 * @return static
	 */
	public function setTargetDir(string $dir): static;

	/**
	 * Get backup paths (root, data, config, state)
	 * For backup: creates new backup structure
	 * For restore: returns existing backup paths
	 *
	 * @return non-empty-array<'config'|'data'|'root'|'state',string>
	 * @throws \Manticoresearch\Backup\Exception\InvalidPathException
	 */
	public function getBackupPaths(): array;

	/**
	 * Set existing backup paths for restore
	 *
	 * @param string $dir
	 * @return static
	 * @throws \InvalidArgumentException
	 */
	public function setBackupPathsUsingDir(string $dir): static;

	/**
	 * Copy files/directories to destination
	 *
	 * @param array<string> $paths Source paths
	 * @param string $to Destination
	 * @param bool $preservePath Keep directory structure
	 * @return bool
	 * @throws \Manticoresearch\Backup\Exception\InvalidPathException
	 */
	public function copyPaths(array $paths, string $to, bool $preservePath = false): bool;

	/**
	 * Get iterator for files in directory
	 *
	 * @param string $dir
	 * @param int $flags
	 * @return \Iterator
	 */
	public static function getFileIterator(string $dir, int $flags = 0): \Iterator;

	/**
	 * Get sorted iterator for files in directory
	 *
	 * @param string $dir
	 * @param int $flags
	 * @return \Iterator
	 */
	public static function getSortedFileIterator(string $dir, int $flags = 0): \Iterator;

	/**
	 * Extract original path from backup path
	 *
	 * @param string $backupPath
	 * @return string
	 */
	public function getOriginRealPath(string $backupPath): string;

	/**
	 * Clean up partial backup on failure
	 *
	 * @return void
	 */
	public function cleanUp(): void;

	/**
	 * Create directory
	 * Create directory
	 *
	 * @param string $dir
	 * @param string|null $origin
	 * @param bool $recursive
	 * @return void
	 * @throws \Manticoresearch\Backup\Exception\InvalidPathException
	 */
	public static function createDir(string $dir, ?string $origin = null, bool $recursive = false): void;

	/**
	 * Check if directory has files
	 *
	 * @param string $dir
	 * @return bool
	 */
	public static function hasFiles(string $dir): bool;

	/**
	 * Calculate total size of files
	 *
	 * @param array<string> $files
	 * @return int
	 */
	public static function calculateFilesSize(array $files): int;

	/**
	 * Get checksum of directory
	 *
	 * @param string $path
	 * @return string
	 * @throws \RuntimeException
	 */
	public static function getPathChecksum(string $path): string;

	/**
	 * Delete directory recursively
	 *
	 * @param string $dir
	 * @param bool $removeSelf
	 * @return void
	 */
	/**
	 * Delete directory recursively
	 *
	 * @param string $dir
	 * @param bool $removeSelf
	 * @return void
	 */
	public static function deleteDir(string $dir, bool $removeSelf = true): void;

	/**
	 * Write content to a file in storage
	 *
	 * @param string $path Path relative to backup root
	 * @param string $content Content to write
	 * @return bool
	 */
	public function putContents(string $path, string $content): bool;

	/**
	 * Read content from a file in storage
	 *
	 * @param string $path Path relative to backup root
	 * @return string
	 * @throws \RuntimeException
	 */
	public function getContents(string $path): string;

	/**
	 * Get list of uploaded files (relative to backup root)
	 * Used for building manifest during backup
	 *
	 * @return array<string>
	 */
	public function getUploadedFiles(): array;

	/**
	 * Clear the list of uploaded files
	 * Called after manifest is stored
	 */
	public function clearUploadedFiles(): void;
}
