<?php

namespace Drupal\acquia_purge_varnish\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishCredentials;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
  protected $logger;

  /**
   * Get the config and logger.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Drupal Logger.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger) {
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->getCredentials();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('acquia_purge_varnish.logger.channel.acquia_purge_varnish')
    );
  }

  /**
   * Stay on the same page.
   */
  public function getCurrentUrl() {
    $request = \Drupal::request();
    return $request->server->get('HTTP_REFERER');
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
   * @return null|string
   *   Check if the domain exist in acquia.
   */
  private function getUuidAndDomains(array $myEnvsIdsDomains, int $myEnvCount, string $currentDomain) {
    $envIdsDos = [];
    for ($i = 0; $i < $myEnvCount; $i++) {
      foreach ($myEnvsIdsDomains[$i] as $key => $myEnvIdDomain) {
        if ($key === "id") {
          $environmentId = $myEnvIdDomain;
          $envIdsDos[$i] = ['id' => $environmentId];
        }
        elseif ($key === "domains") {
          $domains = implode(' | ', $myEnvIdDomain);
          $envIdsDos[$i] += ["domains" => $domains];
          // One of Acquia's domain.
          if (preg_match("/{$currentDomain}/i", $domains)) {
            return "https://cloud.acquia.com/api/environments/" . $envIdsDos[$i]['id'] . "/domains/{$currentDomain}/actions/clear-varnish";
          }
        }
      }
      continue;
    }
    unset($envIdsDos);
    return '';
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
      $currentDomain = $_ENV['HTTP_HOST'];
      $apiKey = $this->getValueFromSettingsOrConfig('api_key');
      $apiSecret = $this->getValueFromSettingsOrConfig('api_secret');

      if ($apiKey != NULL && $apiSecret != NULL) {
        $getAppUrl = "https://cloud.acquia.com/api/applications";
        $getCredentials = [$apiKey, $apiSecret];

        // Get the application UUID, make a request to GET /api/applications.
        $getAppUuid = AcquiaPurgeVarnishCredentials::getResponse('GET', $getAppUrl, $getCredentials);
        $myAppUuid = $getAppUuid["_embedded"]["items"][0]["uuid"];
        // Make a request to GET /api/applications/[uuid]/environments.
        $getEnvsUrl = "https://cloud.acquia.com/api/applications/{$myAppUuid}/environments";

        $myEnvs = AcquiaPurgeVarnishCredentials::getResponse('GET', $getEnvsUrl, $getCredentials);
        // Count how many environment you have.
        $myEnvCount = count($myEnvs["_embedded"]["items"]);
        // Store each environment UUID plus the domains.  Each environment will
        // have one unique uuid and one or more domains.
        $myEnvsIdsDomains = $myEnvs["_embedded"]["items"];
        // Loop in $myEnvsIdsDomains to store the uuid and domains.
        // Last function will be called only if we find a domain.
        $clearVarnishApi = $this->getUuidAndDomains($myEnvsIdsDomains, $myEnvCount, $currentDomain);
        if ($clearVarnishApi !== '') {
          // Message from varnish.
          $output = AcquiaPurgeVarnishCredentials::getResponse('POST', $clearVarnishApi, $getCredentials);
          $this->messenger()->addMessage($this->t('@message', ['@message' => $output['message']]));
          $this->logger->info($output['message']);
        }
        unset($clearVarnishApi);
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
    return $valueFromSettings[$name] ?: $valueFromConfig->get($name);
  }

}
