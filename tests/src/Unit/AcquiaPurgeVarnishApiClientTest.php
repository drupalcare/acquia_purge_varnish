<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Unit;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ServerBag;

/**
 * Tests the Acquia Purge Varnish API Client service.
 *
 * @group acquia_purge_varnish
 * @coversDefaultClass \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
 */
class AcquiaPurgeVarnishApiClientTest extends UnitTestCase {

  /**
   * The HTTP client mock.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The request stack mock.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The API client under test.
   *
   * @var \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
   */
  protected AcquiaPurgeVarnishApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);

    $this->apiClient = new AcquiaPurgeVarnishApiClient(
      $this->httpClient,
      $this->logger,
      $this->configFactory,
      $this->requestStack
    );
  }

  /**
   * Tests getting the Acquia environment.
   *
   * @covers ::getAcquiaEnvironment
   */
  public function testGetAcquiaEnvironment(): void {
    $request = $this->createMock(SymfonyRequest::class);
    $serverBag = $this->createMock(ServerBag::class);

    $request->server = $serverBag;
    $serverBag->expects($this->once())
      ->method('get')
      ->with('AH_SITE_ENVIRONMENT')
      ->willReturn('prod');

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $environment = $this->apiClient->getAcquiaEnvironment();
    $this->assertEquals('prod', $environment);
  }

  /**
   * Tests getting the Acquia environment when no request exists.
   *
   * @covers ::getAcquiaEnvironment
   */
  public function testGetAcquiaEnvironmentNoRequest(): void {
    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn(NULL);

    $environment = $this->apiClient->getAcquiaEnvironment();
    $this->assertNull($environment);
  }

  /**
   * Tests getting the Acquia site group.
   *
   * @covers ::getAcquiaSiteGroup
   */
  public function testGetAcquiaSiteGroup(): void {
    $request = $this->createMock(SymfonyRequest::class);
    $serverBag = $this->createMock(ServerBag::class);

    $request->server = $serverBag;
    $serverBag->expects($this->once())
      ->method('get')
      ->with('AH_SITE_GROUP')
      ->willReturn('mysite');

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $siteGroup = $this->apiClient->getAcquiaSiteGroup();
    $this->assertEquals('mysite', $siteGroup);
  }

  /**
   * Tests getting the Acquia site group when no request exists.
   *
   * @covers ::getAcquiaSiteGroup
   */
  public function testGetAcquiaSiteGroupNoRequest(): void {
    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn(NULL);

    $siteGroup = $this->apiClient->getAcquiaSiteGroup();
    $this->assertNull($siteGroup);
  }

  /**
   * Tests getting the current host.
   *
   * @covers ::getCurrentHost
   */
  public function testGetCurrentHost(): void {
    $request = $this->createMock(SymfonyRequest::class);
    $serverBag = $this->createMock(ServerBag::class);

    $request->server = $serverBag;
    $serverBag->expects($this->once())
      ->method('get')
      ->with('HTTP_HOST')
      ->willReturn('example.com');

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $host = $this->apiClient->getCurrentHost();
    $this->assertEquals('example.com', $host);
  }

  /**
   * Tests getting the current host when no request exists.
   *
   * @covers ::getCurrentHost
   */
  public function testGetCurrentHostNoRequest(): void {
    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn(NULL);

    $host = $this->apiClient->getCurrentHost();
    $this->assertNull($host);
  }

  /**
   * Tests checking if in Acquia environment.
   *
   * @covers ::isAcquiaEnvironment
   */
  public function testIsAcquiaEnvironment(): void {
    $request = $this->createMock(SymfonyRequest::class);
    $serverBag = $this->createMock(ServerBag::class);

    $request->server = $serverBag;
    $serverBag->expects($this->once())
      ->method('get')
      ->with('AH_SITE_ENVIRONMENT')
      ->willReturn('prod');

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $this->assertTrue($this->apiClient->isAcquiaEnvironment());
  }

  /**
   * Tests checking if NOT in Acquia environment.
   *
   * @covers ::isAcquiaEnvironment
   */
  public function testIsNotAcquiaEnvironment(): void {
    $request = $this->createMock(SymfonyRequest::class);
    $serverBag = $this->createMock(ServerBag::class);

    $request->server = $serverBag;
    $serverBag->expects($this->once())
      ->method('get')
      ->with('AH_SITE_ENVIRONMENT')
      ->willReturn(NULL);

    $this->requestStack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    $this->assertFalse($this->apiClient->isAcquiaEnvironment());
  }

}
