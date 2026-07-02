<?php

declare(strict_types=1);

namespace Tests\integration;

use Mage;
use Tests\IntegrationTestCase;

/**
 * Basic mode synthesises the expected rules from config and the gate answers
 * enforcement questions accordingly, using runtime config overrides (no DB).
 */
final class BasicModeGateTest extends IntegrationTestCase
{
    private function product(int $id, array $categoryIds = []): \Mage_Catalog_Model_Product
    {
        /** @var \Mage_Catalog_Model_Product $p */
        $p = Mage::getModel('catalog/product');
        $p->setId($id)->setData('category_ids', $categoryIds);
        return $p;
    }

    private function freshGate(): \MageAustralia_B2bAccess_Model_Gate
    {
        /** @var \MageAustralia_B2bAccess_Model_Gate $gate */
        $gate = Mage::getModel('b2baccess/gate');
        return $gate;
    }

    public function testByCustomerGroupCatalogWide(): void
    {
        $this->setConfig('b2baccess/general/enabled', '1');
        $this->setConfig('b2baccess/general/mode', 'basic');
        $this->setConfig('b2baccess/price/hide', '1');
        $this->setConfig('b2baccess/price/hide_listing', '1');
        $this->setConfig('b2baccess/matrix/by_customer', '1');
        $this->setConfig('b2baccess/matrix/customer_groups', '1');
        $this->setConfig('b2baccess/matrix/by_category', '0');

        $storeId = (int) Mage::app()->getStore()->getId();
        $gate = $this->freshGate();
        $rules = $gate->getRules($storeId);
        self::assertCount(1, $rules);

        $product = $this->product(123, [55]);
        // group 1 is gated; group 2 is not
        self::assertNotEmpty($gate->matchingRules($product, $storeId, 1));
        self::assertEmpty($gate->matchingRules($product, $storeId, 2));

        // catalog-wide hide-listing: entire catalog hidden for group 1
        self::assertTrue($gate->hidesEntireCatalog($storeId, 1));
        self::assertFalse($gate->hidesEntireCatalog($storeId, 2));

        // group 1 is restricted in search for this (any) product
        self::assertContains(1, $gate->getRestrictedGroupIds($product, $storeId));
    }

    public function testByCategoryAnyGroup(): void
    {
        $this->setConfig('b2baccess/general/enabled', '1');
        $this->setConfig('b2baccess/general/mode', 'basic');
        $this->setConfig('b2baccess/price/hide', '1');
        $this->setConfig('b2baccess/price/hide_listing', '0');
        $this->setConfig('b2baccess/matrix/by_customer', '0');
        $this->setConfig('b2baccess/matrix/by_category', '1');
        $this->setConfig('b2baccess/matrix/categories', '777');

        $storeId = (int) Mage::app()->getStore()->getId();
        $gate = $this->freshGate();

        $inCat = $this->product(1, [777]);
        $notInCat = $this->product(2, [888]);

        // by-category gate applies regardless of group
        self::assertNotEmpty($gate->matchingRules($inCat, $storeId, 99));
        self::assertEmpty($gate->matchingRules($notInCat, $storeId, 99));
    }

    public function testDisabledYieldsNoRules(): void
    {
        $this->setConfig('b2baccess/general/enabled', '0');
        $gate = $this->freshGate();
        self::assertSame([], $gate->getRules((int) Mage::app()->getStore()->getId()));
    }
}
