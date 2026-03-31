<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Integration;

use Drupal\backlit\Service\LitSsrRenderer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
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

  private string $componentDir = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $binaryPath = $this->findBinary();
    if ($binaryPath === NULL) {
      $this->markTestSkipped('lit-ssr binary not installed. Run: composer run post-install-cmd');
    }

    // Create a temp directory with a simple LitElement component.
    $this->componentDir = sys_get_temp_dir() . '/backlit-integration-' . uniqid();
    mkdir($this->componentDir, 0755, TRUE);

    // The component needs node_modules with lit for esbuild bundling.
    // Create a symlink to the project's node_modules if available.
    $projectRoot = dirname(__DIR__, 3);
    $nodeModules = $this->findNodeModules($projectRoot);
    if ($nodeModules === NULL) {
      $this->markTestSkipped('node_modules with lit not found. Run: npm install lit');
    }
    symlink($nodeModules, "{$this->componentDir}/node_modules");

    file_put_contents("{$this->componentDir}/test-greeting.js", <<<'JS'
import { LitElement, html, css } from 'lit';

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
    if (is_dir($this->componentDir)) {
      // Remove symlink first.
      $link = "{$this->componentDir}/node_modules";
      if (is_link($link)) {
        unlink($link);
      }
      array_map('unlink', glob("{$this->componentDir}/*.js") ?: []);
      rmdir($this->componentDir);
    }
    parent::tearDown();
  }

  /**
   * Verify that a known custom element gets Declarative Shadow DOM.
   */
  public function testRendersDeclarativeShadowDom(): void {
    $renderer = $this->createRenderer();

    $html = '<html><body><test-greeting name="Drupal"></test-greeting></body></html>';
    $result = $renderer->render($html);

    $this->assertStringContainsString('shadowrootmode="open"', $result);
    $this->assertStringContainsString('Hello, Drupal!', $result);
  }

  /**
   * Unknown elements should pass through unchanged.
   */
  public function testPassesThroughUnknownElements(): void {
    $renderer = $this->createRenderer();

    $html = '<html><body><unknown-widget>content</unknown-widget></body></html>';
    $result = $renderer->render($html);

    $this->assertStringContainsString('<unknown-widget>content</unknown-widget>', $result);
    $this->assertStringNotContainsString('shadowrootmode', $result);
  }

  /**
   * Verify the process stays warm across multiple renders.
   */
  public function testProcessStaysWarm(): void {
    $renderer = $this->createRenderer();

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
    $renderer = $this->createRenderer();

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
   * Minification strips CSS comments but preserves Lit markers and content.
   */
  public function testMinifyShadowRoots(): void {
    $renderer = $this->createRenderer(minify: TRUE);

    $html = '<html><body><test-greeting name="Drupal"></test-greeting></body></html>';
    $result = $renderer->render($html);

    // Lit markers must survive minification.
    $this->assertStringContainsString('<!--lit-part', $result);
    $this->assertStringContainsString('<!--/lit-part-->', $result);

    // Rendered content is preserved.
    $this->assertStringContainsString('Hello, Drupal!', $result);
    $this->assertStringContainsString('shadowrootmode="open"', $result);

    // The component's static styles should be present but without any
    // CSS comments (the test component uses css`:host { display: block; }`
    // which has no comments, so just verify styles survived).
    $this->assertStringContainsString(':host { display: block; }', $result);
  }

  /**
   * Minification does not alter output when there are no comments to strip.
   */
  public function testMinifyProducesSameOutputWhenNoComments(): void {
    $rendererPlain = $this->createRenderer(minify: FALSE);
    $rendererMinify = $this->createRenderer(minify: TRUE);

    $html = '<html><body><test-greeting name="Test"></test-greeting></body></html>';
    $plain = $rendererPlain->render($html);

    // The test component has no CSS or HTML comments, so minified output
    // should be identical to plain output.
    $minified = $rendererMinify->render($html);
    $this->assertSame($plain, $minified);
  }

  /**
   * Create a renderer with a mock config factory.
   */
  private function createRenderer(bool $minify = FALSE): LitSsrRenderer {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn(string $key) => match ($key) {
        'minify' => $minify,
        default => NULL,
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('backlit.settings')
      ->willReturn($config);

    return new LitSsrRenderer($configFactory);
  }

  /**
   * Find the binary, or return NULL if not installed.
   */
  private function findBinary(): ?string {
    $binDir = dirname(__DIR__, 3) . '/bin';

    $osMap = ['Linux' => 'linux', 'Darwin' => 'darwin', 'Windows' => 'win32'];
    $os = $osMap[PHP_OS_FAMILY] ?? NULL;
    if ($os === NULL) {
      return NULL;
    }

    $archMap = ['x86_64' => 'x64', 'amd64' => 'x64', 'aarch64' => 'arm64', 'arm64' => 'arm64'];
    $arch = $archMap[php_uname('m')] ?? NULL;
    if ($arch === NULL) {
      return NULL;
    }

    $name = "lit-ssr-$os-$arch";
    if ($os === 'win32') {
      $name .= '.exe';
    }

    $path = "$binDir/$name";
    return is_executable($path) ? $path : NULL;
  }

  /**
   * Find a node_modules directory containing lit.
   *
   * Walks up from the given directory looking for node_modules/lit.
   */
  private function findNodeModules(string $startDir): ?string {
    $dir = realpath($startDir);
    $prevDir = '';
    while ($dir && $dir !== $prevDir) {
      $candidate = "$dir/node_modules";
      if (is_dir("$candidate/lit")) {
        return $candidate;
      }
      $prevDir = $dir;
      $dir = dirname($dir);
    }
    return NULL;
  }

}
