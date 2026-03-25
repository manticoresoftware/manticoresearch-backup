<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\TestCase;

class S3StorageTest extends TestCase {
	private function makeStorage(MockHandler $handler, string $url = 's3://my-bucket/backups'): MockS3Storage {
		// Credentials must be set for the constructor
		putenv('AWS_ACCESS_KEY_ID=test-key');
		putenv('AWS_SECRET_ACCESS_KEY=test-secret');
		putenv('AWS_S3_ENCRYPTION=0');
		putenv('AWS_REGION=us-east-1');

		$storage = new MockS3Storage($url);
		$storage->injectMockHandler($handler);
		return $storage;
	}

	// -------------------------------------------------------------------------
	// listBackups()
	// -------------------------------------------------------------------------

	public function testListBackupsReturnsSortedNames(): void {
		$handler = new MockHandler();
		$handler->append(
			new Result(
				[
				'CommonPrefixes' => [
				['Prefix' => 'backups/backup-20260310120000/'],
				['Prefix' => 'backups/backup-20260312050529/'],
				['Prefix' => 'backups/backup-20260311080000/'],
				['Prefix' => 'backups/not-a-backup/'],   // should be ignored
				],
				]
			)
		);

		$storage = $this->makeStorage($handler);
		$backups = $storage->listBackups();

		$this->assertSame(
			[
			'backup-20260310120000',
			'backup-20260311080000',
			'backup-20260312050529',
			], $backups
		);
	}

	public function testListBackupsEmptyBucket(): void {
		$handler = new MockHandler();
		$handler->append(new Result(['CommonPrefixes' => []]));

		$storage = $this->makeStorage($handler);
		$this->assertSame([], $storage->listBackups());
	}

	public function testListBackupsNoCommonPrefixesKey(): void {
		// S3 omits CommonPrefixes entirely when there are no results
		$handler = new MockHandler();
		$handler->append(new Result([]));

		$storage = $this->makeStorage($handler);
		$this->assertSame([], $storage->listBackups());
	}

	public function testListBackupsR2NoSuchKeyReturnsEmpty(): void {
		// Cloudflare R2 returns NoSuchKey instead of an empty list
		$handler = new MockHandler();
		$handler->append(
			function (Command $cmd) {
				throw new S3Exception(
					'NoSuchKey',
					$cmd,
					['code' => 'NoSuchKey']
				);
			}
		);

		$storage = $this->makeStorage($handler);
		$this->assertSame([], $storage->listBackups());
	}

	public function testListBackupsNoSuchBucketReturnsEmpty(): void {
		$handler = new MockHandler();
		$handler->append(
			function (Command $cmd) {
				throw new S3Exception(
					'NoSuchBucket',
					$cmd,
					['code' => 'NoSuchBucket']
				);
			}
		);

		$storage = $this->makeStorage($handler);
		$this->assertSame([], $storage->listBackups());
	}

	public function testListBackupsOtherS3ExceptionRethrows(): void {
		$handler = new MockHandler();
		$handler->append(
			function (Command $cmd) {
				throw new S3Exception(
					'AccessDenied',
					$cmd,
					['code' => 'AccessDenied']
				);
			}
		);

		$storage = $this->makeStorage($handler);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Failed to list backups/');
		$storage->listBackups();
	}

	// -------------------------------------------------------------------------
	// getBackupPaths()
	// -------------------------------------------------------------------------

	public function testGetBackupPathsStructure(): void {
		// getBackupPaths() calls createS3Marker (putObject) 3 times for config/state/data
		$handler = new MockHandler();
		$handler->append(new Result([])); // config marker
		$handler->append(new Result([])); // state marker
		$handler->append(new Result([])); // data marker

		$storage = $this->makeStorage($handler, 's3://my-bucket/backups');
		$paths = $storage->getBackupPaths();

		$this->assertArrayHasKey('root', $paths);
		$this->assertArrayHasKey('config', $paths);
		$this->assertArrayHasKey('state', $paths);
		$this->assertArrayHasKey('data', $paths);

		// All paths must be under the prefix
		foreach (['config', 'state', 'data'] as $dir) {
			$this->assertStringStartsWith('backups/backup-', $paths[$dir]);
			$this->assertStringEndsWith('/' . $dir, $paths[$dir]);
		}

		// Calling again returns the same cached result (no extra S3 calls)
		$this->assertSame($paths, $storage->getBackupPaths());
	}

	public function testGetBackupPathsNoPrefixBucket(): void {
		$handler = new MockHandler();
		$handler->append(new Result([]));
		$handler->append(new Result([]));
		$handler->append(new Result([]));

		// s3://my-bucket with no prefix
		$storage = $this->makeStorage($handler, 's3://my-bucket');
		$paths = $storage->getBackupPaths();

		// root should be just backup-YYYYMMDDHHIISS (no leading slash or prefix)
		$this->assertMatchesRegularExpression('/^backup-\d{14}$/', $paths['root']);
	}

	// -------------------------------------------------------------------------
	// getOriginRealPath()
	// -------------------------------------------------------------------------

	public function testGetOriginRealPathConfig(): void {
		$storage = $this->makeStorage(new MockHandler());
		// config section preserves original absolute path under config/
		$backupPath = '/tmp/manticore-backup-restore-backup-20260312050529'
			. '/backup-20260312050529/config/etc/manticoresearch/manticore.conf';
		$this->assertSame('/etc/manticoresearch/manticore.conf', $storage->getOriginRealPath($backupPath));
	}

	public function testGetOriginRealPathState(): void {
		$storage = $this->makeStorage(new MockHandler());
		$backupPath = '/tmp/manticore-backup-restore-backup-20260312050529'
			. '/backup-20260312050529/state/var/lib/manticore/binlog.meta';
		$this->assertSame('/var/lib/manticore/binlog.meta', $storage->getOriginRealPath($backupPath));
	}

	public function testGetOriginRealPathData(): void {
		$storage = $this->makeStorage(new MockHandler());
		// data section returns relative path (table/file), no leading separator
		$backupPath = '/tmp/manticore-backup-restore-backup-20260312050529'
			. '/backup-20260312050529/data/mytable/mytable.spd';
		$this->assertSame('mytable/mytable.spd', $storage->getOriginRealPath($backupPath));
	}

	public function testGetOriginRealPathStripsZst(): void {
		$storage = $this->makeStorage(new MockHandler());
		$backupPath = '/tmp/manticore-backup-restore-backup-20260312050529'
			. '/backup-20260312050529/config/etc/manticoresearch/manticore.conf.zst';
		$this->assertSame('/etc/manticoresearch/manticore.conf', $storage->getOriginRealPath($backupPath));
	}

	public function testGetOriginRealPathNoBackupDirReturnsAsIs(): void {
		$storage = $this->makeStorage(new MockHandler());
		$this->assertSame('/some/random/path', $storage->getOriginRealPath('/some/random/path'));
	}

	// -------------------------------------------------------------------------
	// copyPaths() — restore direction (local absolute destination)
	// -------------------------------------------------------------------------

	public function testCopyPathsRestoreDirectionCopiesLocally(): void {
		$storage = $this->makeStorage(new MockHandler());

		$srcDir = sys_get_temp_dir() . '/s3storage-test-src-' . uniqid();
		$dstDir = sys_get_temp_dir() . '/s3storage-test-dst-' . uniqid();
		mkdir($srcDir, 0755, true);
		mkdir($dstDir, 0755, true);

		$srcFile = $srcDir . '/test.txt';
		file_put_contents($srcFile, 'hello');

		// Absolute $to path → must copy locally, not upload to S3
		$result = $storage->copyPaths([$srcFile], $dstDir);

		$this->assertTrue($result);
		$this->assertFileExists($dstDir . '/test.txt');
		$this->assertSame('hello', file_get_contents($dstDir . '/test.txt'));

		// Cleanup
		unlink($srcFile);
		unlink($dstDir . '/test.txt');
		rmdir($srcDir);
		rmdir($dstDir);
	}

	// -------------------------------------------------------------------------
	// createDir() — restore direction (local absolute path)
	// -------------------------------------------------------------------------

	public function testCreateDirRestoreDirectionCreatesLocalDir(): void {
		$dir = sys_get_temp_dir() . '/s3storage-test-createdir-' . uniqid();
		$this->assertDirectoryDoesNotExist($dir);

		MockS3Storage::createDir($dir);

		$this->assertDirectoryExists($dir);
		rmdir($dir);
	}

	public function testCreateDirS3KeyIsNoop(): void {
		// S3 key (no leading slash) — must not throw or create anything
		MockS3Storage::createDir('backups/backup-20260312050529/data');
		$this->assertTrue(true); // no exception = pass
	}
}
