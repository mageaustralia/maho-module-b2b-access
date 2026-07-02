<?php

declare(strict_types=1);

namespace Tests\unit;

use MageAustralia_B2bAccess_Model_Gate_Rule as Rule;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of the access-rule value object: scope matching (group /
 * store / country / product) and enforcement flags. No Maho required.
 */
final class GateRuleTest extends TestCase
{
    private function rule(array $overrides = []): Rule
    {
        $d = array_merge([
            'id' => 't', 'name' => 'n',
            'groupIds' => [], 'storeIds' => [], 'countryCodes' => [],
            'categoryIds' => [], 'productIds' => [],
            'hideListing' => false, 'hidePrice' => false, 'blockPurchase' => false,
            'redirectCms' => null, 'enforcement' => Rule::ENFORCE_BOTH, 'message' => null,
        ], $overrides);

        return new Rule(
            $d['id'], $d['name'], $d['groupIds'], $d['storeIds'], $d['countryCodes'],
            $d['categoryIds'], $d['productIds'], $d['hideListing'], $d['hidePrice'],
            $d['blockPurchase'], $d['redirectCms'], $d['enforcement'], $d['message'],
        );
    }

    public function testCatalogWideWhenNoProductOrCategoryScope(): void
    {
        self::assertTrue($this->rule()->isCatalogWide());
        self::assertFalse($this->rule(['categoryIds' => [5]])->isCatalogWide());
        self::assertFalse($this->rule(['productIds' => [5]])->isCatalogWide());
    }

    public function testGroupMatching(): void
    {
        $r = $this->rule(['groupIds' => [1, 4]]);
        self::assertTrue($r->matchesGroup(1));
        self::assertTrue($r->matchesGroup(4));
        self::assertFalse($r->matchesGroup(2));
        self::assertTrue($this->rule()->matchesGroup(999), 'empty groupIds matches any group');
    }

    public function testStoreMatching(): void
    {
        self::assertTrue($this->rule()->matchesStore(3), 'empty matches any');
        self::assertTrue($this->rule(['storeIds' => [0]])->matchesStore(5), '[0] sentinel matches any');
        self::assertTrue($this->rule(['storeIds' => [1, 2]])->matchesStore(2));
        self::assertFalse($this->rule(['storeIds' => [1, 2]])->matchesStore(3));
    }

    public function testCountryMatching(): void
    {
        $r = $this->rule(['countryCodes' => ['US', 'CA']]);
        self::assertTrue($r->matchesCountry('us'), 'case-insensitive');
        self::assertTrue($r->matchesCountry('CA'));
        self::assertFalse($r->matchesCountry('GB'));
        self::assertFalse($r->matchesCountry(null), 'country-scoped rule never matches unknown');
        self::assertTrue($this->rule()->matchesCountry(null), 'unscoped rule matches unknown');
    }

    public function testProductCoverage(): void
    {
        self::assertTrue($this->rule()->coversProduct(7, [1, 2]), 'catalog-wide covers all');

        $r = $this->rule(['categoryIds' => [10, 11], 'productIds' => [500]]);
        self::assertTrue($r->coversProduct(500, [1, 2]), 'direct product id');
        self::assertTrue($r->coversProduct(1, [11, 2]), 'via category intersect');
        self::assertFalse($r->coversProduct(1, [2, 3]), 'unrelated product');
    }

    public function testEnforcementFlags(): void
    {
        $both = $this->rule(['enforcement' => Rule::ENFORCE_BOTH]);
        self::assertTrue($both->enforcesVisibility());
        self::assertTrue($both->enforcesCheckout());

        $vis = $this->rule(['enforcement' => Rule::ENFORCE_VISIBILITY]);
        self::assertTrue($vis->enforcesVisibility());
        self::assertFalse($vis->enforcesCheckout());

        $co = $this->rule(['enforcement' => Rule::ENFORCE_CHECKOUT]);
        self::assertFalse($co->enforcesVisibility());
        self::assertTrue($co->enforcesCheckout());
    }
}
