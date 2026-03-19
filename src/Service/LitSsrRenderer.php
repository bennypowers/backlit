<?php

declare(strict_types=1);

namespace Drupal\backlit\Service;

/**
 * Manages a persistent lit-ssr process for rendering web components.
 *
 * The lit-ssr binary embeds a WASM module containing QuickJS, the Lit SSR
 * engine, and your component definitions. HTML goes in on stdin
 * (NUL-terminated), Declarative Shadow DOM comes out on stdout
 * (also NUL-terminated). The WASM instance stays warm across renders.
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
   * Render HTML through the lit-ssr WASM binary.
   *
   * Pipes the entire response HTML through the binary. Every custom element
   * with a registered definition gets its shadow DOM injected as a
   * <template shadowrootmode="open"> element. Elements without definitions
   * pass through unchanged -- no harm, no foul.
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
      // If the binary isn't available, return the original HTML.
      // The components will still work client-side, they just won't
      // have the sweet sweet instant first paint.
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
   *
   * The process stays alive across renders within the same PHP-FPM worker.
   * First call pays the cold start (~350ms). Every subsequent call: ~0.32ms.
   */
  private function ensureProcess(): void {
    if ($this->process !== NULL && proc_get_status($this->process)['running']) {
      return;
    }

    $binary = self::getBinaryPath();
    if (!is_executable($binary)) {
      throw new \RuntimeException("Backlit binary not found: $binary. Run: composer run post-install-cmd");
    }

    $this->process = proc_open(
      [$binary],
      [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ],
      $this->pipes,
    );

    if (!is_resource($this->process)) {
      throw new \RuntimeException('Failed to start lit-ssr process. Check file permissions on the binary.');
    }
  }

  /**
   * Resolve the platform-specific binary path.
   *
   * Binaries follow the naming convention lit-ssr-{os}-{arch}:
   *   linux-x64, linux-arm64, darwin-x64, darwin-arm64,
   *   win32-x64, win32-arm64
   *
   * Yes, we support Windows. No, we haven't tested it. Godspeed.
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
