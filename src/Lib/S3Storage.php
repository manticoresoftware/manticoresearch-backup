<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Aws\S3\S3Client;
use Aws\S3\S3Transfer\Models\UploadRequest;
use Aws\S3\S3Transfer\S3TransferManager;
use FilesystemIterator;
use GuzzleHttp\Psr7\Stream;
use Iterator;
use Manticoresearch\Backup\Exception\InvalidPathException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * S3-compatible storage backend for backups
 * Uses AWS SDK for PHP with streaming uploads
 */
class S3Storage implements StorageInterface {
	public const DIR_PERMISSION = 0755;

	protected S3Client $client;
	protected string $bucket;
	protected string $prefix;
	protected ?string $backupDir = null;
	/** @var array<string, string>|null */
	protected ?array $backupPaths = null;
	protected bool $useCompression;
	protected bool $useEncryption;
	/** @var array<string> List of uploaded file paths relative to backup root */
	protected array $uploadedFiles = [];
	/**
	 * @param string $s3Url S3 URL in format: s3://bucket/prefix
	 * @param bool $useCompression
	 * @throws RuntimeException If required environment variables are missing
	 * @throws RuntimeException If bucket name or path contains invalid characters
	 */
	public function __construct(string $s3Url, bool $useCompression = false) {
		[$bucket, $prefix] = $this->parseS3Url($s3Url);
		$this->bucket = $bucket;
		$this->prefix = $prefix;
		$this->useCompression = $useCompression;

		// Encryption: enabled by default for AWS S3, can be disabled via env var
		// Set AWS_S3_ENCRYPTION=0 to disable for MinIO/custom endpoints
		$encryptionEnv = getenv('AWS_S3_ENCRYPTION');
		$this->useEncryption = ($encryptionEnv === false || $encryptionEnv === '')
			? true  // Default: enabled
			: filter_var($encryptionEnv, FILTER_VALIDATE_BOOLEAN);

		$this->client = new S3Client($this->buildS3ClientConfig());
	}

	/**
	 * Parse and validate an S3 URL, returning [bucket, prefix].
	 *
	 * @param string $s3Url
	 * @return array{0: string, 1: string}
	 * @throws RuntimeException
	 */
	protected function parseS3Url(string $s3Url): array {
		$parsed = parse_url($s3Url);
		if ($parsed === false || !isset($parsed['host'])) {
			throw new RuntimeException("Invalid S3 URL format: {$s3Url}");
		}

		$bucket = $parsed['host'];
		if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
			throw new RuntimeException(
				"Invalid S3 bucket name: {$bucket}. " .
				'Bucket names must be 3-63 characters, lowercase, numbers, hyphens, and periods.'
			);
		}

		$prefix = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';
		if ($prefix !== '') {
			$prefix = str_replace(['../', '..\\', '..'], '', $prefix);
			$prefix = preg_replace('/[\x00-\x1F\x7F]/', '', $prefix);
			if ($prefix === null || $prefix === '') {
				throw new RuntimeException("Invalid S3 prefix path in URL: {$s3Url}");
			}
		}

		return [$bucket, $prefix];
	}

	/**
	 * Build S3Client config array from environment variables.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException
	 */
	protected function buildS3ClientConfig(): array {
		$accessKey = getenv('AWS_ACCESS_KEY_ID') ?: null;
		$secretKey = getenv('AWS_SECRET_ACCESS_KEY') ?: null;
		$region = getenv('AWS_REGION') ?: null;
		$endpoint = getenv('AWS_ENDPOINT_URL') ?: null;

		if (!$accessKey || !$secretKey) {
			throw new RuntimeException(
				'S3 credentials required. Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY environment variables.'
			);
		}

		$config = [
			'region' => $region,
			'version' => 'latest',
			'credentials' => [
				'key' => $accessKey,
				'secret' => $secretKey,
			],
			'http' => [
				'connect_timeout' => (int)(getenv('AWS_S3_CONNECT_TIMEOUT') ?: 10),
				// Per-part timeout: 64MB at ~50MB/s = ~1.3s, 600s is very generous
				'timeout' => (int)(getenv('AWS_S3_TIMEOUT') ?: 600),
			],
		];
		if ($endpoint) {
			$config['endpoint'] = $endpoint;
			// Use path-style for custom endpoints (MinIO, Wasabi, etc.)
			$config['use_path_style_endpoint'] = true;
		}

		return $config;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getBackupDir(): ?string {
		return $this->backupDir;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFullBackupPath(): string {
		return 's3://' . $this->bucket . ($this->prefix ? '/' . $this->prefix : '');
	}

	/**
	 * {@inheritdoc}
	 */
	public function setTargetDir(string $dir): static {
		$this->backupDir = $dir;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getBackupPaths(): array {
		if (!isset($this->backupPaths)) {
			$backupName = 'backup-' . gmdate('YmdHis');
			$basePath = $this->prefix ? $this->prefix . '/' . $backupName : $backupName;

			$this->backupPaths = [
				'root' => $basePath,
				'config' => $basePath . '/config',
				'state' => $basePath . '/state',
				'data' => $basePath . '/data',
			];

			// Create directory markers in S3
			foreach (['config', 'state', 'data'] as $dir) {
				if (!isset($this->backupPaths[$dir])) {
					continue;
				}
				$this->createS3Marker($this->backupPaths[$dir]);
			}
		}

		/** @var non-empty-array<'config'|'data'|'root'|'state', string> */
		return $this->backupPaths;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setBackupPathsUsingDir(string $dir): static {
		// Download backup from S3 to local temp directory
		$tempDir = $this->downloadBackupFromS3($dir);
		$this->backupDir = $tempDir;

		$destination = $tempDir . DIRECTORY_SEPARATOR . $dir;
		$this->backupPaths = [
			'root' => $destination,
			'config' => $destination . DIRECTORY_SEPARATOR . 'config',
			'state' => $destination . DIRECTORY_SEPARATOR . 'state',
			'data' => $destination . DIRECTORY_SEPARATOR . 'data',
		];

		// Validate directories exist
		foreach (['config', 'state', 'data'] as $dirName) {
			if (!is_dir($this->backupPaths[$dirName])) {
				throw new \InvalidArgumentException(
					"Cannot find '{$dirName}' in '{$destination}'"
				);
			}
		}

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function copyPaths(array $paths, string $to, bool $preservePath = false): bool {
		// During restore, $to is a local absolute path (files already downloaded from S3).
		// During backup, $to is an S3 key prefix (no leading slash).
		if (str_starts_with($to, DIRECTORY_SEPARATOR)) {
			$localStorage = new FileStorage(null);
			return $localStorage->copyPaths($paths, $to, $preservePath);
		}

		$result = true;

		foreach ($paths as $path) {
			if (is_file($path)) {
				$s3Key = $this->localPathToS3Key($path, $to, $preservePath);
				$result = $result && $this->uploadFile($path, $s3Key);
			} elseif (is_dir($path)) {
				$result = $result && $this->uploadDirectory($path, $to);
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getFileIterator(string $dir, int $flags = 0): Iterator {
		// For S3 restore, files are already downloaded to local temp
		return FileStorage::getFileIterator($dir, $flags);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSortedFileIterator(string $dir, int $flags = 0): Iterator {
		return FileStorage::getSortedFileIterator($dir, $flags);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOriginRealPath(string $backupPath): string {
		// Extract original path from backup path.
		// During restore, files are downloaded to a local temp dir with structure:
		//   /tmp/.../backup-XXX/{config,state,data}/original/absolute/path/file
		// config/state preserve the original absolute filesystem path under their section dir.
		// data files are relative (table-name/file), matching FileStorage behaviour.
		$parts = explode(DIRECTORY_SEPARATOR, $backupPath);

		// Find the backup-XXX directory component
		$backupIdx = -1;
		foreach ($parts as $i => $part) {
			if (str_starts_with($part, 'backup-')) {
				$backupIdx = $i;
				break;
			}
		}

		if ($backupIdx === -1) {
			return $backupPath;
		}

		$section = $parts[$backupIdx + 1] ?? '';
		$relativeParts = array_slice($parts, $backupIdx + 2);
		$realPath = implode(DIRECTORY_SEPARATOR, $relativeParts);

		if (str_ends_with($realPath, '.zst')) {
			$realPath = substr($realPath, 0, -4);
		}

		// config/state store the original absolute path under their section dir
		// data stores a relative path (table/file) — no leading separator needed
		if ($section === 'config' || $section === 'state') {
			return DIRECTORY_SEPARATOR . $realPath;
		}

		return $realPath;
	}

	/**
	 * {@inheritdoc}
	 */
	public function cleanUp(): void {
		if (!isset($this->backupPaths)) {
			return;
		}

		// Delete all objects with backup prefix
		$prefix = $this->backupPaths['root'];

		try {
			$objects = $this->client->listObjectsV2(
				[
				'Bucket' => $this->bucket,
				'Prefix' => $prefix,
				]
			);

			if (isset($objects['Contents']) && is_array($objects['Contents'])) {
				$keys = array_map(
					fn($obj) => ['Key' => $obj['Key']],
					$objects['Contents']
				);

				$this->client->deleteObjects(
					[
					'Bucket' => $this->bucket,
					'Delete' => ['Objects' => $keys],
					]
				);
			}
		} catch (\Exception) {
			// Ignore cleanup errors
		}

		// Clean local temp dir if exists
		if ($this->backupDir === null || !is_dir($this->backupDir)) {
			return;
		}

		FileStorage::deleteDir($this->backupDir);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function createDir(string $dir, ?string $origin = null, bool $recursive = false): void {
		// During restore, $dir is a local absolute path — create it on the filesystem.
		// During backup, $dir is an S3 key prefix — S3 has no real directories, so no-op.
		if (!str_starts_with($dir, DIRECTORY_SEPARATOR)) {
			return;
		}

		FileStorage::createDir($dir, $origin, $recursive);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function hasFiles(string $dir): bool {
		return FileStorage::hasFiles($dir);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function calculateFilesSize(array $files): int {
		return FileStorage::calculateFilesSize($files);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getPathChecksum(string $path): string {
		return FileStorage::getPathChecksum($path);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function deleteDir(string $dir, bool $removeSelf = true): void {
		FileStorage::deleteDir($dir, $removeSelf);
	}

	/**
	 * List available backups in S3 bucket
	 *
	 * @return array<string>
	 */
	public function listBackups(): array {
		try {
			$objects = $this->client->listObjectsV2(
				[
				'Bucket' => $this->bucket,
				'Prefix' => $this->prefix ? $this->prefix . '/' : '',
				'Delimiter' => '/',
				]
			);

			$backups = [];
			$commonPrefixes = $objects['CommonPrefixes'] ?? [];
		/** @var array<array<string, string>> $commonPrefixes */
			foreach ($commonPrefixes as $prefixObj) {
				if (!is_array($prefixObj)) {
					continue;
				}
				$prefix = trim($prefixObj['Prefix'] ?? '', '/');
				$backupName = $this->prefix
					? substr($prefix, strlen($this->prefix) + 1)
					: $prefix;

				if (!str_starts_with($backupName, 'backup-')) {
					continue;
				}

				$backups[] = $backupName;
			}

			sort($backups);
			return $backups;
		} catch (\Aws\Exception\AwsException $e) {
			// Cloudflare R2 (and some S3-compatible stores) return NoSuchKey instead of
			// an empty list when the prefix doesn't exist yet — treat it as no backups found.
			if (in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NoSuchBucket'], true)) {
				return [];
			}
			throw new RuntimeException(
				"Failed to list backups in S3 bucket '{$this->bucket}': " . $e->getMessage()
			);
		} catch (\Exception $e) {
			throw new RuntimeException(
				"Failed to list backups in S3 bucket '{$this->bucket}': " . $e->getMessage()
			);
		}
	}

	/**
	 * Upload single file to S3 with streaming
	 *
	 * @param string $localPath
	 * @param string $s3Key
	 * @return bool
	 */
	protected function uploadFile(string $localPath, string $s3Key): bool {
		if (!is_readable($localPath)) {
			throw new InvalidPathException(__FUNCTION__ . ': failed to read the path: ' . $localPath);
		}

		// Track the uploaded file for manifest
		$this->uploadedFiles[] = $s3Key;

		// Resolve source: file path (fast, direct) or compressed stream
		[$source, $targetKey] = $this->resolveUploadSource($localPath, $s3Key);

		$fileSize = is_string($source) ? filesize($localPath) : $source->getSize();
		$mupThreshold = (int)(getenv('AWS_S3_MUP_THRESHOLD') ?: 8 * 1024 * 1024);
		$envPartSize = getenv('AWS_S3_PART_SIZE');
		$partSize = $envPartSize ? (int)$envPartSize : self::calculatePartSize($fileSize ?: 0);
		$concurrency = (int)(getenv('AWS_S3_CONCURRENCY') ?: 10);

		$requestArgs = [
			'Bucket' => $this->bucket,
			'Key' => $targetKey,
		];
		if ($this->useEncryption) {
			$requestArgs['ServerSideEncryption'] = 'AES256';
		}

		return $this->executeUpload(
			$source,
			$requestArgs,
			$localPath,
			$targetKey,
			$partSize,
			$mupThreshold,
			$concurrency
		);
	}

	/**
	 * Resolve the upload source and target S3 key.
	 * Returns the file path directly for uncompressed uploads (optimal AWS SDK performance),
	 * or a compressed Stream when compression is enabled.
	 *
	 * @param string $localPath
	 * @param string $s3Key
	 * @return array{0: string|Stream, 1: string}
	 */
	private function resolveUploadSource(string $localPath, string $s3Key): array {
		if (!$this->useCompression || str_ends_with($localPath, '.zst')) {
			return [$localPath, $s3Key];
		}

		$targetKey = $s3Key . '.zst';
		$stream = fopen($localPath, 'rb');
		if ($stream === false) {
			throw new InvalidPathException('resolveUploadSource: failed to open file: ' . $localPath);
		}
		$compressedStream = $this->compressStream($stream);
		fclose($stream);

		if ($compressedStream !== null) {
			return [new Stream($compressedStream), $targetKey];
		}

		return [$localPath, $targetKey];
	}

	/**
	 * Execute the S3 transfer manager upload and handle stream cleanup.
	 *
	 * @param string|Stream $source
	 * @param array<string,mixed> $requestArgs
	 * @param string $localPath  Used only for error messages
	 * @param string $targetKey  Used only for error messages
	 * @param int $partSize
	 * @param int $mupThreshold
	 * @param int $concurrency
	 * @return bool
	 */
	private function executeUpload(
		string|Stream $source,
		array $requestArgs,
		string $localPath,
		string $targetKey,
		int $partSize,
		int $mupThreshold,
		int $concurrency
	): bool {
		try {
			$transferManager = new S3TransferManager(
				$this->client, [
				'target_part_size_bytes' => $partSize,
				'multipart_upload_threshold_bytes' => $mupThreshold,
				'concurrency' => $concurrency,
				]
			);

			$uploadRequest = new UploadRequest(
				source: $source,
				uploadRequestArgs: $requestArgs,
			);

			$transferManager->upload($uploadRequest)->wait();

			if ($source instanceof Stream) {
				$source->close();
			}
			return true;
		} catch (\Exception $e) {
			if ($source instanceof Stream) {
				$source->close();
			}
			throw new RuntimeException(
				"Failed to upload file to S3: {$localPath} -> {$targetKey}: " . $e->getMessage()
			);
		}
	}

	/**
	 * Calculate optimal part size based on file size.
	 * Larger files benefit from larger parts (fewer HTTP requests, less overhead).
	 * AWS allows max 10,000 parts, so we also ensure we stay within that limit.
	 */
	protected static function calculatePartSize(int $fileSize): int {
		$minPartSize = 8 * 1024 * 1024;   // 8MB minimum
		$maxPartSize = 512 * 1024 * 1024;  // 512MB maximum

		// Target ~100 parts for optimal parallelism vs overhead balance
		$targetParts = 100;
		$calculated = (int)ceil($fileSize / $targetParts);

		return max($minPartSize, min($calculated, $maxPartSize));
	}

	/**
	 * Upload directory recursively to S3
	 *
	 * @param string $localDir
	 * @param string $s3Prefix
	 * @return bool
	 */
	protected function uploadDirectory(string $localDir, string $s3Prefix): bool {
		if (!is_dir($localDir) || !is_readable($localDir)) {
			throw new InvalidPathException('Cannot read from source directory: ' . $localDir);
		}

		$result = true;
		$dirLen = strlen($localDir);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$file->isFile()) {
				continue;
			}

			$relativePath = substr($file->getPathname(), $dirLen);
			$s3Key = rtrim($s3Prefix, '/') . '/' . ltrim($relativePath, '/');

			$result = $result && $this->uploadFile($file->getPathname(), $s3Key);
		}

		return $result;
	}

	/**
	 * Convert local path to S3 key
	 *
	 * @param string $localPath
	 * @param string $s3Prefix
	 * @param bool $preservePath
	 * @return string
	 */
	protected function localPathToS3Key(string $localPath, string $s3Prefix, bool $preservePath): string {
		if ($preservePath) {
			// Normalize absolute path to relative
			$normalized = FileStorage::normalizeAbsolutePath($localPath);
			return rtrim($s3Prefix, '/') . $normalized;
		}

		return rtrim($s3Prefix, '/') . '/' . basename($localPath);
	}

	/**
	 * Create S3 directory marker (empty object with trailing slash)
	 *
	 * @param string $s3Key
	 * @return void
	 */
	protected function createS3Marker(string $s3Key): void {
		try {
			$params = [
				'Bucket' => $this->bucket,
				'Key' => rtrim($s3Key, '/') . '/',
				'Body' => '',
			];

			if ($this->useEncryption) {
				$params['ServerSideEncryption'] = 'AES256';
			}

			$this->client->putObject($params);
		} catch (\Exception $e) {
			throw new RuntimeException(
				"Failed to create S3 directory marker at '{$s3Key}': " . $e->getMessage()
			);
		}
	}

	/**
	 * Download entire backup from S3 to local temp directory
	 * Uses manifest file when available, falls back to listing for backwards compatibility
	 *
	 * @param string $backupName
	 * @return string Local temp directory path
	 */
	protected function downloadBackupFromS3(string $backupName): string {
		$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manticore-backup-restore-' . $backupName;

		if (is_dir($tempDir)) {
			FileStorage::deleteDir($tempDir);
		}

		if (!mkdir($tempDir, 0755, true)) {
			throw new RuntimeException("Failed to create temp directory: {$tempDir}");
		}

		$prefix = $this->prefix
			? $this->prefix . '/' . $backupName
			: $backupName;

		// Try to download using manifest first (avoids ListObjects permission requirement)
		$manifestDownloaded = $this->downloadUsingManifest($backupName, $prefix, $tempDir);

		if (!$manifestDownloaded) {
			// No manifest found - this backup was created with old version or is corrupted
			throw new RuntimeException(
				"Cannot restore backup '{$backupName}': manifest.json not found. " .
				'Backups created with older versions require ListBucket permission. ' .
				'Please use a backup created with the current version.'
			);
		}

		return $tempDir;
	}

	/**
	 * Download backup using manifest file (no ListObjects permission needed)
	 *
	 * @param string $backupName
	 * @param string $prefix
	 * @param string $tempDir
	 * @return bool True if manifest-based download succeeded
	 */
	protected function downloadUsingManifest(string $backupName, string $prefix, string $tempDir): bool {
		$manifestKey = $prefix . '/manifest.json';
		$manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';

		try {
			$this->client->getObject(
				[
				'Bucket' => $this->bucket,
				'Key' => $manifestKey,
				'SaveAs' => $manifestPath,
				]
			);
		} catch (\Exception $e) {
			// No manifest - fallback to listing
			return false;
		}

		$manifestContent = file_get_contents($manifestPath);
		if ($manifestContent === false) {
			return false;
		}

		$manifest = json_decode($manifestContent, true);
		if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
			return false;
		}

		// Ensure required subdirs always exist (e.g. state/ may be empty and have no files in manifest)
		$backupLocalRoot = $tempDir . DIRECTORY_SEPARATOR . $backupName;
		$this->ensureBackupSubdirs($backupLocalRoot);

		foreach ($manifest['files'] as $file) {
			if (!is_string($file)) {
				continue;
			}
			$key = $prefix . '/' . $file;
			$localPath = $backupLocalRoot . DIRECTORY_SEPARATOR . $file;
			$this->downloadS3ObjectToPath($key, $localPath);
		}

		return true;
	}

	/**
	 * Ensure config/state/data subdirectories exist under a backup root.
	 *
	 * @param string $backupLocalRoot
	 * @return void
	 */
	protected function ensureBackupSubdirs(string $backupLocalRoot): void {
		foreach (['config', 'state', 'data'] as $subdir) {
			$subdirPath = $backupLocalRoot . DIRECTORY_SEPARATOR . $subdir;
			if (!is_dir($subdirPath) && !mkdir($subdirPath, 0755, true)) {
				throw new RuntimeException("Failed to create directory: {$subdirPath}");
			}
		}
	}

	/**
	 * Download a single S3 object to a local path, creating parent dirs as needed,
	 * and decompress if compression is enabled.
	 *
	 * @param string $key S3 object key
	 * @param string $localPath Destination local path
	 * @return void
	 */
	protected function downloadS3ObjectToPath(string $key, string $localPath): void {
		$dir = dirname($localPath);
		if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
			throw new RuntimeException("Failed to create directory: {$dir}");
		}

		try {
			$this->client->getObject(
				[
				'Bucket' => $this->bucket,
				'Key' => $key,
				'SaveAs' => $localPath,
				]
			);
		} catch (\Exception $e) {
			throw new RuntimeException(
				"Failed to download S3 object '{$key}': " . $e->getMessage()
			);
		}

		if (!$this->useCompression || !str_ends_with($localPath, '.zst')) {
			return;
		}

		$decompressedPath = substr($localPath, 0, -4);
		$this->decompressFile($localPath, $decompressedPath);
		unlink($localPath);
	}

	/**
	 * Download backup by listing objects (requires ListBucket permission)
	 *
	 * @param string $backupName
	 * @param string $prefix
	 * @param string $tempDir
	 */
	protected function downloadUsingListing(string $backupName, string $prefix, string $tempDir): void {
		try {
			$objects = $this->client->listObjectsV2(
				[
				'Bucket' => $this->bucket,
				'Prefix' => $prefix,
				]
			);

			if (!isset($objects['Contents']) || empty($objects['Contents'])) {
				throw new RuntimeException("Backup not found: {$backupName}");
			}

		/** @var array<array<string, string>> $contents */
			$contents = $objects['Contents'];
			foreach ($contents as $object) {
				if (!is_array($object)) {
					continue;
				}
				$key = $object['Key'] ?? '';
				if (!is_string($key) || str_ends_with($key, '/')) {
					continue; // Skip directory markers
				}
				$relativePath = substr($key, strlen($prefix) + 1);
				$localPath = $tempDir . DIRECTORY_SEPARATOR . $backupName . DIRECTORY_SEPARATOR . $relativePath;
				$this->downloadS3ObjectToPath($key, $localPath);
			}
		} catch (\Exception $e) {
			if (is_dir($tempDir)) {
				FileStorage::deleteDir($tempDir);
			}
			throw new RuntimeException(
				"Failed to download backup '{$backupName}' from S3: " . $e->getMessage()
			);
		}
	}

	/**
	 * Compress stream using zstd
	 *
	 * @param resource $stream
	 * @return resource|null
	 */
	protected function compressStream($stream) {
		if (!function_exists('zstd_compress')) {
			return null;
		}

		// Read stream content, compress, return new stream
		$content = stream_get_contents($stream);
		if ($content === false) {
			return null;
		}
		$compressed = zstd_compress($content);
		if ($compressed === false) {
			return null;
		}

		$tempStream = fopen('php://temp', 'r+');
		if ($tempStream === false) {
			return null;
		}
		fwrite($tempStream, $compressed);
		rewind($tempStream);

		return $tempStream;
	}

	/**
	 * Decompress zstd file
	 *
	 * @param string $source
	 * @param string $target
	 * @return bool
	 */
	protected function decompressFile(string $source, string $target): bool {
		if (!function_exists('zstd_uncompress')) {
			throw new RuntimeException('ZSTD extension required for decompression');
		}

		$compressed = file_get_contents($source);
		if ($compressed === false) {
			return false;
		}

		$decompressed = zstd_uncompress($compressed);
		if ($decompressed === false) {
			return false;
		}

		return file_put_contents($target, $decompressed) !== false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function putContents(string $path, string $content): bool {
		if (!isset($this->backupPaths)) {
			throw new RuntimeException('Backup paths not initialized');
		}

		// Write to temp file first
		$tempFile = tempnam(sys_get_temp_dir(), 'manticore-backup-');
		if ($tempFile === false) {
			throw new RuntimeException('Failed to create temp file');
		}

		try {
			if (file_put_contents($tempFile, $content) === false) {
				throw new RuntimeException('Failed to write temp file');
			}

			// Upload to S3 - use backupPaths['root'] which includes prefix and backup name
			$s3Key = $this->backupPaths['root'] . '/' . $path;
			return $this->uploadFile($tempFile, $s3Key);
		} finally {
			@unlink($tempFile);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getContents(string $path): string {
		if (!isset($this->backupPaths)) {
			throw new RuntimeException('Backup paths not initialized');
		}

		// For restore, files are already downloaded to local temp
		$localPath = $this->backupPaths['root'] . DIRECTORY_SEPARATOR . $path;
		$content = file_get_contents($localPath);
		if ($content === false) {
			throw new RuntimeException("Failed to read file: {$localPath}");
		}
		return $content;
	}

	/**
	 * Get list of uploaded files (relative to backup root)
	 * Used for building manifest during backup
	 *
	 * @return array<string>
	 */
	public function getUploadedFiles(): array {
		return $this->uploadedFiles;
	}

	/**
	 * Clear the list of uploaded files
	 * Called after manifest is stored
	 */
	public function clearUploadedFiles(): void {
		$this->uploadedFiles = [];
	}
}
