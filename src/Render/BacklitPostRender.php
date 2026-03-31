<?php

declare(strict_types=1);

namespace Drupal\backlit\Render;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Post-render callback that pipes HTML through the lit-ssr binary.
 */
final class BacklitPostRender implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['render'];
  }

  /**
   * Post-render callback.
   *
   * @param string $html
   *   The rendered HTML string.
   * @param array $element
   *   The render array.
   *
   * @return string
   *   HTML with Declarative Shadow DOM injected.
   */
  public static function render(string $html, array $element): string {
    return \Drupal::service('backlit.renderer')->render($html);
  }

}
