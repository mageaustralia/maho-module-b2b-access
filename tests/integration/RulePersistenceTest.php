<?php

declare(strict_types=1);

namespace Tests\integration;

use Mage;
use MageAustralia_B2bAccess_Model_Gate_Rule as GateRule;
use Tests\IntegrationTestCase;

/**
 * A rule round-trips through the DB: scope JSON, product links, and conversion
 * to the immutable gate rule.
 */
final class RulePersistenceTest extends IntegrationTestCase
{
    public function testSaveLoadRoundTrip(): void
    {
        /** @var \MageAustralia_B2bAccess_Model_Rule $rule */
        $rule = Mage::getModel('b2baccess/rule');
        $rule->addData([
            'name' => 'Test rule',
            'is_active' => 1,
            'priority' => 5,
            'scope_group_ids' => json_encode([1, 4]),
            'scope_store_ids' => json_encode([0]),
            'scope_country_codes' => json_encode(['US', 'CA']),
            'scope_category_ids' => json_encode([10, 11]),
            'action_hide_listing' => 1,
            'action_hide_price' => 1,
            'action_block_purchase' => 1,
            'action_redirect_cms' => null,
            'enforcement' => GateRule::ENFORCE_BOTH,
            'message' => null,
        ]);
        $rule->setProductIdsArray([501, 502, 502]);
        $rule->save();
        $id = (int) $rule->getId();
        self::assertGreaterThan(0, $id);

        /** @var \MageAustralia_B2bAccess_Model_Rule $loaded */
        $loaded = Mage::getModel('b2baccess/rule')->load($id);
        self::assertSame('Test rule', $loaded->getName());
        self::assertSame([1, 4], $loaded->getGroupIdsArray());
        self::assertSame([0], $loaded->getStoreIdsArray());
        self::assertSame(['US', 'CA'], $loaded->getCountryCodesArray());
        self::assertSame([10, 11], $loaded->getCategoryIdsArray());
        self::assertEqualsCanonicalizing([501, 502], $loaded->getProductIdsArray());
    }

    public function testCollectionBulkLoadsProducts(): void
    {
        /** @var \MageAustralia_B2bAccess_Model_Rule $rule */
        $rule = Mage::getModel('b2baccess/rule');
        $rule->addData(['name' => 'Bulk rule', 'is_active' => 1, 'enforcement' => GateRule::ENFORCE_BOTH]);
        $rule->setProductIdsArray([701, 702]);
        $rule->save();

        $collection = Mage::getResourceModel('b2baccess/rule_collection')->addActiveFilter();
        $found = null;
        foreach ($collection as $item) {
            if ((int) $item->getId() === (int) $rule->getId()) {
                $found = $item;
            }
        }
        self::assertNotNull($found);
        self::assertEqualsCanonicalizing([701, 702], $found->getProductIdsArray());
    }

    public function testToGateRuleExpandsAndTypes(): void
    {
        /** @var \MageAustralia_B2bAccess_Model_Rule $rule */
        $rule = Mage::getModel('b2baccess/rule');
        $rule->addData([
            'name' => 'Convert rule',
            'is_active' => 1,
            'scope_group_ids' => json_encode([2]),
            'enforcement' => GateRule::ENFORCE_CHECKOUT,
            'action_block_purchase' => 1,
        ]);
        $rule->setProductIdsArray([9001]);

        /** @var \MageAustralia_B2bAccess_Model_Gate $gate */
        $gate = Mage::getModel('b2baccess/gate');
        $gateRule = $rule->toGateRule($gate);

        self::assertInstanceOf(GateRule::class, $gateRule);
        self::assertSame([2], $gateRule->groupIds);
        self::assertSame([9001], $gateRule->productIds);
        self::assertTrue($gateRule->blockPurchase);
        self::assertFalse($gateRule->enforcesVisibility());
        self::assertTrue($gateRule->enforcesCheckout());
    }
}
