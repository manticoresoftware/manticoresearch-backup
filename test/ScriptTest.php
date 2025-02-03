<?php declare(strict_types=1);

use Manticoresearch\Backup\Lib\FileStorage;

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

class ScriptTest extends SearchdTestCase {
	const CMD = './build/manticore-backup';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$cmd = './phar_builder/bin/build --template=sh'
			. ' --name="Manticore Backup"'
			. ' --package="manticore-backup"'
			. ' --index="src/main.php"';
		system($cmd);
		system(
			'test -d /usr/share/manticore/modules/manticore-backup && ' .
				'mv /usr/share/manticore/modules/manticore-backup /usr/share/manticore/modules/manticore-backup-old'
		);
	}

	public static function tearDownAfterClass(): void {
		system(
			'test -d /usr/share/manticore/modules/manticore-backup-old && ' .
				'mv /usr/share/manticore/modules/manticore-backup-old /usr/share/manticore/modules/manticore-backup'
		);
	}

	public function testHelpArg(): void {
		$output = $this->exec('--help');
		$this->assertStringContainsString('--help', $output);
		$this->assertStringContainsString('--backup-dir', $output);
		$this->assertStringContainsString('--config', $output);
		$this->assertStringContainsString('--tables', $output);
		$this->assertStringContainsString('--unlock', $output);
		$this->assertStringContainsString('--restore', $output);
		$this->assertStringContainsString('--version', $output);
		$this->assertStringContainsString('--disable-telemetry', $output);
	}

	public function testNoTargetDirArgProducesError(): void {
		$output = $this->exec('');
		$this->assertStringContainsString('Failed to find backup dir to store backup', $output);
	}

	public function testNonExistingTargetDirProducesError(): void {
		$output = $this->exec('--backup-dir=non-existing-dir');
		$this->assertStringContainsString('Failed to find backup dir to store backup', $output);
	}

	public function testNonExistingConfigProducesError(): void {
		$output = $this->exec('--config=unexisting-config');
		$this->assertStringContainsString('Failed to find passed config', $output);
	}

	public function testVersionArg(): void {
		$output = $this->exec('--version');
		$this->assertStringContainsString('Manticore Backup version', $output);
	}

	public function testUnknownArg(): void {
		$output = $this->exec('--foo=bar');
		$this->assertStringContainsString('Unknown option: --foo', $output);

		$output = $this->exec('-bar');
		$this->assertStringContainsString('Unknown option: -bar', $output);

		$output = $this->exec('-version');
		$this->assertStringContainsString('Unknown option: -version', $output);

		$output = $this->exec('---version');
		$this->assertStringContainsString('Unknown option: ---version', $output);

		$output = $this->exec('--versio');
		$this->assertStringContainsString('Unknown option: --versio', $output);

		$output = $this->exec('--backup-dir1');
		$this->assertStringContainsString('Unknown option: --backup-dir1', $output);

		$output = $this->exec('--backup-dir1=tratata');
		$this->assertStringContainsString('Unknown option: --backup-dir1', $output);
	}

	public function testUnlockArg(): void {
		$output = $this->exec('--unlock');

		$this->assertStringContainsString('Unfreezing all tables', $output);
		$this->assertMatchesRegularExpression('/movie...' . PHP_EOL . '[^\r\n]+OK/', $output);
		$this->assertMatchesRegularExpression('/people...' . PHP_EOL . '[^\r\n]+OK/', $output);
		$this->assertMatchesRegularExpression('/people_dist_agent...' . PHP_EOL . '[^\r\n]+OK/', $output);
		$this->assertMatchesRegularExpression('/people_dist_local...' . PHP_EOL . '[^\r\n]+OK/', $output);
		$this->assertMatchesRegularExpression('/people_pq...' . PHP_EOL . '[^\r\n]+OK/', $output);
	}

	public function testRestoreArg(): void {
		$output = $this->exec('--restore');
		$this->assertStringContainsString('Failed to find backup dir to store backup', $output);

		$tmpDir = FileStorage::getTmpDir() . DIRECTORY_SEPARATOR . 'no-backup-test';
		mkdir($tmpDir, 0755);
		$output = $this->exec('--backup-dir=' . escapeshellarg($tmpDir) . ' --restore');
		$this->assertStringContainsString('There are no backups available to restore', $output);

		$this->exec('--backup-dir=' . escapeshellarg($tmpDir));
		$output = $this->exec('--backup-dir=' . escapeshellarg($tmpDir) . ' --restore');
		$this->assertStringContainsString('Available backups: 1', $output);

		FileStorage::deleteDir($tmpDir);
	}

  /**
   * Helper function to validate that shell comand executed and return output
   *
   * @param string $arg
   * @return string
   * @throws Exception
   */
	protected function exec(string $arg): string {
		$command = static::CMD . ' ' . $arg;
		$output = shell_exec($command);
		if (false === $output || $output === null) {
			throw new Exception('Failed to run shell command: ' . $command);
		}

		return $output;
	}
}
