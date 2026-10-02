<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\acquia_purge_varnish\Controller\AcquiaPurgeVarnishController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the Acquia Purge Varnish Controller.
 *
 * @group acquia_purge_varnish
 * @coversDefaultClass \Drupal\acquia_purge_varnish\Controller\AcquiaPurgeVarnishController
 */
#[CoversClass(\Drupal\acquia_purge_varnish\Controller\AcquiaPurgeVarnishController::class)]
#[Group('acquia_purge_varnish')]
class AcquiaPurgeVarnishControllerTest extends UnitTestCase {

  /**
   * The mocked config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked logger.
   */
  protected LoggerChannelInterface $logger;

  /**
   * The mocked request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The mocked API client.
   */
  protected AcquiaPurgeVarnishApiClient $apiClient;

  /**
   * The mocked messenger.
   */
  protected MessengerInterface $messenger;

  /**
   * The controller under test.
   */
  protected AcquiaPurgeVarnishController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->apiClient = $this->createMock(AcquiaPurgeVarnishApiClient::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $this->controller = new AcquiaPurgeVarnishController(
      $this->configFactory,
      $this->logger,
      $this->requestStack,
      $this->apiClient,
      $this->messenger
    );

    $this->controller->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Tests purgeVarnish returns a redirect response.
   *
   * @covers ::purgeVarnish
   */
  public function testPurgeVarnishReturnsRedirect(): void {
    // A REAL Request, not a mock with its ->server reassigned. That older
    // shape stopped working — Symfony's Request::$server is no longer a
    // property a test can swap on a mock — so the mocked ServerBag was never
    // consulted, HTTP_REFERER read as NULL, and the controller fell through
    // to its Url::fromRoute() branch, which needs a container this unit test
    // does not have. The visible symptom was ContainerNotInitializedException,
    // three layers away from the actual cause.
    $request = new Request([], [], [], [], [], [
      'HTTP_REFERER' => 'https://example.com/previous-page',
      'HTTP_HOST' => 'example.com',
      'HTTPS' => 'on',
    ]);

    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->apiClient->expects($this->once())->method('isAcquiaEnvironment')->willReturn(FALSE);
    $this->apiClient->method('getCurrentHost')->willReturn('localhost');
    $this->messenger->expects($this->once())->method('addError');

    $response = $this->controller->purgeVarnish();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('https://example.com/previous-page', $response->getTargetUrl());
  }

  /**
   * Tests purge fails when not on Acquia.
   *
   * @covers ::purgeVarnish
   * @covers ::purgeAcquiaVarnishCache
   */
  public function testPurgeFailsWhenNotOnAcquia(): void {
    $this->apiClient->expects($this->once())->method('isAcquiaEnvironment')->willReturn(FALSE);
    $this->apiClient->expects($this->once())->method('getCurrentHost')->willReturn('localhost');
    $this->messenger->expects($this->once())->method('addError');

    // A REAL Request, not a mock with its ->server reassigned. That older
    // shape stopped working — Symfony's Request::$server is no longer a
    // property a test can swap on a mock — so the mocked ServerBag was never
    // consulted, HTTP_REFERER read as NULL, and the controller fell through
    // to its Url::fromRoute() branch, which needs a container this unit test
    // does not have. The visible symptom was ContainerNotInitializedException,
    // three layers away from the actual cause.
    $request = new Request([], [], [], [], [], [
      'HTTP_REFERER' => 'https://example.com',
      'HTTP_HOST' => 'example.com',
      'HTTPS' => 'on',
    ]);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $response = $this->controller->purgeVarnish();
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  /**
   * Tests purge fails when credentials are missing.
   *
   * @covers ::purgeAcquiaVarnishCache
   * @covers ::getConfigValue
   */
  public function testPurgeFailsWhenCredentialsMissing(): void {
    $this->apiClient->method('isAcquiaEnvironment')->willReturn(TRUE);
    $this->apiClient->method('getCurrentHost')->willReturn('example.com');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $this->configFactory->method('get')->willReturn($config);

    $this->messenger->expects($this->once())->method('addError');

    // A REAL Request, not a mock with its ->server reassigned. That older
    // shape stopped working — Symfony's Request::$server is no longer a
    // property a test can swap on a mock — so the mocked ServerBag was never
    // consulted, HTTP_REFERER read as NULL, and the controller fell through
    // to its Url::fromRoute() branch, which needs a container this unit test
    // does not have. The visible symptom was ContainerNotInitializedException,
    // three layers away from the actual cause.
    $request = new Request([], [], [], [], [], [
      'HTTP_REFERER' => 'https://example.com',
      'HTTP_HOST' => 'example.com',
      'HTTPS' => 'on',
    ]);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->controller->purgeVarnish();
  }

  /**
   * Tests getConfigValue returns config value.
   *
   * @covers ::getConfigValue
   */
  public function testGetConfigValue(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())
      ->method('get')
      ->with('api_key')
      ->willReturn('test-key');

    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('acquia_purge_varnish.settings')
      ->willReturn($config);

    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('getConfigValue');

    $result = $method->invoke($this->controller, 'api_key');
    $this->assertEquals('test-key', $result);
  }

  /**
   * Tests getUuidAndDomains for current domain purge.
   *
   * @covers ::getUuidAndDomains
   */
  public function testGetUuidAndDomainsCurrentDomain(): void {
    $environments = [
      [
        'id' => 'env-123',
        'name' => 'dev',
        'domains' => ['example.com', 'test.example.com'],
      ],
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('domain')->willReturn('current');
    $this->configFactory->method('get')->willReturn($config);

    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('getUuidAndDomains');

    [$url, $options] = $method->invoke($this->controller, $environments, 'example.com');

    $this->assertStringContainsString('env-123', $url);
    $this->assertStringContainsString('example.com', $url);
    $this->assertStringContainsString('clear-caches', $url);
    $this->assertEmpty($options);
  }

  /**
   * Tests getUuidAndDomains for all domains purge.
   *
   * @covers ::getUuidAndDomains
   */
  public function testGetUuidAndDomainsAllDomains(): void {
    $environments = [
      [
        'id' => 'env-456',
        'name' => 'prod',
        'domains' => ['example.com', 'www.example.com'],
      ],
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('domain')->willReturn('all');
    $this->configFactory->method('get')->willReturn($config);

    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('getUuidAndDomains');

    [$url, $options] = $method->invoke($this->controller, $environments, 'example.com');

    $this->assertStringContainsString('env-456', $url);
    $this->assertStringContainsString('clear-caches', $url);
    $this->assertArrayHasKey('json', $options);
    $this->assertArrayHasKey('domains', $options['json']);
    $this->assertEquals(['example.com', 'www.example.com'], $options['json']['domains']);
  }

  /**
   * Tests getUuidAndDomains returns empty when domain not found.
   *
   * @covers ::getUuidAndDomains
   */
  public function testGetUuidAndDomainsDomainNotFound(): void {
    $environments = [
      [
        'id' => 'env-789',
        'name' => 'stage',
        'domains' => ['stage.example.com'],
      ],
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('current');
    $this->configFactory->method('get')->willReturn($config);

    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('getUuidAndDomains');

    [$url, $options] = $method->invoke($this->controller, $environments, 'notfound.example.com');

    $this->assertEmpty($url);
    $this->assertEmpty($options);
  }

}
