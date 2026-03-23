<?php

declare(strict_types=1);

namespace Drupal\backlit\Service;

/**
 * Renders HTML through the lit-ssr binary.
 */
interface LitSsrRendererInterface {

  /**
   * Render HTML through the lit-ssr runtime binary.
   *
   * @param string $html
   *   The HTML string containing web components to render.
   *
   * @return string
   *   The rendered HTML with Declarative Shadow DOM, or the
   *   original HTML if rendering fails.
   */
  public function render(string $html): string;

}
