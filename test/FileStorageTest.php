<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Exception\InvalidPathException;
use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\OS;
use PHPUnit\Framework\TestCase;

class FileStorageTest extends TestCase {
	public function testDirCreated(): void {
		$tmpDir = FileStorage::getTmpDir();
		$dir = $tmpDir . DIRECTORY_SEPARATOR . 'test-dir-' . uniqid();
		$storage = new FileStorage($tmpDir, false);

		$this->assertDirectoryDoesNotExist($dir);
		$storage->createDir($dir);
		$this->assertDirectoryExists($dir);
	}

	public function testCopyPathsWithoutOwnership(): void {
		$tmpDir = FileStorage::getTmpDir();
		$paths = [
			$tmpDir . DIRECTORY_SEPARATOR . 'source-dir-'. uniqid(), // dir
			$tmpDir . DIRECTORY_SEPARATOR . 'source-file-'. uniqid(), // file
		];
		$target = $tmpDir . DIRECTORY_SEPARATOR . 'target-path-' . uniqid();
		$storage = new FileStorage($tmpDir, false);
		$storage->createDir($target);

		$this->expectException(InvalidPathException::class);
		$storage->copyPaths($paths, $target);

		$storage->createDir($paths[0]);
		$this->expectException(InvalidPathException::class);
		$storage->copyPaths($paths, $target);

		file_put_contents($paths[1], random_bytes(128));
		$storage->copyPaths($paths, $target);
		$this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . basename($paths[0]));
		$this->assertFileExists($target . DIRECTORY_SEPARATOR . basename($paths[1]));
	}

	public function testCopyPathsWithOwnershipTransfer(): void {
		if (!OS::isWindows() && posix_getuid() !== 0) {
			throw new Exception('This test should be run under root username');
		}

		$tmpDir = FileStorage::getTmpDir();

	  // We have user test added on bootstrapping
		$paths = [
			$tmpDir . DIRECTORY_SEPARATOR . 'source-dir-'. uniqid(), // dir
			$tmpDir . DIRECTORY_SEPARATOR . 'source-file-'. uniqid(), // file
		];
		$target = $tmpDir . DIRECTORY_SEPARATOR . 'target-path-' . uniqid();
		mkdir($target, 0755);
		mkdir($paths[0], 0755);

		$storage = new FileStorage($tmpDir, false);

		file_put_contents($paths[1], random_bytes(128));
		$userUid = (int)system('id -u test');
		chown($paths[0], $userUid);
		chown($paths[1], $userUid);

		$storage->copyPaths($paths, $target, false);
		$this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . basename($paths[0]));
		$this->assertFileExists($target . DIRECTORY_SEPARATOR . basename($paths[1]));
		$this->assertEquals(fileowner($paths[0]), fileowner($target . DIRECTORY_SEPARATOR . basename($paths[0])));
		$this->assertEquals(fileowner($paths[1]), fileowner($target . DIRECTORY_SEPARATOR . basename($paths[1])));
	}
}
