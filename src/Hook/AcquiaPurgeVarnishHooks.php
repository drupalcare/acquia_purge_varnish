<?php

namespace Drupal\acquia_purge_varnish\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for acquia_purge_varnish.
 */
class AcquiaPurgeVarnishHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    switch ($route_name) {
      case 'help.page.acquia_purge_varnish':
        $readme_file = __DIR__ . '/README.md';
        if (!file_exists($readme_file)) {
          return NULL;
        }
        $text = file_get_contents($readme_file);
        if ($text === FALSE) {
          return NULL;
        }
        // Use the Markdown filter to render the README if available.
        if (\Drupal::moduleHandler()->moduleExists('markdown')) {
          $filter_manager = \Drupal::service('plugin.manager.filter');
          $markdown_config = \Drupal::configFactory()->get('markdown.settings')->getRawData();
          $filter_config = [
            'settings' => $markdown_config,
          ];
          $filter = $filter_manager->createInstance('markdown', $filter_config);
          if ($filter) {
            return $filter->process($text, 'en')->getProcessedText();
          }
        }
        // Fallback to plain text display.
        return '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    return NULL;
  }

}
