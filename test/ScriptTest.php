<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ScriptTest extends TestCase {
  const CMD = './build/manticore_backup';

  public static function setUpBeforeClass(): void {
    system('./bin/build');
  }

  public function testHelpArg(): void {
    $output = $this->exec('--help');
    $this->assertStringContainsString('--help', $output);
    $this->assertStringContainsString('--backup-dir', $output);
    $this->assertStringContainsString('--config', $output);
    $this->assertStringContainsString('--tables', $output);
    $this->assertStringContainsString('--unlock', $output);
    $this->assertStringContainsString('--version', $output);
  }

  public function testNoTargetDirArgProducesError(): void {
    $output = $this->exec('');
    $this->assertStringContainsString('Failed to find target dir to store backup', $output);
  }

  public function testNonExistingTargetDirProducesError(): void {
    $output = $this->exec('--backup-dir=non-existing-dir');
    $this->assertStringContainsString('Failed to find target dir to store backup', $output);
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
