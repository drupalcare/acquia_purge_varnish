<?php

declare(strict_types=1);

namespace Drupal\acquia_purge_varnish\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Refuses installation when league/oauth2-client is missing.
 *
 * WHY THIS CLASS EXISTS. The OAuth row is an Error during the install phase,
 * and that row is the guard that stops the module being enabled without the
 * library it cannot work without. Every other row this module reports is
 * advisory.
 *
 * **On Drupal 12 that guard silently stopped working.** Measured 2026-09-06 on
 * 12.0-dev with `league/oauth2-client` genuinely absent: the module installed
 * anyway, with no message. Install-phase requirements are no longer read from
 * the procedural hook — `ModuleInstaller` performs no such check — and core
 * takes them from a class in this exact namespace instead. The status report
 * still showed the row, red and correct, which is what hid it.
 *
 * WHY THIS DUPLICATES THE ROW IN AcquiaPurgeVarnishRequirementsHooks, WHICH
 * LOOKS LIKE THE ONE THING THIS PROJECT FORBIDS. It was written to delegate
 * there, and that broke the install outright:
 *
 *   Class "…\Hook\AcquiaPurgeVarnishRequirementsHooks" not found
 *   in …::getRequirements()
 *
 * Install-phase requirements run before the module's namespace is registered,
 * so nothing in `src/` is autoloadable yet — core's own
 * InstallRequirementsInterface says so in as many words: *"During
 * installation, if you need to load a class from your module, you'll need to
 * include the class file directly."* The duplication is forced by that
 * contract, and the alternative is a module that cannot be installed at all.
 *
 * No version guard is needed here, unlike the runtime path: this interface
 * only exists from Drupal 11.2, which is the same release that introduced
 * RequirementSeverity. If core can call this class, the enum exists.
 *
 * @see \Drupal\acquia_purge_varnish\Hook\AcquiaPurgeVarnishRequirementsHooks::oauthRow()
 * @see \Drupal\Core\Extension\InstallRequirementsInterface
 */
class AcquiaPurgeVarnishRequirements implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The install requirement rows.
   */
  public static function getRequirements(): array {
    // Asks nothing of the container and loads nothing from this module — only
    // whether the vendor class the module needs can be autoloaded.
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
