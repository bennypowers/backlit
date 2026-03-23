<?php

declare(strict_types=1);

namespace Drupal\backlit\Service;

/**
 * Manages a persistent lit-ssr process for rendering web components.
 *
 * The lit-ssr binary bundles component source files with esbuild at startup,
 * then evaluates them inside a WASM-embedded QuickJS engine. Components stay
 * registered across renders via the read-loop protocol. HTML goes in on stdin
 * (NUL-terminated), Declarative Shadow DOM comes out on stdout (also
 * NUL-terminated).
 */
final class LitSsrRenderer implements LitSsrRendererInterface {

  /** @var resource|null */
  private $process = NULL;

  /** @var resource[] */
  private array $pipes = [];

  /**
   * Render HTML through the lit-ssr runtime binary.
   *
   * @param string $html
   *   The HTML string containing web components to render.
   *
   * @return string
   *   The rendered HTML with Declarative Shadow DOM, or the
   *   original HTML if rendering fails (because we're not monsters).
   */
  public function render(string $html): string {
    try {
      $this->ensureProcess();
    }
    catch (\RuntimeException $e) {
      return $html;
    }

    fwrite($this->pipes[0], $html . "\0");
    fflush($this->pipes[0]);

    $result = '';
    while (($chunk = fread($this->pipes[1], 8192)) !== FALSE && $chunk !== '') {
      $nulPos = strpos($chunk, "\0");
      if ($nulPos !== FALSE) {
        $result .= substr($chunk, 0, $nulPos);
        break;
      }
      $result .= $chunk;
    }

    return $result ?: $html;
  }

  /**
   * Start the lit-ssr process if not already running.
   */
  private function ensureProcess(): void {
    if ($this->process !== NULL && proc_get_status($this->process)['running']) {
      return;
    }

    $binary = self::getBinaryPath();
    if (!is_executable($binary)) {
      throw new \RuntimeException("Backlit binary not found: $binary. Run: composer run post-install-cmd");
    }

    $files = self::getComponentFiles();
    if ($files === []) {
      throw new \RuntimeException('Backlit: no component files found. Set backlit.components_dir in settings.php or place source files in your theme\'s components/ directory.');
    }

    $cmd = array_merge([$binary], $files);

    $this->process = proc_open(
      $cmd,
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $this->pipes,
    );

    if (!is_resource($this->process)) {
      throw new \RuntimeException('Failed to start lit-ssr-runtime process.');
    }
  }

  /**
   * Collect component source files from all configured sources.
   *
   * Aggregates JS and TS files from:
   * 1. Drupal settings: $settings['backlit']['components_dir']
   * 2. The active theme's 'components' subdirectory
   * 3. Every custom module's js/ directory
   *
   * @return string[]
   *   Paths to source files.
   */
  private static function getComponentFiles(): array {
    $files = [];

    // 1. Explicit config in settings.php
    $settings = \Drupal::service('settings');
    $backlit = $settings->get('backlit', []);
    if (!empty($backlit['components_dir'])) {
      $dir = $backlit['components_dir'];
      if (is_dir($dir)) {
        $files = array_merge($files, self::globSourceFiles($dir));
      }
    }

    // 2. Active theme's components/ directory
    $theme = \Drupal::theme()->getActiveTheme();
    $themeDir = $theme->getPath() . '/components';
    if (is_dir($themeDir)) {
      $files = array_merge($files, self::globSourceFiles($themeDir));
    }

    // 3. All custom modules with a js/ directory
    $modulesDir = DRUPAL_ROOT . '/modules/custom';
    if (is_dir($modulesDir)) {
      foreach (scandir($modulesDir) as $mod) {
        if ($mod === '.' || $mod === '..') {
          continue;
        }
        $jsDir = "$modulesDir/$mod/js";
        if (is_dir($jsDir)) {
          $files = array_merge($files, self::globSourceFiles($jsDir));
        }
      }
    }

    return $files;
  }

  /**
   * Glob JS and TS source files from a directory.
   *
   * Excludes .d.ts and .test.ts/.test.js files to match the CLI behavior.
   *
   * @return string[]
   */
  private static function globSourceFiles(string $dir): array {
    $files = [];
    foreach (['*.js', '*.ts'] as $pattern) {
      foreach (glob("$dir/$pattern") ?: [] as $file) {
        if (str_ends_with($file, '.d.ts')
         || str_ends_with($file, '.test.ts')
         || str_ends_with($file, '.test.js')) {
          continue;
        }
        $files[] = $file;
      }
    }
    return $files;
  }

  /**
   * Resolve the platform-specific binary path.
   */
  private static function getBinaryPath(): string {
    $binDir = __DIR__ . '/../../bin';

    $os = match (PHP_OS_FAMILY) {
      'Linux' => 'linux',
      'Darwin' => 'darwin',
      'Windows' => 'win32',
      default => throw new \RuntimeException('Unsupported OS: ' . PHP_OS_FAMILY),
    };

    $arch = match (php_uname('m')) {
      'x86_64', 'amd64' => 'x64',
      'aarch64', 'arm64' => 'arm64',
      default => throw new \RuntimeException('Unsupported architecture: ' . php_uname('m')),
    };

    $name = "lit-ssr-$os-$arch";
    if ($os === 'win32') {
      $name .= '.exe';
    }

    return "$binDir/$name";
  }

  /**
   * Clean up the process on shutdown.
   */
  public function __destruct() {
    if ($this->process !== NULL) {
      @fclose($this->pipes[0]);
      @fclose($this->pipes[1]);
      @fclose($this->pipes[2]);
      @proc_close($this->process);
    }
  }

}
