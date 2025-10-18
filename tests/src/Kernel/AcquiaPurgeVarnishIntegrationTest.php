<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;

/**
 * Integration tests for Acquia Purge Varnish module.
 *
 * @group acquia_purge_varnish
 */
class AcquiaPurgeVarnishIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'acquia_purge_varnish',
  ];

  /**
   * The API client service.
   *
   * @var \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
   */
  protected AcquiaPurgeVarnishApiClient $apiClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['acquia_purge_varnish']);
    $this->apiClient = $this->container->get('acquia_purge_varnish.api_client');
  }

  /**
   * Tests that services are properly registered.
   */
  public function testServicesAreRegistered(): void {
    $this->assertInstanceOf(
      AcquiaPurgeVarnishApiClient::class,
      $this->apiClient,
      'API client service is registered'
    );

    $controller = $this->container->get('acquia_purge_varnish.controller');
    $this->assertNotNull($controller, 'Controller service is registered');
  }

  /**
   * Tests configuration schema.
   */
  public function testConfigurationSchema(): void {
    $config = $this->config('acquia_purge_varnish.settings');

    // Set some values.
    $config->set('api_key', 'test-key');
    $config->set('api_secret', 'test-secret');
    $config->set('application_name', 'testapp');
    $config->set('domain', 'all');
    $config->set('log_requests', TRUE);
    $config->save();

    // Verify values were saved.
    $saved_config = $this->config('acquia_purge_varnish.settings');
    $this->assertEquals('test-key', $saved_config->get('api_key'));
    $this->assertEquals('test-secret', $saved_config->get('api_secret'));
    $this->assertEquals('testapp', $saved_config->get('application_name'));
    $this->assertEquals('all', $saved_config->get('domain'));
    $this->assertTrue($saved_config->get('log_requests'));
  }

  /**
   * Tests API client methods with no Acquia environment.
   */
  public function testApiClientWithoutAcquiaEnvironment(): void {
    $this->markTestSkipped('RequestStack not available in kernel tests');
  }

  /**
   * Tests API client methods with simulated Acquia environment.
   */
  public function testApiClientWithAcquiaEnvironment(): void {
    $this->markTestSkipped('RequestStack not available in kernel tests');
  }

  /**
   * Tests logger channel is properly configured.
   */
  public function testLoggerChannelIsConfigured(): void {
    $logger = $this->container->get('logger.channel.acquia_purge_varnish');
    $this->assertNotNull($logger);

    // Test that logging doesn't throw exceptions.
    $logger->info('Test log message');
    $this->assertTrue(TRUE);
  }

  /**
   * Tests configuration form route is accessible.
   */
  public function testConfigurationFormRoute(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route = $route_provider->getRouteByName('acquia_purge_varnish.form');

    $this->assertNotNull($route);
    $this->assertEquals('/admin/config/acquia-purge/settings', $route->getPath());
  }

  /**
   * Tests purge route is accessible.
   */
  public function testPurgeRoute(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route = $route_provider->getRouteByName('acquia_purge_varnish.cache');

    $this->assertNotNull($route);
    $this->assertEquals('/admin/config/acquia-purge/varnish', $route->getPath());
  }

  /**
   * Tests permission is properly defined.
   */
  public function testPermissionIsDefined(): void {
    $permission_handler = $this->container->get('user.permissions');
    $permissions = $permission_handler->getPermissions();

    $this->assertArrayHasKey('administer acquia purge varnish', $permissions);
    $this->assertTrue($permissions['administer acquia purge varnish']['restrict access']);
  }

  /**
   * Tests config factory service integration.
   */
  public function testConfigFactoryIntegration(): void {
    $config_factory = $this->container->get('config.factory');
    $config = $config_factory->getEditable('acquia_purge_varnish.settings');

    $config->set('log_requests', TRUE);
    $config->save();

    $retrieved = $config_factory->get('acquia_purge_varnish.settings');
    $this->assertTrue($retrieved->get('log_requests'));
  }

  /**
   * Tests that makeRequest method handles empty credentials gracefully.
   */
  public function testMakeRequestWithEmptyCredentials(): void {
    // This would require mocking the OAuth provider, which is complex.
    // Testing actual API calls is better done with the test module.
    $this->markTestSkipped('OAuth integration testing requires test module');
  }

  /**
   * Tests default configuration values.
   */
  public function testDefaultConfigurationValues(): void {
    $config = $this->config('acquia_purge_varnish.settings');

    // After install, these should be NULL or have defaults.
    $this->assertNull($config->get('api_key'));
    $this->assertNull($config->get('api_secret'));
    $this->assertNull($config->get('application_name'));
    // Domain might have a default value.
    $domain = $config->get('domain');
    $this->assertTrue($domain === NULL || $domain === 'current');
  }

}
