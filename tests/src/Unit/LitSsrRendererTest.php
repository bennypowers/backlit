<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Unit;

use Drupal\backlit\Service\LitSsrRenderer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\backlit\Service\LitSsrRenderer
 * @group backlit
 */
class LitSsrRendererTest extends TestCase {

  /**
   * Helper to call private static methods via reflection.
   */
  private static function callPrivateStatic(string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod(LitSsrRenderer::class, $method);
    return $ref->invoke(NULL, ...$args);
  }

  /**
   * @covers ::getBinaryPath
   */
  public function testGetBinaryPathFormat(): void {
    $path = self::callPrivateStatic('getBinaryPath');

    // Should contain the platform-specific binary name.
    $this->assertMatchesRegularExpression(
      '#bin/lit-ssr-(linux|darwin|win32)-(x64|arm64)#',
      $path,
    );
  }

  /**
   * @covers ::getBinaryPath
   */
  public function testGetBinaryPathContainsCorrectOsAndArch(): void {
    $path = self::callPrivateStatic('getBinaryPath');

    $expectedOs = match (PHP_OS_FAMILY) {
      'Linux' => 'linux',
      'Darwin' => 'darwin',
      'Windows' => 'win32',
      default => $this->markTestSkipped('Unsupported OS for this test'),
    };

    $expectedArch = match (php_uname('m')) {
      'x86_64', 'amd64' => 'x64',
      'aarch64', 'arm64' => 'arm64',
      default => $this->markTestSkipped('Unsupported arch for this test'),
    };

    $this->assertStringContainsString("lit-ssr-$expectedOs-$expectedArch", $path);
  }

  /**
   * @covers ::getComponentFiles
   */
  public function testGetComponentFilesReturnsEmptyWhenNothingConfigured(): void {
    $this->setUpDrupalContainer([], '/nonexistent/theme/path');

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir() . '/backlit-test-' . uniqid());
    }

    $files = self::callPrivateStatic('getComponentFiles');
    $this->assertSame([], $files);
  }

  /**
   * @covers ::getComponentFiles
   */
  public function testGetComponentFilesFromSettingsDir(): void {
    $tmpDir = sys_get_temp_dir() . '/backlit-test-settings-' . uniqid();
    mkdir($tmpDir, 0755, TRUE);
    file_put_contents("$tmpDir/my-card.js", '// component');

    $this->setUpDrupalContainer(
      ['components_dir' => $tmpDir],
      '/nonexistent/theme/path',
    );

    $files = self::callPrivateStatic('getComponentFiles');
    $this->assertContains("$tmpDir/my-card.js", $files);

    // Cleanup.
    unlink("$tmpDir/my-card.js");
    rmdir($tmpDir);
  }

  /**
   * @covers ::getComponentFiles
   */
  public function testGetComponentFilesFromThemeDir(): void {
    $tmpDir = sys_get_temp_dir() . '/backlit-test-theme-' . uniqid();
    $componentsDir = "$tmpDir/components";
    mkdir($componentsDir, 0755, TRUE);
    file_put_contents("$componentsDir/rh-card.js", '// component');

    $this->setUpDrupalContainer([], $tmpDir);

    $files = self::callPrivateStatic('getComponentFiles');
    $this->assertContains("$componentsDir/rh-card.js", $files);

    // Cleanup.
    unlink("$componentsDir/rh-card.js");
    rmdir($componentsDir);
    rmdir($tmpDir);
  }

  /**
   * @covers ::getComponentFiles
   */
  public function testGetComponentFilesAggregatesFromMultipleSources(): void {
    $settingsDir = sys_get_temp_dir() . '/backlit-test-multi-settings-' . uniqid();
    mkdir($settingsDir, 0755, TRUE);
    file_put_contents("$settingsDir/a.js", '// a');

    $themeBase = sys_get_temp_dir() . '/backlit-test-multi-theme-' . uniqid();
    $themeComponents = "$themeBase/components";
    mkdir($themeComponents, 0755, TRUE);
    file_put_contents("$themeComponents/b.js", '// b');

    $this->setUpDrupalContainer(
      ['components_dir' => $settingsDir],
      $themeBase,
    );

    $files = self::callPrivateStatic('getComponentFiles');
    $this->assertContains("$settingsDir/a.js", $files);
    $this->assertContains("$themeComponents/b.js", $files);

    // Cleanup.
    unlink("$settingsDir/a.js");
    rmdir($settingsDir);
    unlink("$themeComponents/b.js");
    rmdir($themeComponents);
    rmdir($themeBase);
  }

  /**
   * @covers ::getComponentFiles
   */
  public function testGetComponentFilesIncludesTypeScript(): void {
    $tmpDir = sys_get_temp_dir() . '/backlit-test-ts-' . uniqid();
    mkdir($tmpDir, 0755, TRUE);
    file_put_contents("$tmpDir/my-card.ts", '// component');
    file_put_contents("$tmpDir/my-card.d.ts", '// declaration');
    file_put_contents("$tmpDir/my-card.test.ts", '// test');

    $this->setUpDrupalContainer(
      ['components_dir' => $tmpDir],
      '/nonexistent/theme/path',
    );

    $files = self::callPrivateStatic('getComponentFiles');
    $this->assertContains("$tmpDir/my-card.ts", $files);
    $this->assertNotContains("$tmpDir/my-card.d.ts", $files);
    $this->assertNotContains("$tmpDir/my-card.test.ts", $files);

    // Cleanup.
    unlink("$tmpDir/my-card.ts");
    unlink("$tmpDir/my-card.d.ts");
    unlink("$tmpDir/my-card.test.ts");
    rmdir($tmpDir);
  }

  /**
   * @covers ::render
   */
  public function testRenderReturnsOriginalHtmlWhenBinaryMissing(): void {
    $renderer = new LitSsrRenderer();
    $html = '<rh-card>Hello</rh-card>';

    $result = $renderer->render($html);
    $this->assertSame($html, $result);
  }

  /**
   * Set up a minimal Drupal container with mocked settings and theme manager.
   */
  private function setUpDrupalContainer(array $backlitSettings, string $themePath): void {
    $settings = new Settings([
      'backlit' => $backlitSettings,
    ]);

    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getPath')->willReturn($themePath);

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);

    $container = new ContainerBuilder();
    $container->set('settings', $settings);
    $container->set('theme.manager', $themeManager);
    \Drupal::setContainer($container);
  }

}
