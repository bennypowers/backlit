<?php

declare(strict_types=1);

namespace Drupal\backlit\EventSubscriber;

use Drupal\backlit\Service\LitSsrRenderer;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Post-processes page markup through the lit-ssr WASM binary.
 *
 * Hooks into KernelEvents::RESPONSE -- the last stop before HTML hits
 * the wire. Every web component in the response gets its shadow DOM
 * server-rendered with Declarative Shadow DOM. The user sees styled
 * content on first paint, before any JavaScript loads.
 *
 * Respects the backlit_ssr field on content entities: if an author
 * has disabled SSR for a page, we leave it alone. Because editorial
 * autonomy matters, even for shadow roots.
 */
final class SsrResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LitSsrRenderer $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse'],
    ];
  }

  /**
   * Kernel response event handler.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();
    $path = $request->getPathInfo();

    // Skip admin pages, content editing, and non-HTML responses.
    if (str_starts_with($path, '/admin/')
     || str_starts_with($path, '/node/add/')
     || str_starts_with($path, '/editor/')
     || str_starts_with($path, '/block/')) {
      return;
    }

    $contentType = $response->headers->get('Content-Type', '');
    if (!str_contains($contentType, 'text/html')) {
      return;
    }

    // Respect the backlit_ssr field: if the author disabled SSR, skip it.
    $node = $request->attributes->get('node');
    if ($node instanceof NodeInterface
     && $node->hasField('backlit_ssr')
     && !(bool) $node->get('backlit_ssr')->value) {
      return;
    }

    $content = $response->getContent();
    if ($content) {
      $response->setContent($this->renderer->render($content));
    }
  }

}
