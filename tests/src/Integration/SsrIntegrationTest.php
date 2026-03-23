<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Integration;

use Drupal\backlit\Service\LitSsrRenderer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests with the real lit-ssr-runtime binary.
 *
 * @group backlit
 * @group integration
 */
class SsrIntegrationTest extends TestCase {

  private string $componentDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $binaryPath = $this->findBinary();
    if ($binaryPath === NULL) {
      $this->markTestSkipped('lit-ssr-runtime binary not installed. Run: composer run post-install-cmd');
    }

    // Create a temp directory with a simple LitElement component.
    $this->componentDir = sys_get_temp_dir() . '/backlit-integration-' . uniqid();
    mkdir($this->componentDir, 0755, TRUE);

    file_put_contents("{$this->componentDir}/test-greeting.js", <<<'JS'
class TestGreeting extends LitElement {
  static properties = {
    name: { type: String },
  };

  static styles = css`:host { display: block; }`;

  constructor() {
    super();
    this.name = 'World';
  }

  render() {
    return html`<p>Hello, ${this.name}!</p>`;
  }
}
customElements.define('test-greeting', TestGreeting);
JS);

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir());
    }

    $settings = new Settings([
      'backlit' => ['components_dir' => $this->componentDir],
    ]);

    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getPath')->willReturn('/nonexistent');

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);

    $container = new ContainerBuilder();
    $container->set('settings', $settings);
    $container->set('theme.manager', $themeManager);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up component files.
    if (is_dir($this->componentDir)) {
      array_map('unlink', glob("{$this->componentDir}/*.js") ?: []);
      rmdir($this->componentDir);
    }
    parent::tearDown();
  }

  /**
   * Verify that a known custom element gets Declarative Shadow DOM.
   */
  public function testRendersDeclarativeShadowDom(): void {
    $renderer = new LitSsrRenderer();

    $html = '<html><body><test-greeting name="Drupal"></test-greeting></body></html>';
    $result = $renderer->render($html);

    $this->assertStringContainsString('shadowrootmode="open"', $result);
    $this->assertStringContainsString('Hello, Drupal!', $result);
  }

  /**
   * Unknown elements should pass through unchanged.
   */
  public function testPassesThroughUnknownElements(): void {
    $renderer = new LitSsrRenderer();

    $html = '<html><body><unknown-widget>content</unknown-widget></body></html>';
    $result = $renderer->render($html);

    $this->assertStringContainsString('<unknown-widget>content</unknown-widget>', $result);
    $this->assertStringNotContainsString('shadowrootmode', $result);
  }

  /**
   * Verify the process stays warm across multiple renders.
   */
  public function testProcessStaysWarm(): void {
    $renderer = new LitSsrRenderer();

    $html = '<html><body><test-greeting name="First"></test-greeting></body></html>';

    // First render (cold start).
    $start1 = hrtime(TRUE);
    $result1 = $renderer->render($html);
    $cold = (hrtime(TRUE) - $start1) / 1e6;

    // Second render (warm).
    $html2 = '<html><body><test-greeting name="Second"></test-greeting></body></html>';
    $start2 = hrtime(TRUE);
    $result2 = $renderer->render($html2);
    $warm = (hrtime(TRUE) - $start2) / 1e6;

    $this->assertStringContainsString('First', $result1);
    $this->assertStringContainsString('Second', $result2);

    // Warm render should be significantly faster than cold start.
    // Cold is typically ~350ms, warm is ~0.3ms. We use a generous
    // threshold: warm should be at most half of cold.
    if ($cold > 50) {
      $this->assertLessThan($cold / 2, $warm, "Warm render ($warm ms) should be much faster than cold start ($cold ms)");
    }
  }

  /**
   * Multi-line HTML should be handled correctly.
   */
  public function testMultiLineHtml(): void {
    $renderer = new LitSsrRenderer();

    $html = <<<'HTML'
<html>
  <body>
    <test-greeting
      name="Multi
Line">
    </test-greeting>
  </body>
</html>
HTML;

    $result = $renderer->render($html);

    $this->assertStringContainsString('shadowrootmode="open"', $result);
  }

  /**
   * Find the binary, or return NULL if not installed.
   */
  private function findBinary(): ?string {
    $binDir = dirname(__DIR__, 3) . '/bin';

    $os = match (PHP_OS_FAMILY) {
      'Linux' => 'linux',
      'Darwin' => 'darwin',
      'Windows' => 'win32',
      default => return NULL,
    };

    $arch = match (php_uname('m')) {
      'x86_64', 'amd64' => 'x64',
      'aarch64', 'arm64' => 'arm64',
      default => return NULL,
    };

    $name = "lit-ssr-$os-$arch";
    if ($os === 'win32') {
      $name .= '.exe';
    }

    $path = "$binDir/$name";
    return is_executable($path) ? $path : NULL;
  }

}
