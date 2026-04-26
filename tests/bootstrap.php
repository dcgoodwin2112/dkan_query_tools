<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
  require $autoloader;
}

$stubDir = __DIR__ . '/stubs';
foreach (glob($stubDir . '/*.php') as $stub) {
  require_once $stub;
}
