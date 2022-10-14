<?php declare(strict_types=1);

use Manticoresearch\Backup\Lib\Searchd;

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class SearchdTest extends SearchdTestCase {
	public function testGetConfigPathWithoutInitFails(): void {
		if (isset(Searchd::$cmd)) {
			Searchd::$cmd = null;
		}
		$this->expectException(InvalidArgumentException::class);
		Searchd::getConfigPath();
	}

	public function testGetConfigPath(): void {
		Searchd::init();
		$configPath = Searchd::getConfigPath();
		$this->assertFileExists($configPath);
	}
}
