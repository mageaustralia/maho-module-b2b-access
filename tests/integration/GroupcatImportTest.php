<?php

declare(strict_types=1);

namespace Tests\integration;

use Mage;
use MageAustralia_B2bAccess_Model_Import_Groupcat as Importer;
use Tests\IntegrationTestCase;

/**
 * The Amasty Groupcat importer. The mapping path runs only when the source
 * tables exist (a real M1 migration DB); everywhere else we still assert the
 * graceful "nothing to import" behaviour and idempotency of the name guard.
 */
final class GroupcatImportTest extends IntegrationTestCase
{
    public function testNoAmastyTablesIsGraceful(): void
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        if ($read->isTableExists(Importer::RULE_TABLE)) {
            self::markTestSkipped('Amasty tables present; covered by testImportsAndIsIdempotent.');
        }

        /** @var Importer $importer */
        $importer = Mage::getModel('b2baccess/import_groupcat');
        $result = $importer->import(false);

        self::assertFalse($result['available']);
        self::assertSame(0, $result['imported']);
    }

    public function testImportsAndIsIdempotent(): void
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        if (!$read->isTableExists(Importer::RULE_TABLE)) {
            self::markTestSkipped('No Amasty Groupcat tables to import from.');
        }

        /** @var Importer $importer */
        $importer = Mage::getModel('b2baccess/import_groupcat');

        $first = $importer->import(false);
        self::assertTrue($first['available']);

        // Re-running imports nothing new; every rule is skipped by name.
        $second = $importer->import(false);
        self::assertSame(0, $second['imported']);
        self::assertGreaterThanOrEqual($first['imported'], $second['skipped']);
    }
}
