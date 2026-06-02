<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 *
 * Deliberately does NOT require this module's own vendor/autoload.php. Doing so
 * under the site-level PHPUnit binary loads the module's bundled PHPUnit
 * (composer pins ^9 || ^10) alongside the site's PHPUnit 11, mixing two major
 * versions and crashing before any test runs. Instead we register only the
 * module's own PSR-4 namespaces; the active runner (site-level or module-local)
 * already provides PHPUnit, PSR-3, Guzzle, Symfony, Procrastinator, etc. on its
 * own autoloader, and the stubs below stand in for the Drupal/DKAN classes the
 * unit tests reference.
 */

spl_autoload_register(static function (string $class): void {
  $map = [
    'Drupal\\dkan_query_tools\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\dkan_query_tools\\' => __DIR__ . '/../tests/src/',
  ];
  foreach ($map as $prefix => $baseDir) {
    if (str_starts_with($class, $prefix)) {
      $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
      $path = $baseDir . $relative . '.php';
      if (is_file($path)) {
        require $path;
      }
      return;
    }
  }
});

// Standalone stubs stand in for Drupal/DKAN classes (no Drupal bootstrap here).
$stubDir = __DIR__ . '/stubs';
foreach (glob($stubDir . '/*.php') as $stub) {
  require_once $stub;
}
