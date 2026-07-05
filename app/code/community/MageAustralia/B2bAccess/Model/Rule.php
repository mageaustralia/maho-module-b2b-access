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
 * A persisted B2B access rule (rules mode).
 *
 * Two-part model:
 *  - Flat scope columns (scope_group_ids, scope_store_ids, scope_country_codes,
 *    scope_category_ids) hold *activation scope* - "where should this rule
 *    fire". These are JSON blobs so a rule can target multiple groups/stores
 *    without a join table, and the gate can index them fast.
 *  - conditions_serialized holds a Mage_Rule condition tree for *product
 *    matching* - "which products qualify" - built with the standard Catalog Rule
 *    combinator, so you get attribute conditions (brand = Head), category
 *    conditions, SKU conditions, price conditions, etc. for free.
 *
 * The b2baccess_rule_product link table + scope_category_ids column are legacy
 * fallbacks kept for rules created before v1.2. If a rule has an empty
 * conditions tree, the gate falls back to those.
 *
 * {@see toGateRule()} converts a loaded row into the immutable value object the
 * enforcement layer consumes.
 *
 * @method string getName()
 * @method int getIsActive()
 * @method int getPriority()
 * @method string|null getScopeGroupIds()
 * @method string|null getScopeStoreIds()
 * @method string|null getScopeCountryCodes()
 * @method string|null getScopeCategoryIds()
 * @method int getActionHideListing()
 * @method int getActionHidePrice()
 * @method int getActionBlockPurchase()
 * @method string|null getActionRedirectCms()
 * @method string getEnforcement()
 * @method string|null getMessage()
 * @method $this setName(string $v)
 * @method $this setIsActive(int $v)
 * @method $this setPriority(int $v)
 */
class MageAustralia_B2bAccess_Model_Rule extends Mage_Rule_Model_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->_init('b2baccess/rule');
    }

    /**
     * The root combinator for product matching. Reuses the Catalog Rule tree
     * (Mage_Catalog_Model_Rule_Condition_Combine) so all of Maho's built-in
     * catalog conditions are available: product attribute, category, SKU,
     * price, custom attributes, subselection, nested AND/OR combinations.
     */
    #[\Override]
    public function getConditionsInstance()
    {
        return Mage::getModel('catalog/rule_condition_combine');
    }

    /**
     * We don't use actions - all actions are stored as boolean columns on the
     * rule row (hide_price / block_purchase / etc.). Return an empty
     * combinator so Mage_Rule_Model_Abstract::_beforeSave() can serialize
     * without complaining; it will always serialize to an empty tree.
     */
    #[\Override]
    public function getActionsInstance()
    {
        return Mage::getModel('rule/condition_combine');
    }

    /**
     * Explicit updated_at stamp: the install script uses TIMESTAMP_INIT_UPDATE
     * for MySQL parity, but PgSQL and SQLite downgrade that to plain
     * CURRENT_TIMESTAMP with no on-update semantics. Stamping here keeps the
     * behaviour engine-agnostic without touching the shipped install script.
     */
    #[\Override]
    protected function _beforeSave()
    {
        $now = Mage_Core_Model_Locale::nowUtc();
        if (!$this->getId()) {
            $this->setCreatedAt($now);
        }
        $this->setUpdatedAt($now);
        return parent::_beforeSave();
    }

    public function getRuleId(): ?int
    {
        $id = $this->getData('rule_id');
        return $id === null ? null : (int) $id;
    }

    /** @return list<int> */
    public function getGroupIdsArray(): array
    {
        return $this->jsonInts($this->getScopeGroupIds());
    }

    /** @return list<int> */
    public function getStoreIdsArray(): array
    {
        return $this->jsonInts($this->getScopeStoreIds());
    }

    /** @return list<string> */
    public function getCountryCodesArray(): array
    {
        $decoded = json_decode((string) ($this->getScopeCountryCodes() ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $codes = [];
        foreach ($decoded as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c !== '') {
                $codes[$c] = $c;
            }
        }
        return array_values($codes);
    }

    /** @return list<int> */
    public function getCategoryIdsArray(): array
    {
        return $this->jsonInts($this->getScopeCategoryIds());
    }

    /**
     * Product ids linked via the join table. Set in bulk by the collection when
     * loading many rules; otherwise loaded on demand for a single rule.
     *
     * @return list<int>
     */
    public function getProductIdsArray(): array
    {
        if ($this->hasData('product_ids_array')) {
            return array_map('intval', (array) $this->getData('product_ids_array'));
        }
        $ids = $this->getRuleId() !== null
            ? $this->_getResource()->getProductIds($this->getRuleId())
            : [];
        $this->setData('product_ids_array', $ids);
        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    public function setProductIdsArray(array $ids): self
    {
        $this->setData('product_ids_array', array_values(array_unique(array_map('intval', $ids))));
        return $this;
    }

    /**
     * True when the rule has a non-empty product-matching condition tree.
     * A rule without conditions matches every product (like a Catalog Rule
     * with an empty tree does).
     */
    public function hasConditions(): bool
    {
        $conditions = $this->getConditions();
        $c = $conditions->getConditions();
        return is_array($c) && count($c) > 0;
    }

    /**
     * Evaluate the condition tree against a product. Returns true when the
     * tree is empty (matches everything) or when the product satisfies the
     * conditions.
     */
    public function matchesProduct(Mage_Catalog_Model_Product $product): bool
    {
        if (!$this->hasConditions()) {
            return true;
        }
        try {
            return (bool) $this->getConditions()->validate($product);
        } catch (Throwable $e) {
            // Malformed tree shouldn't take a page down; log and treat as no-match.
            Mage::logException($e);
            return false;
        }
    }

    public function getEnforcementValue(): string
    {
        return in_array($this->getEnforcement(), [
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_VISIBILITY,
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_CHECKOUT,
            MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH,
        ], true)
            ? (string) $this->getEnforcement()
            : MageAustralia_B2bAccess_Model_Gate_Rule::ENFORCE_BOTH;
    }

    /**
     * Convert to the immutable enforcement rule. Categories are expanded to
     * include descendants (via the gate, which memoises the tree walk).
     * The condition-tree object is carried over so the gate can evaluate it
     * against individual products at match time.
     */
    public function toGateRule(MageAustralia_B2bAccess_Model_Gate $gate): MageAustralia_B2bAccess_Model_Gate_Rule
    {
        $redirect = trim((string) ($this->getActionRedirectCms() ?? ''));
        $message = trim((string) ($this->getMessage() ?? ''));

        return new MageAustralia_B2bAccess_Model_Gate_Rule(
            id: $this->getRuleId() ?? 'unsaved',
            name: (string) $this->getName(),
            groupIds: $this->getGroupIdsArray(),
            storeIds: $this->getStoreIdsArray(),
            countryCodes: $this->getCountryCodesArray(),
            categoryIds: $gate->expandCategoryIds($this->getCategoryIdsArray()),
            productIds: $this->getProductIdsArray(),
            hideListing: (bool) $this->getActionHideListing(),
            hidePrice: (bool) $this->getActionHidePrice(),
            blockPurchase: (bool) $this->getActionBlockPurchase(),
            redirectCms: $redirect !== '' ? $redirect : null,
            enforcement: $this->getEnforcementValue(),
            message: $message !== '' ? $message : null,
            priority: (int) $this->getPriority(),
            ruleModel: $this,
        );
    }

    /**
     * @param string|null $json
     * @return list<int>
     */
    private function jsonInts(?string $json): array
    {
        $decoded = json_decode((string) ($json ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            $v = (int) $v;
            $out[$v] = $v;
        }
        return array_values($out);
    }

    /** @return MageAustralia_B2bAccess_Model_Resource_Rule */
    #[\Override]
    protected function _getResource()
    {
        /** @var MageAustralia_B2bAccess_Model_Resource_Rule $resource */
        $resource = parent::_getResource();
        return $resource;
    }
}
