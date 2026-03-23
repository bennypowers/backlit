<?php

declare(strict_types=1);

namespace Drupal\backlit\Service;

/**
 * Manages a persistent lit-ssr process for rendering web components.
 *
 * The runtime binary loads component JS files at startup and evaluates them
 * inside a WASM-embedded QuickJS engine. Components stay registered across
 * renders via the read-loop protocol. HTML goes in on stdin (NUL-terminated),
 * Declarative Shadow DOM comes out on stdout (also NUL-terminated).
 *
 * Cold start: ~350ms (once). Warm renders: ~0.32ms (forever after).
 * The drop is always moving, but your shadow DOM renders instantly.
 */
final class LitSsrRenderer {

  /** @var resource|null */
  private $process = NULL;

  /** @var resource[] */
  private array $pipes = [];

  /**
   * Render HTML through the lit-ssr binary.
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
    while (($ch = fread($this->pipes[1], 1)) !== FALSE && $ch !== "\0" && $ch !== '') {
      $result .= $ch;
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

    $componentsDir = self::getComponentsDir();
    if ($componentsDir === NULL) {
      throw new \RuntimeException('Backlit: no components directory configured. Set backlit.components_dir in settings.php or place JS files in a "components" directory next to your custom theme.');
    }

    $cmd = [$binary, '--dir', $componentsDir];

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
      throw new \RuntimeException('Failed to start lit-ssr process.');
    }
  }

  /**
   * Find the directory containing component JS files.
   *
   * Checks, in order:
   * 1. Drupal settings: $settings['backlit']['components_dir']
   * 2. The active theme's 'components' subdirectory
   * 3. modules/custom/ * /js/ (first match)
   *
   * Returns NULL if no directory with JS files is found.
   */
  private static function getComponentsDir(): ?string {
    // 1. Explicit config in settings.php
    $settings = \Drupal::service('settings');
    $backlit = $settings->get('backlit', []);
    if (!empty($backlit['components_dir'])) {
      $dir = $backlit['components_dir'];
      if (is_dir($dir) && glob("$dir/*.js")) {
        return $dir;
      }
    }

    // 2. Active theme's components/ directory
    $theme = \Drupal::theme()->getActiveTheme();
    $themeDir = $theme->getPath() . '/components';
    if (is_dir($themeDir) && glob("$themeDir/*.js")) {
      return $themeDir;
    }

    // 3. First custom module with a js/ directory
    $modulesDir = DRUPAL_ROOT . '/modules/custom';
    if (is_dir($modulesDir)) {
      foreach (scandir($modulesDir) as $mod) {
        $jsDir = "$modulesDir/$mod/js";
        if (is_dir($jsDir) && glob("$jsDir/*.js")) {
          return $jsDir;
        }
      }
    }

    return NULL;
  }

  /**
   * Resolve the platform-specific binary path.
   *
   * Uses lit-ssr-{os}-{arch}.
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
