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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient $api_client
   *   The Acquia API client service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected LoggerChannelInterface $logger,
    protected RequestStack $requestStack,
    protected AcquiaPurgeVarnishApiClient $apiClient,
    MessengerInterface $messenger,
  ) {
    $this->configFactory = $config_factory;
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
   * Gets the current URL from the HTTP referer or falls back to front page.
   *
   * @return string
   *   The URL to redirect to.
   */
  protected function getCurrentUrl(): string {
    $request = $this->requestStack->getCurrentRequest();

    if ($request && $request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }

    // Use Url::fromRoute for testability instead of base_path().
    return Url::fromRoute('<front>')->toString();
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

      // Check if current domain is in this environment.
      if (in_array($currentDomain, $domains, TRUE)) {
        // Purge only current domain.
        if ($domainSetting === 'current') {
          return [
            "https://cloud.acquia.com/api/environments/{$environmentId}/domains/{$currentDomain}/actions/clear-caches",
            [],
          ];
        }

        // Purge all domains in the environment.
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
    $applicationsUrl = 'https://cloud.acquia.com/api/applications';
    $applications = $this->apiClient->makeRequest('GET', $applicationsUrl, $credentials);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Response: @response', [
        '@url' => $applicationsUrl,
        '@response' => Json::encode($applications),
      ]);
    }

    if (empty($applications['_embedded']['items'])) {
      $this->messenger->addError(
        $this->t('Failed to retrieve applications from Acquia API.')
      );
      return;
    }

    // Find the matching application UUID.
    $applicationUuid = NULL;
    foreach ($applications['_embedded']['items'] as $application) {
      if (isset($application['name'])
        && strcasecmp($application['name'], $applicationName) === 0) {
        $applicationUuid = $application['uuid'] ?? NULL;
        break;
      }
    }

    if (empty($applicationUuid)) {
      $this->messenger->addError(
        $this->t('Application @name not found in your Acquia account.', [
          '@name' => $applicationName,
        ])
      );
      return;
    }

    // Step 2: Get environments for this application.
    $environmentsUrl = "https://cloud.acquia.com/api/applications/{$applicationUuid}/environments";
    $environmentsData = $this->apiClient->makeRequest('GET', $environmentsUrl, $credentials);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Response: @response', [
        '@url' => $environmentsUrl,
        '@response' => Json::encode($environmentsData),
      ]);
    }

    if (empty($environmentsData['_embedded']['items'])) {
      $this->messenger->addError(
        $this->t('Failed to retrieve environments from Acquia API.')
      );
      return;
    }

    $environments = $environmentsData['_embedded']['items'];

    // Step 3: Find the matching environment and construct purge request.
    [$purgeUrl, $options] = $this->getUuidAndDomains($environments, $currentDomain);

    if (empty($purgeUrl)) {
      $this->messenger->addError(
        $this->t('Could not find domain @domain in any Acquia environment.', [
          '@domain' => $currentDomain,
        ])
      );
      return;
    }

    // Step 4: Execute the purge request.
    $result = $this->apiClient->makeRequest('POST', $purgeUrl, $credentials, $options);

    if ($logRequests) {
      $this->logger->info('Acquia API Request - URL: @url | Body: @body | Response: @response', [
        '@url' => $purgeUrl,
        '@body' => Json::encode($options),
        '@response' => Json::encode($result),
      ]);
    }

    if (!empty($result['message'])) {
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
   * Purges Varnish cache and redirects back to the previous page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response to the previous page.
   */
  public function purgeVarnish(): RedirectResponse {
    $this->purgeAcquiaVarnishCache();
    return new RedirectResponse($this->getCurrentUrl());
  }

}
