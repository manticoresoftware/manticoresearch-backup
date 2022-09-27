<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FileStorageTest extends TestCase {
  public function testDirCreated() {
    $tmp_dir = FileStorage::getTmpDir();
    $dir = $tmp_dir . DIRECTORY_SEPARATOR . 'test-dir-' . uniqid();
    $Storage = new FileStorage($tmp_dir, false);

    $this->assertDirectoryDoesNotExist($dir);
    $Storage->createDir($dir);
    $this->assertDirectoryExists($dir);
  }

  public function testCopyPathsWithoutOwnership() {
    $tmp_dir = FileStorage::getTmpDir();
    $paths = [
      $tmp_dir . DIRECTORY_SEPARATOR . 'source-dir-'. uniqid(), // dir
      $tmp_dir . DIRECTORY_SEPARATOR . 'source-file-'. uniqid(), // file
    ];
    $target = $tmp_dir . DIRECTORY_SEPARATOR . 'target-path-' . uniqid();
    $Storage = new FileStorage($tmp_dir, false);
    $Storage->createDir($target);

    $this->expectException(InvalidPathException::class);
    $Storage->copyPaths($paths, $target);

    $Storage->createDir($paths[0]);
    $this->expectException(InvalidPathException::class);
    $Storage->copyPaths($paths, $target);

    file_put_contents($paths[1], random_bytes(128));
    $Storage->copyPaths($paths, $target);
    $this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . basename($paths[0]));
    $this->assertFileExists($target . DIRECTORY_SEPARATOR . basename($paths[1]));
  }

  public function testCopyPathsWithOwnershipTransfer() {
    if (!OS::isWindows() && posix_getuid() !== 0) {
      throw new Exception('This test should be run under root username');
    }

    $tmp_dir = FileStorage::getTmpDir();

    // We have user test added on bootstrapping
    $paths = [
      $tmp_dir . DIRECTORY_SEPARATOR . 'source-dir-'. uniqid(), // dir
      $tmp_dir . DIRECTORY_SEPARATOR . 'source-file-'. uniqid(), // file
    ];
    $target = $tmp_dir . DIRECTORY_SEPARATOR . 'target-path-' . uniqid();
    mkdir($target, 0755);
    mkdir($paths[0], 0755);

    $Storage = new FileStorage($tmp_dir, false);

    file_put_contents($paths[1], random_bytes(128));
    chown($paths[0], 'test');
    chown($paths[1], 'test');

    $Storage->copyPaths($paths, $target, false);
    $this->assertDirectoryExists($target . DIRECTORY_SEPARATOR . basename($paths[0]));
    $this->assertFileExists($target . DIRECTORY_SEPARATOR . basename($paths[1]));
    $this->assertEquals(fileowner($paths[0]), fileowner($target . DIRECTORY_SEPARATOR . basename($paths[0])));
    $this->assertEquals(fileowner($paths[1]), fileowner($target . DIRECTORY_SEPARATOR . basename($paths[1])));
  }
}