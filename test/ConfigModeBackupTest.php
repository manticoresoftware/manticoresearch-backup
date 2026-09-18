<?php declare(strict_types=1);

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreBackup;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use PHPUnit\Framework\TestCase;

class ConfigModeBackupTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = FileStorage::getTmpDir() . '/plain-' . uniqid();
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		FileStorage::deleteDir($this->dir);
	}

	private function config(string $body): ManticoreConfig {
		$path = $this->dir . '/custom.conf';
		file_put_contents($path, "searchd {\n listen = 127.0.0.1:9308:http # endpoint\n}\n" . $body);
		return new ManticoreConfig($path);
	}

	public function testCommentsAndTablePath(): void {
		$config = $this->config("index plain { # comment\n path = {$this->dir}/base # path\n}\n");
		$this->assertFalse(isset($config->dataDir));
		$this->assertFalse(isset($config->schemaPath));
		$this->assertSame($this->dir, $config->getTableDir('plain'));
	}

	public function testTableFilesComeFromTheDaemon(): void {
		$client = new class extends ManticoreClient {
			public string $query;

			public function __construct() {
			}

			public function execute(string $query): array {
				$this->query = $query;
				return [['data' => [
					['normalized' => '/tmp/plain.sph'],
					['normalized' => '/tmp/plain.spa'],
				], 'error' => '']];
			}
		};
		$this->assertSame(['/tmp/plain.sph', '/tmp/plain.spa'], $client->getTableFiles('plain'));
		$this->assertSame('SELECT normalized FROM plain.@files', $client->query);
	}

	public function testInheritanceIsExplicitlyRejected(): void {
		$this->expectExceptionMessage('inheritance');
		$this->config("index base {\n path = /tmp/base\n}\nindex child : base {\n path = /tmp/child\n}\n");
	}

	public function testRelativeTablePathIsRejected(): void {
		$this->expectExceptionMessage('absolute');
		$this->config("index plain {\n path = relative/plain\n}\n");
	}

	/** @dataProvider collisionKinds */
	public function testCompressedRestoreCollision(string $kind): void {
		$config = $this->config("index plain {\n path = {$this->dir}/base\n}\n");
		$storage = new FileStorage($this->dir, true);
		$paths = $storage->getBackupPaths();
		mkdir($paths['data'] . '/plain');
		file_put_contents($paths['data'] . '/plain/base.sph.zst', zstd_compress('backup'));
		$target = $this->dir . '/base.sph';
		match ($kind) {
			'file' => file_put_contents($target, 'DO NOT OVERWRITE'),
			'directory' => mkdir($target),
			'symlink' => symlink($this->dir . '/absent', $target),
			default => throw new InvalidArgumentException($kind),
		};
		$method = new ReflectionMethod(ManticoreBackup::class, 'validateRestore');
		$method->setAccessible(true);
		$this->expectExceptionMessage('Destination file already exists: ' . $target);
		try {
			$method->invoke(null, $storage, $paths['data'], $config);
		} finally {
			if (is_link($target)) {
				unlink($target);
			}
		}
	}

	/** @return array<string,array{string}> */
	public static function collisionKinds(): array {
		return ['file' => ['file'], 'directory' => ['directory'], 'symlink' => ['symlink']];
	}

	public function testLegacyMultipleConfigsStillSelectManticoreConfig(): void {
		$storage = new FileStorage($this->dir);
		$paths = $storage->getBackupPaths();
		foreach (['a.conf', 'manticore.conf', 'z.conf'] as $name) {
			file_put_contents($paths['config'] . '/' . $name, "searchd {\n data_dir = /tmp/data\n}\n");
		}
		$method = new ReflectionMethod(ManticoreBackup::class, 'getConfigFromBackup');
		$method->setAccessible(true);
		$config = $method->invoke(null, $storage, $paths['config']);
		$this->assertInstanceOf(ManticoreConfig::class, $config);
		$this->assertSame($paths['config'] . '/manticore.conf', $config->path);
	}

	public function testAmbiguousBackupConfigIsRejected(): void {
		$storage = new FileStorage($this->dir);
		$paths = $storage->getBackupPaths();
		foreach (['a.conf', 'z.conf'] as $name) {
			file_put_contents($paths['config'] . '/' . $name, "searchd {\n data_dir = /tmp/data\n}\n");
		}
		$method = new ReflectionMethod(ManticoreBackup::class, 'getConfigFromBackup');
		$method->setAccessible(true);
		$this->expectExceptionMessage('Multiple config files');
		$method->invoke(null, $storage, $paths['config']);
	}
}
