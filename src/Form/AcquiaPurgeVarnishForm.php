<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
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
    $environment = $this->apiClient->getAcquiaEnvironment() ?? 'dev';

    $form['#attached']['library'][] = 'acquia_purge_varnish/form';
    $form['#attributes']['class'][] = 'acquia-purge-varnish-form';

    $form['apv_band'] = $this->buildBand($config->get('domain'), $config->get('custom_domains'), $environment);
    $form['apv_nav'] = $this->buildNav();

    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->sectionTitle(1, $this->t('Acquia Cloud API')),
      '#attributes' => ['class' => ['apv-section', 'apv-section--credentials']],
      '#description' => $this->t('Enter your Acquia Cloud Platform API credentials. You can generate these from your <a href="@url" target="_blank" rel="noopener">Acquia Cloud account<span class="visually-hidden"> (opens in a new tab)</span></a>.', [
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
        '#markup' => new FormattableMarkup('<span class="apv-locked">@text</span>', ['@text' => $this->t('API Key is currently being overridden in <code>settings.php</code>.')]),
      ];
    }

    // API Secret field.
    if (!$this->isOverridden('api_secret')) {
      // The saved secret is never sent back to the browser. A blank field on
      // save keeps the saved secret.
      $hasSecret = (string) ($config->get('api_secret') ?? '') !== '';
      $form['credentials']['api_secret'] = [
        '#type' => 'password',
        '#title' => $this->t('API Secret'),
        '#size' => 60,
        '#maxlength' => 128,
        '#required' => !$hasSecret,
        '#attributes' => [
          'autocomplete' => 'new-password',
          'placeholder' => $hasSecret ? $this->t('Saved. Leave blank to keep it.') : $this->t('Enter your API Secret'),
        ],
        '#description' => $hasSecret
          ? $this->t('A secret is saved and hidden. Enter a new one only to replace it.')
          : $this->t('Your Acquia Cloud Platform API Secret.'),
      ];
    }
    else {
      $form['credentials']['api_secret'] = [
        '#type' => 'item',
        '#title' => $this->t('API Secret'),
        '#markup' => new FormattableMarkup('<span class="apv-locked">@text</span>', ['@text' => $this->t('API Secret is currently being overridden in <code>settings.php</code>.')]),
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
        '#description' => $this->t('Your Acquia Cloud application name. Leave empty to auto-detect from the <code>AH_SITE_GROUP</code> environment variable. See <a href="@url" target="_blank" rel="noopener">Acquia documentation<span class="visually-hidden"> (opens in a new tab)</span></a> for more information.', [
          '@url' => 'https://docs.acquia.com/cloud-platform/manage/',
        ]),
      ];
    }
    else {
      $form['credentials']['application_name'] = [
        '#type' => 'item',
        '#title' => $this->t('Application Name'),
        '#markup' => new FormattableMarkup('<span class="apv-locked">@text</span>', ['@text' => $this->t('Application Name is currently being overridden in <code>settings.php</code>.')]),
      ];
    }

    $form['purge_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->sectionTitle(2, $this->t('What to purge')),
      '#attributes' => ['class' => ['apv-section', 'apv-section--purge']],
    ];

    $form['purge_settings']['domain'] = [
      '#type' => 'radios',
      '#title' => $this->t('Which domain(s) from @env should be purged?', [
        '@env' => $environment,
      ]),
      '#description' => $this->t('Choose whether to purge all domains or only the current domain.'),
      '#attributes' => ['class' => ['apv-choices']],
      '#options' => [
        'all' => $this->optionWithHint($this->t('All domains'), $this->t('Every domain on this environment')),
        'current' => $this->optionWithHint($this->t('Current domain only'), $this->t('Only the domain of this request')),
        'custom' => $this->optionWithHint($this->t('Select specific domains'), $this->t('Your own list')),
      ],
      '#default_value' => $config->get('domain') ?? 'current',
      '#required' => TRUE,
    ];

    $form['purge_settings']['domain_note'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['apv-callout'], 'role' => 'note'],
      '#value' => $this->t('Use "All domains" if admin and public sites use different domains. Check the list in your Acquia Cloud Domain Management.'),
    ];

    $form['purge_settings']['custom_domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom domains'),
      '#attributes' => ['placeholder' => 'www.example.com'],
      '#description' => $this->t('Enter one domain per line.'),
      '#default_value' => implode("\n", $config->get('custom_domains') ?? []),
      '#states' => [
        'visible' => [
          ':input[name="domain"]' => ['value' => 'custom'],
        ],
        'required' => [
          ':input[name="domain"]' => ['value' => 'custom'],
        ],
      ],
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
   * Builds the header band: name, purpose and status chips.
   *
   * @param mixed $domain
   *   The stored domain mode.
   * @param mixed $customDomains
   *   The stored custom domain list.
   * @param string $environment
   *   The Acquia environment name.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function buildBand(mixed $domain, mixed $customDomains, string $environment): array {
    $locked = $this->isOverridden('api_key') && $this->isOverridden('api_secret');
    $domainLabel = match ($domain) {
      'all' => $this->t('All'),
      'custom' => $this->t('@count custom', ['@count' => is_array($customDomains) ? count($customDomains) : 0]),
      default => $this->t('Current'),
    };
    $chips = [
      ['apv-chip', $this->t('Environment: @env', ['@env' => $environment])],
      [
        $locked ? 'apv-chip apv-chip--credentials apv-chip--locked' : 'apv-chip apv-chip--credentials',
        $locked ? $this->t('Credentials: settings.php') : $this->t('Credentials: Form'),
      ],
      ['apv-chip', $this->t('Domains: @domains', ['@domains' => $domainLabel])],
    ];
    $band = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['apv-band']],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:' . static::SETTINGS],
      ],
      'name' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['apv-band__name']],
        '#value' => $this->t('Acquia Purge Varnish'),
      ],
      'purpose' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['apv-band__purpose']],
        '#value' => $this->t("Clear Varnish on Acquia Cloud for this environment's domains."),
      ],
      'chips' => [
        '#type' => 'html_tag',
        '#tag' => 'ul',
        '#attributes' => ['class' => ['apv-chips']],
      ],
    ];
    foreach ($chips as $i => [$class, $text]) {
      $band['chips'][$i] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#attributes' => ['class' => explode(' ', $class)],
        '#value' => $text,
      ];
    }
    return $band;
  }

  /**
   * Builds the two-link navigation (Settings, Purge varnish).
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function buildNav(): array {
    $nav = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#weight' => -90,
      '#attributes' => ['class' => ['apv-nav']],
      '#cache' => ['contexts' => ['user.permissions']],
      'settings' => [
        '#type' => 'html_tag',
        '#tag' => 'li',
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Settings'),
          '#url' => Url::fromRoute('acquia_purge_varnish.form'),
          '#attributes' => ['aria-current' => 'page'],
        ],
      ],
    ];
    // The route needs the same permission as this form, so no extra check.
    $nav['purge'] = [
      '#type' => 'html_tag',
      '#tag' => 'li',
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Purge varnish'),
        '#url' => Url::fromRoute('acquia_purge_varnish.cache'),
      ],
    ];
    return $nav;
  }

  /**
   * Builds a numbered section title.
   *
   * @param int $step
   *   The step number.
   * @param mixed $label
   *   The translated section label.
   */
  protected function sectionTitle(int $step, mixed $label): FormattableMarkup {
    return new FormattableMarkup('<span class="apv-step" aria-hidden="true">@step</span>@label', [
      '@step' => (string) $step,
      '@label' => $label,
    ]);
  }

  /**
   * Builds a radio option label with a short hint line.
   *
   * @param mixed $label
   *   The translated option label.
   * @param mixed $hint
   *   The translated hint.
   */
  protected function optionWithHint(mixed $label, mixed $hint): FormattableMarkup {
    return new FormattableMarkup('@label<span class="apv-hint">@hint</span>', [
      '@label' => $label,
      '@hint' => $hint,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Only validate if not overridden in settings.php.
    if (!$this->isOverridden('api_key')) {
      $apiKey = trim((string) $form_state->getValue('api_key'));
      if (empty($apiKey)) {
        $form_state->setErrorByName('api_key', $this->t('API Key is required.'));
      }
    }

    if (!$this->isOverridden('api_secret')) {
      $apiSecret = trim((string) $form_state->getValue('api_secret'));
      $savedSecret = (string) ($this->config(self::SETTINGS)->get('api_secret') ?? '');
      if ($apiSecret === '' && $savedSecret === '') {
        $form_state->setErrorByName('api_secret', $this->t('API Secret is required.'));
      }
    }
    if ($form_state->getValue('domain') === 'custom') {
      $domains = $form_state->getValue('custom_domains');
      $domainList = array_filter(array_map('trim', explode("\n", $domains)));

      if (empty($domainList)) {
        $form_state->setErrorByName('custom_domains',
          $this->t('Please enter at least one domain.'));
      }

      // Validate domain format.
      foreach ($domainList as $domain) {
        if (!preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i', $domain)) {
          $form_state->setErrorByName('custom_domains',
            $this->t('Invalid domain: @domain', ['@domain' => $domain]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(self::SETTINGS);

    // Only save values that aren't overridden in settings.php.
    if (!$this->isOverridden('api_key')) {
      $config->set('api_key', trim((string) $form_state->getValue('api_key')));
    }

    // A blank secret field keeps the saved secret.
    $newSecret = trim((string) $form_state->getValue('api_secret'));
    if (!$this->isOverridden('api_secret') && $newSecret !== '') {
      $config->set('api_secret', $newSecret);
    }

    if (!$this->isOverridden('application_name')) {
      $config->set('application_name', trim((string) $form_state->getValue('application_name')));
    }

    $config->set('domain', $form_state->getValue('domain'));

    if ($form_state->getValue('domain') === 'custom') {
      $domains = $form_state->getValue('custom_domains');
      $domainList = array_filter(array_map('trim', explode("\n", $domains)));
      $config->set('custom_domains', $domainList);
    }

    $config->set('domain', $form_state->getValue('domain'))
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
   * @return bool
   *   TRUE if overridden, FALSE otherwise.
   */
  protected function isOverridden(string $name): bool {
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');
    return !empty($acquiaCredentials[$name]);
  }

}
