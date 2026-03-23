<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the download-binary.sh script.
 *
 * @group backlit
 */
class DownloadBinaryTest extends TestCase {

  private string $scriptPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scriptPath = dirname(__DIR__, 3) . '/scripts/download-binary.sh';
    if (!is_file($this->scriptPath)) {
      $this->markTestSkipped('download-binary.sh not found');
    }
  }

  /**
   * Verify the script constructs a correct GitHub release URL.
   */
  public function testConstructsCorrectUrl(): void {
    // We can't run the script (it would actually download), so we
    // parse it and verify the URL template is correct.
    $script = file_get_contents($this->scriptPath);

    $this->assertStringContainsString(
      'https://github.com/bennypowers/lit-ssr-wasm/releases/download/${VERSION}/${BINARY}',
      $script,
    );
  }

  /**
   * Verify the default version.
   */
  public function testDefaultVersion(): void {
    $script = file_get_contents($this->scriptPath);

    // Extract VERSION="${1:-vX.Y.Z}" pattern.
    preg_match('/VERSION="\$\{1:-([^}]+)\}"/', $script, $matches);
    $this->assertNotEmpty($matches, 'VERSION default not found in script');
    $this->assertMatchesRegularExpression('/^v\d+\.\d+\.\d+$/', $matches[1]);
  }

  /**
   * Verify the script accepts a version override as first argument.
   */
  public function testAcceptsVersionOverride(): void {
    $script = file_get_contents($this->scriptPath);

    // The pattern ${1:-default} means $1 overrides the default.
    $this->assertMatchesRegularExpression(
      '/VERSION="\$\{1:-/',
      $script,
      'Script should accept version as first argument via ${1:-default}',
    );
  }

  /**
   * Verify linux platform detection.
   */
  public function testLinuxPlatformMapping(): void {
    $script = file_get_contents($this->scriptPath);

    $this->assertStringContainsString('Linux)  PLATFORM_OS="linux"', $script);
  }

  /**
   * Verify architecture detection for x86_64.
   */
  public function testX64ArchMapping(): void {
    $script = file_get_contents($this->scriptPath);

    $this->assertStringContainsString('x86_64|amd64) PLATFORM_ARCH="x64"', $script);
  }

}
