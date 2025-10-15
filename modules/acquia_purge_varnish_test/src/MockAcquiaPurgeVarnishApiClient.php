<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish_test;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;

/**
 * Mock API client for local testing without Acquia environment.
 *
 * This service simulates Acquia Cloud Platform API responses and environment
 * variables for local development and testing.
 */
class MockAcquiaPurgeVarnishApiClient extends AcquiaPurgeVarnishApiClient {

  /**
   * {@inheritdoc}
   */
  public function makeRequest(string $method, string $url, array $credentials, array $options = []): ?array {
    // Log the mock request.
    $this->logger->info('MOCK API Request - Method: @method | URL: @url', [
      '@method' => $method,
      '@url' => $url,
    ]);

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
    return [
      'message' => 'Mock API endpoint not implemented',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAcquiaEnvironment(): ?string {
    // Simulate Acquia environment.
    return 'dev';
  }

  /**
   * {@inheritdoc}
   */
  public function getAcquiaSiteGroup(): ?string {
    // Simulate Acquia site group.
    return 'testsite';
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentHost(): ?string {
    // Use actual host or fallback to mock.
    $host = parent::getCurrentHost();
    return $host ?? 'testsite.local';
  }

  /**
   * {@inheritdoc}
   */
  public function isAcquiaEnvironment(): bool {
    // Always return TRUE in test mode.
    return TRUE;
  }

  /**
   * Returns mock applications API response.
   *
   * @return array
   *   Mock applications data.
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
      ],
    ];
  }

  /**
   * Returns mock environments API response.
   *
   * @return array
   *   Mock environments data.
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
            ],
            'sshUrl' => 'testsite.prod@ded-456.prod.hosting.acquia.com',
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
              'production_mode' => TRUE,
              'vcs_path_verified' => TRUE,
            ],
            '_links' => [
              'self' => [
                'href' => 'https://cloud.acquia.com/api/environments/24-a47ac10b-58cc-4372-a567-0e02b2c3d471',
              ],
            ],
          ],
        ],
      ],
      '_links' => [
        'self' => [
          'href' => 'https://cloud.acquia.com/api/applications/a47ac10b-58cc-4372-a567-0e02b2c3d470/environments',
        ],
      ],
    ];
  }

  /**
   * Returns mock purge response.
   *
   * @param string $url
   *   The purge URL being called.
   * @param array $options
   *   Request options that may contain domain information.
   *
   * @return array
   *   Mock purge response data.
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

    $this->logger->notice('MOCK: Varnish cache cleared for @domains', [
      '@domains' => $domainList,
    ]);

    return [
      'message' => sprintf(
        'Mock Varnish cache purge completed successfully for: %s',
        $domainList
      ),
      'notification_uuid' => 'mock-notification-' . uniqid(),
      '_links' => [
        'self' => [
          'href' => $url,
        ],
        'notification' => [
          'href' => 'https://cloud.acquia.com/api/notifications/mock-notification-' . uniqid(),
        ],
      ],
    ];
  }

}
