<?php

declare(strict_types=1);

namespace Drupal\Tests\acquia_purge_varnish\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Procedural code must never fetch this module's own classes as services.
 *
 * WHY THIS EXISTS. Issue #3628961: "You have requested a non-existent
 * service". The AcquiaPurgeVarnishHooks service is declared in
 * acquia_purge_varnish.services.yml from 3.0.1 on; it did not exist in 3.0.0.
 * After updating the files, a site keeps its 3.0.0 container until a cache
 * rebuild, and the #[LegacyHook] wrapper in the .module called
 * \Drupal::service(AcquiaPurgeVarnishHooks::class). Core's Help block invokes
 * every hook_help() on each page it is shown on, so those pages failed until
 * `drush cr` or `drush updb`.
 *
 * The wrappers now use \Drupal::classResolver(), which returns the service
 * when the container has it and instantiates the class itself when it does
 * not. The same fix shipped in the Solo theme 1.0.36.
 *
 * WHAT THIS ENFORCES. No \Drupal::service() call in *.module, *.install or
 * *.inc names a class in the Drupal\acquia_purge_varnish namespace.
 * Core and string-id services are not flagged: `use` statements are
 * resolved, so the check is about the namespace, not the name.
 *
 * Red-proofed against the 3.0.1 .module: 1 call(s) flagged. Reads files as
 * text, so it runs on every core version.
 *
 * @group acquia_purge_varnish
 */
#[Group('acquia_purge_varnish')]
class HookClassResolutionTest extends TestCase {

  /**
   * The number of #[LegacyHook] wrappers that resolve the Hook class.
   */
  private const EXPECTED_WRAPPERS = 1;

  /**
   * Procedural module files in the module root.
   *
   * @return list<string>
   *   Absolute paths.
   */
  private function proceduralFiles(): array {
    $root = dirname(__DIR__, 3);
    return array_merge(
      glob($root . '/*.module') ?: [],
      glob($root . '/*.install') ?: [],
      glob($root . '/*.inc') ?: [],
    );
  }

  /**
   * Maps each imported short class name to its fully qualified name.
   *
   * @return array<string, string>
   *   FQCN keyed by the short name (or alias) used in the file.
   */
  private function imports(string $code): array {
    $map = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $fqcn = ltrim($match[1], '\\');
      $short = $match[2] ?? '';
      if ($short === '') {
        $parts = explode('\\', $fqcn);
        $short = end($parts);
      }
      $map[$short] = $fqcn;
    }
    return $map;
  }

  /**
   * No wrapper asks the container for a class the module itself defines.
   */
  public function testNoContainerLookupOfModuleOwnClasses(): void {
    $offenders = [];
    $resolved = 0;
    foreach ($this->proceduralFiles() as $file) {
      $code = (string) file_get_contents($file);
      $imports = $this->imports($code);
      preg_match_all('/\\\\Drupal::(service|classResolver)\(\s*\\\\?([\w\\\\]+)::class\s*\)/', $code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
      foreach ($calls as $call) {
        $name = $call[2][0];
        $fqcn = str_contains($name, '\\') ? ltrim($name, '\\') : ($imports[$name] ?? $name);
        if (!str_starts_with($fqcn, 'Drupal\\acquia_purge_varnish\\')) {
          continue;
        }
        if ($call[1][0] === 'classResolver') {
          $resolved++;
          continue;
        }
        $line = substr_count(substr($code, 0, $call[0][1]), "\n") + 1;
        $offenders[] = sprintf('%s:%d \\Drupal::service(%s::class)', basename($file), $line, $name);
      }
    }

    $this->assertSame([], $offenders, sprintf(
      "A service declared in a new release does not exist in the container of a site updated from an older one until a cache rebuild; use \\Drupal::classResolver() instead. %d offending call(s):\n%s",
      count($offenders),
      implode("\n", $offenders)
    ));
    // A scan that matches nothing proves nothing: a moved file or a broken
    // pattern must go red rather than pass on an empty result.
    $this->assertSame(self::EXPECTED_WRAPPERS, $resolved, sprintf('Found %d classResolver() wrappers; expected %d.', $resolved, self::EXPECTED_WRAPPERS));
  }

}
