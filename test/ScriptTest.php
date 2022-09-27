<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ScriptTest extends TestCase {
  const CMD = './build/manticore_backup';

  public static function setUpBeforeClass(): void {
    system('./bin/build');
  }

  public function testHelpArg() {
    foreach (['-h', '--help'] as $arg) {
      $output = shell_exec(static::CMD . ' ' . $arg);
      $this->assertStringContainsString('--help', $output);
      $this->assertStringContainsString('--target-dir', $output);
      $this->assertStringContainsString('--config', $output);
      $this->assertStringContainsString('--indexes', $output);
      $this->assertStringContainsString('--unlock', $output);
      $this->assertStringContainsString('--version', $output);
    }
  }

  public function testNoTargetDirArgProducesError() {
    $output = shell_exec(static::CMD);
    $this->assertStringContainsString('Failed to find target dir to store backup', $output);
  }

  public function testNonExistingTargetDirProducesError() {
    $output = shell_exec(static::CMD . ' --target-dir=non-existing-dir');
    $this->assertStringContainsString('Failed to find target dir to store backup', $output);
  }

  public function testNonExistingConfigProducesError() {
    $output = shell_exec(static::CMD . ' --config=unexisting-config');
    $this->assertStringContainsString('Failed to find passed config', $output);
  }

  public function testVersionArg() {
    $output = shell_exec(static::CMD . ' --version');
    $this->assertStringContainsString('Manticore Backup Script version', $output);
  }
}
