<?php

declare(strict_types=1);

/**
 * MageAustralia_B2bAccess - test bootstrap.
 *
 * Two suites:
 *
 *  - Unit (tests/unit): pure PHP, no Maho. The value objects under test
 *    (e.g. the gate Rule) have zero framework dependencies, so we just require
 *    them directly below and run anywhere - including CI without a database.
 *
 *  - Integration (tests/integration): needs a real Maho install, taken from
 *    $MAHO_ROOT. Each test wraps itself in a transaction and rolls back, so the
 *    dev DB is left untouched. When MAHO_ROOT is absent the integration classes
 *    still autoload but their tests skip in setUp().
 *
 * Usage:
 *   vendor/bin/phpunit --testsuite Unit               # anywhere
 *   MAHO_ROOT=/path/to/maho vendor/bin/phpunit        # full suite
 */

// Always available: the framework-free classes exercised by the Unit suite.
$module = __DIR__ . '/../app/code/community/MageAustralia/B2bAccess';
require_once $module . '/Model/Gate/Rule.php';

// PSR-0 autoload for our Tests\* helpers under tests/.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Tests\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('Tests\\'))) . '.php';
    $path = __DIR__ . '/' . $rel;
    if (is_file($path)) {
        require_once $path;
    }
});

// Integration suite: boot Maho when MAHO_ROOT points at a real install.
$root = getenv('MAHO_ROOT') ?: (defined('MAHO_ROOT') ? MAHO_ROOT : '');
if ($root !== '' && is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
    Mage::register('isSecureArea', true, true);
    Mage::app('admin');
    define('B2BACCESS_MAHO_BOOTED', true);
} else {
    define('B2BACCESS_MAHO_BOOTED', false);
}
