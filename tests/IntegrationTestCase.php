<?php

declare(strict_types=1);

namespace Tests;

use Mage;
use PHPUnit\Framework\TestCase;

/**
 * Base for B2B Access integration tests. Skips when Maho is not booted (no
 * MAHO_ROOT), so the class still autoloads in the pure-unit CI job. Each test
 * runs inside a transaction that is rolled back at teardown, leaving the dev DB
 * untouched (schema-owning DDL is not exercised here).
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var \Varien_Db_Adapter_Interface|null */
    protected $write;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('B2BACCESS_MAHO_BOOTED') || B2BACCESS_MAHO_BOOTED !== true) {
            $this->markTestSkipped('Set MAHO_ROOT to a Maho install to run integration tests.');
        }
        $this->write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $this->write->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            $this->write?->rollBack();
        } catch (\Throwable $e) {
            fwrite(STDERR, '[IntegrationTestCase] rollback failed: ' . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    /**
     * Override a store-config path at runtime (no DB write, no cache), so a test
     * can drive the basic-mode gate without persisting configuration.
     */
    protected function setConfig(string $path, string $value): void
    {
        Mage::app()->getStore()->setConfig($path, $value);
    }
}
