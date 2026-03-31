<?php

declare(strict_types=1);

namespace Drupal\backlit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

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

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

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

    if ($result === '') {
      return $html;
    }

    if ($this->configFactory->get('backlit.settings')->get('minify')) {
      $result = self::minifyShadowRoots($result);
    }

    return $result;
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

    $cmd = self::buildCommand($binary);

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
   * Build the command array for the lit-ssr binary.
   *
   * If $settings['backlit']['bundle'] is set, uses --skip-bundle with
   * the pre-built JS file. Otherwise discovers source files and passes
   * them as positional args for esbuild bundling.
   *
   * @return string[]
   */
  private static function buildCommand(string $binary): array {
    $settings = \Drupal::service('settings');
    $backlit = $settings->get('backlit', []);

    if (!empty($backlit['bundle'])) {
      $bundle = $backlit['bundle'];
      if (!is_file($bundle)) {
        throw new \RuntimeException("Backlit bundle not found: $bundle");
      }
      return [$binary, '--skip-bundle', $bundle];
    }

    $files = self::getComponentFiles();
    if ($files === []) {
      throw new \RuntimeException('Backlit: no component files found. Set backlit.components_dir in settings.php or place source files in your theme\'s components/ directory.');
    }
    return array_merge([$binary], $files);
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
      foreach (scandir($modulesDir) ?: [] as $mod) {
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
   * Strip comments from rendered shadow root content.
   *
   * Removes CSS comments from <style> blocks and non-Lit HTML comments
   * from within <template shadowroot*> elements. Lit markers like
   * <!--lit-part ...-->, <!--/lit-part-->, and <!--lit-node ...--> are
   * preserved because they are required for client-side hydration.
   *
   * Content outside shadow roots is never modified.
   *
   * @param string $html
   *   The rendered HTML containing Declarative Shadow DOM.
   *
   * @return string
   *   The HTML with shadow root comments removed.
   */
  private static function minifyShadowRoots(string $html): string {
    $len = strlen($html);
    if ($len === 0) {
      return '';
    }

    $result = '';
    $pos = 0;

    while ($pos < $len) {
      // Find the next shadow root template opening.
      $templateStart = self::findShadowRootOpen($html, $pos);
      if ($templateStart === FALSE) {
        // No more shadow roots; append the rest unchanged.
        $result .= substr($html, $pos);
        break;
      }

      // Find end of the opening tag (the '>').
      $tagEnd = strpos($html, '>', $templateStart);
      if ($tagEnd === FALSE) {
        $result .= substr($html, $pos);
        break;
      }
      $tagEnd++;

      // Append everything before and including the opening tag.
      $result .= substr($html, $pos, $tagEnd - $pos);
      $pos = $tagEnd;

      // Find the matching </template> (accounting for nesting).
      $closePos = self::findMatchingTemplateClose($html, $pos);
      if ($closePos === FALSE) {
        $result .= substr($html, $pos);
        break;
      }

      // Extract the shadow root inner content and minify it.
      $inner = substr($html, $pos, $closePos - $pos);
      $inner = self::minifyShadowRootContent($inner);
      $result .= $inner;

      $pos = $closePos;
    }

    return $result;
  }

  /**
   * Find the next <template shadowroot opening tag.
   *
   * @return int|false
   *   Position of the '<' or FALSE if not found.
   */
  private static function findShadowRootOpen(string $html, int $offset): int|false {
    while (($pos = stripos($html, '<template', $offset)) !== FALSE) {
      // Check that the tag has a shadowroot or shadowrootmode attribute.
      $tagEnd = strpos($html, '>', $pos);
      if ($tagEnd === FALSE) {
        return FALSE;
      }
      $tag = substr($html, $pos, $tagEnd - $pos + 1);
      if (preg_match('/\bshadowroot(mode)?\s*=/', $tag)) {
        return $pos;
      }
      $offset = $tagEnd + 1;
    }
    return FALSE;
  }

  /**
   * Find the matching </template> for an already-opened shadow root.
   *
   * Handles nested <template> elements by tracking depth.
   *
   * @param string $html
   *   The full HTML string.
   * @param int $offset
   *   Position just after the opening tag's '>'.
   *
   * @return int|false
   *   Position of the '<' in the matching </template>, or FALSE.
   */
  private static function findMatchingTemplateClose(string $html, int $offset): int|false {
    $depth = 1;
    $pos = $offset;
    $len = strlen($html);

    while ($pos < $len && $depth > 0) {
      // Find the next <template or </template.
      $next = stripos($html, '<template', $pos);
      $nextClose = stripos($html, '</template>', $pos);

      if ($nextClose === FALSE) {
        return FALSE;
      }

      if ($next !== FALSE && $next < $nextClose) {
        // Check if this is a self-closing <template .../> (skip if so).
        $tagEnd = strpos($html, '>', $next);
        if ($tagEnd !== FALSE && $html[$tagEnd - 1] === '/') {
          $pos = $tagEnd + 1;
          continue;
        }
        $depth++;
        $pos = ($tagEnd !== FALSE) ? $tagEnd + 1 : $next + 9;
      }
      else {
        $depth--;
        if ($depth === 0) {
          return $nextClose;
        }
        $pos = $nextClose + 11; // strlen('</template>')
      }
    }

    return FALSE;
  }

  /**
   * Minify content inside a shadow root.
   *
   * Strips CSS comments from <style> blocks (respecting string literals)
   * and removes non-Lit HTML comments.
   */
  private static function minifyShadowRootContent(string $content): string {
    // 1. Process <style> blocks: strip CSS comments.
    $content = preg_replace_callback(
      '/<style\b[^>]*>(.*?)<\/style>/si',
      function (array $m) {
        return '<style' . '>' . self::stripCssComments($m[1]) . '</style>';
      },
      $content,
    );

    // 2. Strip non-Lit HTML comments.
    $content = self::stripNonLitComments($content);

    return $content;
  }

  /**
   * Remove CSS block comments while respecting string literals.
   *
   * Scans character by character to avoid stripping comment-like content
   * inside quoted strings (e.g. content: "not a comment").
   */
  private static function stripCssComments(string $css): string {
    $len = strlen($css);
    $result = '';
    $i = 0;

    while ($i < $len) {
      // Check for string literal.
      if ($css[$i] === '"' || $css[$i] === "'") {
        $quote = $css[$i];
        $result .= $quote;
        $i++;
        // Copy until matching close quote, respecting backslash escapes.
        while ($i < $len) {
          if ($css[$i] === '\\' && $i + 1 < $len) {
            $result .= $css[$i] . $css[$i + 1];
            $i += 2;
            continue;
          }
          if ($css[$i] === $quote) {
            $result .= $quote;
            $i++;
            break;
          }
          $result .= $css[$i];
          $i++;
        }
        continue;
      }

      // Check for comment start.
      if ($i + 1 < $len && $css[$i] === '/' && $css[$i + 1] === '*') {
        // Skip until end of comment.
        $end = strpos($css, '*/', $i + 2);
        if ($end === FALSE) {
          // Unterminated comment: skip the rest.
          break;
        }
        $i = $end + 2;
        continue;
      }

      $result .= $css[$i];
      $i++;
    }

    return $result;
  }

  /**
   * Remove HTML comments that are not Lit SSR markers.
   *
   * Preserves:
   *   <!--lit-part ...-->
   *   <!--/lit-part-->
   *   <!--lit-node ...-->
   *
   * Also avoids stripping comment-like syntax inside attribute values.
   */
  private static function stripNonLitComments(string $html): string {
    $len = strlen($html);
    $result = '';
    $i = 0;

    while ($i < $len) {
      // Inside a tag? Copy it verbatim to avoid mangling attributes
      // that contain "<!--" in their values.
      if ($html[$i] === '<' && $i + 1 < $len && $html[$i + 1] !== '!') {
        // Regular tag: copy until '>'.
        $tagEnd = strpos($html, '>', $i);
        if ($tagEnd === FALSE) {
          $result .= substr($html, $i);
          break;
        }
        $result .= substr($html, $i, $tagEnd - $i + 1);
        $i = $tagEnd + 1;
        continue;
      }

      // Check for HTML comment.
      if ($i + 3 < $len && substr($html, $i, 4) === '<!--') {
        $commentEnd = strpos($html, '-->', $i + 4);
        if ($commentEnd === FALSE) {
          $result .= substr($html, $i);
          break;
        }
        $comment = substr($html, $i, $commentEnd + 3 - $i);

        // Preserve Lit markers.
        if (self::isLitMarker($comment)) {
          $result .= $comment;
        }
        // Else: drop the comment.

        $i = $commentEnd + 3;
        continue;
      }

      $result .= $html[$i];
      $i++;
    }

    return $result;
  }

  /**
   * Check if an HTML comment is a Lit SSR marker.
   */
  private static function isLitMarker(string $comment): bool {
    return str_starts_with($comment, '<!--lit-part')
        || str_starts_with($comment, '<!--/lit-part')
        || str_starts_with($comment, '<!--lit-node');
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
