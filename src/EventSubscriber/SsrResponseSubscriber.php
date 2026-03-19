<?php

declare(strict_types=1);

namespace Drupal\backlit\EventSubscriber;

use Drupal\backlit\Service\LitSsrRenderer;
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
 * Skips admin pages because Drupal's admin UI has enough problems
 * without us injecting shadow roots into it.
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

    $content = $response->getContent();
    if ($content) {
      $response->setContent($this->renderer->render($content));
    }
  }

}
