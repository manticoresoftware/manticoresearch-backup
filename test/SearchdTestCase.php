<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\Searchd;
use PHPUnit\Framework\TestCase;

class SearchdTestCase extends TestCase {
	const SEARCHD_PID_FILE = '/var/run/manticore/searchd.pid';

	public static function setUpBeforeClass(): void {

		if (Searchd::isRunning()) {
			return;
		}

		Searchd::run();
	}

	public static function tearDownAfterClass(): void {
		if (!is_file(static::SEARCHD_PID_FILE)) {
			return;
		}

		$pid = (int)file_get_contents(static::SEARCHD_PID_FILE);
		posix_kill($pid, 9);
	}
}
