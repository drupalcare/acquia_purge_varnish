<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish_test;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Mock API client for local testing without Acquia environment.
 *
 * This service simulates Acquia Cloud Platform API responses and environment
 * variables for local development and testing.
 *
 * @see \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
 */
class MockAcquiaPurgeVarnishApiClient extends AcquiaPurgeVarnishApiClient {

  /**
   * Constructs a MockAcquiaPurgeVarnishApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    ClientInterface $httpClient,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $configFactory,
    RequestStack $requestStack,
  ) {
    parent::__construct($httpClient, $logger, $configFactory, $requestStack);

    // Log that mock mode is active.
    $this->logger->warning('Mock Acquia API client is active. This should only be used for development/testing.');
  }

  /**
   * {@inheritdoc}
   */
  public function makeRequest(string $method, string $url, array $credentials, array $options = []): ?array {
    // Log the mock request.
    $this->logger->info('MOCK API Request - Method: @method | URL: @url | Options: @options', [
      '@method' => $method,
      '@url' => $url,
      '@options' => json_encode($options),
    ]);

    // Simulate network delay (optional, for realistic testing).
    // usleep(100000); // 0.1 second delay.
    // Parse URL to determine which mock response to return.
    if (str_contains($url, '/api/applications') && !str_contains($url, '/environments')) {
      return $this->getMockApplicationsResponse();
    }

    if (str_contains($url, '/environments') && !str_contains($url, '/actions/clear-caches')) {
      return $this->getMockEnvironmentsResponse();
    }

    if (str_contains($url, '/actions/clear-caches')) {
      return $this->getMockPurgeResponse($url, $options);
    }

    // Default response for unknown endpoints.
    $this->logger->warning('Mock API endpoint not implemented: @url', ['@url' => $url]);
    return [
      'error' => 'not_implemented',
      'message' => 'Mock API endpoint not implemented',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAcquiaEnvironment(): ?string {
    // Check for environment variable override (for testing different envs).
    $override = getenv('MOCK_AH_SITE_ENVIRONMENT');
    if ($override !== FALSE) {
      return $override;
    }

    // Default to 'dev' for local testing.
    return 'dev';
  }

  /**
   * {@inheritdoc}
   */
  public function getAcquiaSiteGroup(): ?string {
    // Check for environment variable override.
    $override = getenv('MOCK_AH_SITE_GROUP');
    if ($override !== FALSE) {
      return $override;
    }

    // Default to 'testsite' for local testing.
    return 'testsite';
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentHost(): ?string {
    // Use actual host from request if available.
    $host = parent::getCurrentHost();

    // Fallback to mock host for CLI/testing.
    return $host ?? 'testsite.local';
  }

  /**
   * {@inheritdoc}
   */
  public function isAcquiaEnvironment(): bool {
    // Always return TRUE in test mode to simulate Acquia environment.
    return TRUE;
  }

  /**
   * Returns mock applications API response.
   *
   * Simulates: GET https://cloud.acquia.com/api/applications.
   *
   * @return array
   *   Mock applications data matching Acquia API v2 structure.
   */
  protected function getMockApplicationsResponse(): array {
    return [
      '_embedded' => [
        'items' => [
          [
            'uuid' => 'a47ac10b-58cc-4372-a567-0e02b2c3d470',
            'name' => 'testsite',
            'hosting' => [
              'type' => 'acp',
              'id' => 'devcloud2',
            ],
            'subscription' => [
              'uuid' => 'a47ac10b-58cc-4372-a567-0e02b2c3d470',
              'name' => 'Test Subscription',
            ],
            'organization' => [
              'uuid' => '8ff7c4f5-0c4f-4f44-9c67-ab3a68e3c5e1',
              'name' => 'Test Organization',
            ],
            'type' => 'drupal',
            'flags' => [
              'production_mode' => FALSE,
            ],
            'status' => 'normal',
            '_links' => [
              'self' => [
                'href' => 'https://cloud.acquia.com/api/applications/a47ac10b-58cc-4372-a567-0e02b2c3d470',
              ],
            ],
          ],
        ],
      ],
      '_links' => [
        'self' => [
          'href' => 'https://cloud.acquia.com/api/applications',
        ],
        'sort' => [
          'href' => 'https://cloud.acquia.com/api/applications{?sort}',
          'templated' => TRUE,
        ],
        'filter' => [
          'href' => 'https://cloud.acquia.com/api/applications{?filter}',
          'templated' => TRUE,
        ],
      ],
      'total' => 1,
    ];
  }

  /**
   * Returns mock environments API response.
   *
   * GET https://cloud.acquia.com/api/applications/{uuid}/environments.
   *
   * @return array
   *   Mock environments data matching Acquia API v2 structure.
   */
  protected function getMockEnvironmentsResponse(): array {
    $currentHost = $this->getCurrentHost();

    return [
      '_embedded' => [
        'items' => [
          [
            'id' => '24-a47ac10b-58cc-4372-a567-0e02b2c3d470',
            'label' => 'Development',
            'name' => 'dev',
            'application' => [
              'name' => 'testsite',
              'uuid' => 'a47ac10b-58cc-4372-a567-0e02b2c3d470',
            ],
            'configuration' => [
              'php' => [
                'version' => '8.3',
                'memory_limit' => 256,
              ],
            ],
            'domains' => [
              $currentHost,
              'testsite.dev.example.com',
              'dev.testsite.com',
              'test.local',
            ],
            'sshUrl' => 'testsite.dev@ded-123.prod.hosting.acquia.com',
            'ips' => ['192.0.2.1'],
            'region' => 'us-east-1',
            'status' => 'normal',
            'type' => 'drupal',
            'vcs' => [
              'type' => 'git',
              'path' => 'testsite',
              'url' => 'testsite@svn-1234.prod.hosting.acquia.com:testsite.git',
            ],
            'flags' => [
              'livedev' => FALSE,
              'production_mode' => FALSE,
              'vcs_path_verified' => TRUE,
            ],
            '_links' => [
              'self' => [
                'href' => 'https://cloud.acquia.com/api/environments/24-a47ac10b-58cc-4372-a567-0e02b2c3d470',
              ],
            ],
          ],
          [
            'id' => '24-a47ac10b-58cc-4372-a567-0e02b2c3d471',
            'label' => 'Staging',
            'name' => 'stage',
            'application' => [
              'name' => 'testsite',
              'uuid' => 'a47ac10b-58cc-4372-a567-0e02b2c3d470',
            ],
            'configuration' => [
              'php' => [
                'version' => '8.3',
                'memory_limit' => 384,
              ],
            ],
            'domains' => [
              'testsite.stage.example.com',
              'stage.testsite.com',
            ],
            'sshUrl' => 'testsite.stage@ded-456.prod.hosting.acquia.com',
            'ips' => ['192.0.2.2'],
            'region' => 'us-east-1',
            'status' => 'normal',
            'type' => 'drupal',
            'vcs' => [
              'type' => 'git',
              'path' => 'testsite',
              'url' => 'testsite@svn-1234.prod.hosting.acquia.com:testsite.git',
            ],
            'flags' => [
              'livedev' => FALSE,
              'production_mode' => FALSE,
              'vcs_path_verified' => TRUE,
            ],
            '_links' => [
              'self' => [
                'href' => 'https://cloud.acquia.com/api/environments/24-a47ac10b-58cc-4372-a567-0e02b2c3d471',
              ],
            ],
          ],
          [
            'id' => '24-a47ac10b-58cc-4372-a567-0e02b2c3d472',
            'label' => 'Production',
            'name' => 'prod',
            'application' => [
              'name' => 'testsite',
              'uuid' => 'a47ac10b-58cc-4372-a567-0e02b2c3d470',
            ],
            'configuration' => [
              'php' => [
                'version' => '8.3',
                'memory_limit' => 512,
              ],
            ],
            'domains' => [
              'testsite.prod.example.com',
              'www.testsite.com',
              'testsite.com',
            ],
            'sshUrl' => 'testsite.prod@ded-789.prod.hosting.acquia.com',
            'ips' => ['192.0.2.3'],
            'region' => 'us-east-1',
            'status' => 'normal',
            'type' => 'drupal',
            'vcs' => [
              'type' => 'git',
              'path' => 'testsite',
              'url' => 'testsite@svn-1234.prod.hosting.acquia.com:testsite.git',
            ],
            'flags' => [
              'livedev' => FALSE,
              'production_mode' => TRUE,
              'vcs_path_verified' => TRUE,
            ],
            '_links' => [
              'self' => [
                'href' => 'https://cloud.acquia.com/api/environments/24-a47ac10b-58cc-4372-a567-0e02b2c3d472',
              ],
            ],
          ],
        ],
      ],
      '_links' => [
        'self' => [
          'href' => 'https://cloud.acquia.com/api/applications/a47ac10b-58cc-4372-a567-0e02b2c3d470/environments',
        ],
        'parent' => [
          'href' => 'https://cloud.acquia.com/api/applications/a47ac10b-58cc-4372-a567-0e02b2c3d470',
        ],
      ],
      'total' => 3,
    ];
  }

  /**
   * Returns mock purge response.
   *
   * Simulates: POST /environments/{id}/actions/clear-caches
   * Or: POST /environments/{id}/domains/{domain}/actions/clear-caches.
   *
   * @param string $url
   *   The purge URL being called.
   * @param array $options
   *   Request options that may contain domain information.
   *
   * @return array
   *   Mock purge response data matching Acquia API v2 structure.
   */
  protected function getMockPurgeResponse(string $url, array $options): array {
    $domains = [];

    // Extract domain from URL if it's a single domain purge.
    if (preg_match('#/domains/([^/]+)/actions/clear-caches#', $url, $matches)) {
      $domains[] = $matches[1];
    }
    // Or get domains from request body for multi-domain purge.
    elseif (!empty($options['json']['domains'])) {
      $domains = $options['json']['domains'];
    }

    $domainList = !empty($domains) ? implode(', ', $domains) : 'all domains';
    $notificationId = 'mock-notification-' . uniqid();

    $this->logger->notice('MOCK: Varnish cache cleared for @domains', [
      '@domains' => $domainList,
    ]);

    return [
      'message' => sprintf(
        'The caches are being cleared for domains: %s',
        $domainList
      ),
      '_links' => [
        'self' => [
          'href' => $url,
        ],
        'notification' => [
          'href' => "https://cloud.acquia.com/api/notifications/{$notificationId}",
        ],
      ],
      '_embedded' => [
        'notification' => [
          'uuid' => $notificationId,
          'event' => 'DomainCacheClearCompleted',
          'label' => 'Clear varnish cache for domain(s): ' . $domainList,
          'description' => 'Varnish cache cleared successfully',
          'created_at' => date('c'),
          'completed_at' => date('c'),
          'status' => 'completed',
          'progress' => 100,
        ],
      ],
    ];
  }

}
