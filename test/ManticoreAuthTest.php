<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\ManticoreAuth;
use PHPUnit\Framework\TestCase;

class ManticoreAuthTest extends TestCase {
	public function testBasicAuthHeader(): void {
		$auth = new ManticoreAuth(user: 'admin', password: 'adminpass');

		$this->assertSame(
			['Authorization: Basic ' . base64_encode('admin:adminpass')],
			$auth->getHeaders()
		);
	}

	public function testBearerTokenTakesPrecedenceAndDelegatesUser(): void {
		$auth = new ManticoreAuth(
			user: 'admin',
			password: 'adminpass',
			token: 'abc123',
			delegatedUser: 'alice',
			userAgent: 'Manticore Buddy/backup'
		);

		$this->assertSame(
			[
				'Authorization: Bearer abc123',
				'X-Manticore-User: alice',
				'User-Agent: Manticore Buddy/backup',
			],
			$auth->getHeaders()
		);
	}

	public function testBasicAuthPreservesPasswordWhitespace(): void {
		$auth = new ManticoreAuth(user: 'admin', password: ' password ');

		$this->assertSame(
			['Authorization: Basic ' . base64_encode('admin: password ')],
			$auth->getHeaders()
		);
	}

	public function testEmptyValuesDoNotCreateHeaders(): void {
		$auth = new ManticoreAuth(user: '', password: '', token: '', delegatedUser: '', userAgent: '');

		$this->assertSame([], $auth->getHeaders());
	}

	public function testBearerTokenRejectsLineBreaks(): void {
		$this->expectException(InvalidArgumentException::class);
		new ManticoreAuth(token: "token\r\nInjected: value");
	}

	public function testDelegatedUserRejectsLineBreaks(): void {
		$this->expectException(InvalidArgumentException::class);
		new ManticoreAuth(delegatedUser: "alice\nInjected: value");
	}

	public function testUserAgentRejectsLineBreaks(): void {
		$this->expectException(InvalidArgumentException::class);
		new ManticoreAuth(userAgent: "backup\rInjected: value");
	}
}
