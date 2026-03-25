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
 * Factory to create storage backend based on backup directory URL
 */
class StorageFactory {
	/**
	 * Create storage backend based on protocol
	 * s3://bucket/prefix -> S3Storage
	 * /path/to/dir -> FileStorage
	 *
	 * @param string|null $backupDir
	 * @param bool $useCompression
	 * @return StorageInterface
	 */
	public static function create(?string $backupDir, bool $useCompression = false): StorageInterface {
		if ($backupDir !== null && str_starts_with($backupDir, 's3://')) {
			return new S3Storage($backupDir, $useCompression);
		}

		return new FileStorage($backupDir, $useCompression);
	}
}
