<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Unit\Form;

use Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient;
use Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the Acquia Purge Varnish configuration form.
 *
 * Note: Full form functionality should be tested with kernel or functional
 * tests. These unit tests verify basic form properties only.
 *
 * @group acquia_purge_varnish
 * @coversDefaultClass \Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm
 */
class AcquiaPurgeVarnishFormTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The typed config manager mock.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The API client mock.
   *
   * @var \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient
   */
  protected AcquiaPurgeVarnishApiClient $apiClient;

  /**
   * The form under test.
   *
   * @var \Drupal\acquia_purge_varnish\Form\AcquiaPurgeVarnishForm
   */
  protected AcquiaPurgeVarnishForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->typedConfigManager = $this->createMock(TypedConfigManagerInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->apiClient = $this->createMock(AcquiaPurgeVarnishApiClient::class);

    $this->form = new AcquiaPurgeVarnishForm(
      $this->configFactory,
      $this->typedConfigManager,
      $this->logger,
      $this->apiClient
    );

    $this->form->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Tests getFormId returns correct ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('acquia_purge_varnish_form', $this->form->getFormId());
  }

  /**
   * Tests getEditableConfigNames returns correct config name.
   *
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $reflection = new \ReflectionClass($this->form);
    $method = $reflection->getMethod('getEditableConfigNames');
    $method->setAccessible(TRUE);

    $expected = ['acquia_purge_varnish.settings'];
    $this->assertEquals($expected, $method->invoke($this->form));
  }

  /**
   * Tests that SETTINGS constant has correct value.
   *
   * @covers ::SETTINGS
   */
  public function testSettingsConstant(): void {
    $this->assertEquals('acquia_purge_varnish.settings', AcquiaPurgeVarnishForm::SETTINGS);
  }

}
