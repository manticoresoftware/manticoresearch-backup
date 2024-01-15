<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Iterator;
use SplFileInfo;
use SplHeap;

/** @phpstan-ignore-next-line */
final class FileSortingIterator extends SplHeap {
	/**
	 * @param Iterator<SplFileInfo> $iterator [description]
	 * @return void
	 */
	public function __construct(Iterator $iterator) {
		foreach ($iterator as $item) {
			$this->insert($item);
		}
	}

	public function compare(mixed $b, mixed $a): int {
		/** @var SplFileInfo $a */
		/** @var SplFileInfo $b */
		return strcmp($a->getRealpath(), $b->getRealpath());
	}
}
