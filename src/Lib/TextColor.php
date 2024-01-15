<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

enum TextColor: int {
	case Black = 30;
	case Red = 31;
	case Green = 32;
	case Yellow = 33;
	case Blue = 34;
	case Magenta = 35;
	case Cyan = 36;
	case LightRed = 91;
	case LightGreen = 92;
	case LightYellow = 93;
	case LightBlue = 94;
	case LightMagenta = 95;
	case LightCyan = 96;
}
