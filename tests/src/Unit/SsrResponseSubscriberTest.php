<?php

declare(strict_types=1);

namespace Drupal\Tests\backlit\Unit;

use Drupal\backlit\EventSubscriber\SsrResponseSubscriber;
use Drupal\backlit\Service\LitSsrRendererInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Routing\AdminContext;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteObjectInterface;

/**
 * @coversDefaultClass \Drupal\backlit\EventSubscriber\SsrResponseSubscriber
 * @group backlit
 */
class SsrResponseSubscriberTest extends TestCase {

  private LitSsrRendererInterface $renderer;

  private AdminContext $adminContext;

  private ConfigFactoryInterface $configFactory;

  private SsrResponseSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->renderer = $this->createMock(LitSsrRendererInterface::class);
    $this->adminContext = $this->createMock(AdminContext::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $this->subscriber = new SsrResponseSubscriber(
      $this->renderer,
      $this->adminContext,
      $this->configFactory,
    );
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesToResponseEvent(): void {
    $events = SsrResponseSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsAdminRoutes(): void {
    $route = new Route('/admin/content');
    $this->adminContext->method('isAdminRoute')->with($route)->willReturn(TRUE);

    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'text/html',
    );

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsNonHtmlResponses(): void {
    $route = new Route('/api/data');
    $this->adminContext->method('isAdminRoute')->willReturn(FALSE);

    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'application/json',
    );

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsNonNodeRoutes(): void {
    $route = new Route('/my-view-page');
    $this->adminContext->method('isAdminRoute')->willReturn(FALSE);

    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'text/html',
      node: NULL,
    );

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsDisabledBundles(): void {
    $route = new Route('/node/1');
    $this->adminContext->method('isAdminRoute')->willReturn(FALSE);
    $this->setUpConfig(['page']);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('article');

    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'text/html',
      node: $node,
    );

    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testProcessesEnabledBundles(): void {
    $route = new Route('/node/1');
    $this->adminContext->method('isAdminRoute')->willReturn(FALSE);
    $this->setUpConfig(['article']);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('article');

    $this->renderer
      ->expects($this->once())
      ->method('render')
      ->with('<rh-card>Hello</rh-card>')
      ->willReturn('<rh-card><template shadowrootmode="open"></template>Hello</rh-card>');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'text/html',
      node: $node,
      body: '<rh-card>Hello</rh-card>',
    );

    $this->subscriber->onResponse($event);

    $this->assertStringContainsString(
      'shadowrootmode="open"',
      $event->getResponse()->getContent(),
    );
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsWhenNoRouteObject(): void {
    $this->adminContext->expects($this->never())->method('isAdminRoute');
    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: NULL,
      contentType: 'text/html',
    );

    // No route object means no admin check fires, but also no node,
    // so the subscriber skips at the node check.
    $this->subscriber->onResponse($event);
  }

  /**
   * @covers ::onResponse
   */
  public function testSkipsEmptyResponseBody(): void {
    $route = new Route('/node/1');
    $this->adminContext->method('isAdminRoute')->willReturn(FALSE);
    $this->setUpConfig(['article']);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('article');

    $this->renderer->expects($this->never())->method('render');

    $event = $this->createResponseEvent(
      route: $route,
      contentType: 'text/html',
      node: $node,
      body: '',
    );

    $this->subscriber->onResponse($event);
  }

  /**
   * Set up the config factory mock with enabled bundles.
   */
  private function setUpConfig(array $enabledBundles): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('enabled_bundles')
      ->willReturn($enabledBundles);

    $this->configFactory->method('get')
      ->with('backlit.settings')
      ->willReturn($config);
  }

  /**
   * Create a ResponseEvent with the given parameters.
   */
  private function createResponseEvent(
    ?Route $route,
    string $contentType,
    ?NodeInterface $node = NULL,
    string $body = '<html></html>',
  ): ResponseEvent {
    $attributes = new ParameterBag();
    if ($route !== NULL) {
      $attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    }
    if ($node !== NULL) {
      $attributes->set('node', $node);
    }

    $request = new Request();
    $ref = new \ReflectionProperty(Request::class, 'attributes');
    $ref->setValue($request, $attributes);

    $response = new Response($body);
    $response->headers->set('Content-Type', $contentType);

    $kernel = $this->createMock(HttpKernelInterface::class);

    return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
  }

}
