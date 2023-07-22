<?php

namespace Drupal\acquia_purge_varnish\Controller;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishCredentials;
use Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for acquia_purge_varnish module routes.
 */
class AcquiaPurgeVarnishController extends ControllerBase {
  /**
   * Store the settings var.
   *
   * @var AcquiaPurgeVarnishController
   */
  private $acquiaPurgeSettings = [];

  /**
   * Get the config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * A request stack symfony instance.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Get the config and logger.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Drupal Logger.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   A request stack symfony instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->requestStack = $request_stack;
    $this->getCredentials();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('acquia_purge_varnish.logger.channel.acquia_purge_varnish'),
      $container->get('request_stack')
    );
  }

  /**
   * Stay on the same page.
   */
  public function getCurrentUrl() {
    $request = $this->requestStack->getCurrentRequest();
    if ($request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }

    return base_path();
  }

  /**
   * Get the credentials.
   */
  public function getCredentials() {
    // Get credentials from settings.
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');
    // Store credentials.
    if (!empty($acquiaCredentials)) {
      $this->acquiaPurgeSettings = $acquiaCredentials;
      return $acquiaCredentials;
    }
    return NULL;
  }

  /**
   * Get UUID for each domain.
   *
   * @param array $myEnvsIdsDomains
   *   Environment IDs.
   * @param int $myEnvCount
   *   Count Environment.
   * @param string $currentDomain
   *   Look for current environment domain.
   *
   * @return array
   *   Return array with Url and Form Params.
   */
  private function getUuidAndDomains(array $myEnvsIdsDomains, int $myEnvCount, string $currentDomain) {
    $domain = $this->getValueFromSettingsOrConfig('domain');
    $envIdsDos = [];
    for ($i = 0; $i < $myEnvCount; $i++) {
      foreach ($myEnvsIdsDomains[$i] as $key => $myEnvIdDomain) {
        if ($key === "id") {
          $environmentId = $myEnvIdDomain;
          $envIdsDos[$i]['id'] = $environmentId;
        }
        if ($key === "name") {
          $environmentName = $myEnvIdDomain;
          $envIdsDos[$i]['name'] = $environmentName;
        }
        elseif ($key === "domains") {
          // One of Acquia's current environment domain.
          if (in_array($currentDomain, $myEnvIdDomain, TRUE)) {
            if ($domain === 'current') {
              return ["https://cloud.acquia.com/api/environments/"
                . $envIdsDos[$i]['id']
                . "/domains/{$currentDomain}/actions/clear-caches", [],
              ];
            }
            if ($domain === 'all') {
              return ["https://cloud.acquia.com/api/environments/" . $envIdsDos[$i]['id'] . "/actions/clear-caches", [
                'json' => [
                  'domains' => $myEnvIdDomain,
                ],
              ],
              ];
            }
          }
        }
      }
    }
    unset($envIdsDos);
    return ['', []];
  }

  /**
   * Function to clear acquia varnish cache.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function acquiaEnvironmentDomain(): void {
    // Three api calls we will have to do to get the required arguments.
    // GET  https://cloud.acquia.com/api/applications To get the [uuid]
    // GET  https://cloud.acquia.com/api/applications/[uuid]/environments To
    // get list of the {environmentId} and {domain}.
    // POST https://cloud.acquia.com/api/environments/{environmentId}/domains/
    // {domain}/actions/clear-varnish To clear varnish caches.
    // before anything we check if the module on Acquia.
    if (isset($_ENV['AH_SITE_ENVIRONMENT'])) {
      $log_requests = $this->getValueFromSettingsOrConfig('log_requests');
      $currentDomain = $_ENV['HTTP_HOST'];
      $apiKey = $this->getValueFromSettingsOrConfig('api_key');
      $apiSecret = $this->getValueFromSettingsOrConfig('api_secret');
      $applicationName = $this->getValueFromSettingsOrConfig('application_name') ?? $_ENV['AH_SITE_GROUP'];

      if (!empty($apiKey) && !empty($apiSecret) && !empty($applicationName)) {
        $getAppUrl = "https://cloud.acquia.com/api/applications";
        $getCredentials = [$apiKey, $apiSecret];

        // Get the application UUID, make a request to GET /api/applications.
        $getAppUuid = AcquiaPurgeVarnishCredentials::getResponse('GET', $getAppUrl, $getCredentials);
        if ($log_requests) {
          $this->logger->info($this->t(
            'URL: @url :: Response: @response',
            [
              '@url' => $getAppUrl,
              '@response' => Json::encode($getAppUuid),
            ]
          ));
        }
        foreach ($getAppUuid["_embedded"]["items"] as $getAppUuid_item_key => $getAppUuid_item) {
          if (isset($getAppUuid_item['name']) && strtolower($getAppUuid_item['name']) == strtolower($applicationName)) {
            $myAppUuid = $getAppUuid_item["uuid"];
          }
        }
        if (!empty($myAppUuid)) {
          // Make a request to GET /api/applications/[uuid]/environments.
          $getEnvsUrl = "https://cloud.acquia.com/api/applications/{$myAppUuid}/environments";

          $myEnvs = AcquiaPurgeVarnishCredentials::getResponse('GET', $getEnvsUrl, $getCredentials);
          if ($log_requests) {
            $this->logger->info($this->t(
              'URL: @url :: Response: @response',
              [
                '@url' => $getEnvsUrl,
                '@response' => Json::encode($myEnvs),
              ]
            ));
          }
          // Count how many environment you have.
          $myEnvCount = count($myEnvs["_embedded"]["items"]);
          // Store each environment UUID plus the domains. Each environment will
          // have one unique uuid and one or more domains.
          $myEnvsIdsDomains = $myEnvs["_embedded"]["items"];
          // Loop in $myEnvsIdsDomains to store the uuid and domains.
          // Last function will be called only if we find a domain.
          [$clearVarnishApi, $options] = $this->getUuidAndDomains($myEnvsIdsDomains, $myEnvCount, $currentDomain);
          if ($clearVarnishApi !== '') {
            // Message from varnish.
            $output = AcquiaPurgeVarnishCredentials::getResponse('POST', $clearVarnishApi, $getCredentials, $options);
            if ($log_requests) {
              $this->logger->info($this->t(
                'URL: @url :: Body: @body :: Response: @response',
                [
                  '@url' => $clearVarnishApi,
                  '@body' => Json::encode($options),
                  '@response' => Json::encode($output),
                ]
              ));
            }
            $this->messenger()->addMessage($this->t('@message', ['@message' => $output['message']]));
            $this->logger->info($output['message']);
          }
          unset($clearVarnishApi);
        }
      }
      else {
        $this->messenger()->addError($this->t('Please insert Acquia credentials.'));
      }

    }
    else {
      $this->messenger()->addError($this->t('The @message does not exist on Acquia cloud', ['@message' => $_SERVER['HTTP_HOST']]));
    }

  }

  /**
   * Clear caches for this node, then redirects to the previous page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the previous url.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function purgeVarnish(): RedirectResponse {

    $this->acquiaEnvironmentDomain();

    return new RedirectResponse($this->getCurrentUrl());

  }

  /**
   * Check if config variable is overridden by the settings.php.
   *
   * @param string $name
   *   Check the value.
   *
   * @return array|bool|mixed|null
   *   Return the value either from settings or config.
   */
  protected function getValueFromSettingsOrConfig(string $name) {
    $valueFromSettings = $this->getCredentials();
    $valueFromConfig   = $this->configFactory->get(AcquiaPurgeVarnishForm::SETTINGS);
    // Null Coalescing Operator.
    return $valueFromSettings[$name] ?? $valueFromConfig->get($name);
  }

}
