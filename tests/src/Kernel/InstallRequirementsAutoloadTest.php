<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Kernel;

use Composer\Autoload\ClassLoader;
use Drupal\KernelTests\KernelTestBase;
use Drupal\acquia_purge_varnish\Hook\AcquiaPurgeVarnishRequirementsHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the install-phase requirements check the way core runs it.
 *
 * WHY THIS TEST EXISTS. On Drupal 10 and 11.0-11.2 the module could not be
 * installed: the install-phase hook_requirements() called a class in this
 * module's src/, and core calls that hook before the module's namespace is
 * registered, so it fataled with "Class ... not found". Every other test in
 * the suite stayed green through it, because core's tests/bootstrap.php
 * registers the namespace of every module on disk, installed or not.
 *
 * This test removes the namespace first, proves the removal took (the
 * control), and only then runs the install path. With the defect present it
 * fails with the same "Class not found" error the site showed.
 *
 * @group acquia_purge_varnish
 */
#[Group('acquia_purge_varnish')]
#[RunTestsInSeparateProcesses]
class InstallRequirementsAutoloadTest extends KernelTestBase {

  /**
   * Deliberately NOT acquia_purge_varnish: install time means not installed.
   *
   * @var string[]
   */
  protected static $modules = ['system'];

  /**
   * The namespace prefix of the module under test.
   */
  private const PREFIX = 'Drupal\\acquia_purge_varnish\\';

  /**
   * Makes the module's own classes unloadable, as they are at install time.
   */
  private function unregisterModuleNamespace(): void {
    foreach (spl_autoload_functions() as $loader) {
      // Drupal 11+ test runs wrap each loader in Symfony's DebugClassLoader;
      // unwrap it, or the Composer loader underneath is never reached.
      if (is_array($loader) && is_object($loader[0]) && method_exists($loader[0], 'getClassLoader')) {
        $loader = $loader[0]->getClassLoader();
      }
      if (is_array($loader) && $loader[0] instanceof ClassLoader) {
        $loader[0]->setPsr4(self::PREFIX, []);
      }
    }
  }

  /**
   * The install row is built without autoloading the module's classes.
   */
  public function testInstallPhaseWithoutModuleAutoload(): void {
    $this->unregisterModuleNamespace();

    // Control: if this fails the test is not reproducing install time and a
    // pass below would mean nothing.
    $this->assertFalse(
      class_exists(AcquiaPurgeVarnishRequirementsHooks::class),
      'The module namespace is still autoloadable; the install-time state was not reproduced.'
    );

    // Every real caller (drupal_check_module(), Drush's pm:install validator)
    // has install.inc loaded first; on Drupal 10 it defines REQUIREMENT_*.
    require_once $this->root . '/core/includes/install.inc';
    $path = $this->container->get('extension.list.module')->getPath('acquia_purge_varnish');
    require_once $this->root . '/' . $path . '/acquia_purge_varnish.install';

    // The legacy hook is what Drupal 10 and 11.0-11.2 call during install.
    $requirements = acquia_purge_varnish_requirements('install');
    $this->assertSame(['acquia_purge_varnish_oauth'], array_keys($requirements));

    $library = class_exists('\League\OAuth2\Client\Provider\GenericProvider');
    $expected = AcquiaPurgeVarnishRequirementsHooks::severity($library ? 'ok' : 'error');
    $this->assertSame($expected, $requirements['acquia_purge_varnish_oauth']['severity']);
  }

  /**
   * Core's own install check passes or refuses, and never throws.
   *
   * Drupal_check_module() is the function core and Drush run before enabling
   * a module, on every version this module supports: it uses the Install
   * Requirements class where core has one, and the legacy hook otherwise.
   */
  public function testCoreInstallCheckWithoutModuleAutoload(): void {
    $this->unregisterModuleNamespace();
    $this->assertFalse(class_exists(AcquiaPurgeVarnishRequirementsHooks::class));

    require_once $this->root . '/core/includes/install.inc';
    $result = drupal_check_module('acquia_purge_varnish');

    // The library guard decides, exactly as on a real site: install is
    // allowed with league/oauth2-client present and refused without it.
    $library = class_exists('\League\OAuth2\Client\Provider\GenericProvider');
    $this->assertSame($library, $result);
  }

}
