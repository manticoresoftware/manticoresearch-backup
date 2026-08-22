<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

/**
 * Optional authentication context for backup HTTP requests to searchd.
 */
class ManticoreAuth {
	public function __construct(
		public ?string $user = null,
		public ?string $password = null,
		public ?string $token = null,
		public ?string $delegatedUser = null,
		public ?string $userAgent = null,
	) {
		$this->user = static::normalizeCredential($this->user);
		$this->password = static::normalizeCredential($this->password);
		$this->token = static::normalizeHeaderValue($this->token, 'token');
		$this->delegatedUser = static::normalizeHeaderValue($this->delegatedUser, 'delegated user');
		$this->userAgent = static::normalizeHeaderValue($this->userAgent, 'user agent');
	}

	/**
	 * @return array<string>
	 */
	public function getHeaders(): array {
		$headers = [];
		if ($this->token !== null) {
			$headers[] = 'Authorization: Bearer ' . $this->token;
		} elseif ($this->user !== null) {
			$headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . ($this->password ?? ''));
		}

		if ($this->delegatedUser !== null) {
			$headers[] = 'X-Manticore-User: ' . $this->delegatedUser;
		}

		if ($this->userAgent !== null) {
			$headers[] = 'User-Agent: ' . $this->userAgent;
		}

		return $headers;
	}

	protected static function normalizeCredential(?string $value): ?string {
		return $value !== '' ? $value : null;
	}

	protected static function normalizeHeaderValue(?string $value, string $name): ?string {
		$value = static::normalizeCredential($value);
		if ($value !== null && strpbrk($value, "\r\n") !== false) {
			throw new \InvalidArgumentException("Authentication $name must not contain line breaks");
		}
		return $value;
	}
}
