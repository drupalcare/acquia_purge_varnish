<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Commands;

use Symfony\Component\Yaml\Yaml;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Acquia Purge Varnish module.
 */
final class AcquiaPurgeVarnishCommands extends DrushCommands {

  /**
   * Constructs an AcquiaPurgeVarnishCommands object.
   *
   * @param \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient $apiClient
   *   The Acquia API client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AcquiaPurgeVarnishApiClient $apiClient,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('acquia_purge_varnish.api_client'),
      $container->get('config.factory')
    );
  }

  /**
   * Purges Varnish cache for the current Acquia environment.
   *
   * @param array $options
   *   Command options.
   *
   * @command acquia-purge-varnish:purge
   * @aliases apv-purge,apv:purge
   * @option domain Specify which domain(s) to purge: 'all' or 'current'. Defaults to config setting.
   * @usage acquia-purge-varnish:purge
   *   Purge Varnish cache using configured domain setting.
   * @usage acquia-purge-varnish:purge --domain=all
   *   Purge Varnish cache for all domains in the environment.
   * @usage acquia-purge-varnish:purge --domain=current
   *   Purge Varnish cache for the current domain only.
   */
  #[CLI\Command(name: 'acquia-purge-varnish:purge', aliases: ['apv-purge', 'apv:purge'])]
  #[CLI\Option(name: 'domain', description: 'Which domain(s) to purge: all or current')]
  #[CLI\Usage(name: 'acquia-purge-varnish:purge', description: 'Purge Varnish cache using configured domain setting')]
  #[CLI\Usage(name: 'acquia-purge-varnish:purge --domain=all', description: 'Purge all domains')]
  public function purge(array $options = ['domain' => NULL]): void {
    // Check if we're on Acquia.
    if (!$this->apiClient->isAcquiaEnvironment()) {
      $this->logger()->error('This command can only be run on Acquia Cloud Platform.');
      return;
    }

    $this->logger()->notice('Starting Varnish cache purge...');

    // Get credentials.
    $credentials = $this->getCredentials();
    if (!$credentials) {
      $this->logger()->error('API credentials are not configured. Please configure them at /admin/config/acquia-purge/settings');
      return;
    }

    [$apiKey, $apiSecret, $applicationName] = $credentials;
    $currentDomain = $this->apiClient->getCurrentHost();

    if (!$currentDomain) {
      $this->logger()->error('Could not determine current domain.');
      return;
    }

    // Determine domain setting.
    $domainSetting = $options['domain'] ?? $this->getAcquiaConfigValue('domain') ?? 'current';
    if (!in_array($domainSetting, ['all', 'current'], TRUE)) {
      $this->logger()->error('Invalid domain option. Use "all" or "current".');
      return;
    }

    $this->logger()->info(dt('Domain setting: @setting', ['@setting' => $domainSetting]));
    $this->logger()->info(dt('Current domain: @domain', ['@domain' => $currentDomain]));
    $this->logger()->info(dt('Application: @app', ['@app' => $applicationName]));

    // Step 1: Get application UUID.
    $this->logger()->info('Fetching application information...');
    $applicationsUrl = 'https://cloud.acquia.com/api/applications';
    $applications = $this->apiClient->makeRequest('GET', $applicationsUrl, [$apiKey, $apiSecret]);

    if (empty($applications['_embedded']['items'])) {
      $this->logger()->error('Failed to retrieve applications from Acquia API.');
      return;
    }

    // Find matching application.
    $applicationUuid = NULL;
    foreach ($applications['_embedded']['items'] as $application) {
      if (isset($application['name']) && strcasecmp($application['name'], $applicationName) === 0) {
        $applicationUuid = $application['uuid'] ?? NULL;
        break;
      }
    }

    if (empty($applicationUuid)) {
      $this->logger()->error(dt('Application "@name" not found in your Acquia account.', ['@name' => $applicationName]));
      return;
    }

    $this->logger()->success(dt('Found application: @uuid', ['@uuid' => $applicationUuid]));

    // Step 2: Get environments.
    $this->logger()->info('Fetching environment information...');
    $environmentsUrl = "https://cloud.acquia.com/api/applications/{$applicationUuid}/environments";
    $environmentsData = $this->apiClient->makeRequest('GET', $environmentsUrl, [$apiKey, $apiSecret]);

    if (empty($environmentsData['_embedded']['items'])) {
      $this->logger()->error('Failed to retrieve environments from Acquia API.');
      return;
    }

    $environments = $environmentsData['_embedded']['items'];
    $this->logger()->success(dt('Found @count environment(s)', ['@count' => count($environments)]));

    // Step 3: Find matching environment and build purge request.
    [$purgeUrl, $purgeOptions, $targetDomains] = $this->buildPurgeRequest(
      $environments,
      $currentDomain,
      $domainSetting
    );

    if (empty($purgeUrl)) {
      $this->logger()->error(dt('Could not find domain "@domain" in any Acquia environment.', ['@domain' => $currentDomain]));
      return;
    }

    // Display what will be purged.
    if ($domainSetting === 'all') {
      $this->logger()->notice(dt('Purging @count domain(s): @domains', [
        '@count' => count($targetDomains),
        '@domains' => implode(', ', $targetDomains),
      ]));
    }
    else {
      $this->logger()->notice(dt('Purging domain: @domain', ['@domain' => $currentDomain]));
    }

    // Step 4: Execute purge.
    $result = $this->apiClient->makeRequest('POST', $purgeUrl, [$apiKey, $apiSecret], $purgeOptions);

    if (!empty($result['message'])) {
      $this->logger()->success($result['message']);

      // Log task information if available.
      if (!empty($result['_links']['notification']['href'])) {
        $this->logger()->info(dt('Task notification: @url', ['@url' => $result['_links']['notification']['href']]));
      }
    }
    else {
      $this->logger()->error('Failed to purge Varnish cache. No response message received.');
    }
  }

  /**
   * Checks the status of Acquia Cloud Platform environment.
   *
   * @command acquia-purge-varnish:status
   * @aliases apv-status,apv:status
   * @usage acquia-purge-varnish:status
   *   Display current Acquia environment status and configuration.
   */
  #[CLI\Command(name: 'acquia-purge-varnish:status', aliases: ['apv-status', 'apv:status'])]
  #[CLI\Usage(name: 'acquia-purge-varnish:status', description: 'Display Acquia environment status')]
  public function status(): void {
    $this->logger()->notice('=== Acquia Purge Varnish Status ===');
    $this->io()->newLine();

    // Check if on Acquia.
    $isAcquia = $this->apiClient->isAcquiaEnvironment();
    $environment = $this->apiClient->getAcquiaEnvironment();
    $siteGroup = $this->apiClient->getAcquiaSiteGroup();
    $currentHost = $this->apiClient->getCurrentHost();

    $this->io()->definitionList([
      'On Acquia Cloud' => $isAcquia ? '✓ Yes' : '✗ No',
      'Environment' => $environment ?? 'N/A',
      'Site Group' => $siteGroup ?? 'N/A',
      'Current Host' => $currentHost ?? 'N/A',
    ]);

    $this->io()->newLine();

    // Check configuration.
    $config = $this->configFactory->get(AcquiaPurgeVarnishForm::SETTINGS);
    $settingsOverride = Settings::get('acquia_purge_varnish_credentials');

    $this->logger()->notice('=== Configuration ===');
    $this->io()->newLine();

    $apiKeySet = !empty($this->getAcquiaConfigValue('api_key'));
    $apiSecretSet = !empty($this->getAcquiaConfigValue('api_secret'));
    $appName = $this->getAcquiaConfigValue('application_name');
    $domainSetting = $this->getAcquiaConfigValue('domain') ?? 'current';
    $logRequests = $this->getAcquiaConfigValue('log_requests') ?? FALSE;

    $this->io()->definitionList([
      'API Key' => $apiKeySet ? '✓ Configured' : '✗ Not configured',
      'API Secret' => $apiSecretSet ? '✓ Configured' : '✗ Not configured',
      'Application Name' => $appName ?: '(auto-detect from AH_SITE_GROUP)',
      'Domain Setting' => $domainSetting,
      'Log Requests' => $logRequests ? 'Enabled' : 'Disabled',
      'Settings Override' => !empty($settingsOverride) ? '✓ Yes (in settings.php)' : '✗ No',
    ]);

    $this->io()->newLine();

    // Provide recommendations.
    if (!$isAcquia) {
      $this->logger()->warning('This site is not running on Acquia Cloud Platform.');
    }

    if (!$apiKeySet || !$apiSecretSet) {
      $this->logger()->warning('API credentials are not configured. Configure them at:');
      $this->logger()->warning('  drush config:set acquia_purge_varnish.settings api_key "YOUR_KEY"');
      $this->logger()->warning('  drush config:set acquia_purge_varnish.settings api_secret "YOUR_SECRET"');
    }
  }

  /**
   * Lists all available domains in the current Acquia environment.
   *
   * @param array $options
   *   Command options.
   *
   * @command acquia-purge-varnish:list-domains
   * @aliases apv-domains,apv:domains
   * @option format Output format: table, json, yaml. Default is table.
   * @usage acquia-purge-varnish:list-domains
   *   List all domains in the current environment.
   * @usage acquia-purge-varnish:list-domains --format=json
   *   List domains in JSON format.
   */
  #[CLI\Command(name: 'acquia-purge-varnish:list-domains', aliases: ['apv-domains', 'apv:domains'])]
  #[CLI\Option(name: 'format', description: 'Output format: table, json, yaml')]
  #[CLI\Usage(name: 'acquia-purge-varnish:list-domains', description: 'List all domains')]
  public function listDomains(array $options = ['format' => 'table']): void {
    if (!$this->apiClient->isAcquiaEnvironment()) {
      $this->logger()->error('This command can only be run on Acquia Cloud Platform.');
      return;
    }

    $credentials = $this->getCredentials();
    if (!$credentials) {
      $this->logger()->error('API credentials are not configured.');
      return;
    }

    [$apiKey, $apiSecret, $applicationName] = $credentials;

    // Get application UUID.
    $applicationsUrl = 'https://cloud.acquia.com/api/applications';
    $applications = $this->apiClient->makeRequest('GET', $applicationsUrl, [$apiKey, $apiSecret]);

    if (empty($applications['_embedded']['items'])) {
      $this->logger()->error('Failed to retrieve applications from Acquia API.');
      return;
    }

    $applicationUuid = NULL;
    foreach ($applications['_embedded']['items'] as $application) {
      if (isset($application['name']) && strcasecmp($application['name'], $applicationName) === 0) {
        $applicationUuid = $application['uuid'] ?? NULL;
        break;
      }
    }

    if (empty($applicationUuid)) {
      $this->logger()->error(dt('Application "@name" not found.', ['@name' => $applicationName]));
      return;
    }

    // Get environments.
    $environmentsUrl = "https://cloud.acquia.com/api/applications/{$applicationUuid}/environments";
    $environmentsData = $this->apiClient->makeRequest('GET', $environmentsUrl, [$apiKey, $apiSecret]);

    if (empty($environmentsData['_embedded']['items'])) {
      $this->logger()->error('Failed to retrieve environments.');
      return;
    }

    $currentEnv = $this->apiClient->getAcquiaEnvironment();
    $currentHost = $this->apiClient->getCurrentHost();
    $rows = [];

    foreach ($environmentsData['_embedded']['items'] as $environment) {
      $envName = $environment['name'] ?? 'Unknown';
      $envId = $environment['id'] ?? 'Unknown';
      $domains = $environment['domains'] ?? [];

      foreach ($domains as $domain) {
        $isCurrent = ($envName === $currentEnv && $domain === $currentHost) ? '✓' : '';
        $rows[] = [
          'environment' => $envName,
          'id' => $envId,
          'domain' => $domain,
          'current' => $isCurrent,
        ];
      }
    }

    // Output based on format.
    $format = $options['format'] ?? 'table';

    switch ($format) {
      case 'json':
        $this->io()->write(Json::encode($rows));
        break;

      case 'yaml':
        $this->io()->write(Yaml::dump($rows));
        break;

      case 'table':
      default:
        if (empty($rows)) {
          $this->logger()->warning('No domains found.');
          return;
        }

        $this->io()->table(
          ['Environment', 'Environment ID', 'Domain', 'Current'],
          array_map(fn($row) => [
            $row['environment'],
            $row['id'],
            $row['domain'],
            $row['current'],
          ], $rows)
        );

        $this->io()->newLine();
        $this->logger()->success(dt('Found @count domain(s)', ['@count' => count($rows)]));
        break;
    }
  }

  /**
   * Configure Acquia Purge Varnish settings.
   *
   * @param array $options
   *   Command options.
   *
   * @command acquia-purge-varnish:config
   * @aliases apv-config,apv:config
   * @option api-key Acquia API Key
   * @option api-secret Acquia API Secret
   * @option application-name Acquia Application Name
   * @option domain Domain setting: all or current
   * @option log-requests Enable request logging (0 or 1)
   * @usage acquia-purge-varnish:config --api-key="YOUR_KEY" --api-secret="YOUR_SECRET"
   *   Configure API credentials.
   * @usage acquia-purge-varnish:config --domain=all
   *   Set domain preference to purge all domains.
   * @usage acquia-purge-varnish:config --log-requests=1
   *   Enable request logging.
   */
  #[CLI\Command(name: 'acquia-purge-varnish:config', aliases: ['apv-config', 'apv:config'])]
  #[CLI\Option(name: 'api-key', description: 'Acquia API Key')]
  #[CLI\Option(name: 'api-secret', description: 'Acquia API Secret')]
  #[CLI\Option(name: 'application-name', description: 'Acquia Application Name')]
  #[CLI\Option(name: 'domain', description: 'Domain setting: all or current')]
  #[CLI\Option(name: 'log-requests', description: 'Enable request logging (0 or 1)')]
  #[CLI\Usage(name: 'acquia-purge-varnish:config --api-key="KEY"', description: 'Set API key')]
  public function configureSettings(
    array $options = [
      'api-key' => NULL,
      'api-secret' => NULL,
      'application-name' => NULL,
      'domain' => NULL,
      'log-requests' => NULL,
    ],
  ): void {
    $config = $this->configFactory->getEditable(AcquiaPurgeVarnishForm::SETTINGS);
    $changed = FALSE;

    if ($options['api-key'] !== NULL) {
      $config->set('api_key', $options['api-key']);
      $this->logger()->success('API Key configured');
      $changed = TRUE;
    }

    if ($options['api-secret'] !== NULL) {
      $config->set('api_secret', $options['api-secret']);
      $this->logger()->success('API Secret configured');
      $changed = TRUE;
    }

    if ($options['application-name'] !== NULL) {
      $config->set('application_name', $options['application-name']);
      $this->logger()->success(dt('Application Name set to: @name', ['@name' => $options['application-name']]));
      $changed = TRUE;
    }

    if ($options['domain'] !== NULL) {
      if (!in_array($options['domain'], ['all', 'current'], TRUE)) {
        $this->logger()->error('Invalid domain option. Use "all" or "current".');
        return;
      }
      $config->set('domain', $options['domain']);
      $this->logger()->success(dt('Domain setting set to: @domain', ['@domain' => $options['domain']]));
      $changed = TRUE;
    }

    if ($options['log-requests'] !== NULL) {
      $logValue = (bool) $options['log-requests'];
      $config->set('log_requests', $logValue);
      $this->logger()->success(dt('Request logging @status', [
        '@status' => $logValue ? 'enabled' : 'disabled',
      ]));
      $changed = TRUE;
    }

    if ($changed) {
      $config->save();
      $this->logger()->success('Configuration saved successfully');
    }
    else {
      $this->logger()->warning('No configuration changes specified. Use --help for options.');
    }
  }

  /**
   * Tests Acquia API credentials.
   *
   * @command acquia-purge-varnish:test-credentials
   * @aliases apv-test,apv:test
   * @usage acquia-purge-varnish:test-credentials
   *   Test if the configured API credentials are valid.
   */
  #[CLI\Command(name: 'acquia-purge-varnish:test-credentials', aliases: ['apv-test', 'apv:test'])]
  #[CLI\Usage(name: 'acquia-purge-varnish:test-credentials', description: 'Test API credentials')]
  public function testCredentials(): void {
    $this->logger()->notice('Testing Acquia API credentials...');
    $this->io()->newLine();

    $credentials = $this->getCredentials();
    if (!$credentials) {
      $this->logger()->error('API credentials are not configured.');
      return;
    }

    [$apiKey, $apiSecret, $applicationName] = $credentials;

    $this->logger()->info(dt('Using application name: @name', ['@name' => $applicationName]));
    $this->io()->newLine();

    // Test API connectivity.
    $applicationsUrl = 'https://cloud.acquia.com/api/applications';
    $applications = $this->apiClient->makeRequest('GET', $applicationsUrl, [$apiKey, $apiSecret]);

    if (empty($applications)) {
      $this->logger()->error('Failed to connect to Acquia API. Check your credentials and network connection.');
      return;
    }

    if (isset($applications['error'])) {
      $this->logger()->error(dt('API Error: @error', ['@error' => $applications['message'] ?? 'Unknown error']));
      return;
    }

    if (empty($applications['_embedded']['items'])) {
      $this->logger()->error('No applications found in your Acquia account.');
      return;
    }

    $this->logger()->success('✓ Successfully connected to Acquia API');
    $this->logger()->success(dt('✓ Found @count application(s) in your account', [
      '@count' => count($applications['_embedded']['items']),
    ]));

    // Find matching application.
    $found = FALSE;
    foreach ($applications['_embedded']['items'] as $application) {
      if (isset($application['name']) && strcasecmp($application['name'], $applicationName) === 0) {
        $found = TRUE;
        $this->logger()->success(dt('✓ Found application: @name (UUID: @uuid)', [
          '@name' => $application['name'],
          '@uuid' => $application['uuid'],
        ]));
        break;
      }
    }

    if (!$found) {
      $this->logger()->error(dt('✗ Application "@name" not found in your account.', ['@name' => $applicationName]));
      $this->logger()->notice('Available applications:');
      foreach ($applications['_embedded']['items'] as $application) {
        $this->logger()->notice('  - ' . ($application['name'] ?? 'Unknown'));
      }
      return;
    }

    $this->io()->newLine();
    $this->logger()->success('All tests passed! Your credentials are valid.');
  }

  /**
   * Gets API credentials from settings or config.
   *
   * @return array|null
   *   Array of [api_key, api_secret, application_name] or NULL if not configured.
   */
  protected function getCredentials(): ?array {
    $apiKey = $this->getAcquiaConfigValue('api_key');
    $apiSecret = $this->getAcquiaConfigValue('api_secret');
    $applicationName = $this->getAcquiaConfigValue('application_name') ?? $this->apiClient->getAcquiaSiteGroup();

    if (empty($apiKey) || empty($apiSecret) || empty($applicationName)) {
      return NULL;
    }

    return [$apiKey, $apiSecret, $applicationName];
  }

  /**
   * Gets a configuration value from settings.php or config.
   *
   * Renamed from getConfigValue to avoid conflict with DrushCommands parent.
   *
   * @param string $name
   *   The configuration key.
   *
   * @return mixed
   *   The configuration value.
   */
  protected function getAcquiaConfigValue(string $name): mixed {
    // Check settings.php override first.
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');
    if (isset($acquiaCredentials[$name])) {
      return $acquiaCredentials[$name];
    }

    // Fall back to configuration.
    return $this->configFactory->get(AcquiaPurgeVarnishForm::SETTINGS)->get($name);
  }

  /**
   * Builds the purge request URL and options.
   *
   * @param array $environments
   *   Array of environments from Acquia API.
   * @param string $currentDomain
   *   The current domain.
   * @param string $domainSetting
   *   The domain setting ('all' or 'current').
   *
   * @return array
   *   Array containing [url, options, target_domains].
   */
  protected function buildPurgeRequest(array $environments, string $currentDomain, string $domainSetting): array {
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
            [$currentDomain],
          ];
        }

        // Purge all domains.
        if ($domainSetting === 'all') {
          return [
            "https://cloud.acquia.com/api/environments/{$environmentId}/actions/clear-caches",
            [
              'json' => [
                'domains' => $domains,
              ],
            ],
            $domains,
          ];
        }
      }
    }

    return ['', [], []];
  }

}
