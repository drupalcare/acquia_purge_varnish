<?php

namespace Drupal\acquia_purge_varnish\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Default ConfigFormBase for the acquia_purge_varnish module.
 */
class AcquiaPurgeVarnishForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  public const SETTINGS = 'acquia_purge_varnish.settings';
  /**
   * Log activity when user enter credentials.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * ClearVarnishForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger) {
    parent::__construct($config_factory);
    $this->logger = $logger;
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
   * Build the form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return array
   *   Return array.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);

    $form['acquia_purge_varnish_form']['api_key'] = [
      '#type' => 'textfield',
      '#title' => t('API Key'),
      '#size' => 60,
      '#required' => TRUE,
      '#default_value' => $this->isOverridden('api_key') ?: $config->get('api_key'),
      '#disabled' => $this->isOverridden('api_key'),
      '#attributes' => [
        'placeholder' => [
          'API Key',
        ],
      ],
      '#description' => t('Enter your Acquia cloud API Key.'),
    ];
    $form['acquia_purge_varnish_form']['api_secret'] = [
      '#type' => 'textfield',
      '#title' => t('API Secret'),
      '#default_value' => $this->isOverridden('api_secret') ?: $config->get('api_secret'),
      '#size' => 60,
      '#required' => TRUE,
      '#disabled' => $this->isOverridden('api_secret'),
      '#attributes' => [
        'placeholder' => [
          'API Secret',
        ],
      ],
      '#description' => t('Enter your Acquia cloud API Secret.'),
    ];
    return parent::buildForm($form, $form_state);

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save all configs on submit.
    $this->config(self::SETTINGS)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('api_secret', $form_state->getValue('api_secret'))
      ->save();
    parent::submitForm($form, $form_state);

  }

  /**
   * Check if config variable is overridden by the settings.php.
   *
   * @param string $name
   *   Check for the field value.
   *
   * @return mixed
   *   Return the value
   */
  protected function isOverridden(string $name) {
    $acquiaCredentials = Settings::get('acquia_purge_varnish_credentials');
    if (!empty($acquiaCredentials[$name])) {
      return $acquiaCredentials[$name];
    }
    return FALSE;
  }

}
