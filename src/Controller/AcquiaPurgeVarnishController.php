<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Controller;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for purging Varnish caches on Acquia Cloud Platform.
 */
class AcquiaPurgeVarnishController extends ControllerBase {

  /**
   * Constructs an AcquiaPurgeVarnishController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient $apiClient
   *   The Acquia API client service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected RequestStack $requestStack,
    protected AcquiaPurgeVarnishApiClient $apiClient,
    MessengerInterface $messenger,
  ) {
    $this->configFactory = $configFactory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.channel.acquia_purge_varnish'),
      $container->get('request_stack'),
      $container->get('acquia_purge_varnish.api_client'),
      $container->get('messenger')
    );
  }

  /**
   * Gets the URL to redirect to after purging.
   *
   * @return string
   *   The URL to redirect to.
   */
  protected function getRedirectUrl(): string {
    $request = $this->requestStack->getCurrentRequest();

    // Try to get referer.
    if ($request) {
      $referer = $request->server->get('HTTP_REFERER');
      // Validate referer is from the same host for security.
      if ($referer && $this->isInternalUrl($referer)) {
        return $referer;
      }
    }

    // Fallback to settings page.
    return Url::fromRoute('acquia_purge_varnish.form')
      ->setAbsolute()
      ->toString();
  }

  /**
   * Checks if a URL is internal to this site.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if internal, FALSE otherwise.
   */
  protected function isInternalUrl(string $url): bool {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return FALSE;
    }

    $current_host = $request->getSchemeAndHttpHost();
    return str_starts_with($url, $current_host);
  }

  /**
   * Gets a configuration value from settings.php or configuration.
   *
   * @param string $name
   *   The configuration key.
   *
   * @return mixed
   *   The configuration value.
   */
  protected function getConfigValue(string $name): mixed {
    // Check settings.php override first.
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');

    if (isset($acquiaCredentials[$name])) {
      return $acquiaCredentials[$name];
    }

    // Fall back to configuration.
    return $this->configFactory
      ->get(AcquiaPurgeVarnishForm::SETTINGS)
      ->get($name);
  }

  /**
   * Validates API response structure.
   *
   * @param mixed $data
   *   The response data to validate.
   * @param string $key
   *   The key to check for.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function validateResponseStructure(mixed $data, string $key = '_embedded.items'): bool {
    if (!is_array($data)) {
      return FALSE;
    }

    $keys = explode('.', $key);
    $current = $data;

    foreach ($keys as $k) {
      if (!isset($current[$k]) || !is_array($current[$k])) {
        return FALSE;
      }
      $current = $current[$k];
    }

    return TRUE;
  }

  /**
   * Fetches application UUID from Acquia API.
   *
   * @param array $credentials
   *   The API credentials [key, secret].
   * @param string $applicationName
   *   The application name to find.
   * @param bool $logRequests
   *   Whether to log API requests.
   *
   * @return string|null
   *   The application UUID or NULL on failure.
   */
  protected function fetchApplicationUuid(array $credentials, string $applicationName, bool $logRequests): ?string {
    $applicationsUrl = 'https://cloud.acquia.com/api/applications';
    $applications = $this->apiClient->makeRequest('GET', $applicationsUrl, $credentials);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Response: @response', [
        '@url' => $applicationsUrl,
        '@response' => Json::encode($applications),
      ]);
    }

    if (!$this->validateResponseStructure($applications)) {
      $this->messenger->addError(
        $this->t('Failed to retrieve applications from Acquia API.')
      );
      return NULL;
    }

    // Find the matching application UUID.
    foreach ($applications['_embedded']['items'] as $application) {
      if (isset($application['name'], $application['uuid'])
        && strcasecmp($application['name'], $applicationName) === 0) {
        return $application['uuid'];
      }
    }

    $this->messenger->addError(
      $this->t('Application @name not found in your Acquia account.', [
        '@name' => $applicationName,
      ])
    );
    return NULL;
  }

  /**
   * Fetches environments for an application.
   *
   * @param array $credentials
   *   The API credentials [key, secret].
   * @param string $applicationUuid
   *   The application UUID.
   * @param bool $logRequests
   *   Whether to log API requests.
   *
   * @return array
   *   Array of environments or empty array on failure.
   */
  protected function fetchEnvironments(array $credentials, string $applicationUuid, bool $logRequests): array {
    $environmentsUrl = "https://cloud.acquia.com/api/applications/{$applicationUuid}/environments";
    $environmentsData = $this->apiClient->makeRequest('GET', $environmentsUrl, $credentials);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Response: @response', [
        '@url' => $environmentsUrl,
        '@response' => Json::encode($environmentsData),
      ]);
    }

    if (!$this->validateResponseStructure($environmentsData)) {
      $this->messenger->addError(
        $this->t('Failed to retrieve environments from Acquia API.')
      );
      return [];
    }

    return $environmentsData['_embedded']['items'];
  }

  /**
   * Gets the UUID and constructs the purge URL and options.
   *
   * @param array $environments
   *   Array of environments with their IDs and domains.
   * @param string $currentDomain
   *   The current domain to match.
   *
   * @return array
   *   Array with [url, options] for the API request.
   */
  protected function getUuidAndDomains(array $environments, string $currentDomain): array {
    $domainSetting = $this->getConfigValue('domain');

    foreach ($environments as $environment) {
      $environmentId = $environment['id'] ?? NULL;
      $domains = $environment['domains'] ?? [];

      if (in_array($currentDomain, $domains, TRUE)) {

        // Current domain only.
        if ($domainSetting === 'current') {
          return [
            "https://cloud.acquia.com/api/environments/{$environmentId}/domains/{$currentDomain}/actions/clear-caches",
            [],
          ];
        }

        // NEW: Custom domains.
        if ($domainSetting === 'custom') {
          $customDomains = $this->getConfigValue('custom_domains') ?? [];
          // Filter to only include domains that exist in this environment.
          $validDomains = array_intersect($customDomains, $domains);

          if (empty($validDomains)) {
            // Fall back to current domain if no valid custom domains.
            return [
              "https://cloud.acquia.com/api/environments/{$environmentId}/domains/{$currentDomain}/actions/clear-caches",
              [],
            ];
          }

          return [
            "https://cloud.acquia.com/api/environments/{$environmentId}/actions/clear-caches",
            [
              'json' => [
                'domains' => array_values($validDomains),
              ],
            ],
          ];
        }

        // All domains.
        if ($domainSetting === 'all') {
          return [
            "https://cloud.acquia.com/api/environments/{$environmentId}/actions/clear-caches",
            [
              'json' => [
                'domains' => $domains,
              ],
            ],
          ];
        }
      }
    }

    return ['', []];
  }

  /**
   * Executes the Varnish purge request.
   *
   * @param array $credentials
   *   The API credentials [key, secret].
   * @param string $purgeUrl
   *   The purge API URL.
   * @param array $options
   *   The request options.
   * @param bool $logRequests
   *   Whether to log API requests.
   */
  protected function executePurgeRequest(array $credentials, string $purgeUrl, array $options, bool $logRequests): void {
    $result = $this->apiClient->makeRequest('POST', $purgeUrl, $credentials, $options);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Body: @body | Response: @response', [
        '@url' => $purgeUrl,
        '@body' => Json::encode($options),
        '@response' => Json::encode($result),
      ]);
    }

    if (isset($result['message']) && !empty($result['message'])) {
      $this->messenger->addStatus($result['message']);
      $this->logger->info('Varnish purge completed: @message', [
        '@message' => $result['message'],
      ]);
    }
    else {
      $this->messenger->addError(
        $this->t('Failed to purge Varnish cache. Check logs for details.')
      );
    }
  }

  /**
   * Purges Varnish cache for the current Acquia environment and domain.
   */
  protected function purgeAcquiaVarnishCache(): void {
    // Verify we're on Acquia Cloud Platform.
    if (!$this->apiClient->isAcquiaEnvironment()) {
      $currentHost = $this->apiClient->getCurrentHost() ?? 'Unknown host';
      $this->messenger->addError(
        $this->t('The domain @domain does not exist on Acquia Cloud Platform.', [
          '@domain' => $currentHost,
        ])
      );
      return;
    }

    $logRequests = (bool) $this->getConfigValue('log_requests');
    $currentDomain = $this->apiClient->getCurrentHost();

    if (!$currentDomain) {
      $this->messenger->addError(
        $this->t('Could not determine current domain.')
      );
      return;
    }

    $apiKey = $this->getConfigValue('api_key');
    $apiSecret = $this->getConfigValue('api_secret');
    $applicationName = $this->getConfigValue('application_name')
      ?? $this->apiClient->getAcquiaSiteGroup();

    // Validate required credentials.
    if (empty($apiKey) || empty($apiSecret) || empty($applicationName)) {
      $this->messenger->addError(
        $this->t('Please configure Acquia API credentials.')
      );
      return;
    }

    $credentials = [$apiKey, $apiSecret];

    // Step 1: Get application UUID.
    $applicationUuid = $this->fetchApplicationUuid(
      $credentials,
      $applicationName,
      $logRequests
    );
    if (!$applicationUuid) {
      return;
    }

    // Step 2: Get environments for this application.
    $environments = $this->fetchEnvironments(
      $credentials,
      $applicationUuid,
      $logRequests
    );
    if (empty($environments)) {
      return;
    }

    // Step 3: Find the matching environment and construct purge request.
    [$purgeUrl, $options] = $this->getUuidAndDomains(
      $environments,
      $currentDomain
    );

    if (empty($purgeUrl)) {
      $this->messenger->addError(
        $this->t('Could not find domain @domain in any Acquia environment.', [
          '@domain' => $currentDomain,
        ])
      );
      return;
    }

    // Step 4: Execute the purge request.
    $this->executePurgeRequest($credentials, $purgeUrl, $options, $logRequests);
  }

  /**
   * Purges Varnish cache and redirects back to the previous page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response to the previous page.
   */
  public function purgeVarnish(): RedirectResponse {
    $this->purgeAcquiaVarnishCache();
    return new RedirectResponse($this->getRedirectUrl());
  }

}
