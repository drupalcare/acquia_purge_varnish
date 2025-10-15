<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Form;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Acquia Purge Varnish settings.
 */
class AcquiaPurgeVarnishForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  public const SETTINGS = 'acquia_purge_varnish.settings';

  /**
   * Constructs an AcquiaPurgeVarnishForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient $apiClient
   *   The Acquia API client service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected LoggerChannelInterface $logger,
    protected AcquiaPurgeVarnishApiClient $apiClient,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('logger.channel.acquia_purge_varnish'),
      $container->get('acquia_purge_varnish.api_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'acquia_purge_varnish_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);

    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Acquia API Credentials'),
      '#description' => $this->t('Enter your Acquia Cloud Platform API credentials. You can generate these from your <a href="@url" target="_blank">Acquia Cloud account</a>.', [
        '@url' => 'https://cloud.acquia.com/a/profile/tokens',
      ]),
    ];

    // API Key field.
    if (!$this->isOverridden('api_key')) {
      $form['credentials']['api_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API Key'),
        '#default_value' => $config->get('api_key') ?? '',
        '#size' => 60,
        '#maxlength' => 128,
        '#required' => TRUE,
        '#attributes' => [
          'placeholder' => $this->t('Enter your API Key'),
        ],
        '#description' => $this->t('Your Acquia Cloud Platform API Key.'),
      ];
    }
    else {
      $form['credentials']['api_key'] = [
        '#type' => 'item',
        '#title' => $this->t('API Key'),
        '#markup' => $this->t('API Key is currently being overridden in <code>settings.php</code>.'),
      ];
    }

    // API Secret field.
    if (!$this->isOverridden('api_secret')) {
      $form['credentials']['api_secret'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API Secret'),
        '#default_value' => $config->get('api_secret') ?? '',
        '#size' => 60,
        '#maxlength' => 128,
        '#required' => TRUE,
        '#attributes' => [
          'placeholder' => $this->t('Enter your API Secret'),
        ],
        '#description' => $this->t('Your Acquia Cloud Platform API Secret.'),
      ];
    }
    else {
      $form['credentials']['api_secret'] = [
        '#type' => 'item',
        '#title' => $this->t('API Secret'),
        '#markup' => $this->t('API Secret is currently being overridden in <code>settings.php</code>.'),
      ];
    }

    // Application Name field.
    if (!$this->isOverridden('application_name')) {
      $form['credentials']['application_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Application Name'),
        '#default_value' => $config->get('application_name') ?? '',
        '#size' => 60,
        '#maxlength' => 128,
        '#required' => FALSE,
        '#attributes' => [
          'placeholder' => $this->t('Leave empty to auto-detect'),
        ],
        '#description' => $this->t('Your Acquia Cloud application name. Leave empty to auto-detect from the <code>AH_SITE_GROUP</code> environment variable. See <a href="@url" target="_blank">Acquia documentation</a> for more information.', [
          '@url' => 'https://docs.acquia.com/cloud-platform/manage/',
        ]),
      ];
    }
    else {
      $form['credentials']['application_name'] = [
        '#type' => 'item',
        '#title' => $this->t('Application Name'),
        '#markup' => $this->t('Application Name is currently being overridden in <code>settings.php</code>.'),
      ];
    }

    $form['purge_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Purge Settings'),
    ];

    $environment = $this->apiClient->getAcquiaEnvironment() ?? 'dev';

    $form['purge_settings']['domain'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which domain(s) from @env should be purged?', [
        '@env' => $environment,
      ]),
      '#description' => $this->t('Choose whether to purge all domains or only the current domain.<br><strong>Note:</strong> If you use different domains for admin and public areas, select "All domains".<br><strong>Note:</strong> Verify domains in your Acquia Cloud Domain Management.'),
      '#options' => [
        'all' => $this->t('All domains'),
        'current' => $this->t('Current domain only'),
      ],
      '#default_value' => $config->get('domain') ?? 'current',
      '#required' => TRUE,
    ];

    $form['purge_settings']['log_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API requests'),
      '#description' => $this->t('Log all API requests and responses for debugging purposes. This may generate a lot of log entries.'),
      '#default_value' => $config->get('log_requests') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('api_secret', $form_state->getValue('api_secret'))
      ->set('application_name', $form_state->getValue('application_name'))
      ->set('domain', $form_state->getValue('domain'))
      ->set('log_requests', (bool) $form_state->getValue('log_requests'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Checks if a configuration value is overridden in settings.php.
   *
   * @param string $name
   *   The configuration key to check.
   *
   * @return mixed
   *   The overridden value if it exists, FALSE otherwise.
   */
  protected function isOverridden(string $name): mixed {
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');

    if (!empty($acquiaCredentials[$name])) {
      return $acquiaCredentials[$name];
    }

    return FALSE;
  }

}
