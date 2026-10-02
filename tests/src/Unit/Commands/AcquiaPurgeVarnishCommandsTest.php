<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Unit\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\acquia_purge_varnish\Commands\AcquiaPurgeVarnishCommands;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Acquia Purge Varnish Drush commands.
 *
 * Note: Full command execution tests should be done manually or with
 * functional tests. These unit tests verify internal helper methods only.
 *
 * @group acquia_purge_varnish
 * @coversDefaultClass \Drupal\acquia_purge_varnish\Commands\AcquiaPurgeVarnishCommands
 */
#[CoversClass(\Drupal\acquia_purge_varnish\Commands\AcquiaPurgeVarnishCommands::class)]
#[Group('acquia_purge_varnish')]
class AcquiaPurgeVarnishCommandsTest extends UnitTestCase {

  /**
   * The API client mock.
   *
   * @var \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
   */
  protected AcquiaPurgeVarnishApiClient $apiClient;

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The commands object under test.
   *
   * @var \Drupal\acquia_purge_varnish\Commands\AcquiaPurgeVarnishCommands
   */
  protected AcquiaPurgeVarnishCommands $commands;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->apiClient = $this->createMock(AcquiaPurgeVarnishApiClient::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->commands = new AcquiaPurgeVarnishCommands($this->apiClient, $this->configFactory);
  }

  /**
   * Tests getCredentials returns proper array when configured.
   *
   * @covers ::getCredentials
   */
  public function testGetCredentialsSuccess(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['api_key', 'test-key'],
      ['api_secret', 'test-secret'],
      ['application_name', 'testapp'],
    ]);
    $this->configFactory->method('get')->willReturn($config);
    $this->apiClient->method('getAcquiaSiteGroup')->willReturn('testapp');

    $reflection = new \ReflectionClass($this->commands);
    $method = $reflection->getMethod('getCredentials');
    $result = $method->invoke($this->commands);

    $this->assertIsArray($result);
    $this->assertCount(3, $result);
    $this->assertEquals('test-key', $result[0]);
    $this->assertEquals('test-secret', $result[1]);
    $this->assertEquals('testapp', $result[2]);
  }

  /**
   * Tests getCredentials returns NULL when API key is missing.
   *
   * @covers ::getCredentials
   */
  public function testGetCredentialsMissingApiKey(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['api_key', NULL],
      ['api_secret', 'test-secret'],
      ['application_name', 'testapp'],
    ]);
    $this->configFactory->method('get')->willReturn($config);

    $reflection = new \ReflectionClass($this->commands);
    $method = $reflection->getMethod('getCredentials');
    $result = $method->invoke($this->commands);

    $this->assertNull($result);
  }

  /**
   * Tests buildPurgeRequest for current domain.
   *
   * @covers ::buildPurgeRequest
   */
  public function testBuildPurgeRequestCurrentDomain(): void {
    $environments = [
      ['id' => 'env-123', 'name' => 'dev', 'domains' => ['example.com']],
    ];

    $reflection = new \ReflectionClass($this->commands);
    $method = $reflection->getMethod('buildPurgeRequest');
    [$url, $options, $domains] = $method->invoke($this->commands, $environments, 'example.com', 'current');

    $this->assertStringContainsString('env-123', $url);
    $this->assertStringContainsString('example.com', $url);
    $this->assertEquals(['example.com'], $domains);
    $this->assertEmpty($options);
  }

  /**
   * Tests buildPurgeRequest for all domains.
   *
   * @covers ::buildPurgeRequest
   */
  public function testBuildPurgeRequestAllDomains(): void {
    $environments = [
      ['id' => 'env-456', 'name' => 'prod', 'domains' => ['example.com', 'www.example.com']],
    ];

    $reflection = new \ReflectionClass($this->commands);
    $method = $reflection->getMethod('buildPurgeRequest');
    [$url, $options, $domains] = $method->invoke($this->commands, $environments, 'example.com', 'all');

    $this->assertStringContainsString('env-456', $url);
    $this->assertArrayHasKey('json', $options);
    $this->assertArrayHasKey('domains', $options['json']);
    $this->assertEquals(['example.com', 'www.example.com'], $domains);
  }

  /**
   * Tests getAcquiaConfigValue retrieves config properly.
   *
   * @covers ::getAcquiaConfigValue
   */
  public function testGetAcquiaConfigValue(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->expects($this->once())->method('get')->with('domain')->willReturn('all');
    $this->configFactory->expects($this->once())->method('get')->willReturn($config);

    $reflection = new \ReflectionClass($this->commands);
    $method = $reflection->getMethod('getAcquiaConfigValue');
    $result = $method->invoke($this->commands, 'domain');

    $this->assertEquals('all', $result);
  }

}
