<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\acquia_purge_varnish\Hook\AcquiaPurgeVarnishHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;

/**
 * The #[LegacyHook] wrapper works when the Hook class is not a service.
 *
 * WHY THIS EXISTS. Issue #3628961: "You have requested a non-existent
 * service". The AcquiaPurgeVarnishHooks service was first declared in 3.0.1. A
 * site updated from 3.0.0 runs the new .module against the container compiled
 * for 3.0.0 until a cache rebuild, and the wrapper called
 * \Drupal::service(AcquiaPurgeVarnishHooks::class), so every hook_help()
 * invocation threw until `drush cr` or `drush updb`.
 *
 * This test builds exactly that container: the Hook class definition is
 * removed after every other compiler pass, including core's
 * HookCollectorPass, which re-registers Hook classes on Drupal 11.1+.
 *
 * @group acquia_purge_varnish
 */
#[Group('acquia_purge_varnish')]
#[RunTestsInSeparateProcesses]
class LegacyHookStaleContainerTest extends KernelTestBase implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'acquia_purge_varnish',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->addCompilerPass($this, PassConfig::TYPE_AFTER_REMOVING, -1000);
  }

  /**
   * Removes the Hook class service, as a 3.0.0 container lacks it.
   */
  public function process(SymfonyContainerBuilder $container): void {
    $container->removeDefinition(AcquiaPurgeVarnishHooks::class);
  }

  /**
   * The wrapper resolves the Hook class without the container service.
   */
  public function testWrapperRunsWithoutTheHookService(): void {
    // Control: without this, a green result would prove nothing.
    $this->assertFalse(
      $this->container->has(AcquiaPurgeVarnishHooks::class),
      'The Hook class must be absent from the container, as on a site updated from 3.0.0 before a cache rebuild.'
    );

    $route_match = $this->container->get('current_route_match');

    // Before the fix this call threw ServiceNotFoundException. An unrelated
    // route has no help text, so the Hook method answers NULL.
    $this->assertNull(acquia_purge_varnish_help('some.other.route', $route_match));
  }

}
