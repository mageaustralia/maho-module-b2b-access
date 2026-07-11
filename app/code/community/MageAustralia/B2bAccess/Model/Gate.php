<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_B2bAccess
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * The rules engine. Produces the list of access rules in force for a store and
 * answers the enforcement questions (hide price / hide listing / block purchase
 * / which groups a product is hidden from). Two rule sources, one behaviour:
 *
 *  - Basic mode: synthesises up to two rules from system config, reproducing the
 *    original single-config behaviour exactly (gate-by-group and gate-by-category
 *    become one rule each).
 *  - Rules mode: loads rows from `b2baccess_rule` (added in a later step).
 *
 * Rules are memoised per store id for the duration of the request.
 */
class MageAustralia_B2bAccess_Model_Gate
{
    public const MODE_BASIC = 'basic';
    public const MODE_RULES = 'rules';

    /** @var array<int, list<MageAustralia_B2bAccess_Model_Gate_Rule>> */
    private array $_rulesByStore = [];

    /** @var array<int, list<int>> memoised expanded category ids, keyed by root category id */
    private array $_expandedCategories = [];

    /** @var array<string, list<int>> memoised hidden product ids, keyed by store:group:country */
    private array $_hiddenProductIds = [];

    private function helper(): MageAustralia_B2bAccess_Helper_Data
    {
        /** @var MageAustralia_B2bAccess_Helper_Data $h */
        $h = Mage::helper('b2baccess');
        return $h;
    }

    public function getMode(?int $storeId = null): string
    {
        $mode = (string) Mage::getStoreConfig(MageAustralia_B2bAccess_Helper_Data::XML_MODE, $storeId);
        return $mode === self::MODE_RULES ? self::MODE_RULES : self::MODE_BASIC;
    }

    /**
     * All rules in force for a store, memoised.
     *
     * @return list<MageAustralia_B2bAccess_Model_Gate_Rule>
     */
    public function getRules(?int $storeId = null): array
    {
        $storeId = $storeId !== null ? $storeId : (int) Mage::app()->getStore()->getId();
        if (isset($this->_rulesByStore[$storeId])) {
            return $this->_rulesByStore[$storeId];
        }

        if (!$this->helper()->isEnabled($storeId)) {
            return $this->_rulesByStore[$storeId] = [];
        }

        $rules = $this->getMode($storeId) === self::MODE_RULES
            ? $this->buildRulesFromDb($storeId)
            : $this->buildRulesFromConfig($storeId);

        return $this->_rulesByStore[$storeId] = $rules;
    }

    /**
     * Rules that apply to a product for a given group/country context, in
     * priority order (lowest priority number first).
     *
     * @return list<MageAustralia_B2bAccess_Model_Gate_Rule>
     */
    public function matchingRules(
        Mage_Catalog_Model_Product $product,
        int $storeId,
        int $groupId,
        ?string $countryCode = null,
    ): array {
        $productCategoryIds = array_map('intval', (array) $product->getCategoryIds());
        $productId = (int) $product->getId();

        $matched = [];
        foreach ($this->getRules($storeId) as $rule) {
            if ($rule->matchesGroup($groupId)
                && $rule->matchesStore($storeId)
                && $rule->matchesCountry($countryCode)
                && $rule->coversProduct($productId, $productCategoryIds)
                && $rule->matchesConditions($product)
            ) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    /**
     * Per-product gate for a caller whose identity is passed in explicitly.
     *
     * The session-based helpers cannot be used from API Platform: the caller is
     * resolved from a JWT, not the customer session, so Mage's customer
     * singleton is empty and every request would look like a guest. Callers that
     * know the group (the API DTO builders) hand it to us instead.
     *
     * Use this - not groupGateApplies() - whenever a product is in hand.
     * groupGateApplies() only considers *catalog-wide* rules, so evaluating a
     * product through it silently ignores every category-scoped, product-scoped
     * and condition-based rule.
     */
    public function actionApplies(
        Mage_Catalog_Model_Product $product,
        int $storeId,
        int $groupId,
        string $action,
        ?string $countryCode = null,
    ): bool {
        foreach ($this->matchingRules($product, $storeId, $groupId, $countryCode) as $rule) {
            if ($this->ruleHasAction($rule, $action)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Group-only gate for surfaces with no product context (e.g. the listing
     * toolbar). True when any catalog-wide rule with the given action matches
     * the current group.
     *
     * Only catalog-wide rules are considered, because without a product there is
     * nothing to evaluate a category/condition scope against. If you have a
     * product, call actionApplies() instead.
     */
    public function groupGateApplies(int $storeId, int $groupId, string $action): bool
    {
        foreach ($this->getRules($storeId) as $rule) {
            if ($rule->matchesGroup($groupId)
                && $rule->matchesStore($storeId)
                && $rule->isCatalogWide()
                && $this->ruleHasAction($rule, $action)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The customer-group ids a product must be hidden from in search, unioned
     * across every visibility-enforcing hide-listing rule that covers it. An
     * "any group" rule (empty groupIds) expands to every real group id, because
     * hiding a product from everyone means it should never surface in search.
     *
     * Country scope is intentionally ignored here: country is enforced at
     * checkout, not in the (CDN-cacheable, geo-agnostic) search index.
     *
     * @return list<int>
     */
    public function getRestrictedGroupIds(Mage_Catalog_Model_Product $product, int $storeId): array
    {
        $productCategoryIds = array_map('intval', (array) $product->getCategoryIds());
        $productId = (int) $product->getId();

        $groupIds = [];
        foreach ($this->getRules($storeId) as $rule) {
            if (!$rule->hideListing || !$rule->enforcesVisibility()) {
                continue;
            }
            if (!$rule->matchesStore($storeId)) {
                continue;
            }
            if (!$rule->coversProduct($productId, $productCategoryIds)) {
                continue;
            }
            if (!$rule->matchesConditions($product)) {
                continue;
            }
            $ruleGroups = $rule->groupIds === [] ? $this->helper()->getAllGroupIds() : $rule->groupIds;
            foreach ($ruleGroups as $gid) {
                $groupIds[$gid] = $gid;
            }
        }
        return array_values($groupIds);
    }

    /**
     * True when a catalog-wide hide-listing rule (no product/category scope)
     * matches this context - i.e. the whole catalog should vanish from listings
     * for this customer. Rare but legitimate ("this group cannot browse").
     */
    public function hidesEntireCatalog(int $storeId, int $groupId, ?string $countryCode = null): bool
    {
        foreach ($this->getRules($storeId) as $rule) {
            if ($rule->hideListing
                && $rule->enforcesVisibility()
                && $rule->isCatalogWide()
                && $rule->matchesGroup($groupId)
                && $rule->matchesStore($storeId)
                && $rule->matchesCountry($countryCode)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The product ids to exclude from SQL catalog listings for this customer:
     * the union of directly-listed products and products in gated categories,
     * across every scoped hide-listing rule that matches. Catalog-wide rules are
     * handled separately by {@see hidesEntireCatalog()}. Memoised per context.
     *
     * @return list<int>
     */
    public function getHiddenProductIds(int $storeId, int $groupId, ?string $countryCode = null): array
    {
        $key = $storeId . ':' . $groupId . ':' . ($countryCode ?? '');
        if (isset($this->_hiddenProductIds[$key])) {
            return $this->_hiddenProductIds[$key];
        }

        $directProductIds = [];
        $categoryIds = [];
        foreach ($this->getRules($storeId) as $rule) {
            if (!$rule->hideListing || !$rule->enforcesVisibility() || $rule->isCatalogWide()) {
                continue;
            }
            if (!$rule->matchesGroup($groupId) || !$rule->matchesStore($storeId) || !$rule->matchesCountry($countryCode)) {
                continue;
            }
            foreach ($rule->productIds as $pid) {
                $directProductIds[$pid] = $pid;
            }
            foreach ($rule->categoryIds as $cid) {
                $categoryIds[$cid] = $cid;
            }
        }

        $ids = $directProductIds;
        if ($categoryIds !== []) {
            /** @var Mage_Core_Model_Resource $resource */
            $resource = Mage::getSingleton('core/resource');
            $read = $resource->getConnection('core_read');
            $select = $read->select()
                ->from($resource->getTableName('catalog/category_product'), ['product_id'])
                ->where('category_id IN (?)', array_values($categoryIds));
            foreach ($read->fetchCol($select) as $pid) {
                $pid = (int) $pid;
                $ids[$pid] = $pid;
            }
        }

        return $this->_hiddenProductIds[$key] = array_values($ids);
    }

    /**
     * True when a checkout-enforcing block-purchase rule covers this product for
     * the given context. This is where country-scoped rules finally bite: at
     * add-to-cart the shipping country is usually unknown, but at checkout the
     * quote carries a real shipping address.
     */
    public function isPurchaseBlockedAtCheckout(
        Mage_Catalog_Model_Product $product,
        int $storeId,
        int $groupId,
        ?string $countryCode,
    ): bool {
        foreach ($this->matchingRules($product, $storeId, $groupId, $countryCode) as $rule) {
            if ($rule->blockPurchase && $rule->enforcesCheckout()) {
                return true;
            }
        }
        return false;
    }

    private function ruleHasAction(MageAustralia_B2bAccess_Model_Gate_Rule $rule, string $action): bool
    {
        // Enforcement-aware: visibility actions require visibility enforcement,
        // blocking purchase requires checkout enforcement. Mirrors the helper's
        // product-context check so both surfaces agree.
        return match ($action) {
            'hide_price'     => $rule->hidePrice && $rule->enforcesVisibility(),
            'hide_listing'   => $rule->hideListing && $rule->enforcesVisibility(),
            'block_purchase' => $rule->blockPurchase && $rule->enforcesCheckout(),
            default          => false,
        };
    }

    /* ---------------- basic mode ---------------- */

    /**
     * @return list<MageAustralia_B2bAccess_Model_Gate_Rule>
     */
    private function buildRulesFromConfig(int $storeId): array
    {
        $helper = $this->helper();
        $hidePrice     = Mage::getStoreConfigFlag(MageAustralia_B2bAccess_Helper_Data::XML_HIDE_PRICE, $storeId);
        $hideListing   = Mage::getStoreConfigFlag(MageAustralia_B2bAccess_Helper_Data::XML_HIDE_LISTING, $storeId);
        $blockPurchase = Mage::getStoreConfigFlag(MageAustralia_B2bAccess_Helper_Data::XML_BLOCK_PURCHASE, $storeId);
        $message       = $helper->getPriceMessage();

        // No visibility/purchase action configured → no rules (the login wall is
        // handled separately and does not depend on the gate).
        if (!$hidePrice && !$hideListing && !$blockPurchase) {
            return [];
        }

        $rules = [];

        if (Mage::getStoreConfigFlag(MageAustralia_B2bAccess_Helper_Data::XML_BY_CUSTOMER, $storeId)) {
            $groups = $helper->getGatedGroupIds();
            if ($groups !== []) {
                $rules[] = new MageAustralia_B2bAccess_Model_Gate_Rule(
                    id: 'basic:group',
                    name: 'Basic - by customer group',
                    groupIds: $groups,
                    storeIds: [],
                    countryCodes: [],
                    categoryIds: [],
                    productIds: [],
                    hideListing: $hideListing,
                    hidePrice: $hidePrice,
                    blockPurchase: $blockPurchase,
                    redirectCms: null,
                    enforcement: MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
                    message: $message,
                );
            }
        }

        if (Mage::getStoreConfigFlag(MageAustralia_B2bAccess_Helper_Data::XML_BY_CATEGORY, $storeId)) {
            $categories = $this->expandCategoryIds(
                $helper->getConfiguredCategoryIds(),
                $storeId,
            );
            if ($categories !== []) {
                $rules[] = new MageAustralia_B2bAccess_Model_Gate_Rule(
                    id: 'basic:category',
                    name: 'Basic - by category',
                    groupIds: [],
                    storeIds: [],
                    countryCodes: [],
                    categoryIds: $categories,
                    productIds: [],
                    hideListing: $hideListing,
                    hidePrice: $hidePrice,
                    blockPurchase: $blockPurchase,
                    redirectCms: null,
                    enforcement: MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
                    message: $message,
                );
            }
        }

        return $rules;
    }

    /* ---------------- rules mode (DB) ---------------- */

    /**
     * @return list<MageAustralia_B2bAccess_Model_Gate_Rule>
     */
    private function buildRulesFromDb(int $storeId): array
    {
        // Populated once the rule tables + resource model land (next step). Until
        // then rules mode yields nothing rather than falling over.
        if (!Mage::getConfig()->getNode('global/models/b2baccess_resource')) {
            return [];
        }

        /** @var MageAustralia_B2bAccess_Model_Resource_Rule_Collection $collection */
        $collection = Mage::getResourceModel('b2baccess/rule_collection');
        $collection->addActiveFilter()->setPriorityOrder();

        // All active rules are built into value objects; per-store scope is then
        // applied by Rule::matchesStore at match time. Rule counts are small
        // (tens), so this is cheaper and less error-prone than a JSON LIKE query.
        $rules = [];
        foreach ($collection as $model) {
            /** @var MageAustralia_B2bAccess_Model_Rule $model */
            $rules[] = $model->toGateRule($this);
        }
        return $rules;
    }

    /* ---------------- shared helpers ---------------- */

    /**
     * Expand a set of category ids to include all descendants, so a product in
     * any subcategory of a gated category is gated too. Memoised per root id.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function expandCategoryIds(array $categoryIds, ?int $storeId = null): array
    {
        $set = [];
        foreach ($categoryIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $set[$id] = true;
            if (isset($this->_expandedCategories[$id])) {
                foreach ($this->_expandedCategories[$id] as $child) {
                    $set[$child] = true;
                }
                continue;
            }
            /** @var Mage_Catalog_Model_Category $cat */
            $cat = Mage::getModel('catalog/category')->load($id);
            $children = [];
            if ($cat->getId()) {
                foreach (explode(',', (string) $cat->getAllChildren()) as $child) {
                    $child = (int) trim($child);
                    if ($child > 0) {
                        $children[] = $child;
                        $set[$child] = true;
                    }
                }
            }
            $this->_expandedCategories[$id] = $children;
        }
        return array_values(array_map('intval', array_keys($set)));
    }
}
