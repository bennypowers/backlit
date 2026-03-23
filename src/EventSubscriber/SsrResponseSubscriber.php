<?php

declare(strict_types=1);

namespace Drupal\backlit\EventSubscriber;

use Drupal\backlit\Service\LitSsrRendererInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouteObjectInterface;

/**
 * Post-processes page markup through the lit-ssr WASM binary.
 *
 * Hooks into KernelEvents::RESPONSE -- the last stop before HTML hits
 * the wire. Every web component in the response gets its shadow DOM
 * server-rendered with Declarative Shadow DOM. The user sees styled
 * content on first paint, before any JavaScript loads.
 *
 * Only processes pages whose content type is enabled in the Backlit
 * configuration. Non-node routes (Views, taxonomy, etc.) are not
 * processed.
 */
final class SsrResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LitSsrRendererInterface $renderer,
    private readonly AdminContext $adminContext,
    private readonly ConfigFactoryInterface $configFactory,
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

    // Skip admin routes and non-HTML responses.
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    if ($route && $this->adminContext->isAdminRoute($route)) {
      return;
    }

    $contentType = $response->headers->get('Content-Type', '');
    if (!str_contains($contentType, 'text/html')) {
      return;
    }

    // Only process pages for enabled content types.
    $node = $request->attributes->get('node');
    if (!$node instanceof NodeInterface) {
      return;
    }
    $enabled = $this->configFactory->get('backlit.settings')->get('enabled_bundles') ?? [];
    if (!in_array($node->bundle(), $enabled, TRUE)) {
      return;
    }

    $content = $response->getContent();
    if ($content) {
      $response->setContent($this->renderer->render($content));
    }
  }

}
