<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Hook;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The runtime status rows, for Drupal 11.3 and later.
 *
 * WHY THIS CLASS EXISTS, and it is not a stylistic port.
 *
 * On Drupal 12 a procedural `hook_requirements()` that is NOT marked
 * `#[LegacyRequirementsHook]` breaks the site: the OOP hook system tries to
 * resolve the function name through the class resolver and raises
 *
 *   Class "acquia_purge_varnish_requirements" does not exist.
 *
 * **and it does not happen while this module is being installed.** It happens
 * on the NEXT module install on that site, so the error names this module
 * while someone is installing something else — which is exactly how it gets
 * misdiagnosed. Reproduced 2026-09-06 on 12.0-dev: with this module enabled,
 * `drush pm:install inline_form_errors` failed with the line above; with it
 * uninstalled, the same install succeeded.
 *
 * `#[LegacyRequirementsHook]` on the procedural function fixes the crash by
 * telling 11.3+ not to call it — and that on its own would silently delete
 * every runtime row from the status report. This class supplies them again
 * through the replacement hook, so the rows survive the fix.
 *
 * The split by core version:
 *   - Drupal 10, 11.0-11.2: the procedural hook runs, and calls the methods
 *     here so there is one copy of the logic.
 *   - Drupal 11.3+: this class runs and the procedural hook is skipped.
 *   - Install phase, all versions from 11.2: see
 *     \Drupal\acquia_purge_varnish\Install\Requirements\AcquiaPurgeVarnishRequirements.
 *
 * @see \acquia_purge_varnish_requirements()
 */
class AcquiaPurgeVarnishRequirementsHooks {

  use StringTranslationTrait;

  /**
   * The severity value this core version expects.
   *
   * LIVES HERE, NOT IN THE .install FILE, and the reason is load order. The
   * install-phase requirements class can run before any .install is loaded —
   * core's own interface warns install-time checks run "without access to the
   * full Drupal API" — so a helper defined in a .install is not reliably
   * callable from it. This class is PSR-4 autoloadable, so every caller can
   * reach it. (Measured the hard way: with the helper in the .install, the
   * status report died with "Call to undefined function".)
   *
   * WHY A GUARD AT ALL. The module supports `^10 || ^11 || ^12` and no single
   * expression spans it: 10 and 11 define the REQUIREMENT_* constants, 11.2
   * replaced them with this enum, and **12 removed the constants outright** —
   * verified against 12.0-dev, there is no `define('REQUIREMENT_ERROR', …)`
   * in core, and reading one is a fatal.
   *
   * An integer literal is NOT a shortcut around it: the constants and the enum
   * share their values (-1/0/1/2), but
   * RequirementSeverity::maxSeverityFromRequirements() reads
   * `$requirement['severity']->value`, so an int fatals on a property read
   * instead — the same bug one step later and harder to place.
   *
   * The mechanism is core's own, and the one the Project Update Bot's rector
   * patch uses on this module (issue #3594580).
   *
   * @param string $level
   *   One of 'error', 'warning', 'info' or 'ok'.
   *
   * @return \Drupal\Core\Extension\Requirement\RequirementSeverity|int
   *   The enum case on Drupal 11.2+, the legacy constant below that.
   */
  public static function severity(string $level): RequirementSeverity|int {
    return DeprecationHelper::backwardsCompatibleCall(
      \Drupal::VERSION,
      '11.2.0',
      fn() => match ($level) {
        'error' => RequirementSeverity::Error,
        'warning' => RequirementSeverity::Warning,
        'info' => RequirementSeverity::Info,
        default => RequirementSeverity::OK,
      },
      fn() => match ($level) {
        // @phpstan-ignore constant.notFound
        'error' => REQUIREMENT_ERROR,
        // @phpstan-ignore constant.notFound
        'warning' => REQUIREMENT_WARNING,
        // @phpstan-ignore constant.notFound
        'info' => REQUIREMENT_INFO,
        // @phpstan-ignore constant.notFound
        default => REQUIREMENT_OK,
      },
    );
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The runtime requirement rows.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return self::rows();
  }

  /**
   * The runtime rows, shared with the legacy procedural hook.
   *
   * Static so that the procedural implementation can call it on core versions
   * that have no OOP hooks, without a container lookup that would fail during
   * early bootstrap.
   *
   * @return array<string, array<string, mixed>>
   *   The runtime requirement rows.
   */
  public static function rows(): array {
    $requirements = self::oauthRow();

    $config = \Drupal::config('acquia_purge_varnish.settings');
    $settings = \Drupal::service('settings');

    $settingsOverride = $settings->get('acquia_purge_varnish_credentials');
    $apiKey = $settingsOverride['api_key'] ?? $config->get('api_key');
    $apiSecret = $settingsOverride['api_secret'] ?? $config->get('api_secret');

    if (empty($apiKey) || empty($apiSecret)) {
      $requirements['acquia_purge_varnish_credentials'] = [
        'title' => t('Acquia Purge Varnish - API Credentials'),
        'value' => t('Not configured'),
        'description' => t('API credentials are required for Varnish purging. <a href="@url">Configure credentials now</a> or add them to settings.php.', [
          '@url' => Url::fromRoute('acquia_purge_varnish.form')->toString(),
        ]),
        'severity' => self::severity('warning'),
      ];
    }
    else {
      $source = !empty($settingsOverride) ? 'settings.php' : 'configuration form';
      $requirements['acquia_purge_varnish_credentials'] = [
        'title' => t('Acquia Purge Varnish - API Credentials'),
        'value' => t('Configured via @source', ['@source' => $source]),
        'severity' => self::severity('ok'),
      ];
    }

    try {
      /** @var \Drupal\acquia_purge_varnish\AcquiaPurgeVarnishApiClient $apiClient */
      $apiClient = \Drupal::service('acquia_purge_varnish.api_client');

      if ($apiClient->isAcquiaEnvironment()) {
        $environment = $apiClient->getAcquiaEnvironment();
        $siteGroup = $apiClient->getAcquiaSiteGroup();

        $requirements['acquia_purge_varnish_environment'] = [
          'title' => t('Acquia Purge Varnish - Environment'),
          'value' => t('@env environment', ['@env' => ucfirst($environment ?? 'Unknown')]),
          'description' => t('Running on Acquia Cloud Platform (Site: @site)', [
            '@site' => $siteGroup ?? 'Unknown',
          ]),
          'severity' => self::severity('ok'),
        ];
      }
      else {
        $requirements['acquia_purge_varnish_environment'] = [
          'title' => t('Acquia Purge Varnish - Environment'),
          'value' => t('Not on Acquia Cloud Platform'),
          'description' => t('This site is not running on Acquia Cloud Platform. Varnish cache purging will not function. This module is designed specifically for Acquia Cloud environments.'),
          'severity' => self::severity('info'),
        ];
      }
    }
    catch (\Exception $e) {
      $requirements['acquia_purge_varnish_environment'] = [
        'title' => t('Acquia Purge Varnish - Environment'),
        'value' => t('Unable to determine'),
        'description' => t('Error checking environment: @error', ['@error' => $e->getMessage()]),
        'severity' => self::severity('warning'),
      ];
    }

    return $requirements;
  }

  /**
   * The OAuth library row, reported in both the install and runtime phases.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement row.
   */
  public static function oauthRow(): array {
    if (!class_exists('\League\OAuth2\Client\Provider\GenericProvider')) {
      return [
        'acquia_purge_varnish_oauth' => [
          'title' => t('Acquia Purge Varnish - OAuth2 Client Library'),
          'description' => t('The league/oauth2-client library is required. Install it with: <code>composer require league/oauth2-client:^2.7</code>'),
          'value' => t('Not installed'),
          'severity' => self::severity('error'),
        ],
      ];
    }

    return [
      'acquia_purge_varnish_oauth' => [
        'title' => t('Acquia Purge Varnish - OAuth2 Client Library'),
        'value' => t('Installed'),
        'severity' => self::severity('ok'),
      ],
    ];
  }

}
