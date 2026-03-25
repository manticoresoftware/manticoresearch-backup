<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Aws\MockHandler;
use Aws\S3\S3Client;
use Manticoresearch\Backup\Lib\S3Storage;

/**
 * Thin subclass that replaces the S3Client with a mock after construction,
 * so we can test S3Storage logic without a real S3 endpoint.
 */
class MockS3Storage extends S3Storage {
	public function injectMockHandler(MockHandler $handler): void {
		$this->client = new S3Client(
			[
			'region' => 'us-east-1',
			'version' => 'latest',
			'credentials' => ['key' => 'test', 'secret' => 'test'],
			'handler' => $handler,
			]
		);
	}
}
