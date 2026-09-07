<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Refuses installation when league/oauth2-client is missing.
 *
 * WHY THIS CLASS EXISTS, and it is not a stylistic port of the hook.
 *
 * The module's `hook_requirements()` returns an Error during the `install`
 * phase when the OAuth library is absent, and that row is the guard that stops
 * the module being enabled without the library it cannot work without. Every
 * other row this module reports is advisory.
 *
 * **On Drupal 12 that guard silently stopped working.** Measured 2026-09-06 on
 * 12.0-dev with `league/oauth2-client` genuinely absent: the module installed
 * anyway. Install-phase requirements are no longer read from the procedural
 * hook — `ModuleInstaller` performs no such check, and core takes them from a
 * class in this exact namespace instead. The hook still runs for the *runtime*
 * phase, which is why `/admin/reports/status` looked correct and hid the
 * regression: the row was present and red, and nothing acted on it.
 *
 * That is the module's own worst failure mode — a control you can see and
 * watch do nothing — so the check is restored here rather than left to the
 * status page.
 *
 * The procedural hook keeps its `install` branch: Drupal 10 and 11.0–11.1 do
 * not look for this class, and dropping it there would move the same silent
 * failure onto those sites instead.
 *
 * @see \acquia_purge_varnish_requirements()
 * @see \Drupal\Core\Extension\InstallRequirementsInterface
 */
class AcquiaPurgeVarnishRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   */
  public static function getRequirements(): array {
    // Install-time requirements run without the full Drupal API, so this
    // deliberately asks nothing of the container — only whether the class the
    // module needs can be autoloaded.
    if (class_exists('\League\OAuth2\Client\Provider\GenericProvider')) {
      return [
        'acquia_purge_varnish_oauth' => [
          'title' => t('Acquia Purge Varnish - OAuth2 Client Library'),
          'value' => t('Installed'),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }

    return [
      'acquia_purge_varnish_oauth' => [
        'title' => t('Acquia Purge Varnish - OAuth2 Client Library'),
        'description' => t('The league/oauth2-client library is required. Install it with: <code>composer require league/oauth2-client:^2.7</code>'),
        'value' => t('Not installed'),
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

}
